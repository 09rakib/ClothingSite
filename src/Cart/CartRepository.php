<?php

declare(strict_types=1);

namespace App\Cart;

use App\Support\Database;
use mysqli;

/**
 * Cart data access (PROJECT_RULES.md §3.2 "Repositories/Query layer").
 *
 * Holds only persistence concerns. Rules such as "you may not add more than
 * the available stock" live in CartService, so they can be tested without a
 * request and cannot be bypassed by a second caller writing its own SQL.
 */
final class CartRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /* =====================================================
     | Cart lookup / creation
     * ===================================================== */

    public function findByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, token FROM carts WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, token FROM carts WHERE token = ? LIMIT 1');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function createForUser(int $userId): int
    {
        $stmt = $this->db->prepare('INSERT INTO carts (user_id) VALUES (?)');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function createForToken(string $token): int
    {
        $stmt = $this->db->prepare('INSERT INTO carts (token) VALUES (?)');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function delete(int $cartId): void
    {
        $stmt = $this->db->prepare('DELETE FROM carts WHERE id = ?');
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Attach a guest cart to a customer (used when merging on login).
     */
    public function assignToUser(int $cartId, int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE carts SET user_id = ?, token = NULL WHERE id = ?');
        $stmt->bind_param('ii', $userId, $cartId);
        $stmt->execute();
        $stmt->close();
    }

    /* =====================================================
     | Cart lines
     * ===================================================== */

    /**
     * Lines joined to their live product data.
     *
     * Archived / deleted products are excluded here rather than filtered by
     * the caller, so no page can accidentally show or charge for a product
     * that is no longer for sale.
     *
     * @return array<int,array<string,mixed>>
     */
    public function items(int $cartId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ci.id, ci.product_id, ci.quantity, ci.price_at_add,
                    p.name, p.slug, p.image, p.stock,
                    p.price AS current_price
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = ?
               AND p.deleted_at IS NULL
               AND p.status = 'active'
             ORDER BY ci.created_at ASC, ci.id ASC"
        );
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Lines whose product has since been archived — shown to the customer as
     * "no longer available" instead of silently vanishing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unavailableItems(int $cartId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ci.id, ci.product_id, ci.quantity, p.name
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = ?
               AND (p.deleted_at IS NOT NULL OR p.status <> 'active')"
        );
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function findItem(int $cartId, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, cart_id, product_id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $cartId, $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function findItemById(int $itemId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, cart_id, product_id, quantity FROM cart_items WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function addItem(int $cartId, int $productId, int $quantity, string $priceAtAdd): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cart_items (cart_id, product_id, quantity, price_at_add) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('iiid', $cartId, $productId, $quantity, $priceAtAdd);
        $stmt->execute();
        $stmt->close();
    }

    public function setQuantity(int $itemId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
        $stmt->bind_param('ii', $quantity, $itemId);
        $stmt->execute();
        $stmt->close();
    }

    public function removeItem(int $itemId): void
    {
        $stmt = $this->db->prepare('DELETE FROM cart_items WHERE id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
    }

    public function clear(int $cartId): void
    {
        $stmt = $this->db->prepare('DELETE FROM cart_items WHERE cart_id = ?');
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Total number of units in the cart, for the header badge.
     */
    public function itemCount(int $cartId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(ci.quantity), 0) AS c
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = ?
               AND p.deleted_at IS NULL
               AND p.status = 'active'"
        );
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $count;
    }

    /**
     * Touch the cart so abandoned-cart cleanup can use updated_at later.
     */
    public function touch(int $cartId): void
    {
        $stmt = $this->db->prepare('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('i', $cartId);
        $stmt->execute();
        $stmt->close();
    }
}
