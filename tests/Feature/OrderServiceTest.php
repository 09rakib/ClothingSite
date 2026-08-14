<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderService;
use RuntimeException;

/**
 * Order placement behaviour (PROJECT_RULES.md §24 "Feature/Integration",
 * Rule 6, Rule 9).
 *
 * PHASE 3: placeOrderFromCart is now the only order-creation path (the old
 * single-item placeSingleProductOrder was removed — Buy Now goes through the
 * cart too), and every order requires a real, owned delivery address.
 */
final class OrderServiceTest extends DatabaseTestCase
{
    private function order(): OrderService
    {
        return new OrderService($this->db);
    }

    public function test_successful_order_writes_order_items_and_decrements_stock(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Polo Shirt', '650.00', 10);

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 2]],
            $addressId
        );

        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(1, $this->countRows('order_items'));
        $this->assertSame(8, $this->stockOf($productId), 'Stock must drop by the quantity ordered.');

        $this->assertSame('1300.00', $result['total'], 'Total must be unit price x quantity.');
        $this->assertSame('Polo Shirt', $result['lines'][0]['product_name']);
    }

    /**
     * Rule 6: never trust the browser. The charge is computed from the
     * database row, not from anything passed in.
     */
    public function test_total_is_calculated_from_the_database_price(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Trusted Price', '999.99', 5);

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 3]],
            $addressId
        );

        $this->assertSame('2999.97', $result['total']);

        $stored = $this->db
            ->query("SELECT total FROM orders WHERE id = {$result['order_id']}")
            ->fetch_assoc()['total'];

        $this->assertSame('2999.97', $stored);
    }

    public function test_order_is_rejected_when_out_of_stock(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Sold Out', '500.00', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of stock');

        $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );
    }

    public function test_order_is_rejected_when_quantity_exceeds_stock(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Limited', '500.00', 2);

        try {
            $this->order()->placeOrderFromCart(
                $userId,
                [['product_id' => $productId, 'quantity' => 5]],
                $addressId
            );
            $this->fail('Expected the order to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only 2', $e->getMessage());
        }

        // Nothing may be written when the order fails (Rule 9 — transaction).
        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
        $this->assertSame(2, $this->stockOf($productId), 'Stock must be untouched after a failed order.');
    }

    public function test_failed_order_leaves_no_partial_rows(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);

        try {
            // Product id that does not exist.
            $this->order()->placeOrderFromCart(
                $userId,
                [['product_id' => 999999, 'quantity' => 1]],
                $addressId
            );
            $this->fail('Expected the order to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(0, $this->countRows('order_items'));
    }

    public function test_archived_product_cannot_be_ordered(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Gone', '500.00', 10);

        $this->db->query("UPDATE products SET status = 'archived', deleted_at = NOW() WHERE id = {$productId}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );
    }

    /**
     * §9 — a payment method that is not enabled in config must be refused
     * rather than silently written.
     */
    public function test_disabled_payment_method_is_rejected(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment method is not available');

        $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            'bkash'
        );
    }

    public function test_zero_or_negative_quantity_is_rejected(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);

        $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 0]],
            $addressId
        );
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

    /* =====================================================
     | Address requirements (§19 "No IDOR")
     * ===================================================== */

    public function test_order_is_rejected_without_a_valid_address(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery address');

        $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            999999 // no such address
        );
    }

    /**
     * A customer must not be able to ship an order using another customer's
     * saved address by guessing its id.
     */
    public function test_order_is_rejected_with_another_customers_address(): void
    {
        $owner       = $this->createUser('owner@test.com');
        $ownerAddr   = $this->createAddress($owner);
        $attacker    = $this->createUser('attacker@test.com');
        $productId   = $this->createProduct();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delivery address');

        $this->order()->placeOrderFromCart(
            $attacker,
            [['product_id' => $productId, 'quantity' => 1]],
            $ownerAddr
        );
    }

    public function test_order_snapshots_the_address_used(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );

        $order = $this->db
            ->query("SELECT recipient_name, city FROM orders WHERE id = {$result['order_id']}")
            ->fetch_assoc();

        $this->assertSame('Test Recipient', $order['recipient_name']);
        $this->assertSame('Dhaka', $order['city']);
    }

    public function test_order_history_is_scoped_to_the_requesting_customer(): void
    {
        $alice        = $this->createUser('alice@test.com');
        $aliceAddress = $this->createAddress($alice);
        $bob          = $this->createUser('bob@test.com');
        $bobAddress   = $this->createAddress($bob);
        $productId    = $this->createProduct('Shared Product', '500.00', 10);

        $orders = $this->order();
        $orders->placeOrderFromCart($alice, [['product_id' => $productId, 'quantity' => 1]], $aliceAddress);
        $orders->placeOrderFromCart($bob, [['product_id' => $productId, 'quantity' => 1]], $bobAddress);
        $orders->placeOrderFromCart($bob, [['product_id' => $productId, 'quantity' => 1]], $bobAddress);

        $this->assertCount(1, $orders->historyForUser($alice), 'Alice must only see her own order.');
        $this->assertCount(2, $orders->historyForUser($bob));
    }

    public function test_stats_reflect_placed_orders(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Stat Shirt', '100.00', 10);

        $orders = $this->order();
        $orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 2]], $addressId);
        $orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $stats = $orders->stats();

        $this->assertSame(2, $stats['total_orders']);
        $this->assertSame('300.00', $stats['total_revenue']);
        $this->assertSame(1, $stats['customers']);
        $this->assertSame(2, $stats['by_status']['pending'], 'New orders start pending.');
    }
}
