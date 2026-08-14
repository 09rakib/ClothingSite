<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Database;
use mysqli;
use RuntimeException;

/**
 * Orders / order_items / order_status_history data access
 * (PROJECT_RULES.md §3.2 "Repositories/Query layer").
 *
 * Order CREATION (which needs pricing, stock locks and address validation)
 * lives in OrderService, which uses this repository's low-level insert
 * methods inside its own transaction. This class also serves reads for the
 * customer order-history/tracking pages and the admin order-management pages.
 */
final class OrderRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /* =====================================================
     | Writes (called from within OrderService's transaction)
     * ===================================================== */

    /**
     * @param array{recipient_name:string,phone:string,address_line1:string,address_line2:?string,city:string} $address
     */
    public function createOrder(
        string $reference,
        int $userId,
        string $subtotal,
        string $total,
        string $paymentMethod,
        array $address,
        ?string $customerNote = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO orders
                (order_reference, user_id, status, subtotal, total, payment_method,
                 recipient_name, phone, address_line1, address_line2, city, customer_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $status = OrderStatus::PENDING;
        $stmt->bind_param(
            'sissssssssss',
            $reference,
            $userId,
            $status,
            $subtotal,
            $total,
            $paymentMethod,
            $address['recipient_name'],
            $address['phone'],
            $address['address_line1'],
            $address['address_line2'],
            $address['city'],
            $customerNote
        );
        $stmt->execute();
        $orderId = (int) $this->db->insert_id;
        $stmt->close();

        // The initial status is itself a history entry, so the timeline a
        // customer sees always starts somewhere instead of appearing mid-story.
        $this->recordStatusChange($orderId, null, OrderStatus::PENDING, null, 'Order placed');

        return $orderId;
    }

    public function addItem(
        int $orderId,
        int $productId,
        string $productName,
        string $unitPrice,
        int $quantity,
        string $lineTotal
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisdid', $orderId, $productId, $productName, $unitPrice, $quantity, $lineTotal);
        $stmt->execute();
        $stmt->close();
    }

    public function recordStatusChange(
        int $orderId,
        ?string $fromStatus,
        string $toStatus,
        ?int $changedBy,
        ?string $note = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issis', $orderId, $fromStatus, $toStatus, $changedBy, $note);
        $stmt->execute();
        $stmt->close();
    }

    /* =====================================================
     | Status transitions
     * ===================================================== */

    /**
     * Move an order to a new status, enforcing the transition rules in
     * OrderStatus and recording who changed it (§7).
     *
     * @throws RuntimeException if the transition is not allowed.
     */
    public function transitionStatus(int $orderId, string $toStatus, ?int $changedBy, ?string $note = null): void
    {
        if (!OrderStatus::isValid($toStatus)) {
            throw new RuntimeException('Unknown order status.');
        }

        $order = $this->find($orderId);
        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }

        $from = (string) $order['status'];

        if (!OrderStatus::canTransition($from, $toStatus)) {
            throw new RuntimeException(sprintf(
                'Cannot move an order from "%s" to "%s".',
                OrderStatus::label($from),
                OrderStatus::label($toStatus)
            ));
        }

        // Delivery is treated as proof of a completed cash-on-delivery
        // payment; a real gateway's own confirmation drives this once Phase 4
        // adds one.
        $paymentStatus = $toStatus === OrderStatus::DELIVERED ? 'paid' : null;

        // The status change and its history entry must succeed or fail
        // together — a status update with no corresponding history row would
        // leave the audit trail (§7, §23) silently incomplete.
        Database::transaction(function (mysqli $db) use ($orderId, $from, $toStatus, $changedBy, $note, $paymentStatus): void {
            if ($paymentStatus !== null) {
                $stmt = $db->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?');
                $stmt->bind_param('ssi', $toStatus, $paymentStatus, $orderId);
            } else {
                $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
                $stmt->bind_param('si', $toStatus, $orderId);
            }
            $stmt->execute();
            $stmt->close();

            $history = $db->prepare(
                'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $history->bind_param('issis', $orderId, $from, $toStatus, $changedBy, $note);
            $history->execute();
            $history->close();
        });
    }

    /* =====================================================
     | Reads
     * ===================================================== */

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE order_reference = ? LIMIT 1');
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function itemsFor(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT oi.id, oi.product_id, oi.product_name, oi.unit_price, oi.quantity, oi.line_total,
                    p.slug AS product_slug
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.id ASC'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * @return array<int,array{from_status:?string,to_status:string,changed_by:?int,changed_by_name:?string,note:?string,created_at:string}>
     */
    public function statusHistory(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.from_status, h.to_status, h.changed_by, h.note, h.created_at, u.name AS changed_by_name
             FROM order_status_history h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.order_id = ?
             ORDER BY h.id ASC'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * A customer's orders, newest first. Items are not joined here — callers
     * that need line items fetch them per-order via itemsFor() only when
     * rendering the detail view, to keep the list query cheap.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_reference, status, total, payment_method, created_at
             FROM orders
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Admin listing with optional status filter and search, paginated.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function paginateForAdmin(?string $status = null, string $search = '', int $page = 1, int $perPage = 20): array
    {
        $where  = [];
        $params = [];
        $types  = '';

        if ($status !== null && $status !== '') {
            $where[]  = 'o.status = ?';
            $params[] = $status;
            $types   .= 's';
        }

        if ($search !== '') {
            $where[]  = '(o.order_reference LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            $params   = array_merge($params, [$like, $like, $like]);
            $types   .= 'sss';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM orders o JOIN users u ON u.id = o.user_id {$whereSql}"
        );
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $perPage = max(1, min($perPage, 100));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT o.id, o.order_reference, o.status, o.total, o.payment_method, o.created_at,
                       u.name AS customer_name, u.email AS customer_email
                FROM orders o
                JOIN users u ON u.id = o.user_id
                {$whereSql}
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT ? OFFSET ?";

        $stmt       = $this->db->prepare($sql);
        $listParams = [...$params, $perPage, $offset];
        $stmt->bind_param($types . 'ii', ...$listParams);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Order counts per status plus revenue figures, for the admin dashboard.
     *
     * @return array{by_status:array<string,int>,total_orders:int,total_revenue:string,orders_today:int,revenue_today:string,customers:int}
     */
    public function stats(): array
    {
        $byStatus = array_fill_keys(OrderStatus::all(), 0);

        $rows = $this->db->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = (int) $row['c'];
        }

        $totals = $this->db->query(
            'SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(total), 0) AS total_revenue,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS orders_today,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total ELSE 0 END), 0) AS revenue_today
             FROM orders
             WHERE status NOT IN ("cancelled", "failed")'
        )->fetch_assoc();

        $customers = (int) $this->db
            ->query("SELECT COUNT(*) AS c FROM users WHERE role = 'user'")
            ->fetch_assoc()['c'];

        return [
            'by_status'     => $byStatus,
            'total_orders'  => (int) $totals['total_orders'],
            'total_revenue' => (string) $totals['total_revenue'],
            'orders_today'  => (int) $totals['orders_today'],
            'revenue_today' => (string) $totals['revenue_today'],
            'customers'     => $customers,
        ];
    }
}
