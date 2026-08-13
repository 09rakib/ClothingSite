<?php

/**
 * Shared admin header.
 *
 * Boots the application, enforces the admin role on the server, then renders
 * the sidebar. Include this as the first thing in any file under /admin, after
 * setting $pageTitle.
 *
 * The authorization check is delegated to Auth::requireAdmin() so there is a
 * single definition of "is an admin" — the old code duplicated this test in
 * deleteproduct.php, where it could have drifted out of sync.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\View;

Auth::requireAdmin();

$conn = Database::connection();

$pageTitle   = $pageTitle ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= View::e($pageTitle) ?> | Seller Panel</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="admin-layout">
    <aside class="admin-sidebar">
        <h2>Seller Panel</h2>
        <ul>
            <li><a href="seller.php" class="<?= $currentPage === 'seller.php' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="addProduct.php" class="<?= $currentPage === 'addProduct.php' ? 'active' : '' ?>">Add Product</a></li>
            <li><a href="displayproduct.php" class="<?= in_array($currentPage, ['displayproduct.php', 'updateproduct.php'], true) ? 'active' : '' ?>">View Products</a></li>
            <li><a href="../index.php">Visit Store</a></li>
            <li>
                <form method="post" action="../logout.php" class="nav-inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="nav-link-button">Logout</button>
                </form>
            </li>
        </ul>
    </aside>

    <main class="admin-main">
    <?= Flash::render() ?>
