<?php

declare(strict_types=1);

/**
 * Checkout — review the cart, apply a coupon, choose a delivery address and
 * payment method, place the order.
 *
 * GET renders the review screen; POST places the order (PROJECT_RULES.md §19
 * "HTTP methods"). The submit carries a single-use token so a double-click or
 * a refresh cannot create two orders (§8 "idempotency protection").
 *
 * Order creation happens inside one transaction in OrderService, which
 * re-locks and re-prices every product, independently verifies the address
 * belongs to the customer, and independently re-validates/redeems the coupon
 * — this page never decides what anything costs (Rule 6). The coupon code
 * shown here is read from the session purely for display continuity across
 * page loads; the actual discount applied is always recomputed server-side
 * at order-placement time from the live coupon and cart state.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Account\AddressRepository;
use App\Cart\CartService;
use App\Coupons\CouponRepository;
use App\Notifications\NotificationService;
use App\Orders\OrderService;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\OneTimeToken;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

Auth::requireCustomer();

$userId    = (int) Auth::id();
$cart      = new CartService();
$addresses = new AddressRepository();
$coupons   = new CouponRepository();
$summary   = $cart->summary();

$placedOrder  = null;
$errorMessage = '';

const COUPON_SESSION_KEY = '_checkout_coupon_code';

/* ---------------------------------------------------------
 | Coupon apply/remove (its own POST action — never places an order)
 * --------------------------------------------------------- */
if (Http::isPost() && in_array((string) ($_POST['action'] ?? ''), ['apply_coupon', 'remove_coupon'], true)) {
    Csrf::verifyRequest();

    if ((string) $_POST['action'] === 'remove_coupon') {
        Session::forget(COUPON_SESSION_KEY);
        Flash::success('Coupon removed.');
    } else {
        $code = trim((string) ($_POST['coupon_code'] ?? ''));

        if ($code === '') {
            Flash::error('Please enter a coupon code.');
        } else {
            try {
                // Validated now purely to give immediate feedback; it is
                // validated again — for real, with redemption — at order
                // placement time, so nothing here is trusted later (Rule 6).
                $coupons->validate($code, $summary['subtotal']);
                Session::set(COUPON_SESSION_KEY, strtoupper($code));
                Flash::success('Coupon applied.');
            } catch (RuntimeException $e) {
                Flash::error($e->getMessage());
            }
        }
    }

    Http::redirect('checkout.php');
}

/* ---------------------------------------------------------
 | Recompute the coupon (if any) against the current cart on every load —
 | the cart may have changed since the coupon was applied.
 * --------------------------------------------------------- */
$couponCode     = Session::get(COUPON_SESSION_KEY);
$couponDiscount = '0.00';
$couponError    = null;

if ($couponCode !== null && $summary['items'] !== []) {
    try {
        $coupon         = $coupons->validate((string) $couponCode, $summary['subtotal']);
        $couponDiscount = $coupons->calculateDiscount($coupon, $summary['subtotal']);
    } catch (RuntimeException $e) {
        $couponError = $e->getMessage();
        Session::forget(COUPON_SESSION_KEY);
        $couponCode = null;
    }
}

$displayTotal = number_format((float) $summary['subtotal'] - (float) $couponDiscount, 2, '.', '');

/* ---------------------------------------------------------
 | Place the order
 * --------------------------------------------------------- */
