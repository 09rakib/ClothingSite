<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderService;
use RuntimeException;

/**
 * Multi-item checkout (PROJECT_RULES.md §8, Rule 6, Rule 9).
 *
 * The guarantee under test is atomicity: a checkout either writes every line
 * and deducts every unit of stock, or it changes nothing at all. A customer
 * must never be charged for two of three items.
 */
final class CartCheckoutTest extends DatabaseTestCase
{
    private function order(): OrderService
    {
        return new OrderService($this->db);
    }

    public function test_multi_item_checkout_writes_one_order_with_two_items(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $a         = $this->createProduct('Shirt', '500.00', 10);
        $b         = $this->createProduct('Pant', '900.00', 10);

        $result = $this->order()->placeOrderFromCart($userId, [
            ['product_id' => $a, 'quantity' => 2],
            ['product_id' => $b, 'quantity' => 1],
        ], $addressId);

        $this->assertCount(2, $result['lines']);
        $this->assertSame('1900.00', $result['total'], '2x500 + 1x900');
        $this->assertMatchesRegularExpression('/^ORD-[A-F0-9]{8}$/', $result['reference']);

        // One order header, two line items — the Phase 3 shape.
        $this->assertSame(1, $this->countRows('orders'));
        $this->assertSame(2, $this->countRows('order_items'));

        $this->assertSame(8, $this->stockOf($a));
        $this->assertSame(9, $this->stockOf($b));
    }

    /**
     * The whole point of the transaction: one bad line rolls back the rest.
     */
    public function test_one_unavailable_line_rolls_back_the_entire_order(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $good      = $this->createProduct('Available', '500.00', 10);
        $bad       = $this->createProduct('Scarce', '900.00', 1);

        try {
            $this->order()->placeOrderFromCart($userId, [
                ['product_id' => $good, 'quantity' => 2],
                ['product_id' => $bad,  'quantity' => 5],   // more than exists
            ], $addressId);
            $this->fail('Expected the checkout to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only 1', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('orders'), 'No order may survive.');
        $this->assertSame(0, $this->countRows('order_items'), 'No line item may survive.');
        $this->assertSame(10, $this->stockOf($good), 'Stock must be untouched.');
        $this->assertSame(1, $this->stockOf($bad));
    }

    public function test_archived_product_in_the_cart_rolls_back_the_order(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $good      = $this->createProduct('Available', '500.00', 10);
        $archived  = $this->createProduct('Archived', '900.00', 10);
        $this->db->query("UPDATE products SET status='archived', deleted_at=NOW() WHERE id={$archived}");

        try {
            $this->order()->placeOrderFromCart($userId, [
                ['product_id' => $good,     'quantity' => 1],
                ['product_id' => $archived, 'quantity' => 1],
            ], $addressId);
            $this->fail('Expected the checkout to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(10, $this->stockOf($good));
    }

    public function test_price_is_read_at_checkout_not_taken_from_the_cart(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Repriced', '100.00', 10);

        $this->db->query("UPDATE products SET price = 175.00 WHERE id = {$productId}");

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 2]],
            $addressId
        );

        $this->assertSame('350.00', $result['total']);
        $this->assertSame('175.00', $result['lines'][0]['unit_price']);
    }

    public function test_duplicate_product_lines_are_combined(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Doubled', '100.00', 10);

        $result = $this->order()->placeOrderFromCart($userId, [
            ['product_id' => $productId, 'quantity' => 2],
            ['product_id' => $productId, 'quantity' => 3],
        ], $addressId);

        $this->assertCount(1, $result['lines'], 'Same product should be one line.');
        $this->assertSame(5, $result['lines'][0]['quantity']);
        $this->assertSame(5, $this->stockOf($productId));
    }

    public function test_empty_cart_cannot_be_checked_out(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        $this->order()->placeOrderFromCart($userId, [], $addressId);
    }

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

    public function test_buying_the_exact_remaining_stock_succeeds(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Last Ones', '100.00', 3);

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 3]],
            $addressId
        );

        $this->assertSame('300.00', $result['total']);
        $this->assertSame(0, $this->stockOf($productId));
    }

    public function test_customer_note_is_stored_and_trimmed(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            null,
            '  Please leave at the door.  '
        );

        $detail = $this->order()->detail((int) $result['order_id']);
        $this->assertSame('Please leave at the door.', $detail['order']['customer_note']);
    }

    /* =====================================================
     | History
     * ===================================================== */

    public function test_history_lists_one_multi_item_checkout_as_one_order(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $a         = $this->createProduct('Shirt', '500.00', 10);
        $b         = $this->createProduct('Pant', '900.00', 10);

        $orders = $this->order();
        $result = $orders->placeOrderFromCart($userId, [
            ['product_id' => $a, 'quantity' => 1],
            ['product_id' => $b, 'quantity' => 1],
        ], $addressId);

        $history = $orders->historyForUser($userId);
        $this->assertCount(1, $history, 'One checkout must read as one order.');
        $this->assertSame('1400.00', $history[0]['total']);

        $detail = $orders->detail((int) $result['order_id']);
        $this->assertCount(2, $detail['items']);
    }

    public function test_separate_checkouts_stay_separate_orders(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $a         = $this->createProduct('Shirt', '500.00', 10);

        $orders = $this->order();
        $orders->placeOrderFromCart($userId, [['product_id' => $a, 'quantity' => 1]], $addressId);
        $orders->placeOrderFromCart($userId, [['product_id' => $a, 'quantity' => 1]], $addressId);

        $this->assertCount(2, $orders->historyForUser($userId));
    }

    public function test_every_order_gets_a_reference_and_starts_pending(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Quick Buy', '500.00', 10);

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );

        $this->assertMatchesRegularExpression('/^ORD-[A-F0-9]{8}$/', $result['reference']);

        $detail = $this->order()->detail((int) $result['order_id']);
        $this->assertSame('pending', $detail['order']['status']);
    }

    public function test_history_is_scoped_to_the_requesting_customer(): void
    {
        $userId      = $this->createUser();
        $addressId   = $this->createAddress($userId);
        $other       = $this->createUser('other@test.com');
        $otherAddr   = $this->createAddress($other);
        $productId   = $this->createProduct('Shared', '100.00', 10);

        $orders = $this->order();
        $orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);
        $orders->placeOrderFromCart($other, [['product_id' => $productId, 'quantity' => 1]], $otherAddr);

        $this->assertCount(1, $orders->historyForUser($userId));
        $this->assertCount(1, $orders->historyForUser($other));
    }
}
