<?php

declare(strict_types=1);

/**
 * Public product detail page, addressed by slug: product.php?slug=denim-pant
 *
 * PROJECT_RULES.md §26 asks product pages to carry a clear price, stock state,
 * a gallery, delivery information and a buy action; §11 asks for slug-based
 * public URLs. This page is also where "Add to Cart" will live once the cart
 * lands in Phase 2 — Buy Now is kept meanwhile so nothing regresses.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Catalog\ProductImageRepository;
use App\Catalog\ProductRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Http;
use App\Support\View;

$products = new ProductRepository();

$slug = trim((string) ($_GET['slug'] ?? ''));

// Older links used ?product_id=N. Honour them by redirecting to the canonical
// slug URL rather than serving the same page at two addresses (§26 "canonical").
if ($slug === '') {
    $legacyId = Http::intParam($_GET, 'product_id') ?? Http::intParam($_GET, 'id');

    if ($legacyId !== null) {
        $byId = $products->findActive($legacyId);
        if ($byId !== null && ($byId['slug'] ?? '') !== '') {
            header('Location: product.php?slug=' . urlencode((string) $byId['slug']), true, 301);
            exit;
        }
    }

    Http::redirect('shop.php');
}

$product = $products->findActiveBySlug($slug);

if ($product === null) {
    // A missing product must answer 404, not 200 with an error message,
    // so search engines and monitoring see the truth.
    http_response_code(404);
    $pageTitle = 'Product not found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
        <div class="empty-state">
            <h1 class="page-heading">Product not found</h1>
            <p>This product may have been removed or is no longer for sale.</p>
            <a href="shop.php" class="btn mt-16">Browse the shop</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$productId = (int) $product['id'];
$stock     = (int) $product['stock'];
$threshold = ProductRepository::lowStockThresholdFor($product);
$gallery   = (new ProductImageRepository())->forProduct($productId);
$related   = $products->relatedTo($productId, $product['category_id'] !== null ? (int) $product['category_id'] : null);

$canBuy = Auth::check() && Auth::isCustomer();

$pageTitle       = (string) $product['name'];
$metaDescription = mb_substr((string) $product['description'], 0, 155);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="index.php">Home</a>
        <span aria-hidden="true">&rsaquo;</span>
        <a href="shop.php">Shop</a>
        <?php if (!empty($product['category_name'])): ?>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="shop.php?category=<?= (int) $product['category_id'] ?>"><?= View::e($product['category_name']) ?></a>
        <?php endif; ?>
        <span aria-hidden="true">&rsaquo;</span>
        <span aria-current="page"><?= View::e($product['name']) ?></span>
    </nav>

    <div class="product-detail">
        <div class="product-gallery">
            <?php $primary = $gallery[0]['filename'] ?? $product['image']; ?>
            <img id="galleryMain"
                 src="assets/images/products/<?= View::e($primary) ?>"
                 alt="<?= View::e($product['name']) ?>"
                 class="gallery-main">

            <?php if (count($gallery) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($gallery as $i => $image): ?>
                        <button type="button"
                                class="gallery-thumb <?= $i === 0 ? 'is-active' : '' ?>"
                                data-full="assets/images/products/<?= View::e($image['filename']) ?>"
                                aria-label="View image <?= $i + 1 ?> of <?= count($gallery) ?>">
                            <img src="assets/images/products/<?= View::e($image['filename']) ?>"
                                 alt="<?= View::e($image['alt_text'] ?? $product['name']) ?>" loading="lazy">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <h1 class="product-title"><?= View::e($product['name']) ?></h1>

            <?php if (!empty($product['sku'])): ?>
                <p class="product-sku">SKU: <?= View::e($product['sku']) ?></p>
            <?php endif; ?>

            <p class="product-detail-price"><?= View::money($product['price']) ?></p>

            <p class="product-stock-line">
                <?php if ($stock <= 0): ?>
                    <span class="status-pill status-archived">Out of stock</span>
                <?php elseif ($stock < $threshold): ?>
                    <span class="status-pill status-low">Only <?= $stock ?> left</span>
                <?php else: ?>
                    <span class="status-pill status-active"><?= $stock ?> in stock</span>
                <?php endif; ?>
            </p>

            <div class="product-detail-desc">
                <?= nl2br(View::e($product['description'])) ?>
            </div>

            <?php if ($stock > 0 && !Auth::isAdmin()): ?>
                <?php
                /*
                 * Both buttons are the same "add to cart" action — Add to Cart
                 * returns to this page, Buy Now returns straight to checkout.
                 * This means there is exactly one order-creation path (through
                 * the cart) rather than two that could drift apart, and it is
                 * why "Buy Now" works for guests too: they land on checkout,
                 * which asks them to log in, and their cart (with this item
                 * already in it) is waiting for them afterwards.
                 */
                ?>
                <form method="post" action="cartaction.php" class="detail-buy-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                    <input type="hidden" name="return_slug" value="<?= View::e($product['slug']) ?>">

                    <div class="qty-row">
                        <label for="quantity">Quantity</label>
                        <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $stock ?>" class="qty-input">
                    </div>

                    <button type="submit" class="btn btn-block btn-lg">Add to Cart</button>
                </form>

                <form method="post" action="cartaction.php" class="detail-buy-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                    <input type="hidden" name="quantity" value="1">
                    <input type="hidden" name="return" value="checkout.php">
                    <button type="submit" class="btn btn-block btn-outline" style="background:var(--color-primary);">Buy Now (1 item)</button>
                </form>

                <?php if (!$canBuy): ?>
                    <p class="note text-center">
                        <a href="login.php">Log in</a> to check out — your cart will be waiting.
                    </p>
                <?php endif; ?>
            <?php elseif (Auth::isAdmin()): ?>
                <span class="btn btn-block btn-disabled btn-lg" aria-disabled="true">Admin View</span>
            <?php else: ?>
                <span class="btn btn-block btn-disabled btn-lg" aria-disabled="true">Out of Stock</span>
            <?php endif; ?>

            <ul class="product-perks">
                <li>Cash on delivery available</li>
                <li>Delivery across Bangladesh</li>
                <li>Contact us within 7 days for exchange queries</li>
            </ul>
        </div>
    </div>

    <?php if ($related !== []): ?>
        <section class="related-section">
            <h2 class="page-heading">You may also like</h2>
            <div class="product-grid">
                <?php foreach ($related as $row): ?>
                    <div class="product-card">
                        <a href="product.php?slug=<?= urlencode((string) $row['slug']) ?>" class="product-card-link">
                            <img src="assets/images/products/<?= View::e($row['image']) ?>"
                                 alt="<?= View::e($row['name']) ?>" loading="lazy">
                            <div class="product-body">
                                <h3><?= View::e($row['name']) ?></h3>
                                <span class="product-price"><?= View::money($row['price']) ?></span>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php if (count($gallery) > 1): ?>
<script>
/* Thumbnail switching. Progressive enhancement: without JS the primary image
   still renders and every thumbnail is still visible. */
(function () {
    var main = document.getElementById('galleryMain');
    var thumbs = document.querySelectorAll('.gallery-thumb');

    thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            main.src = thumb.getAttribute('data-full');
            thumbs.forEach(function (t) { t.classList.remove('is-active'); });
            thumb.classList.add('is-active');
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