if (Http::isPost() && (string) ($_POST['action'] ?? '') === 'place_order') {
    Csrf::verifyRequest();

    if (!OneTimeToken::consume('checkout', OneTimeToken::fromRequest())) {
        // Token already spent: a refresh or a double submit.
        Flash::error('This order was already submitted. Check "My Orders" before trying again.');
        Http::redirect('myorder.php');
    }

    $validator = (new Validator($_POST))
        ->label('payment_method', 'Payment method')
        ->label('address_id', 'Delivery address')
        ->label('note', 'Order note')
        ->required('payment_method')
        ->inList('payment_method', PaymentMethod::enabledKeys())
        ->required('address_id')->integer('address_id', 1)
        ->maxLength('note', 500);

    if ($summary['items'] === []) {
        $errorMessage = 'Your cart is empty.';
    } elseif ($summary['has_issues']) {
        $errorMessage = 'Some items in your cart need attention. Please review your cart.';
    } elseif ($validator->fails()) {
        $errorMessage = $validator->firstError();
    } else {
        $lines = array_map(
            static fn(array $item): array => [
                'product_id' => $item['product_id'],
                'quantity'   => $item['quantity'],
            ],
            $summary['items']
        );

        try {
            $placedOrder = (new OrderService())->placeOrderFromCart(
                $userId,
                $lines,
                (int) $validator->value('address_id'),
                $validator->value('payment_method'),
                $validator->value('note') ?: null,
                $couponCode !== null ? (string) $couponCode : null
            );

            // Only emptied/cleared after the order committed successfully
            // (§8 "Clear cart only after successful order creation").
            $cart->clear();
            Session::forget(COUPON_SESSION_KEY);

            $emailStmt = Database::connection()->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $emailStmt->bind_param('i', $userId);
            $emailStmt->execute();
            $customerEmail = (string) ($emailStmt->get_result()->fetch_assoc()['email'] ?? '');
            $emailStmt->close();

            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $basePath = rtrim((string) Config::get('app.url', ''), '/');
            $orderUrl = "{$scheme}://{$host}{$basePath}/orderdetail.php?reference=" . urlencode($placedOrder['reference']);

            if ($customerEmail !== '') {
                (new NotificationService())->sendOrderConfirmation(
                    $customerEmail,
                    Auth::name(),
                    $placedOrder['reference'],
                    $placedOrder['lines'],
                    $placedOrder['total'],
                    $orderUrl
                );
            }
        } catch (RuntimeException $e) {
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('Checkout failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            $errorMessage = 'Something went wrong while placing your order. Please try again.';
        }
    }

    // Refresh the cart/coupon view after a failure so the customer sees
    // current state.
    if ($placedOrder === null) {
        $summary = $cart->summary();
    }
}

/* ---------------------------------------------------------
 | An empty cart has nothing to check out
 * --------------------------------------------------------- */
if ($placedOrder === null && $summary['items'] === []) {
    $pageTitle = 'Checkout';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
        <h1 class="page-heading">Checkout</h1>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
        <?php endif; ?>
        <div class="empty-state">
            Your cart is empty.<br>
            <a href="shop.php" class="btn mt-16">Start Shopping</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$addressBook  = $placedOrder === null ? $addresses->forUser($userId) : [];
$selectedAddr = Http::intParam($_POST, 'address_id');

$orderDetail = $placedOrder !== null ? (new OrderService())->detail((int) $placedOrder['order_id']) : null;

$pageTitle = $placedOrder !== null ? 'Order Confirmed' : 'Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

<?php if ($placedOrder !== null && $orderDetail !== null): ?>
    <?php /* ---------------- Confirmation ---------------- */ ?>
    <?php $o = $orderDetail['order']; ?>
    <div class="container-narrow">
        <div class="alert alert-success" role="status">
            <strong>Order placed successfully!</strong>
        </div>

        <div class="admin-card admin-card-wide">
            <h2 class="card-title">
                Order <?= View::e($placedOrder['reference']) ?>
                <span class="status-pill <?= View::e(App\Orders\OrderStatus::cssClass($o['status'])) ?>">
                    <?= View::e(App\Orders\OrderStatus::label($o['status'])) ?>
                </span>
            </h2>

            <table class="plain-table">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Qty</th>
                        <th scope="col">Unit Price</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($placedOrder['lines'] as $line): ?>
                        <tr>
                            <td><?= View::e($line['product_name']) ?></td>
                            <td><?= (int) $line['quantity'] ?></td>
                            <td><?= View::money($line['unit_price']) ?></td>
                            <td><?= View::money($line['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Subtotal</th>
                        <td colspan="2"></td>
                        <th><?= View::money($placedOrder['subtotal']) ?></th>
                    </tr>
                    <?php if ((float) $placedOrder['discount_amount'] > 0): ?>
                        <tr>
                            <th scope="row">Discount<?= $placedOrder['coupon_code'] ? ' (' . View::e($placedOrder['coupon_code']) . ')' : '' ?></th>
                            <td colspan="2"></td>
                            <th class="movement-positive">&minus;<?= View::money($placedOrder['discount_amount']) ?></th>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row">Total</th>
                        <td colspan="2"></td>
                        <th><?= View::money($placedOrder['total']) ?></th>
                    </tr>
                </tfoot>
            </table>

            <div class="order-confirm-meta">
                <div>
                    <h3 class="card-subtitle">Delivery Address</h3>
                    <p><?= View::e($o['recipient_name']) ?> &middot; <?= View::e($o['phone']) ?></p>
                    <p><?= View::e($o['address_line1']) ?><?= $o['address_line2'] ? ', ' . View::e($o['address_line2']) : '' ?></p>
                    <p><?= View::e($o['city']) ?></p>
                </div>
                <div>
                    <h3 class="card-subtitle">Payment</h3>
                    <p><?= View::e(PaymentMethod::label($placedOrder['payment_method'])) ?></p>
                </div>
            </div>

            <p class="note mt-16">
                Keep reference <strong><?= View::e($placedOrder['reference']) ?></strong> for any questions.
            </p>
        </div>

        <p class="text-center mt-16">
            <a href="myorder.php" class="btn">View My Orders</a>
            <a href="shop.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">Continue Shopping</a>
        </p>
    </div>

<?php else: ?>
    <?php /* ---------------- Review & confirm ---------------- */ ?>
    <h1 class="page-heading">Checkout</h1>
    <p class="page-subheading">Review your order before placing it</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($couponError !== null): ?>
        <div class="alert alert-error" role="alert"><?= View::e($couponError) ?></div>
    <?php endif; ?>

    <?php if ($addressBook === []): ?>
        <div class="admin-card admin-card-wide">
            <h2 class="card-title">Delivery Address</h2>
            <p>You don't have a saved delivery address yet.</p>
            <a href="addresses.php?return=checkout.php" class="btn mt-16">Add a Delivery Address</a>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <div class="cart-items">
                <div class="admin-card admin-card-wide">
                    <div class="card-title-row">
                        <h2 class="card-title" style="border:none; margin:0; padding:0;">Delivery Address</h2>
                        <a href="addresses.php?return=checkout.php" class="link-button">Manage addresses</a>
                    </div>

                    <form method="post" action="checkout.php">
                        <?= Csrf::field() ?>
                        <?= OneTimeToken::field('checkout') ?>
                        <input type="hidden" name="action" value="place_order">

                        <?php foreach ($addressBook as $addr): ?>
                            <div class="form-check payment-option">
                                <input type="radio" id="addr-<?= (int) $addr['id'] ?>" name="address_id"
                                       value="<?= (int) $addr['id'] ?>"
                                       <?= ($selectedAddr ?? (int) ($addressBook[0]['id'] ?? 0)) === (int) $addr['id'] ? 'checked' : '' ?>
                                       required>
                                <label for="addr-<?= (int) $addr['id'] ?>">
                                    <strong><?= View::e($addr['label']) ?></strong>
                                    <?php if ($addr['is_default']): ?><span class="status-pill status-active">Default</span><?php endif; ?>
                                    <br>
                                    <?= View::e($addr['recipient_name']) ?> &middot; <?= View::e($addr['phone']) ?><br>
                                    <span class="muted">
                                        <?= View::e($addr['address_line1']) ?><?= $addr['address_line2'] ? ', ' . View::e($addr['address_line2']) : '' ?>,
                                        <?= View::e($addr['city']) ?>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <h2 class="card-title mt-16">Payment Method</h2>

                        <?php foreach (PaymentMethod::enabled() as $key => $label): ?>
                            <div class="form-check payment-option">
                                <input type="radio" id="pay-<?= View::e($key) ?>" name="payment_method"
                                       value="<?= View::e($key) ?>"
                                       <?= $key === PaymentMethod::default() ? 'checked' : '' ?> required>
                                <label for="pay-<?= View::e($key) ?>"><?= View::e($label) ?></label>
                            </div>
                        <?php endforeach; ?>

                        <div class="form-group mt-16">
                            <label for="note">Order note <span class="optional">(optional)</span></label>
                            <textarea id="note" name="note" maxlength="500" placeholder="Delivery instructions, etc."></textarea>
                        </div>

                        <div class="admin-card admin-card-wide mt-16" style="padding:0; box-shadow:none;">
                            <h2 class="card-title">Items (<?= $summary['count'] ?>)</h2>

                            <table class="plain-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Product</th>
                                        <th scope="col">Qty</th>
                                        <th scope="col">Unit Price</th>
                                        <th scope="col">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summary['items'] as $item): ?>
                                        <tr>
                                            <td><?= View::e($item['name']) ?></td>
                                            <td><?= $item['quantity'] ?></td>
                                            <td><?= View::money($item['unit_price']) ?></td>
                                            <td><?= View::money($item['line_total']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <p class="note mt-16"><a href="cart.php">Edit cart</a></p>
                        </div>

                        <button type="submit" class="btn btn-block btn-lg mt-16">Place Order</button>
                    </form>
                </div>
            </div>

            <aside class="cart-summary">
                <h2 class="card-title">Coupon</h2>
                <?php if ($couponCode !== null): ?>
                    <p>
                        <span class="status-pill status-active"><?= View::e((string) $couponCode) ?></span>
                        &minus;<?= View::money($couponDiscount) ?>
                    </p>
                    <form method="post" action="checkout.php" class="mb-16">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="remove_coupon">
                        <button type="submit" class="link-button">Remove coupon</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="checkout.php" class="coupon-form mb-16">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="apply_coupon">
                        <input type="text" name="coupon_code" placeholder="Coupon code" maxlength="30" style="text-transform:uppercase;">
                        <button type="submit" class="btn btn-sm">Apply</button>
                    </form>
                <?php endif; ?>

                <h2 class="card-title">Order Summary</h2>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span><?= View::money($summary['subtotal']) ?></span>
                </div>
                <?php if ((float) $couponDiscount > 0): ?>
                    <div class="summary-row">
                        <span>Discount</span>
                        <span class="movement-positive">&minus;<?= View::money($couponDiscount) ?></span>
                    </div>
                <?php endif; ?>
                <div class="summary-row">
                    <span>Delivery</span>
                    <span class="muted">Calculated at delivery</span>
                </div>
                <div class="summary-row summary-total">
                    <span>Total</span>
                    <span><?= View::money($displayTotal) ?></span>
                </div>

                <p class="note text-center mt-16">
                    Stock, prices and coupon validity are confirmed when you place the order.
                </p>
            </aside>
        </div>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
