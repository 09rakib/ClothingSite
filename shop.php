<?php

declare(strict_types=1);

/**
 * Storefront product listing.
 *
 * Search, category filter, sorting and pagination are all resolved
 * server-side against indexed columns (PROJECT_RULES.md §12). The page itself
 * runs no SQL — it asks ProductRepository, which is the only place that knows
 * archived products must stay hidden.
 */

$pageTitle = 'Shop';
require_once __DIR__ . '/includes/header.php';

use App\Catalog\CategoryRepository;
use App\Catalog\ProductRepository;
use App\Orders\PaymentMethod;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Http;
use App\Support\OneTimeToken;
use App\Support\View;

$products   = new ProductRepository();
$categories = new CategoryRepository();

// Read filters from the query string; the repository validates/whitelists them.
$search     = trim((string) ($_GET['q'] ?? ''));
$categoryId = Http::intParam($_GET, 'category');
$sort       = (string) ($_GET['sort'] ?? Config::get('catalog.default_sort', 'newest'));
$page       = Http::intParam($_GET, 'page') ?? 1;

$sortOptions = (array) Config::get('catalog.sort_options', []);
if (!array_key_exists($sort, $sortOptions)) {
    $sort = (string) Config::get('catalog.default_sort', 'newest');
}

$result = $products->paginateActive([
    'search'   => $search,
    'category' => $categoryId,
    'sort'     => $sort,
    'page'     => $page,
]);

$canBuy = Auth::check() && Auth::isCustomer();

// Preserved across pagination and sort links so filters are not lost.
$activeFilters = array_filter([
    'q'        => $search,
    'category' => $categoryId,
    'sort'     => $sort !== Config::get('catalog.default_sort') ? $sort : null,
]);
?>

<div class="container">
    <h1 class="page-heading">Shop All Products</h1>
    <p class="page-subheading">Browse our full collection of shirts and pants</p>

    <form method="get" action="shop.php" class="shop-filters" role="search">
        <div class="filter-row">
            <label class="sr-only" for="q">Search products</label>
            <input type="search" id="q" name="q" value="<?= View::e($search) ?>" placeholder="Search products..." class="filter-input">

            <label class="sr-only" for="category">Category</label>
            <select name="category" id="category" class="filter-input">
                <option value="">All categories</option>
                <?php foreach ($categories->allWithProductCounts() as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $categoryId === $category['id'] ? 'selected' : '' ?>>
                        <?= View::e($category['name']) ?> (<?= (int) $category['product_count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="sr-only" for="sort">Sort by</label>
            <select name="sort" id="sort" class="filter-input">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?= View::e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Apply</button>
            <?php if ($activeFilters !== []): ?>
                <a href="shop.php" class="btn btn-outline" style="background:var(--color-primary);">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="result-count">
        <?= (int) $result['total'] ?> product<?= $result['total'] === 1 ? '' : 's' ?> found<?php
            if ($search !== '') {
                echo ' for "' . View::e($search) . '"';
            }
        ?>.
    </p>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state">
            No products matched your search.<br>
            <a href="shop.php" class="btn mt-16">View all products</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($result['items'] as $row): ?>
                <?php $stock = (int) $row['stock']; ?>
                <?php
                $threshold  = ProductRepository::lowStockThresholdFor($row);
                $detailUrl  = 'product.php?slug=' . urlencode((string) $row['slug']);
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
                            <?php
                            /*
                             * Buy Now is a POST form, not a link: it changes
                             * state. The one-time token makes a double submit
                             * idempotent (§8).
                             */
                            ?>
                            <form method="post" action="singleorder.php" class="buy-form">
                                <?= Csrf::field() ?>
                                <?= OneTimeToken::field('place_order') ?>
                                <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="payment_method" value="<?= View::e(PaymentMethod::default()) ?>">
                                <label class="sr-only" for="qty-<?= (int) $row['id'] ?>">Quantity</label>
                                <input type="number" id="qty-<?= (int) $row['id'] ?>" name="quantity"
                                       value="1" min="1" max="<?= $stock ?>" class="qty-input">
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

        <?php if ($result['pages'] > 1): ?>
            <nav class="pagination" aria-label="Product pages">
                <?php if ($result['page'] > 1): ?>
                    <a href="shop.php<?= View::queryString($activeFilters, ['page' => $result['page'] - 1]) ?>" class="page-link">&laquo; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                    <?php if ($i === $result['page']): ?>
                        <span class="page-link current" aria-current="page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="shop.php<?= View::queryString($activeFilters, ['page' => $i]) ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($result['page'] < $result['pages']): ?>
                    <a href="shop.php<?= View::queryString($activeFilters, ['page' => $result['page'] + 1]) ?>" class="page-link">Next &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
