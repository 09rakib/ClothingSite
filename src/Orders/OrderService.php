<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Database;
use App\Support\Logger;
use mysqli;
use RuntimeException;

/**
 * Order placement business logic (PROJECT_RULES.md §7, §8, Rule 6, Rule 9).
 *
 * WHY this is a service and not inline page code:
 * placing an order touches three tables and must be all-or-nothing. When that
 * logic lived inside singleorder.php it could only be exercised by loading a
 * URL, which made it untestable and easy to duplicate the day a second entry
 * point (cart checkout) appeared. Here it is one transactional method that
 * both the current Buy Now flow and the future cart checkout can call.
 *
 * Guarantees:
 *   - Price comes from the database, never from the browser (Rule 6).
 *   - The product row is locked FOR UPDATE so two shoppers cannot both buy the
 *     last unit (§10 "race conditions").
 *   - A snapshot of name and unit price is stored on the order, so history
 *     stays correct if the product is later renamed or repriced (§5, Rule 10).
 *   - Everything runs in one transaction and rolls back on any failure.
 */
final class OrderService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Place a single-product order.
     *
     * @return array{order_id:int,product_name:string,unit_price:string,quantity:int,total:string,payment_method:string}
     * @throws RuntimeException with a message safe to show the customer.
     */
    public function placeSingleProductOrder(
        int $userId,
        int $productId,
        int $quantity = 1,
        ?string $paymentMethod = null
    ): array {
        if ($quantity < 1) {
            throw new RuntimeException('Quantity must be at least 1.');
        }

        // Reject a forged payment method instead of silently accepting it.
        $paymentMethod ??= PaymentMethod::default();
        if (!PaymentMethod::isEnabled($paymentMethod)) {
            throw new RuntimeException('That payment method is not available.');
        }

        return Database::transaction(function (mysqli $db) use ($userId, $productId, $quantity, $paymentMethod): array {
            // FOR UPDATE locks this product row for the rest of the
            // transaction, so a concurrent order cannot read the same stock
            // value and oversell the last unit.
            $stmt = $db->prepare(
                "SELECT id, name, price, stock
                 FROM products
                 WHERE id = ? AND deleted_at IS NULL AND status = 'active'
                 FOR UPDATE"
            );
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                throw new RuntimeException('This product is no longer available.');
            }

            $available = (int) $product['stock'];
            if ($available <= 0) {
                throw new RuntimeException('Sorry, this product is out of stock.');
            }
            if ($available < $quantity) {
                throw new RuntimeException("Only {$available} left in stock.");
            }

            // Authoritative price: read from the database inside the lock.
            $unitPrice = (string) $product['price'];
            $total     = number_format((float) $unitPrice * $quantity, 2, '.', '');
            $name      = (string) $product['name'];

            // Snapshot name and unit price onto the order row so the customer's
            // history stays accurate after future catalog edits.
            $orderStmt = $db->prepare(
                'INSERT INTO single_order (user_id, product_id, product_name, unit_price, quantity, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->bind_param('iisdid', $userId, $productId, $name, $unitPrice, $quantity, $total);
            $orderStmt->execute();
            $orderId = (int) $db->insert_id;
            $orderStmt->close();

            // The `stock >= ?` guard is a second line of defence alongside the
            // row lock and the CHECK constraint.
            $stockStmt = $db->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            );
            $stockStmt->bind_param('iii', $quantity, $productId, $quantity);
            $stockStmt->execute();
            $stockChanged = $stockStmt->affected_rows;
            $stockStmt->close();

            if ($stockChanged !== 1) {
                // Someone else took the stock between the read and the write.
                throw new RuntimeException('That product just went out of stock. Please try again.');
            }

            $paymentStmt = $db->prepare(
                'INSERT INTO payments (order_id, user_id, total_amount, payment_method)
                 VALUES (?, ?, ?, ?)'
            );
            $paymentStmt->bind_param('iids', $orderId, $userId, $total, $paymentMethod);
            $paymentStmt->execute();
            $paymentStmt->close();

            Logger::info('Order placed', [
                'order_id' => $orderId,
                'user_id'  => $userId,
                'product'  => $productId,
                'quantity' => $quantity,
            ]);

            return [
                'order_id'       => $orderId,
                'product_name'   => $name,
                'unit_price'     => $unitPrice,
                'quantity'       => $quantity,
                'total'          => $total,
                'payment_method' => $paymentMethod,
            ];
        });
    }

    /**
     * Order history for one customer.
     *
     * Reads the snapshot columns rather than joining products, so an archived
     * product still shows its name exactly as it was when purchased.
     *
     * @return array<int,array<string,mixed>>
     */
    public function historyForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT so.id AS order_id,
                    COALESCE(so.product_name, p.name, "Unavailable product") AS product_name,
                    so.unit_price,
                    so.quantity,
                    so.total_amount,
                    so.created_at,
                    pay.payment_method
             FROM single_order so
             LEFT JOIN products p ON p.id = so.product_id
             LEFT JOIN payments pay ON pay.order_id = so.id
             WHERE so.user_id = ?
             ORDER BY so.created_at DESC, so.id DESC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Fetch a single order. Returns the owning user_id so the caller can run
     * an ownership check before displaying it (§19 "No IDOR").
     *
     * @return array<string,mixed>|null
     */
    public function find(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT so.id AS order_id, so.user_id, so.product_id,
                    COALESCE(so.product_name, "Unavailable product") AS product_name,
                    so.unit_price, so.quantity, so.total_amount, so.created_at,
                    pay.payment_method
             FROM single_order so
             LEFT JOIN payments pay ON pay.order_id = so.id
             WHERE so.id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Aggregate figures for the admin dashboard.
     *
     * @return array{total_orders:int,total_revenue:string,orders_today:int,revenue_today:string,customers:int}
     */
    public function stats(): array
    {
        $row = $this->db->query(
            'SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS orders_today,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END), 0) AS revenue_today
             FROM single_order'
        )->fetch_assoc();

        $customers = (int) $this->db
            ->query("SELECT COUNT(*) AS c FROM users WHERE role = 'user'")
            ->fetch_assoc()['c'];

        return [
            'total_orders'  => (int) $row['total_orders'],
            'total_revenue' => (string) $row['total_revenue'],
            'orders_today'  => (int) $row['orders_today'],
            'revenue_today' => (string) $row['revenue_today'],
            'customers'     => $customers,
        ];
    }
}
