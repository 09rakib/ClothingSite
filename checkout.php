<?php

declare(strict_types=1);

/**
 * Checkout — review the cart, choose a payment method, place the order.
 *
 * GET renders the review screen; POST places the order (PROJECT_RULES.md §19
 * "HTTP methods"). The submit carries a single-use token so a double-click or
 * a refresh cannot create two orders (§8 "idempotency protection").
 *
 * Order creation itself happens inside one transaction in OrderService, which
 * re-locks and re-prices every product — this page never decides what anything
 * costs (Rule 6).
 *
 * SCOPE NOTE: the shipping address used is the one captured at registration.
 * A proper address book, plus the order status machine, are Phase 3.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Cart\CartService;
use App\Orders\OrderService;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\OneTimeToken;
use App\Support\Validator;
use App\Support\View;

Auth::requireCustomer();

$cart    = new CartService();
$summary = $cart->summary();

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
        ->required('payment_method')
        ->inList('payment_method', PaymentMethod::enabledKeys());

    if ($summary['items'] === []) {
        $errorMessage = 'Your cart is empty.';
    } elseif ($summary['has_issues']) {
        $errorMessage = 'Some items in your cart need attention. Please review your cart.';
    } elseif ($validator->fails()) {
        $errorMessage = $validator->firstError();
    } else {
        // Only product ids and quantities are passed on; the service re-reads
        // every price from the database inside its transaction.
        $lines = array_map(
            static fn(array $item): array => [
                'product_id' => $item['product_id'],
                'quantity'   => $item['quantity'],
            ],
            $summary['items']
        );

        try {
            $placedOrder = (new OrderService())->placeOrderFromCart(
                (int) Auth::id(),
                $lines,
                $validator->value('payment_method')
            );

            // Only emptied after the order committed successfully (§8
            // "Clear cart only after successful order creation").
            $cart->clear();
        } catch (RuntimeException $e) {
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('Checkout failed', [
                'user_id' => Auth::id(),
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

// Delivery details come from the account record until the Phase 3 address book.
$customer = null;
if ($placedOrder === null) {
    $stmt = Database::connection()->prepare('SELECT name, phone, address FROM users WHERE id = ? LIMIT 1');
    $userId = (int) Auth::id();
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pageTitle = $placedOrder !== null ? 'Order Confirmed' : 'Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

<?php if ($placedOrder !== null): ?>
    <?php /* ---------------- Confirmation ---------------- */ ?>
    <div class="container-narrow">
        <div class="alert alert-success" role="status">
            <strong>Order placed successfully!</strong>
        </div>

        <div class="admin-card admin-card-wide">
            <h2 class="card-title">Order <?= View::e($placedOrder['reference']) ?></h2>

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

            <p class="note mt-16">
                Paying by <strong><?= View::e(PaymentMethod::label($placedOrder['payment_method'])) ?></strong>.
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

    <form method="post" action="checkout.php">
        <?= Csrf::field() ?>
        <?= OneTimeToken::field('checkout') ?>

        <div class="cart-layout">
            <div class="cart-items">
                <div class="admin-card admin-card-wide">
                    <h2 class="card-title">Delivery Details</h2>
                    <p><strong><?= View::e($customer['name'] ?? Auth::name()) ?></strong></p>
                    <p><?= View::e($customer['address'] ?? '') ?></p>
                    <p class="muted"><?= View::e($customer['phone'] ?? '') ?></p>
                    <p class="note">
                        Delivery details come from your account.
                        <?php /* Honest about the current limitation rather than
                                 pretending an address book exists. */ ?>
                        Need to change them? Contact us with your order reference.
                    </p>
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

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
