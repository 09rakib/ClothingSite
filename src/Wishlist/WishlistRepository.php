<?php

declare(strict_types=1);

namespace App\Wishlist;

use App\Support\Database;
use mysqli;

/**
 * Customer wishlist (PROJECT_RULES.md §15).
 *
 * Every method is scoped to a user id, and add()/remove() are idempotent —
 * calling either twice is harmless — which keeps the "Add to Wishlist"
 * button on a product page simple: it never needs to know current state to
 * be safe to click.
 */
final class WishlistRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Add a product, silently doing nothing if it is already saved — the
     * UNIQUE (user_id, product_id) index is the real guarantee, this is just
     * a clean way to use it.
     */
    public function add(int $userId, int $productId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO wishlist_items (user_id, product_id) VALUES (?, ?)'
        );
        $stmt->bind_param('ii', $userId, $productId);
        $stmt->execute();
        $stmt->close();
    }

    public function remove(int $userId, int $productId): void
    {
        $stmt = $this->db->prepare('DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?');
        $stmt->bind_param('ii', $userId, $productId);
        $stmt->execute();
        $stmt->close();
    }

    public function contains(int $userId, int $productId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM wishlist_items WHERE user_id = ? AND product_id = ? LIMIT 1');
        $stmt->bind_param('ii', $userId, $productId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $found;
    }

    /**
     * Every product id a customer has wishlisted — used by product/shop pages
     * to render the heart icon filled without an N+1 query per card.
     *
     * @return array<int,int>
     */
    public function productIdsForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT product_id FROM wishlist_items WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static fn(array $r): int => (int) $r['product_id'], $rows);
    }

    /**
     * Saved products joined with live catalog data — archived products are
     * excluded automatically, same rule as everywhere else a product is
     * listed for a customer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT wi.id AS wishlist_item_id, p.id, p.name, p.slug, p.price, p.stock, p.image
             FROM wishlist_items wi
             JOIN products p ON p.id = wi.product_id
             WHERE wi.user_id = ?
               AND p.deleted_at IS NULL
               AND p.status = 'active'
             ORDER BY wi.created_at DESC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function count(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM wishlist_items WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $count;
    }
}
