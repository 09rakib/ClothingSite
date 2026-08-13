<?php

declare(strict_types=1);

namespace App\Cart;

use App\Catalog\ProductRepository;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Database;
use mysqli;
use RuntimeException;

/**
 * Cart business rules (PROJECT_RULES.md §8, Rule 6).
 *
 * THE CENTRAL RULE HERE: nothing the browser sends about money is trusted.
 * The request may say which product and how many; the price, the subtotal and
 * the total are always read from the database at the moment they are needed.
 * cart_items.price_at_add exists only to tell the customer that a price moved
 * since they added the item — it never determines what they pay.
 *
 * Stock is validated when adding and updating, and validated AGAIN under a row
 * lock at checkout, because a cart is a statement of intent and stock can
 * change between the two.
 */
final class CartService
{
    /** Name of the cookie holding a guest cart token. */
    public const GUEST_COOKIE = 'cart_token';

    /** Guest carts survive this long in the browser. */
    private const GUEST_COOKIE_LIFETIME = 60 * 60 * 24 * 30;

    private mysqli $db;
    private CartRepository $carts;
    private ProductRepository $products;

    public function __construct(?mysqli $db = null)
    {
        $this->db       = $db ?? Database::connection();
        $this->carts    = new CartRepository($this->db);
        $this->products = new ProductRepository($this->db);
    }

    /* =====================================================
     | Identifying the current cart
     * ===================================================== */

    /**
     * The current visitor's cart id, creating one if needed.
     *
     * Logged-in customers are keyed by user id; guests by a random cookie
     * token. $create = false avoids writing a row just because a page rendered
     * a cart badge for a visitor who has not added anything.
     */
    public function currentCartId(bool $create = true): ?int
    {
        if (Auth::check() && Auth::isCustomer()) {
            $userId = (int) Auth::id();
            $cart   = $this->carts->findByUser($userId);

            if ($cart !== null) {
                return (int) $cart['id'];
            }

            return $create ? $this->carts->createForUser($userId) : null;
        }

        // Admins have no storefront cart.
        if (Auth::check()) {
            return null;
        }

        $token = $this->guestToken(false);

        if ($token !== null) {
            $cart = $this->carts->findByToken($token);
            if ($cart !== null) {
                return (int) $cart['id'];
            }
        }

        if (!$create) {
            return null;
        }

        $token = $this->guestToken(true);

        return $this->carts->createForToken((string) $token);
    }

    /**
     * Read (or issue) the guest cart cookie.
     *
     * The token is 32 random bytes: it is a bearer credential for a cart, so
     * it must not be guessable.
     */
    private function guestToken(bool $create): ?string
    {
        $existing = $_COOKIE[self::GUEST_COOKIE] ?? null;

        // Reject anything that is not the exact shape we issue.
        if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/', $existing)) {
            return $existing;
        }

