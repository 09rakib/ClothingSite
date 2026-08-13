<?php
/**
 * Shared admin header: starts the session, connects to the DB,
 * blocks non-admins, and renders the sidebar. Include this as the
 * first thing on any file inside /admin, after setting $pageTitle.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pageTitle = $pageTitle ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> | Seller Panel</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <h2>Seller Panel</h2>
        <ul>
            <li><a href="seller.php" class="<?php echo $currentPage === 'seller.php' ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="addProduct.php" class="<?php echo $currentPage === 'addProduct.php' ? 'active' : ''; ?>">Add Product</a></li>
            <li><a href="displayproduct.php" class="<?php echo in_array($currentPage, ['displayproduct.php', 'updateproduct.php']) ? 'active' : ''; ?>">View Products</a></li>
            <li><a href="../index.php">Visit Store</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
    </aside>

    <main class="admin-main">
