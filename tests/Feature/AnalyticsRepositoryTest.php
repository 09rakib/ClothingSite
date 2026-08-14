<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Analytics\AnalyticsRepository;
use App\Orders\OrderRepository;
use App\Orders\OrderService;
use App\Orders\OrderStatus;

/**
 * Reporting queries (PROJECT_RULES.md §7 Phase 7 "Analytics & Growth").
 */
final class AnalyticsRepositoryTest extends DatabaseTestCase
{
    private AnalyticsRepository $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new AnalyticsRepository($this->db);
    }

    private function placeOrder(int $productId, string $price = '500.00'): array
    {
        $userId    = $this->createUser('customer' . uniqid('', true) . '@test.com');
        $addressId = $this->createAddress($userId);

        return (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );
    }

    public function test_daily_revenue_includes_every_day_in_range_even_with_no_orders(): void
    {
        $from = date('Y-m-d', strtotime('-4 days'));
        $to   = date('Y-m-d');

        $days = $this->analytics->dailyRevenue($from, $to);

        $this->assertCount(5, $days, 'A 5-day range must return 5 rows.');
        foreach ($days as $day) {
            $this->assertSame(0, $day['orders']);
            $this->assertSame('0.00', $day['revenue']);
        }
    }

    public function test_todays_order_appears_in_the_daily_revenue(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);
        $this->placeOrder($productId);

        $today = date('Y-m-d');
        $days  = $this->analytics->dailyRevenue($today, $today);

        $this->assertSame(1, $days[0]['orders']);
        $this->assertSame('500.00', $days[0]['revenue']);
    }

    public function test_summary_excludes_cancelled_orders(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);
        $good      = $this->placeOrder($productId);
        $cancelled = $this->placeOrder($productId);

        $admin = $this->createUser('admin@test.com', 'admin');
        (new OrderRepository($this->db))->transitionStatus((int) $cancelled['order_id'], OrderStatus::CANCELLED, $admin);

        $today   = date('Y-m-d');
        $summary = $this->analytics->summary($today, $today);

        $this->assertSame(1, $summary['orders'], 'Cancelled order must not count.');
        $this->assertSame('500.00', $summary['revenue']);
    }

    public function test_average_order_value_is_computed_correctly(): void
    {
        $a = $this->createProduct('A', '300.00', 10);
        $b = $this->createProduct('B', '700.00', 10);
        $this->placeOrder($a, '300.00');
        $this->placeOrder($b, '700.00');

        $today   = date('Y-m-d');
        $summary = $this->analytics->summary($today, $today);

        $this->assertSame(2, $summary['orders']);
        $this->assertSame('1000.00', $summary['revenue']);
        $this->assertSame('500.00', $summary['average_order_value']);
    }

    public function test_best_sellers_ranks_by_quantity(): void
    {
        $popular = $this->createProduct('Popular Shirt', '100.00', 100);
        $rare    = $this->createProduct('Rare Shirt', '100.00', 100);

        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $order     = new OrderService($this->db);

        $order->placeOrderFromCart($userId, [
            ['product_id' => $popular, 'quantity' => 5],
            ['product_id' => $rare, 'quantity' => 1],
        ], $addressId);

        $today = date('Y-m-d');
        $best  = $this->analytics->bestSellers($today, $today);

        $this->assertSame('Popular Shirt', $best[0]['name']);
        $this->assertSame(5, $best[0]['quantity']);
    }

    public function test_customer_metrics_counts_repeat_customers(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $repeatCustomer = $this->createUser('repeat@test.com');
        $addr           = $this->createAddress($repeatCustomer);
        $order          = new OrderService($this->db);
        $order->placeOrderFromCart($repeatCustomer, [['product_id' => $productId, 'quantity' => 1]], $addr);
        $order->placeOrderFromCart($repeatCustomer, [['product_id' => $productId, 'quantity' => 1]], $addr);

        $this->placeOrder($productId); // a different, one-time customer

        $today   = date('Y-m-d');
        $metrics = $this->analytics->customerMetrics($today, $today);

        $this->assertSame(2, $metrics['ordering_customers']);
        $this->assertSame(1, $metrics['repeat_customers']);
        $this->assertSame(50.0, $metrics['repeat_rate']);
    }

    public function test_top_customers_ranks_by_total_spend(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 100);

        $bigSpender = $this->createUser('big@test.com');
        $bigAddr    = $this->createAddress($bigSpender);
        $order      = new OrderService($this->db);
        $order->placeOrderFromCart($bigSpender, [['product_id' => $productId, 'quantity' => 3]], $bigAddr);

        $this->placeOrder($productId); // smaller spender

        $top = $this->analytics->topCustomers(5);

        $this->assertSame('big@test.com', $top[0]['email']);
        $this->assertSame('1500.00', $top[0]['total_spent']);
    }
}