        if (!$create) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::GUEST_COOKIE, $token, [
                'expires'  => time() + self::GUEST_COOKIE_LIFETIME,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (bool) Config::get('security.cookie_secure', false),
            ]);
        }

        // Make it visible to the rest of this request too.
        $_COOKIE[self::GUEST_COOKIE] = $token;

        return $token;
    }

    /**
     * Merge a guest cart into the customer's cart at login.
     *
     * Quantities are summed and then clamped to available stock, so merging
     * two carts can never produce a line that exceeds what is in the warehouse.
     * Called from the login flow; safe to call when there is no guest cart.
     */
    public function mergeGuestCartIntoUser(int $userId): void
    {
        $token = $this->guestToken(false);
        if ($token === null) {
            return;
        }

        $guestCart = $this->carts->findByToken($token);
        if ($guestCart === null) {
            $this->forgetGuestCookie();

            return;
        }

        $guestCartId = (int) $guestCart['id'];
        $guestItems  = $this->carts->items($guestCartId);

        if ($guestItems === []) {
            $this->carts->delete($guestCartId);
            $this->forgetGuestCookie();

            return;
        }

        $userCart = $this->carts->findByUser($userId);

        if ($userCart === null) {
            // No existing customer cart: adopt the guest cart wholesale.
            $this->carts->assignToUser($guestCartId, $userId);
            $this->forgetGuestCookie();

            return;
        }

        $userCartId = (int) $userCart['id'];

        foreach ($guestItems as $item) {
            $productId = (int) $item['product_id'];
            $stock     = (int) $item['stock'];
            $existing  = $this->carts->findItem($userCartId, $productId);

            $wanted = (int) $item['quantity'] + (int) ($existing['quantity'] ?? 0);
            $wanted = max(1, min($wanted, $stock));

            if ($stock <= 0) {
                continue; // Nothing to merge for an out-of-stock product.
            }

            if ($existing !== null) {
                $this->carts->setQuantity((int) $existing['id'], $wanted);
            } else {
                $this->carts->addItem($userCartId, $productId, $wanted, (string) $item['current_price']);
            }
        }

        $this->carts->delete($guestCartId);
        $this->forgetGuestCookie();
    }

    private function forgetGuestCookie(): void
    {
        unset($_COOKIE[self::GUEST_COOKIE]);

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::GUEST_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /* =====================================================
     | Mutations
     * ===================================================== */

    /**
     * Add a product, or increase its quantity if already present.
     *
     * @throws RuntimeException with a message safe to show the customer.
     */
    public function add(int $productId, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new RuntimeException('Quantity must be at least 1.');
        }

        $product = $this->products->findActive($productId);
        if ($product === null) {
            throw new RuntimeException('This product is no longer available.');
        }

        $stock = (int) $product['stock'];
        if ($stock <= 0) {
            throw new RuntimeException('Sorry, this product is out of stock.');
        }

        $cartId   = (int) $this->currentCartId();
        $existing = $this->carts->findItem($cartId, $productId);
        $desired  = $quantity + (int) ($existing['quantity'] ?? 0);

        if ($desired > $stock) {
            throw new RuntimeException(
                $existing !== null
                    ? "You already have {$existing['quantity']} in your cart and only {$stock} are available."
                    : "Only {$stock} available."
            );
        }

        if ($existing !== null) {
            $this->carts->setQuantity((int) $existing['id'], $desired);
        } else {
            // Price recorded for the "price changed" notice only.
            $this->carts->addItem($cartId, $productId, $quantity, (string) $product['price']);
        }

        $this->carts->touch($cartId);
    }

    /**
     * Set an exact quantity for a line the current visitor owns.
     */
    public function updateQuantity(int $itemId, int $quantity): void
    {
        $cartId = $this->currentCartId(false);
        if ($cartId === null) {
            throw new RuntimeException('Your cart is empty.');
        }

        $item = $this->ownedItem($itemId, $cartId);

        if ($quantity < 1) {
            $this->carts->removeItem($itemId);
            $this->carts->touch($cartId);

            return;
        }

        $product = $this->products->findActive((int) $item['product_id']);
        if ($product === null) {
            $this->carts->removeItem($itemId);
            throw new RuntimeException('That product is no longer available and was removed from your cart.');
        }

        $stock = (int) $product['stock'];
        if ($quantity > $stock) {
            throw new RuntimeException("Only {$stock} available.");
        }

        $this->carts->setQuantity($itemId, $quantity);
        $this->carts->touch($cartId);
    }

    public function remove(int $itemId): void
    {
        $cartId = $this->currentCartId(false);
        if ($cartId === null) {
            return;
        }

        $this->ownedItem($itemId, $cartId);
        $this->carts->removeItem($itemId);
        $this->carts->touch($cartId);
    }

    public function clear(): void
    {
        $cartId = $this->currentCartId(false);
        if ($cartId !== null) {
            $this->carts->clear($cartId);
        }
    }

    /**
     * Verify a cart line belongs to the current visitor's cart.
     *
     * Without this, changing the item_id in the form would let anyone edit
     * somebody else's cart (§19 "No IDOR vulnerabilities").
     *
     * @return array<string,mixed>
     */
    private function ownedItem(int $itemId, int $cartId): array
    {
        $item = $this->carts->findItemById($itemId);

        if ($item === null || (int) $item['cart_id'] !== $cartId) {
            throw new RuntimeException('That item is not in your cart.');
        }

        return $item;
    }

    /* =====================================================
     | Reading
     * ===================================================== */

    /**
     * The cart as the customer should see it, with all money recomputed from
     * live product prices.
     *
     * @return array{
     *   items:array<int,array<string,mixed>>,
     *   unavailable:array<int,array<string,mixed>>,
     *   subtotal:string,
     *   total:string,
     *   count:int,
     *   has_issues:bool
     * }
     */
    public function summary(): array
    {
        $cartId = $this->currentCartId(false);

        if ($cartId === null) {
            return [
                'items'       => [],
                'unavailable' => [],
                'subtotal'    => '0.00',
                'total'       => '0.00',
                'count'       => 0,
                'has_issues'  => false,
            ];
        }

        $rows        = $this->carts->items($cartId);
        $unavailable = $this->carts->unavailableItems($cartId);

        $items      = [];
        $subtotal   = 0.0;
        $count      = 0;
        $hasIssues  = $unavailable !== [];

        foreach ($rows as $row) {
            $stock    = (int) $row['stock'];
            $quantity = (int) $row['quantity'];

            // Always the live price, never price_at_add (Rule 6).
            $unitPrice = (float) $row['current_price'];
            $lineTotal = $unitPrice * $quantity;

            $priceChanged = abs($unitPrice - (float) $row['price_at_add']) > 0.001;
            $overStock    = $quantity > $stock;

            if ($overStock || $stock <= 0) {
                $hasIssues = true;
            }

            $items[] = [
                'id'            => (int) $row['id'],
                'product_id'    => (int) $row['product_id'],
                'name'          => (string) $row['name'],
                'slug'          => (string) $row['slug'],
                'image'         => (string) $row['image'],
                'quantity'      => $quantity,
                'stock'         => $stock,
                'unit_price'    => number_format($unitPrice, 2, '.', ''),
                'price_at_add'  => number_format((float) $row['price_at_add'], 2, '.', ''),
                'line_total'    => number_format($lineTotal, 2, '.', ''),
                'price_changed' => $priceChanged,
                'over_stock'    => $overStock,
                'out_of_stock'  => $stock <= 0,
            ];

            $subtotal += $lineTotal;
            $count    += $quantity;
        }

        return [
            'items'       => $items,
            'unavailable' => $unavailable,
            'subtotal'    => number_format($subtotal, 2, '.', ''),
            // Shipping and tax are not modelled yet, so the total equals the
            // subtotal. Kept as a separate key so adding them later does not
            // change every template.
            'total'       => number_format($subtotal, 2, '.', ''),
            'count'       => $count,
            'has_issues'  => $hasIssues,
        ];
    }

    /**
     * Units in the cart, for the header badge.
     */
    public function count(): int
    {
        $cartId = $this->currentCartId(false);

        return $cartId === null ? 0 : $this->carts->itemCount($cartId);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
