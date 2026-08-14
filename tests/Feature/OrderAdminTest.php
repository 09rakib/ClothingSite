<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderRepository;
use App\Orders\OrderService;
use App\Orders\OrderStatus;
use RuntimeException;

/**
 * Admin order management: listing/filtering and status transitions
 * (PROJECT_RULES.md §7, §16, §30 Phase 3 "Admin order management").
 */
final class OrderAdminTest extends DatabaseTestCase
{
    private OrderRepository $repo;
    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = new OrderRepository($this->db);
        $this->orders = new OrderService($this->db);
    }

    private function placeOrder(?int $userId = null): array
    {
        $userId   ??= $this->createUser('customer' . uniqid('', true) . '@test.com');
        $addressId  = $this->createAddress($userId);
        $productId  = $this->createProduct('Test Product ' . uniqid('', true), '500.00', 10);

        return $this->orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);
    }

    /* =====================================================
     | Status transitions
     * ===================================================== */

    public function test_valid_transition_updates_status_and_records_history(): void
    {
        $result = $this->placeOrder();
        $admin  = $this->createUser('admin@test.com', 'admin');

        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::CONFIRMED, $admin, 'Payment verified');

        $order = $this->repo->find((int) $result['order_id']);
        $this->assertSame('confirmed', $order['status']);

        $history = $this->repo->statusHistory((int) $result['order_id']);
        $this->assertCount(2, $history, 'Initial pending + the new transition.');
        $this->assertSame('pending', $history[1]['from_status']);
        $this->assertSame('confirmed', $history[1]['to_status']);
        $this->assertSame('Payment verified', $history[1]['note']);
    }

    /**
     * The exact rule the whole status machine exists to enforce: an admin
     * cannot skip steps even by posting a status value directly.
     */
    public function test_invalid_transition_is_rejected_and_nothing_changes(): void
    {
        $result = $this->placeOrder();
        $admin  = $this->createUser('admin2@test.com', 'admin');

        try {
            $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::DELIVERED, $admin);
            $this->fail('Expected the skip-ahead transition to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot move', $e->getMessage());
        }

        $order = $this->repo->find((int) $result['order_id']);
        $this->assertSame('pending', $order['status'], 'Status must be unchanged after a rejected transition.');
        $this->assertCount(1, $this->repo->statusHistory((int) $result['order_id']), 'No new history row on a rejected transition.');
    }

    public function test_a_terminal_order_accepts_no_further_transitions(): void
    {
        $result = $this->placeOrder();
        $admin  = $this->createUser('admin3@test.com', 'admin');

        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::CANCELLED, $admin);

        $this->expectException(RuntimeException::class);
        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::CONFIRMED, $admin);
    }

    public function test_delivering_an_order_marks_payment_as_paid(): void
    {
        $result = $this->placeOrder();
        $admin  = $this->createUser('admin4@test.com', 'admin');

        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::CONFIRMED, $admin);
        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::PROCESSING, $admin);
        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::SHIPPED, $admin);

        $order = $this->repo->find((int) $result['order_id']);
        $this->assertSame('unpaid', $order['payment_status']);

        $this->repo->transitionStatus((int) $result['order_id'], OrderStatus::DELIVERED, $admin);

        $order = $this->repo->find((int) $result['order_id']);
        $this->assertSame('paid', $order['payment_status']);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $result = $this->placeOrder();
        $admin  = $this->createUser('admin5@test.com', 'admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown order status');

        $this->repo->transitionStatus((int) $result['order_id'], 'teleported', $admin);
    }

    /* =====================================================
     | Admin listing / filtering
     * ===================================================== */

    public function test_admin_can_see_orders_from_every_customer(): void
    {
        $this->placeOrder();
        $this->placeOrder();
        $this->placeOrder();

        $result = $this->repo->paginateForAdmin();

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['items']);
    }

    public function test_admin_listing_filters_by_status(): void
    {
        $a = $this->placeOrder();
        $this->placeOrder();
        $admin = $this->createUser('admin6@test.com', 'admin');

        $this->repo->transitionStatus((int) $a['order_id'], OrderStatus::CONFIRMED, $admin);

        $pending   = $this->repo->paginateForAdmin(OrderStatus::PENDING);
        $confirmed = $this->repo->paginateForAdmin(OrderStatus::CONFIRMED);

        $this->assertSame(1, $pending['total']);
        $this->assertSame(1, $confirmed['total']);
    }

    public function test_admin_listing_searches_by_reference_and_customer(): void
    {
        $userId = $this->createUser('findme@test.com');
        $result = $this->placeOrder($userId);
        $this->placeOrder(); // noise

        $byReference = $this->repo->paginateForAdmin(null, $result['reference']);
        $this->assertSame(1, $byReference['total']);

        $byEmail = $this->repo->paginateForAdmin(null, 'findme@test.com');
        $this->assertSame(1, $byEmail['total']);
    }

    public function test_admin_listing_paginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->placeOrder();
        }

        $page1 = $this->repo->paginateForAdmin(null, '', 1, 2);
        $this->assertCount(2, $page1['items']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['pages']);
    }

    public function test_stats_count_orders_per_status(): void
    {
        $a = $this->placeOrder();
        $this->placeOrder();
        $admin = $this->createUser('admin7@test.com', 'admin');

        $this->repo->transitionStatus((int) $a['order_id'], OrderStatus::CONFIRMED, $admin);

        $stats = $this->repo->stats();

        $this->assertSame(1, $stats['by_status']['pending']);
        $this->assertSame(1, $stats['by_status']['confirmed']);
        $this->assertSame(2, $stats['total_orders']);
    }

    /**
     * Cancelled/failed orders should not inflate the revenue figure shown on
     * the dashboard.
     */
    public function test_revenue_excludes_cancelled_orders(): void
    {
        $a = $this->placeOrder();
        $this->placeOrder();
        $admin = $this->createUser('admin8@test.com', 'admin');

        $this->repo->transitionStatus((int) $a['order_id'], OrderStatus::CANCELLED, $admin);

        $stats = $this->repo->stats();

        // Only the non-cancelled order (500.00) counts toward revenue.
        $this->assertSame('500.00', $stats['total_revenue']);
    }
}
