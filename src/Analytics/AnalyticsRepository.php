<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Support\Database;
use mysqli;

/**
 * Read-only reporting queries (PROJECT_RULES.md §7 Phase 7 "Analytics & Growth").
 *
 * Every revenue figure here excludes cancelled/failed orders, the same rule
 * OrderRepository::stats() already uses for the admin dashboard — an
 * analytics page that counted money that was never actually collected would
 * be misleading rather than useful.
 */
final class AnalyticsRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * One row per day in [from, to], revenue and order count, days with no
     * orders included as zero so a chart never silently skips a gap.
     *
     * @return array<int,array{date:string,orders:int,revenue:string}>
     */
    public function dailyRevenue(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
             FROM orders
             WHERE status NOT IN ('cancelled', 'failed')
               AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['d']] = ['orders' => (int) $row['orders'], 'revenue' => (string) $row['revenue']];
        }

        $result = [];
        $cursor = strtotime($from);
        $end    = strtotime($to);
        while ($cursor <= $end) {
            $date     = date('Y-m-d', $cursor);
            $result[] = [
                'date'    => $date,
                'orders'  => $byDate[$date]['orders'] ?? 0,
                'revenue' => $byDate[$date]['revenue'] ?? '0.00',
            ];
            $cursor = strtotime('+1 day', $cursor);
        }

        return $result;
    }

    /**
     * @return array{orders:int,revenue:string,average_order_value:string,discount_given:string}
     */
    public function summary(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(total), 0) AS revenue,
                    COALESCE(SUM(discount_amount), 0) AS discount_given
             FROM orders
             WHERE status NOT IN ('cancelled', 'failed')
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $orders = (int) $row['orders'];
        $revenue = (string) $row['revenue'];

        return [
            'orders'              => $orders,
            'revenue'             => $revenue,
            'average_order_value' => $orders > 0 ? number_format((float) $revenue / $orders, 2, '.', '') : '0.00',
            'discount_given'      => (string) $row['discount_given'],
        ];
    }

    /**
     * Top-selling products by quantity within the range.
     *
     * @return array<int,array{product_id:int,name:string,slug:?string,quantity:int,revenue:string}>
     */
    public function bestSellers(string $from, string $to, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $stmt = $this->db->prepare(
            "SELECT oi.product_id, oi.product_name, p.slug,
                    SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE o.status NOT IN ('cancelled', 'failed')
               AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY oi.product_id, oi.product_name, p.slug
             ORDER BY qty DESC
             LIMIT ?"
        );
        $stmt->bind_param('ssi', $from, $to, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(
            static fn(array $r): array => [
                'product_id' => (int) $r['product_id'],
                'name'       => (string) $r['product_name'],
                'slug'       => $r['slug'] !== null ? (string) $r['slug'] : null,
                'quantity'   => (int) $r['qty'],
                'revenue'    => (string) $r['revenue'],
            ],
            $rows
        );
    }

    /**
     * @return array{new_customers:int,repeat_customers:int,ordering_customers:int,repeat_rate:float}
     */
    public function customerMetrics(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c FROM users WHERE role = 'user' AND DATE(created_at) BETWEEN ? AND ?"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $newCustomers = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $perCustomer = $this->db->query(
            "SELECT user_id, COUNT(*) AS order_count
             FROM orders
             WHERE status NOT IN ('cancelled', 'failed')
             GROUP BY user_id"
        )->fetch_all(MYSQLI_ASSOC);

        $ordering = count($perCustomer);
        $repeat   = count(array_filter($perCustomer, static fn(array $r): bool => (int) $r['order_count'] > 1));

        return [
            'new_customers'      => $newCustomers,
            'repeat_customers'   => $repeat,
            'ordering_customers' => $ordering,
            'repeat_rate'        => $ordering > 0 ? round(($repeat / $ordering) * 100, 1) : 0.0,
        ];
    }

    /**
     * Top customers by total spend, all-time (not range-limited — this is a
     * "who matters most" view, not a period report).
     *
     * @return array<int,array{user_id:int,name:string,email:string,orders:int,total_spent:string}>
     */
    public function topCustomers(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.email, COUNT(o.id) AS orders, COALESCE(SUM(o.total), 0) AS total_spent
             FROM users u
             JOIN orders o ON o.user_id = u.id AND o.status NOT IN ('cancelled', 'failed')
             GROUP BY u.id, u.name, u.email
             ORDER BY total_spent DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(
            static fn(array $r): array => [
                'user_id'     => (int) $r['id'],
                'name'        => (string) $r['name'],
                'email'       => (string) $r['email'],
                'orders'      => (int) $r['orders'],
                'total_spent' => (string) $r['total_spent'],
            ],
            $rows
        );
    }
}
