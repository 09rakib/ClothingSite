<?php

declare(strict_types=1);

/**
 * Customer wishlist page (PROJECT_RULES.md §15).
 */

$pageTitle = 'My Wishlist';
require_once __DIR__ . '/includes/header.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\View;
use App\Wishlist\WishlistRepository;

Auth::requireCustomer();

$items = (new WishlistRepository())->forUser((int) Auth::id());
?>

<div class="container">
    <h1 class="page-heading">My Wishlist</h1>
    <p class="page-subheading"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> saved</p>

    <?php if ($items === []): ?>
        <div class="empty-state">
            Nothing saved yet.<br>
            <a href="shop.php" class="btn mt-16">Browse the Shop</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($items as $item): ?>
                <?php $stock = (int) $item['stock']; ?>
                <div class="product-card">
                    <a href="product.php?slug=<?= urlencode((string) $item['slug']) ?>" class="product-card-link">
                        <img src="assets/images/products/<?= View::e($item['image']) ?>"
                             alt="<?= View::e($item['name']) ?>" loading="lazy">
                    </a>
                    <div class="product-body">
                        <h3><a href="product.php?slug=<?= urlencode((string) $item['slug']) ?>"><?= View::e($item['name']) ?></a></h3>
                        <div class="product-meta">
                            <span class="product-price"><?= View::money($item['price']) ?></span>
                            <span class="stock-badge <?= $stock <= 0 ? 'low' : '' ?>">
                                <?= $stock > 0 ? $stock . ' in stock' : 'Out of stock' ?>
                            </span>
                        </div>

                        <?php if ($stock > 0): ?>
                            <form method="post" action="cartaction.php" class="buy-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="quantity" value="1">
                                <input type="hidden" name="return" value="wishlist.php">
                                <button type="submit" class="btn btn-block">Move to Cart</button>
                            </form>
                        <?php else: ?>
                            <span class="btn btn-block btn-disabled" aria-disabled="true">Out of Stock</span>
                        <?php endif; ?>

                        <form method="post" action="wishaction.php" class="inline-form mt-8">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="return" value="wishlist.php">
                            <button type="submit" class="link-button">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
