<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/admin-header.php';

$totalProducts = $conn->query('SELECT COUNT(*) AS c FROM products')->fetch_assoc()['c'];
$lowStock      = $conn->query('SELECT COUNT(*) AS c FROM products WHERE stock < 5')->fetch_assoc()['c'];
$totalOrders   = $conn->query('SELECT COUNT(*) AS c FROM single_order')->fetch_assoc()['c'];
$totalRevenue  = $conn->query('SELECT COALESCE(SUM(total_amount), 0) AS s FROM payments')->fetch_assoc()['s'];
?>

<h1 class="page-heading">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h1>
<p class="page-subheading">Here's a quick snapshot of your store.</p>

<div class="value-grid">
    <div class="value-card">
        <h3>Total Products</h3>
        <p style="font-size:22px; font-weight:700; color:var(--color-text);"><?php echo (int) $totalProducts; ?></p>
    </div>
    <div class="value-card">
        <h3>Low Stock (&lt; 5)</h3>
        <p style="font-size:22px; font-weight:700; color:var(--color-danger);"><?php echo (int) $lowStock; ?></p>
    </div>
    <div class="value-card">
        <h3>Total Orders</h3>
        <p style="font-size:22px; font-weight:700; color:var(--color-text);"><?php echo (int) $totalOrders; ?></p>
    </div>
    <div class="value-card">
        <h3>Total Revenue</h3>
        <p style="font-size:22px; font-weight:700; color:var(--color-success-dark);">&#2547; <?php echo number_format($totalRevenue, 2); ?></p>
    </div>
</div>

<p class="mt-16">
    <a href="addProduct.php" class="btn">Add New Product</a>
    <a href="displayproduct.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">View Products</a>
</p>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
