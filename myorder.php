<?php

declare(strict_types=1);

/**
 * Customer order history.
 *
 * Rows are scoped to the session user inside OrderService — the page never
 * accepts a user id from the request, so there is no id to tamper with
 * (PROJECT_RULES.md §13 "Never expose another customer's records by changing
 * an ID in the URL").
 *
 * Product names come from the order snapshot rather than a join, so an order
 * still reads correctly after the product is renamed or archived (§5).
 */

$pageTitle = 'My Orders';
require_once __DIR__ . '/includes/header.php';

use App\Orders\OrderService;
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
                        <th scope="col">Product</th>
                        <th scope="col">Unit Price</th>
                        <th scope="col">Qty</th>
                        <th scope="col">Total</th>
                        <th scope="col">Payment Method</th>
                        <th scope="col">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $row): ?>
                        <tr>
                            <td>#<?= (int) $row['order_id'] ?></td>
                            <td><?= View::e($row['product_name']) ?></td>
                            <td><?= View::money($row['unit_price'] ?? 0) ?></td>
                            <td><?= (int) ($row['quantity'] ?? 1) ?></td>
                            <td><?= View::money($row['total_amount']) ?></td>
                            <td><?= View::paymentLabel((string) ($row['payment_method'] ?? '')) ?></td>
                            <td><?= View::e(date('d M Y', strtotime((string) $row['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
