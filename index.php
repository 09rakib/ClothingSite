<?php

declare(strict_types=1);

/**
 * Home page — hero plus a small preview of the newest products.
 */

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';

use App\Catalog\ProductRepository;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\OneTimeToken;
use App\Support\View;

$featured = (new ProductRepository())->latestActive(3);
$canBuy   = Auth::check() && Auth::isCustomer();
?>

<section class="hero">
    <h1>Quality Shirts &amp; Pants, Delivered to Your Door</h1>
    <p>Everyday essentials made from comfortable fabric, at honest prices.</p>
    <a href="shop.php" class="btn btn-outline">Browse All Products</a>
</section>

<div class="container">
    <h2 class="page-heading">Featured Products</h2>
    <p class="page-subheading">A few of our customer favorites</p>

    <?php if ($featured === []): ?>
        <div class="empty-state">No products available right now. Please check back later.</div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($featured as $row): ?>
                <?php $stock = (int) $row['stock']; ?>
                <?php
                $threshold = ProductRepository::lowStockThresholdFor($row);
                $detailUrl = 'product.php?slug=' . urlencode((string) $row['slug']);
                ?>
                <div class="product-card">
                    <a href="<?= View::e($detailUrl) ?>" class="product-card-link">
                        <img src="assets/images/products/<?= View::e($row['image']) ?>"
                             alt="<?= View::e($row['name']) ?>" loading="lazy">
                    </a>
                    <div class="product-body">
                        <h3><a href="<?= View::e($detailUrl) ?>"><?= View::e($row['name']) ?></a></h3>
                        <p class="product-desc"><?= View::e($row['description']) ?></p>
                        <div class="product-meta">
                            <span class="product-price"><?= View::money($row['price']) ?></span>
                            <span class="stock-badge <?= $stock > 0 && $stock < $threshold ? 'low' : '' ?>">
                                <?= $stock > 0 ? $stock . ' in stock' : 'Out of stock' ?>
                            </span>
                        </div>

                        <?php if ($canBuy && $stock > 0): ?>
                            <form method="post" action="singleorder.php" class="buy-form">
                                <?= Csrf::field() ?>
                                <?= OneTimeToken::field('place_order') ?>
                                <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="quantity" value="1">
                                <input type="hidden" name="payment_method" value="<?= View::e(PaymentMethod::default()) ?>">
                                <button type="submit" class="btn btn-block">Buy Now</button>
                            </form>
                        <?php elseif ($canBuy): ?>
                            <span class="btn btn-block btn-disabled" aria-disabled="true">Out of Stock</span>
                        <?php elseif (Auth::check()): ?>
                            <span class="btn btn-block btn-disabled" aria-disabled="true">Admin View</span>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-block">Login to Buy</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-center mt-16">
        <a href="shop.php" class="btn">View Full Shop</a>
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
