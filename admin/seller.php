<?php

declare(strict_types=1);

/**
 * Admin dashboard.
 *
 * Statistics come from the repositories rather than inline SQL, and the
 * low-stock threshold is read from config instead of the literal 5 that used
 * to be duplicated across three files (PROJECT_RULES.md Rule 5).
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\ProductRepository;
use App\Orders\OrderService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\View;

$productStats = (new ProductRepository())->stats();
$orderStats   = (new OrderService())->stats();
$threshold    = (int) Config::get('catalog.low_stock_threshold', 5);
?>

<h1 class="page-heading">Welcome, <?= View::e(Auth::name()) ?></h1>
<p class="page-subheading">Here's a quick snapshot of your store.</p>

<div class="value-grid">
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
    <div class="value-card">
        <h3>Archived Products</h3>
        <p class="stat-number"><?= $productStats['archived'] ?></p>
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
    </div>
    <div class="value-card">
        <h3>Revenue Today</h3>
        <p class="stat-number stat-success"><?= View::money($orderStats['revenue_today']) ?></p>
    </div>
    <div class="value-card">
        <h3>Customers</h3>
        <p class="stat-number"><?= $orderStats['customers'] ?></p>
    </div>
</div>

<p class="mt-16">
    <a href="addProduct.php" class="btn">Add New Product</a>
    <a href="displayproduct.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">View Products</a>
</p>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
