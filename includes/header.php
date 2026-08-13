<?php
/**
 * Shared header: starts the session, connects to the DB, and renders
 * the top navigation. Include this as the very first thing on any
 * root-level page, then set $pageTitle before including it.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

$pageTitle = $pageTitle ?? 'Shirt & Pant Store';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> | Shirt &amp; Pant Store</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="site-header">
    <div class="logo"><a href="index.php">Shirt &amp; Pant Store</a></div>
    <nav>
        <ul>
            <li><a href="index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">Home</a></li>
            <li><a href="shop.php" class="<?php echo $currentPage === 'shop.php' ? 'active' : ''; ?>">Shop</a></li>
            <li><a href="blog.php" class="<?php echo $currentPage === 'blog.php' ? 'active' : ''; ?>">Blog</a></li>
            <li><a href="about.php" class="<?php echo $currentPage === 'about.php' ? 'active' : ''; ?>">About</a></li>
            <li><a href="contact.php" class="<?php echo $currentPage === 'contact.php' ? 'active' : ''; ?>">Contact</a></li>

            <?php if (isset($_SESSION['user_id'])): ?>
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <li><a href="admin/seller.php" class="cta">Admin Panel</a></li>
                <?php else: ?>
                    <li><a href="myorder.php" class="<?php echo $currentPage === 'myorder.php' ? 'active' : ''; ?>">My Orders</a></li>
                <?php endif; ?>
                <li><a href="logout.php">Logout</a></li>
            <?php else: ?>
                <li><a href="login.php" class="cta">Login</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<main class="site-main">
