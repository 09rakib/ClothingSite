<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\InventoryRepository;
use App\Orders\OrderService;
use App\Orders\OrderStatus;
use RuntimeException;

/**
 * Stock movement ledger (PROJECT_RULES.md §10).
 */
final class InventoryTest extends DatabaseTestCase
{
    private InventoryRepository $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = new InventoryRepository($this->db);
    }

    public function test_manual_adjustment_increases_stock_and_logs_it(): void
    {
        $productId = $this->createProduct('Widget', '100.00', 10);
        $admin     = $this->createUser('admin@test.com', 'admin');

        $this->inventory->adjust($productId, 5, 'Found extra stock during count', $admin);

        $this->assertSame(15, $this->stockOf($productId));

        $movements = $this->inventory->forProduct($productId);
        $this->assertCount(1, $movements);
        $this->assertSame(5, (int) $movements[0]['quantity_change']);
        $this->assertSame('manual_adjustment', $movements[0]['type']);
        $this->assertSame('Found extra stock during count', $movements[0]['reason']);
    }

    public function test_manual_adjustment_can_decrease_stock(): void
    {
        $productId = $this->createProduct('Widget', '100.00', 10);
        $admin     = $this->createUser('admin@test.com', 'admin');

        $this->inventory->adjust($productId, -3, 'Damaged units removed', $admin);

        $this->assertSame(7, $this->stockOf($productId));
    }

    /**
     * §10 "Prevent negative stock at the database/business-rule level."
     */
    public function test_adjustment_cannot_take_stock_negative(): void
    {
        $productId = $this->createProduct('Widget', '100.00', 5);
        $admin     = $this->createUser('admin@test.com', 'admin');

        $this->expectException(RuntimeException::class);

        $this->inventory->adjust($productId, -10, 'Too many removed', $admin);
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $productId = $this->createProduct();
        $admin     = $this->createUser('admin@test.com', 'admin');

        $this->expectException(RuntimeException::class);

        $this->inventory->adjust($productId, 5, '', $admin);
    }

    public function test_zero_adjustment_is_rejected(): void
    {
        $productId = $this->createProduct();
        $admin     = $this->createUser('admin@test.com', 'admin');

        $this->expectException(RuntimeException::class);

        $this->inventory->adjust($productId, 0, 'No-op', $admin);
    }

    public function test_failed_adjustment_leaves_stock_and_ledger_untouched(): void
    {
        $productId = $this->createProduct('Widget', '100.00', 5);
        $admin     = $this->createUser('admin@test.com', 'admin');

        try {
            $this->inventory->adjust($productId, -10, 'Too many', $admin);
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(5, $this->stockOf($productId));
        $this->assertCount(0, $this->inventory->forProduct($productId));
    }

    /**
     * Placing an order must write a 'sale' movement alongside the stock
     * decrement, so the ledger explains every unit that left.
     */
    public function test_placing_an_order_records_a_sale_movement(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Widget', '100.00', 10);

        (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 3]],
            $addressId
        );

        $movements = $this->inventory->forProduct($productId);
        $this->assertCount(1, $movements);
        $this->assertSame(-3, (int) $movements[0]['quantity_change']);
        $this->assertSame('sale', $movements[0]['type']);
        $this->assertNotEmpty($movements[0]['reference'], 'Sale movement should reference the order.');
    }

    /**
     * Returning an order must restock the items AND record a 'return'
     * movement — the inventory-domain equivalent of the payment refund path.
     */
    public function test_returning_an_order_restocks_and_records_a_return_movement(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Widget', '100.00', 10);
        $admin     = $this->createUser('admin@test.com', 'admin');

        $order  = new OrderService($this->db);
        $result = $order->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 2]], $addressId);
        $this->assertSame(8, $this->stockOf($productId));

        $orderId = (int) $result['order_id'];
        $order->updateStatus($orderId, OrderStatus::CONFIRMED, $admin);
        $order->updateStatus($orderId, OrderStatus::PROCESSING, $admin);
        $order->updateStatus($orderId, OrderStatus::SHIPPED, $admin);
        $order->updateStatus($orderId, OrderStatus::DELIVERED, $admin);
        $order->updateStatus($orderId, OrderStatus::RETURNED, $admin);

        $this->assertSame(10, $this->stockOf($productId), 'Stock must be restored after a return.');

        $movements = $this->inventory->forProduct($productId);
        $types = array_column($movements, 'type');
        $this->assertContains('sale', $types);
        $this->assertContains('return', $types);

        $returnMove = $movements[array_search('return', $types, true)];
        $this->assertSame(2, (int) $returnMove['quantity_change']);
    }

    public function test_recent_lists_movements_across_products(): void
    {
        $admin = $this->createUser('admin@test.com', 'admin');
        $a     = $this->createProduct('A');
        $b     = $this->createProduct('B');

        $this->inventory->adjust($a, 5, 'Restock', $admin);
        $this->inventory->adjust($b, 3, 'Restock', $admin);

        $recent = $this->inventory->recent();

        $this->assertCount(2, $recent);
    }
}
