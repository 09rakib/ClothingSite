<?php

declare(strict_types=1);

/**
 * Shopping cart page.
 *
 * Every figure shown here is recomputed from live product prices by
 * CartService — nothing is read back from a hidden form field
 * (PROJECT_RULES.md §8, Rule 6). The page also surfaces the three things that
 * can go wrong between adding an item and checking out: the price changed, the
 * stock dropped below the quantity wanted, or the product was archived.
 */

$pageTitle = 'Your Cart';
require_once __DIR__ . '/includes/header.php';

use App\Cart\CartService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\View;

// Admins have no storefront cart.
if (Auth::check() && Auth::isAdmin()) {
    App\Support\Http::redirect('admin/seller.php');
}

$summary = (new CartService())->summary();
?>

<div class="container">
    <h1 class="page-heading">Your Cart</h1>
    <p class="page-subheading">
        <?= $summary['count'] === 0
            ? 'Your cart is empty'
            : $summary['count'] . ' item' . ($summary['count'] === 1 ? '' : 's') . ' in your cart' ?>
    </p>

    <?php if ($summary['items'] === [] && $summary['unavailable'] === []): ?>
        <div class="empty-state">
            You haven't added anything yet.<br>
            <a href="shop.php" class="btn mt-16">Start Shopping</a>
        </div>
    <?php else: ?>

        <?php if ($summary['unavailable'] !== []): ?>
            <div class="alert alert-error" role="alert">
                <strong>Some items are no longer available</strong> and will not be ordered:
                <ul class="plain-list">
                    <?php foreach ($summary['unavailable'] as $gone): ?>
                        <li>
                            <?= View::e($gone['name']) ?>
                            <form method="post" action="cartaction.php" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="item_id" value="<?= (int) $gone['id'] ?>">
                                <input type="hidden" name="return" value="cart.php">
                                <button type="submit" class="link-button">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="cart-layout">
            <div class="cart-items">
                <?php foreach ($summary['items'] as $item): ?>
                    <div class="cart-item <?= $item['over_stock'] || $item['out_of_stock'] ? 'has-issue' : '' ?>">
                        <a href="product.php?slug=<?= urlencode($item['slug']) ?>" class="cart-item-image">
                            <img src="assets/images/products/<?= View::e($item['image']) ?>"
                                 alt="<?= View::e($item['name']) ?>" loading="lazy">
                        </a>

                        <div class="cart-item-body">
                            <h3>
                                <a href="product.php?slug=<?= urlencode($item['slug']) ?>"><?= View::e($item['name']) ?></a>
                            </h3>

                            <p class="cart-item-price">
                                <?= View::money($item['unit_price']) ?> each

                                <?php if ($item['price_changed']): ?>
                                    <?php /* Honesty: tell the customer the price moved rather than
                                             quietly charging the new one. */ ?>
                                    <span class="price-changed-note">
                                        (was <?= View::money($item['price_at_add']) ?> when added)
                                    </span>
                                <?php endif; ?>
                            </p>

                            <?php if ($item['out_of_stock']): ?>
                                <p class="cart-issue">Out of stock — remove this to check out.</p>
                            <?php elseif ($item['over_stock']): ?>
                                <p class="cart-issue">Only <?= $item['stock'] ?> left — please lower the quantity.</p>
                            <?php endif; ?>

                            <div class="cart-item-controls">
                                <form method="post" action="cartaction.php" class="qty-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="return" value="cart.php">

                                    <label class="sr-only" for="qty-<?= $item['id'] ?>">
                                        Quantity for <?= View::e($item['name']) ?>
                                    </label>
                                    <input type="number" id="qty-<?= $item['id'] ?>" name="quantity"
                                           value="<?= $item['quantity'] ?>" min="1"
                                           max="<?= max(1, $item['stock']) ?>" class="qty-input">
                                    <button type="submit" class="btn btn-sm">Update</button>
                                </form>

                                <form method="post" action="cartaction.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="return" value="cart.php">
                                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </div>
                        </div>

                        <div class="cart-item-total">
                            <?= View::money($item['line_total']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($summary['items'] !== []): ?>
                    <form method="post" action="cartaction.php" class="mt-16"
                          onsubmit="return confirm('Remove everything from your cart?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="clear">
                        <input type="hidden" name="return" value="cart.php">
                        <button type="submit" class="link-button">Empty cart</button>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="cart-summary">
                <h2 class="card-title">Order Summary</h2>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span><?= View::money($summary['subtotal']) ?></span>
                </div>
                <div class="summary-row">
                    <span>Delivery</span>
                    <span class="muted">Calculated at delivery</span>
                </div>
                <div class="summary-row summary-total">
                    <span>Total</span>
                    <span><?= View::money($summary['total']) ?></span>
                </div>

                <?php if ($summary['items'] === []): ?>
                    <p class="note">Add an available item to check out.</p>
                <?php elseif ($summary['has_issues']): ?>
                    <p class="cart-issue">Please fix the issues above before checking out.</p>
                    <span class="btn btn-block btn-disabled" aria-disabled="true">Proceed to Checkout</span>
                <?php elseif (!Auth::check()): ?>
                    <?php /* Guests keep their cart: it is merged into their account on login. */ ?>
                    <p class="note">Log in to complete your order — your cart will be waiting.</p>
                    <a href="login.php" class="btn btn-block">Login to Check Out</a>
                <?php else: ?>
                    <a href="checkout.php" class="btn btn-block btn-lg">Proceed to Checkout</a>
                <?php endif; ?>

                <a href="shop.php" class="btn btn-block btn-outline mt-16" style="background:var(--color-primary);">
                    Continue Shopping
                </a>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
