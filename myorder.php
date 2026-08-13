<?php

declare(strict_types=1);

/**
 * Customer order history.
 *
 * Orders are grouped by reference, so a checkout containing three products
 * reads as one order rather than three unrelated ones.
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

$orders = (new OrderService())->groupedHistoryForUser((int) Auth::id());
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
        <?php foreach ($orders as $order): ?>
            <article class="order-card">
                <header class="order-card-header">
                    <div>
                        <h2 class="order-reference"><?= View::e($order['reference']) ?></h2>
                        <p class="order-date">
                            <?= View::e(date('d M Y, g:i a', strtotime($order['created_at']))) ?>
                        </p>
                    </div>
                    <div class="order-card-meta">
                        <span class="order-total"><?= View::money($order['total']) ?></span>
                        <?php if ($order['payment_method'] !== null): ?>
                            <span class="status-pill status-active">
                                <?= View::paymentLabel($order['payment_method']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col">Unit Price</th>
                                <th scope="col">Qty</th>
                                <th scope="col">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order['lines'] as $line): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($line['product_slug'])): ?>
                                            <a href="product.php?slug=<?= urlencode((string) $line['product_slug']) ?>">
                                                <?= View::e($line['product_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= View::e($line['product_name']) ?>
                                            <small class="muted">(no longer sold)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= View::money($line['unit_price'] ?? 0) ?></td>
                                    <td><?= (int) ($line['quantity'] ?? 1) ?></td>
                                    <td><?= View::money($line['total_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
