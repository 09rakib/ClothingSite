<?php

declare(strict_types=1);

/**
 * Customer order history — one row per order (PROJECT_RULES.md §13
 * "Order tracking"). Each order links to orderdetail.php for the full item
 * list and status timeline.
 *
 * Orders are scoped to the session user inside OrderRepository — the page
 * never accepts a user id from the request (§13 "Never expose another
 * customer's records by changing an ID in the URL").
 */

$pageTitle = 'My Orders';
require_once __DIR__ . '/includes/header.php';

use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Support\Auth;
use App\Support\View;

Auth::requireCustomer();

$orders = (new OrderService())->historyForUser((int) Auth::id());
?>

<div class="container">
    <h1 class="page-heading">My Orders</h1>
    <p class="page-subheading">Everything you've bought from us so far</p>

    <?php if ($orders === []): ?>
        <div class="empty-state">
            You haven't placed any orders yet.<br>
            <a href="shop.php" class="btn mt-16">Start Shopping</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Order #</th>
                        <th scope="col">Date</th>
                        <th scope="col">Total</th>
                        <th scope="col">Payment</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <a href="orderdetail.php?reference=<?= urlencode((string) $order['order_reference']) ?>">
                                    <?= View::e($order['order_reference']) ?>
                                </a>
                            </td>
                            <td><?= View::e(date('d M Y', strtotime((string) $order['created_at']))) ?></td>
                            <td><?= View::money($order['total']) ?></td>
                            <td><?= View::paymentLabel((string) $order['payment_method']) ?></td>
                            <td>
                                <span class="status-pill <?= View::e(OrderStatus::cssClass($order['status'])) ?>">
                                    <?= View::e(OrderStatus::label($order['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="orderdetail.php?reference=<?= urlencode((string) $order['order_reference']) ?>" class="btn btn-sm">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
