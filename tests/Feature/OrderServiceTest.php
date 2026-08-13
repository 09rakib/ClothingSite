<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderService;
use RuntimeException;

/**
 * Checkout behaviour: stock rules, pricing authority and transaction safety
 * (PROJECT_RULES.md §24 "Feature/Integration", Rule 6, Rule 9).
 */
final class OrderServiceTest extends DatabaseTestCase
{
    public function test_successful_order_writes_order_payment_and_decrements_stock(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Polo Shirt', '650.00', 10);

        $result = (new OrderService())->placeSingleProductOrder($userId, $productId, 2);

        $this->assertSame(1, $this->countRows('single_order'));
        $this->assertSame(1, $this->countRows('payments'));
        $this->assertSame(8, $this->stockOf($productId), 'Stock must drop by the quantity ordered.');

        $this->assertSame('Polo Shirt', $result['product_name']);
        $this->assertSame(2, $result['quantity']);
        $this->assertSame('1300.00', $result['total'], 'Total must be unit price x quantity.');
    }

    /**
     * Rule 6: never trust the browser. Even if a caller passes a price, the
     * charge is computed from the database row.
     */
    public function test_total_is_calculated_from_the_database_price(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Trusted Price', '999.99', 5);

        $result = (new OrderService())->placeSingleProductOrder($userId, $productId, 3);

        $this->assertSame('2999.97', $result['total']);

        $stmt = $this->db->prepare('SELECT total_amount FROM payments WHERE order_id = ?');
        $stmt->bind_param('i', $result['order_id']);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc()['total_amount'];
        $stmt->close();

        $this->assertSame('2999.97', $stored);
    }

    public function test_order_is_rejected_when_out_of_stock(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Sold Out', '500.00', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of stock');

        (new OrderService())->placeSingleProductOrder($userId, $productId);
    }

    public function test_order_is_rejected_when_quantity_exceeds_stock(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Limited', '500.00', 2);

        try {
            (new OrderService())->placeSingleProductOrder($userId, $productId, 5);
            $this->fail('Expected the order to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only 2 left', $e->getMessage());
        }

        // Nothing may be written when the order fails (Rule 9 — transaction).
        $this->assertSame(0, $this->countRows('single_order'));
        $this->assertSame(0, $this->countRows('payments'));
        $this->assertSame(2, $this->stockOf($productId), 'Stock must be untouched after a failed order.');
    }

    public function test_failed_order_leaves_no_partial_rows(): void
    {
        $userId = $this->createUser();

        try {
            // Product id that does not exist.
            (new OrderService())->placeSingleProductOrder($userId, 999999);
            $this->fail('Expected the order to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('single_order'));
        $this->assertSame(0, $this->countRows('payments'));
    }

    public function test_archived_product_cannot_be_ordered(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Gone', '500.00', 10);

        $this->db->query("UPDATE products SET status = 'archived', deleted_at = NOW() WHERE id = {$productId}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        (new OrderService())->placeSingleProductOrder($userId, $productId);
    }

    /**
     * §9 — a payment method that is not enabled in config must be refused
     * rather than silently written to the payments table.
     */
    public function test_disabled_payment_method_is_rejected(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment method is not available');

        (new OrderService())->placeSingleProductOrder($userId, $productId, 1, 'bkash');
    }

    public function test_zero_or_negative_quantity_is_rejected(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);

        (new OrderService())->placeSingleProductOrder($userId, $productId, 0);
    }

    /**
     * The CHECK constraint added in migration 002 is the last line of defence
     * against overselling (§10 "Prevent negative stock at database level").
     */
    public function test_database_refuses_negative_stock(): void
    {
        $productId = $this->createProduct('Guarded', '500.00', 1);

        $this->expectException(\mysqli_sql_exception::class);

        $this->db->query("UPDATE products SET stock = -1 WHERE id = {$productId}");
    }

    public function test_order_history_is_scoped_to_the_requesting_customer(): void
    {
        $alice     = $this->createUser('alice@test.com');
        $bob       = $this->createUser('bob@test.com');
        $productId = $this->createProduct('Shared Product', '500.00', 10);

        $orders = new OrderService();
        $orders->placeSingleProductOrder($alice, $productId);
        $orders->placeSingleProductOrder($bob, $productId);
        $orders->placeSingleProductOrder($bob, $productId);

        $this->assertCount(1, $orders->historyForUser($alice), "Alice must only see her own order.");
        $this->assertCount(2, $orders->historyForUser($bob));
    }

    public function test_stats_reflect_placed_orders(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Stat Shirt', '100.00', 10);

        $orders = new OrderService();
        $orders->placeSingleProductOrder($userId, $productId, 2);
        $orders->placeSingleProductOrder($userId, $productId, 1);

        $stats = $orders->stats();

        $this->assertSame(2, $stats['total_orders']);
        $this->assertSame('300.00', $stats['total_revenue']);
        $this->assertSame(1, $stats['customers']);
    }
}
