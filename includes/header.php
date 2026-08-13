<?php

/**
 * Shared storefront header.
 *
 * Boots the application (autoloader, config, hardened session, security
 * headers), opens the database connection and renders the top navigation.
 * Set $pageTitle before including it.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Support\Auth;
use App\Support\Database;
use App\Support\Flash;
use App\Support\View;

// $conn is kept for pages that have not yet been moved onto repositories.
$conn = Database::connection();

$pageTitle   = $pageTitle ?? 'Shirt & Pant Store';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= View::e($pageTitle) ?> | Shirt &amp; Pant Store</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="site-header">
    <div class="logo"><a href="index.php">Shirt &amp; Pant Store</a></div>
    <nav>
        <ul>
            <li><a href="index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">Home</a></li>
            <li><a href="shop.php" class="<?= $currentPage === 'shop.php' ? 'active' : '' ?>">Shop</a></li>
            <li><a href="blog.php" class="<?= $currentPage === 'blog.php' ? 'active' : '' ?>">Blog</a></li>
            <li><a href="about.php" class="<?= $currentPage === 'about.php' ? 'active' : '' ?>">About</a></li>
            <li><a href="contact.php" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Contact</a></li>

            <?php if (Auth::check()): ?>
                <?php if (Auth::isAdmin()): ?>
                    <li><a href="admin/seller.php" class="cta">Admin Panel</a></li>
                <?php else: ?>
                    <li><a href="myorder.php" class="<?= $currentPage === 'myorder.php' ? 'active' : '' ?>">My Orders</a></li>
                <?php endif; ?>
                <?php
                /*
                 * Logout changes state, so it is a POST form rather than a link
                 * (§19 "Never delete/order/create via GET links"). Styled as a
                 * nav link so the header looks unchanged.
                 */
                ?>
                <li>
                    <form method="post" action="logout.php" class="nav-inline-form">
                        <?= App\Support\Csrf::field() ?>
                        <button type="submit" class="nav-link-button">Logout</button>
                    </form>
                </li>
            <?php else: ?>
                <li><a href="login.php" class="cta">Login</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<main class="site-main">
<?= Flash::render() ?>
