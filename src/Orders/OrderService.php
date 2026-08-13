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
            $reference = self::generateReference();

            $orderStmt = $db->prepare(
                'INSERT INTO single_order
                    (order_reference, user_id, product_id, product_name, unit_price, quantity, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $orderStmt->bind_param('siisdid', $reference, $userId, $productId, $name, $unitPrice, $quantity, $total);
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
                'reference'      => $reference,
                'product_name'   => $name,
                'unit_price'     => $unitPrice,
                'quantity'       => $quantity,
                'total'          => $total,
                'payment_method' => $paymentMethod,
            ];
        });
    }

    /**
     * Place an order for every line in a cart, atomically.
     *
     * WHY this re-reads everything instead of trusting the cart:
     * the cart is a statement of intent that may be minutes or days old. By
     * the time checkout runs, a product may have been archived, repriced, or
     * bought by someone else. So inside one transaction this method
     *   - locks every product row involved (ordered by id to avoid deadlocks),
     *   - re-reads the price from the database (Rule 6),
     *   - re-checks stock,
     *   - writes a snapshot of each line,
     *   - and rolls the whole thing back if any single line fails.
     *
     * Partial success is deliberately impossible: a customer must not be
     * charged for two of three items with no explanation.
     *
     * @param array<int,array{product_id:int,quantity:int}> $lines
     * @return array{reference:string,total:string,lines:array<int,array<string,mixed>>,payment_method:string}
     * @throws RuntimeException with a message safe to show the customer.
     */
    public function placeOrderFromCart(
        int $userId,
        array $lines,
        ?string $paymentMethod = null
    ): array {
        if ($lines === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $paymentMethod ??= PaymentMethod::default();
        if (!PaymentMethod::isEnabled($paymentMethod)) {
            throw new RuntimeException('That payment method is not available.');
        }

        // Collapse duplicate product ids and sort, so concurrent checkouts
        // always take row locks in the same order and cannot deadlock.
        $wanted = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity  = (int) $line['quantity'];

            if ($quantity < 1) {
                throw new RuntimeException('Quantity must be at least 1.');
            }

            $wanted[$productId] = ($wanted[$productId] ?? 0) + $quantity;
        }
        ksort($wanted);

        return Database::transaction(function (mysqli $db) use ($userId, $wanted, $paymentMethod): array {
            $reference = self::generateReference();
            $total     = 0.0;
            $placed    = [];

            $lockStmt = $db->prepare(
                "SELECT id, name, price, stock
                 FROM products
                 WHERE id = ? AND deleted_at IS NULL AND status = 'active'
                 FOR UPDATE"
            );

            $orderStmt = $db->prepare(
                'INSERT INTO single_order
                    (order_reference, user_id, product_id, product_name, unit_price, quantity, total_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $stockStmt = $db->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            );

            $paymentStmt = $db->prepare(
                'INSERT INTO payments (order_id, user_id, total_amount, payment_method)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($wanted as $productId => $quantity) {
                $lockStmt->bind_param('i', $productId);
                $lockStmt->execute();
                $product = $lockStmt->get_result()->fetch_assoc();

                if (!$product) {
                    throw new RuntimeException('A product in your cart is no longer available. Please review your cart.');
                }

                $name      = (string) $product['name'];
                $available = (int) $product['stock'];

                if ($available <= 0) {
                    throw new RuntimeException("\"{$name}\" is out of stock. Please remove it from your cart.");
                }
                if ($available < $quantity) {
                    throw new RuntimeException("Only {$available} of \"{$name}\" left. Please lower the quantity.");
                }

                $unitPrice = (string) $product['price'];
                $lineTotal = number_format((float) $unitPrice * $quantity, 2, '.', '');

                $orderStmt->bind_param(
                    'siisdid',
                    $reference,
                    $userId,
                    $productId,
                    $name,
                    $unitPrice,
                    $quantity,
                    $lineTotal
                );
                $orderStmt->execute();
                $orderId = (int) $db->insert_id;

                $stockStmt->bind_param('iii', $quantity, $productId, $quantity);
                $stockStmt->execute();

                if ($stockStmt->affected_rows !== 1) {
                    throw new RuntimeException("\"{$name}\" just went out of stock. Please try again.");
                }

                // One payment row per order line keeps the existing
                // payments-to-single_order relationship intact; Phase 4 will
                // replace this with one payment per order.
                $paymentStmt->bind_param('iids', $orderId, $userId, $lineTotal, $paymentMethod);
                $paymentStmt->execute();

                $total += (float) $lineTotal;

                $placed[] = [
                    'order_id'     => $orderId,
                    'product_id'   => $productId,
                    'product_name' => $name,
                    'unit_price'   => $unitPrice,
                    'quantity'     => $quantity,
                    'line_total'   => $lineTotal,
                ];
            }

            $lockStmt->close();
            $orderStmt->close();
            $stockStmt->close();
            $paymentStmt->close();

            Logger::info('Cart order placed', [
                'reference' => $reference,
                'user_id'   => $userId,
                'lines'     => count($placed),
            ]);

            return [
                'reference'      => $reference,
                'total'          => number_format($total, 2, '.', ''),
                'lines'          => $placed,
                'payment_method' => $paymentMethod,
            ];
        });
    }

    /**
     * Human-quotable order reference.
     *
     * Random rather than sequential so the reference does not leak how many
     * orders the store has taken.
     */
    private static function generateReference(): string
    {
        return 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
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
                    so.order_reference,
                    so.product_id,
                    COALESCE(so.product_name, p.name, "Unavailable product") AS product_name,
                    p.slug AS product_slug,
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
     * Order history grouped so one checkout reads as one order, however many
     * products it contained.
     *
     * @return array<int,array{reference:string,created_at:string,payment_method:?string,total:string,lines:array<int,array<string,mixed>>}>
     */
    public function groupedHistoryForUser(int $userId): array
    {
        $grouped = [];

        foreach ($this->historyForUser($userId) as $row) {
            // Rows predating the reference column fall back to their own id,
            // so nothing is silently dropped from the customer's history.
            $reference = (string) ($row['order_reference'] ?? '') !== ''
                ? (string) $row['order_reference']
                : 'ORD-' . str_pad((string) $row['order_id'], 6, '0', STR_PAD_LEFT);

            if (!isset($grouped[$reference])) {
                $grouped[$reference] = [
                    'reference'      => $reference,
                    'created_at'     => (string) $row['created_at'],
                    'payment_method' => $row['payment_method'] !== null ? (string) $row['payment_method'] : null,
                    'total'          => '0.00',
                    'lines'          => [],
                ];
            }

            $grouped[$reference]['lines'][] = $row;
            $grouped[$reference]['total']   = number_format(
                (float) $grouped[$reference]['total'] + (float) $row['total_amount'],
                2,
                '.',
                ''
            );
        }

        return array_values($grouped);
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
