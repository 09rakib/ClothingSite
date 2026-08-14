<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Support\Database;
use mysqli;
use RuntimeException;

/**
 * Stock movement ledger (PROJECT_RULES.md §10 "Inventory must be treated as
 * a separate business domain").
 *
 * `products.stock` remains the fast-read current value (unchanged by this
 * class's callers using their own UPDATE where needed — see OrderService for
 * the sale/return path); this repository is the audit trail answering "why
 * is stock at this number", the same current-value/ledger split
 * payment_transactions and order_status_history already use elsewhere in
 * this codebase.
 */
final class InventoryRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Record a movement without touching products.stock — used by callers
     * (OrderService) that already updated stock themselves inside their own
     * transaction and just need the ledger entry alongside it.
     */
    public function recordMovement(
        int $productId,
        int $quantityChange,
        string $type,
        ?string $reason = null,
        ?string $reference = null,
        ?int $createdBy = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_movements (product_id, quantity_change, type, reason, reference, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssi', $productId, $quantityChange, $type, $reason, $reference, $createdBy);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Manual admin stock adjustment: updates products.stock and records the
     * movement in one transaction, requiring a reason (§10 "Admin adjustment
     * reason").
     *
     * @throws RuntimeException if the adjustment would take stock negative.
     */
    public function adjust(int $productId, int $delta, string $reason, int $adminUserId): void
    {
        if ($delta === 0) {
            throw new RuntimeException('Adjustment must not be zero.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required for a manual stock adjustment.');
        }

        Database::transaction(function (mysqli $db) use ($productId, $delta, $reason, $adminUserId): void {
            $stmt = $db->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($product === null) {
                throw new RuntimeException('Product not found.');
            }

            $newStock = (int) $product['stock'] + $delta;
            if ($newStock < 0) {
                throw new RuntimeException(
                    "This would take stock below zero (currently {$product['stock']}, adjustment {$delta})."
                );
            }

            $update = $db->prepare('UPDATE products SET stock = ? WHERE id = ?');
            $update->bind_param('ii', $newStock, $productId);
            $update->execute();
            $update->close();

            $move = $db->prepare(
                'INSERT INTO inventory_movements (product_id, quantity_change, type, reason, created_by)
                 VALUES (?, ?, "manual_adjustment", ?, ?)'
            );
            $move->bind_param('iisi', $productId, $delta, $reason, $adminUserId);
            $move->execute();
            $move->close();
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forProduct(int $productId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $stmt = $this->db->prepare(
            'SELECT m.*, u.name AS created_by_name
             FROM inventory_movements m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.product_id = ?
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('ii', $productId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Store-wide recent movements for the admin inventory page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        $stmt = $this->db->prepare(
            'SELECT m.*, p.name AS product_name, p.slug AS product_slug, u.name AS created_by_name
             FROM inventory_movements m
             JOIN products p ON p.id = m.product_id
             LEFT JOIN users u ON u.id = m.created_by
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}
