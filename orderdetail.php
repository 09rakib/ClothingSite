<?php

declare(strict_types=1);

/**
 * Customer order tracking — item list, delivery address and a status
 * timeline (PROJECT_RULES.md §13 "Order tracking", §7 "Customer should see a
 * timeline instead of only a single status label").
 *
 * The timeline renders the actual history log rather than a fixed progress
 * bar: an order can be cancelled or returned, and a bar implying "Shipped is
 * next" on a cancelled order would be a fake status (Rule 12).
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Http;
use App\Support\View;

Auth::requireCustomer();

$reference = trim((string) ($_GET['reference'] ?? ''));
if ($reference === '') {
    Http::redirect('myorder.php');
}

$detail = (new OrderService())->detailByReference($reference);

if ($detail === null) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
        <div class="empty-state">
            <h1 class="page-heading">Order not found</h1>
            <a href="myorder.php" class="btn mt-16">Back to My Orders</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// A customer may only ever see their own order (§19 "No IDOR").
Auth::requireOwnership((int) $detail['order']['user_id']);

$order   = $detail['order'];
$items   = $detail['items'];
$history = $detail['history'];

$pageTitle = 'Order ' . $order['order_reference'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <p class="mt-16"><a href="myorder.php">&laquo; Back to My Orders</a></p>

    <h1 class="page-heading">
        Order <?= View::e($order['order_reference']) ?>
        <span class="status-pill <?= View::e(OrderStatus::cssClass($order['status'])) ?>">
            <?= View::e(OrderStatus::label($order['status'])) ?>
        </span>
    </h1>
    <p class="page-subheading">Placed <?= View::e(date('d M Y, g:i a', strtotime((string) $order['created_at']))) ?></p>

    <div class="cart-layout">
        <div class="cart-items">
            <div class="admin-card admin-card-wide">
                <h2 class="card-title">Items</h2>
                <table class="plain-table">
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Unit Price</th>
                            <th scope="col">Qty</th>
                            <th scope="col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($item['product_slug'])): ?>
                                        <a href="product.php?slug=<?= urlencode((string) $item['product_slug']) ?>">
                                            <?= View::e($item['product_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= View::e($item['product_name']) ?>
                                        <small class="muted">(no longer sold)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= View::money($item['unit_price']) ?></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= View::money($item['line_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" scope="row">Total</th>
                            <th><?= View::money($order['total']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="admin-card admin-card-wide mt-16">
                <h2 class="card-title">Order Timeline</h2>
                <ol class="order-timeline">
                    <?php foreach ($history as $event): ?>
                        <li>
                            <span class="status-pill <?= View::e(OrderStatus::cssClass($event['to_status'])) ?>">
                                <?= View::e(OrderStatus::label($event['to_status'])) ?>
                            </span>
                            <span class="order-timeline-date">
                                <?= View::e(date('d M Y, g:i a', strtotime((string) $event['created_at']))) ?>
                            </span>
                            <?php if (!empty($event['note'])): ?>
                                <p class="note"><?= View::e($event['note']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>

        <aside class="cart-summary">
            <h2 class="card-title">Delivery Address</h2>
            <p><?= View::e($order['recipient_name']) ?></p>
            <p class="muted"><?= View::e($order['phone']) ?></p>
            <p>
                <?= View::e($order['address_line1']) ?><?= $order['address_line2'] ? ', ' . View::e($order['address_line2']) : '' ?><br>
                <?= View::e($order['city']) ?>
            </p>

            <h2 class="card-title mt-16">Payment</h2>
            <p><?= View::e(PaymentMethod::label($order['payment_method'])) ?></p>

            <?php if (!empty($order['customer_note'])): ?>
                <h2 class="card-title mt-16">Your Note</h2>
                <p class="muted"><?= View::e($order['customer_note']) ?></p>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
