<?php

declare(strict_types=1);

/**
 * Checkout — review the cart, choose a delivery address and payment method,
 * place the order.
 *
 * GET renders the review screen; POST places the order (PROJECT_RULES.md §19
 * "HTTP methods"). The submit carries a single-use token so a double-click or
 * a refresh cannot create two orders (§8 "idempotency protection").
 *
 * Order creation happens inside one transaction in OrderService, which
 * re-locks and re-prices every product and independently verifies the address
 * belongs to the customer — this page never decides what anything costs or
 * trusts an address id at face value (Rule 6, §19 IDOR).
 *
 * PHASE 3: the shipping address is now chosen from the customer's own address
 * book instead of being fixed to whatever was entered at registration.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Account\AddressRepository;
use App\Cart\CartService;
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
use App\Support\Validator;
use App\Support\View;

Auth::requireCustomer();

$userId    = (int) Auth::id();
$cart      = new CartService();
$addresses = new AddressRepository();
$summary   = $cart->summary();

$placedOrder = null;
$errorMessage = '';

/* ---------------------------------------------------------
 | Place the order
 * --------------------------------------------------------- */
if (Http::isPost()) {
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
                $validator->value('note') ?: null
            );

            // Only emptied after the order committed successfully (§8
            // "Clear cart only after successful order creation").
            $cart->clear();

            // Sent after the order transaction has already committed, and
            // never allowed to affect the confirmation shown to the customer
            // (§20 "Email sending should not block checkout").
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

    // Refresh the cart view after a failure so the customer sees current state.
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

$addressBook   = $placedOrder === null ? $addresses->forUser($userId) : [];
$selectedAddr  = Http::intParam($_POST, 'address_id');

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
                        <th colspan="3" scope="row">Total</th>
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

    <?php if ($addressBook === []): ?>
        <div class="admin-card admin-card-wide">
            <h2 class="card-title">Delivery Address</h2>
            <p>You don't have a saved delivery address yet.</p>
            <a href="addresses.php?return=checkout.php" class="btn mt-16">Add a Delivery Address</a>
        </div>
    <?php else: ?>
        <form method="post" action="checkout.php">
            <?= Csrf::field() ?>
            <?= OneTimeToken::field('checkout') ?>

            <div class="cart-layout">
                <div class="cart-items">
                    <div class="admin-card admin-card-wide">
                        <div class="card-title-row">
                            <h2 class="card-title" style="border:none; margin:0; padding:0;">Delivery Address</h2>
                            <a href="addresses.php?return=checkout.php" class="link-button">Manage addresses</a>
                        </div>

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
                    </div>

                    <div class="admin-card admin-card-wide mt-16">
                        <h2 class="card-title">Payment Method</h2>

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
                    </div>

                    <div class="admin-card admin-card-wide mt-16">
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
                </div>

                <aside class="cart-summary">
                    <h2 class="card-title">Order Summary</h2>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span><?= View::money($summary['subtotal']) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Delivery</span>
                        <span class="muted">Calculated at delivery</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span>Total</span>
                        <span><?= View::money($summary['total']) ?></span>
                    </div>

                    <button type="submit" class="btn btn-block btn-lg">Place Order</button>

                    <p class="note text-center mt-16">
                        Stock and prices are confirmed when you place the order.
                    </p>
                </aside>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
