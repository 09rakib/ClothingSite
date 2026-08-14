<?php

declare(strict_types=1);

/**
 * Admin — order list (PROJECT_RULES.md §16 "Admin should be a real back
 * office", §30 Phase 3 "Admin order management").
 *
 * Before this page, admins had no way to see orders at all beyond the
 * dashboard's total count. Filter by status and search by reference/customer,
 * paginated server-side like the shop catalog.
 */

$pageTitle = 'Orders';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Orders\OrderRepository;
use App\Orders\OrderStatus;
use App\Support\Http;
use App\Support\View;

$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$page   = Http::intParam($_GET, 'page') ?? 1;

if ($status !== '' && !OrderStatus::isValid($status)) {
    $status = '';
}

$result = (new OrderRepository())->paginateForAdmin($status, $search, $page);

$activeFilters = array_filter(['status' => $status, 'q' => $search]);
?>

<h1 class="page-heading">Orders</h1>
<p class="page-subheading"><?= $result['total'] ?> order<?= $result['total'] === 1 ? '' : 's' ?></p>

<form method="get" action="orders.php" class="shop-filters" role="search">
    <div class="filter-row">
        <label class="sr-only" for="q">Search orders</label>
        <input type="search" id="q" name="q" value="<?= View::e($search) ?>"
               placeholder="Search by reference, name or email..." class="filter-input">

        <label class="sr-only" for="status">Status</label>
        <select name="status" id="status" class="filter-input">
            <option value="">All statuses</option>
            <?php foreach (OrderStatus::all() as $s): ?>
                <option value="<?= View::e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                    <?= View::e(OrderStatus::label($s)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Filter</button>
        <?php if ($activeFilters !== []): ?>
            <a href="orders.php" class="btn btn-outline" style="background:var(--color-primary);">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($result['items'] === []): ?>
    <div class="empty-state">No orders match this filter.</div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Order #</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Total</th>
                    <th scope="col">Payment</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['items'] as $order): ?>
                    <tr>
                        <td><?= View::e($order['order_reference']) ?></td>
                        <td>
                            <?= View::e($order['customer_name']) ?><br>
                            <small class="muted"><?= View::e($order['customer_email']) ?></small>
                        </td>
                        <td><?= View::money($order['total']) ?></td>
                        <td><?= View::paymentLabel((string) $order['payment_method']) ?></td>
                        <td>
                            <span class="status-pill <?= View::e(OrderStatus::cssClass($order['status'])) ?>">
                                <?= View::e(OrderStatus::label($order['status'])) ?>
                            </span>
                        </td>
                        <td><?= View::e(date('d M Y', strtotime((string) $order['created_at']))) ?></td>
                        <td>
                            <a href="vieworder.php?order_id=<?= (int) $order['id'] ?>" class="btn btn-sm btn-success">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="Order pages">
            <?php if ($result['page'] > 1): ?>
                <a href="orders.php<?= View::queryString($activeFilters, ['page' => $result['page'] - 1]) ?>" class="page-link">&laquo; Previous</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <?php if ($i === $result['page']): ?>
                    <span class="page-link current" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                    <a href="orders.php<?= View::queryString($activeFilters, ['page' => $i]) ?>" class="page-link"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($result['page'] < $result['pages']): ?>
                <a href="orders.php<?= View::queryString($activeFilters, ['page' => $result['page'] + 1]) ?>" class="page-link">Next &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
