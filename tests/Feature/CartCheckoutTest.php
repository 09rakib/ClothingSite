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
    private OrderService $orders;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService($this->db);
        $this->userId = $this->createUser();
    }

    public function test_multi_item_checkout_writes_every_line_under_one_reference(): void
    {
        $a = $this->createProduct('Shirt', '500.00', 10);
        $b = $this->createProduct('Pant', '900.00', 10);

        $result = $this->orders->placeOrderFromCart($this->userId, [
            ['product_id' => $a, 'quantity' => 2],
            ['product_id' => $b, 'quantity' => 1],
        ]);

        $this->assertCount(2, $result['lines']);
        $this->assertSame('1900.00', $result['total'], '2x500 + 1x900');
        $this->assertMatchesRegularExpression('/^ORD-[A-F0-9]{8}$/', $result['reference']);

        $this->assertSame(2, $this->countRows('single_order'));
        $this->assertSame(2, $this->countRows('payments'));

        // Both rows share one reference, so it reads as a single order.
        $references = $this->db
            ->query('SELECT DISTINCT order_reference FROM single_order')
            ->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $references);

        $this->assertSame(8, $this->stockOf($a));
        $this->assertSame(9, $this->stockOf($b));
    }

    /**
     * The whole point of the transaction: one bad line rolls back the rest.
     */
    public function test_one_unavailable_line_rolls_back_the_entire_order(): void
    {
        $good = $this->createProduct('Available', '500.00', 10);
        $bad  = $this->createProduct('Scarce', '900.00', 1);

        try {
            $this->orders->placeOrderFromCart($this->userId, [
                ['product_id' => $good, 'quantity' => 2],
                ['product_id' => $bad,  'quantity' => 5],   // more than exists
            ]);
            $this->fail('Expected the checkout to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only 1', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('single_order'), 'No order line may survive.');
        $this->assertSame(0, $this->countRows('payments'), 'No payment may survive.');
        $this->assertSame(10, $this->stockOf($good), 'Stock must be untouched.');
        $this->assertSame(1, $this->stockOf($bad));
    }

    public function test_archived_product_in_the_cart_rolls_back_the_order(): void
    {
        $good     = $this->createProduct('Available', '500.00', 10);
        $archived = $this->createProduct('Archived', '900.00', 10);
        $this->db->query("UPDATE products SET status='archived', deleted_at=NOW() WHERE id={$archived}");

        try {
            $this->orders->placeOrderFromCart($this->userId, [
                ['product_id' => $good,     'quantity' => 1],
                ['product_id' => $archived, 'quantity' => 1],
            ]);
            $this->fail('Expected the checkout to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('single_order'));
        $this->assertSame(10, $this->stockOf($good));
    }

    /**
     * Rule 6: the charge comes from the database at checkout time, not from
     * whatever the cart recorded earlier.
     */
    public function test_price_is_read_at_checkout_not_taken_from_the_cart(): void
    {
        $productId = $this->createProduct('Repriced', '100.00', 10);

        $this->db->query("UPDATE products SET price = 175.00 WHERE id = {$productId}");

        $result = $this->orders->placeOrderFromCart($this->userId, [
            ['product_id' => $productId, 'quantity' => 2],
        ]);

        $this->assertSame('350.00', $result['total']);
        $this->assertSame('175.00', $result['lines'][0]['unit_price']);
    }

    public function test_duplicate_product_lines_are_combined(): void
    {
        $productId = $this->createProduct('Doubled', '100.00', 10);

        $result = $this->orders->placeOrderFromCart($this->userId, [
            ['product_id' => $productId, 'quantity' => 2],
            ['product_id' => $productId, 'quantity' => 3],
        ]);

        $this->assertCount(1, $result['lines'], 'Same product should be one line.');
        $this->assertSame(5, $result['lines'][0]['quantity']);
        $this->assertSame(5, $this->stockOf($productId));
    }

    public function test_empty_cart_cannot_be_checked_out(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        $this->orders->placeOrderFromCart($this->userId, []);
    }

    public function test_disabled_payment_method_is_rejected(): void
    {
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment method is not available');

        $this->orders->placeOrderFromCart(
            $this->userId,
            [['product_id' => $productId, 'quantity' => 1]],
            'bkash'
        );
    }

    public function test_buying_the_exact_remaining_stock_succeeds(): void
    {
        $productId = $this->createProduct('Last Ones', '100.00', 3);

        $result = $this->orders->placeOrderFromCart($this->userId, [
            ['product_id' => $productId, 'quantity' => 3],
        ]);

        $this->assertSame('300.00', $result['total']);
        $this->assertSame(0, $this->stockOf($productId));
    }

    /* =====================================================
     | Grouped history
     * ===================================================== */

    public function test_history_groups_a_multi_item_checkout_as_one_order(): void
    {
        $a = $this->createProduct('Shirt', '500.00', 10);
        $b = $this->createProduct('Pant', '900.00', 10);

        $this->orders->placeOrderFromCart($this->userId, [
            ['product_id' => $a, 'quantity' => 1],
            ['product_id' => $b, 'quantity' => 1],
        ]);

        $grouped = $this->orders->groupedHistoryForUser($this->userId);

        $this->assertCount(1, $grouped, 'One checkout must read as one order.');
        $this->assertCount(2, $grouped[0]['lines']);
        $this->assertSame('1400.00', $grouped[0]['total']);
    }

    public function test_separate_checkouts_stay_separate_orders(): void
    {
        $a = $this->createProduct('Shirt', '500.00', 10);

        $this->orders->placeOrderFromCart($this->userId, [['product_id' => $a, 'quantity' => 1]]);
        $this->orders->placeOrderFromCart($this->userId, [['product_id' => $a, 'quantity' => 1]]);

        $this->assertCount(2, $this->orders->groupedHistoryForUser($this->userId));
    }

    public function test_buy_now_orders_also_get_a_reference(): void
    {
        $productId = $this->createProduct('Quick Buy', '500.00', 10);

        $result = $this->orders->placeSingleProductOrder($this->userId, $productId);

        $this->assertMatchesRegularExpression('/^ORD-[A-F0-9]{8}$/', $result['reference']);
        $this->assertCount(1, $this->orders->groupedHistoryForUser($this->userId));
    }

    public function test_history_is_scoped_to_the_requesting_customer(): void
    {
        $other     = $this->createUser('other@test.com');
        $productId = $this->createProduct('Shared', '100.00', 10);

        $this->orders->placeOrderFromCart($this->userId, [['product_id' => $productId, 'quantity' => 1]]);
        $this->orders->placeOrderFromCart($other, [['product_id' => $productId, 'quantity' => 1]]);

        $this->assertCount(1, $this->orders->groupedHistoryForUser($this->userId));
        $this->assertCount(1, $this->orders->groupedHistoryForUser($other));
    }
}
