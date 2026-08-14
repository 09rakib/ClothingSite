<?php

declare(strict_types=1);

/**
 * Admin dashboard.
 *
 * Statistics come from the repositories rather than inline SQL, and the
 * low-stock threshold is read from config instead of the literal 5 that used
 * to be duplicated across three files (PROJECT_RULES.md Rule 5).
 *
 * PHASE 3: order stats now come from the orders table and include a status
 * breakdown, so an admin can see how many orders are waiting on them
 * (pending/confirmed) at a glance instead of only a lifetime total.
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\ProductRepository;
use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Support\Auth;
use App\Support\Config;
use App\Support\View;

$productStats = (new ProductRepository())->stats();
$orderStats   = (new OrderService())->stats();
$threshold    = (int) Config::get('catalog.low_stock_threshold', 5);

// "Needs attention" = not yet shipped and not in a terminal state.
$actionable = $orderStats['by_status'][OrderStatus::PENDING]
    + $orderStats['by_status'][OrderStatus::CONFIRMED]
    + $orderStats['by_status'][OrderStatus::PROCESSING];
?>

<h1 class="page-heading">Welcome, <?= View::e(Auth::name()) ?></h1>
<p class="page-subheading">Here's a quick snapshot of your store.</p>

<div class="value-grid">
    <div class="value-card">
        <h3>Needs Attention</h3>
        <p class="stat-number stat-danger"><?= $actionable ?></p>
        <p class="muted">Pending, confirmed or processing</p>
    </div>
    <div class="value-card">
        <h3>Total Orders</h3>
        <p class="stat-number"><?= $orderStats['total_orders'] ?></p>
    </div>
    <div class="value-card">
        <h3>Orders Today</h3>
        <p class="stat-number"><?= $orderStats['orders_today'] ?></p>
    </div>
    <div class="value-card">
        <h3>Total Revenue</h3>
        <p class="stat-number stat-success"><?= View::money($orderStats['total_revenue']) ?></p>
        <p class="muted">Excludes cancelled/failed</p>
    </div>
    <div class="value-card">
        <h3>Revenue Today</h3>
        <p class="stat-number stat-success"><?= View::money($orderStats['revenue_today']) ?></p>
    </div>
    <div class="value-card">
        <h3>Customers</h3>
        <p class="stat-number"><?= $orderStats['customers'] ?></p>
    </div>
    <div class="value-card">
        <h3>Total Products</h3>
        <p class="stat-number"><?= $productStats['total'] ?></p>
    </div>
    <div class="value-card">
        <h3>Low Stock (&lt; <?= $threshold ?>)</h3>
        <p class="stat-number stat-danger"><?= $productStats['low_stock'] ?></p>
    </div>
    <div class="value-card">
        <h3>Out of Stock</h3>
        <p class="stat-number stat-danger"><?= $productStats['out_of_stock'] ?></p>
    </div>
</div>

<h2 class="page-heading mt-16">Orders by Status</h2>
<div class="status-breakdown">
    <?php foreach (OrderStatus::all() as $status): ?>
        <a href="orders.php?status=<?= View::e($status) ?>" class="status-breakdown-item">
            <span class="status-pill <?= View::e(OrderStatus::cssClass($status)) ?>">
                <?= View::e(OrderStatus::label($status)) ?>
            </span>
            <span class="status-breakdown-count"><?= $orderStats['by_status'][$status] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<p class="mt-16">
    <a href="orders.php" class="btn">View Orders</a>
    <a href="addProduct.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">Add New Product</a>
    <a href="displayproduct.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">View Products</a>
</p>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
