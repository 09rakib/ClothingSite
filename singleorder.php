<?php

declare(strict_types=1);

/**
 * Place a single-product order ("Buy Now").
 *
 * This endpoint previously accepted GET, which meant a link prefetch, a
 * crawler, or a cross-site <img> tag could place an order on a logged-in
 * customer's behalf. It is now a CSRF-verified POST guarded by a single-use
 * token, so a refresh or double-click cannot create a duplicate order
 * (PROJECT_RULES.md §8, §19).
 *
 * All order logic lives in OrderService — this file only orchestrates the
 * request and renders the outcome (§3.2).
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Orders\OrderService;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Http;
use App\Support\Logger;
use App\Support\OneTimeToken;
use App\Support\Validator;
use App\Support\View;

Http::requirePost();          // Method check + CSRF verification.
Auth::requireCustomer();      // Admins have no customer cart/orders.

$errorMessage = '';
$order        = null;

$productId = Http::intParam($_POST, 'product_id');

if ($productId === null) {
    $errorMessage = 'No product was selected.';
} elseif (!OneTimeToken::consume('place_order', OneTimeToken::fromRequest())) {
    // The token was already spent: this is a refresh or a double submit.
    $errorMessage = 'This order was already submitted. Check "My Orders" before trying again.';
} else {
    // Quantity and payment method are validated, never trusted as given.
    $validator = (new Validator($_POST))
        ->label('quantity', 'Quantity')
        ->label('payment_method', 'Payment method')
        ->integer('quantity', 1, 100)
        ->inList('payment_method', PaymentMethod::enabledKeys());

    if ($validator->fails()) {
        $errorMessage = $validator->firstError();
    } else {
        $quantity      = (int) ($validator->value('quantity') ?: 1);
        $paymentMethod = $validator->value('payment_method') ?: PaymentMethod::default();

        try {
            $order = (new OrderService())->placeSingleProductOrder(
                (int) Auth::id(),
                $productId,
                $quantity,
                $paymentMethod
            );
        } catch (RuntimeException $e) {
            // Message is written to be safe for the customer to read.
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('Order placement failed', [
                'user_id'    => Auth::id(),
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
            $errorMessage = 'Something went wrong while placing your order. Please try again.';
        }
    }
}

$pageTitle = 'Order Confirmation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-narrow text-center">
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
        <a href="shop.php" class="btn">Back to Shop</a>
        <a href="myorder.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">View My Orders</a>
    <?php else: ?>
        <div class="alert alert-success" role="status">
            Order placed successfully!
            <strong><?= View::e($order['product_name']) ?></strong>
            &times; <?= (int) $order['quantity'] ?>
            (<?= View::money($order['total']) ?>)
            &mdash; paying by <?= View::e(PaymentMethod::label($order['payment_method'])) ?>.
        </div>
        <p class="note">Your order reference is #<?= (int) $order['order_id'] ?>.</p>
        <a href="myorder.php" class="btn">View My Orders</a>
        <a href="shop.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">Buy More</a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
