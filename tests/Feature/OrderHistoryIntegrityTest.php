<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalog\ProductRepository;
use App\Orders\OrderService;
use mysqli_sql_exception;

/**
 * Regression tests for order-history integrity under the Phase 3
 * orders/order_items schema (PROJECT_RULES.md §6.1, Rule 10, §24 "Regression").
 *
 * The Phase 0 defect this guards against: a product's foreign key from order
 * history must never cascade-delete that history. Migration 007 carries the
 * same RESTRICT policy forward onto order_items.product_id and adds it fresh
 * on orders.user_id.
 */
final class OrderHistoryIntegrityTest extends DatabaseTestCase
{
    private function order(): OrderService
    {
        return new OrderService($this->db);
    }

    public function test_product_cannot_be_hard_deleted_while_order_items_reference_it(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $this->assertSame(1, $this->countRows('order_items'));

        $this->expectException(mysqli_sql_exception::class);

        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
    }

    public function test_archiving_a_product_preserves_its_order_history(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Archivable Shirt');

        $orders = $this->order();
        $orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        (new ProductRepository())->archive($productId);

        $this->assertSame(1, $this->countRows('orders'), 'Order history must survive archiving.');
        $this->assertSame(1, $this->countRows('order_items'));

        $history = $orders->historyForUser($userId);
        $this->assertCount(1, $history);
    }

    /**
     * The snapshot is what keeps an old order readable after the catalog
     * changes (§5 "An old order must remain correct even if the product is
     * later renamed, repriced, archived, or deleted").
     */
    public function test_order_keeps_the_name_and_price_from_purchase_time(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Original Name', '850.00');

        $orders = $this->order();
        $result = $orders->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        // Rename and reprice the product after the sale.
        $this->db->query("UPDATE products SET name = 'Renamed Product', price = 2500.00 WHERE id = {$productId}");

        $detail = $orders->detail((int) $result['order_id']);
        $item   = $detail['items'][0];

        $this->assertSame('Original Name', $item['product_name'], 'Order item must show the name as it was when purchased.');
        $this->assertSame('850.00', $item['unit_price'], 'Order item must keep the price paid, not the current price.');
    }

    public function test_customer_cannot_be_deleted_while_orders_exist(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $this->expectException(mysqli_sql_exception::class);

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    /**
     * Deleting a customer's saved address must not affect an order already
     * placed with it — the order keeps its own copy of the address text.
     */
    public function test_deleting_an_address_does_not_affect_past_orders(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $result = $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $stmt = $this->db->prepare('DELETE FROM addresses WHERE id = ?');
        $stmt->bind_param('i', $addressId);
        $stmt->execute();

        $detail = $this->order()->detail((int) $result['order_id']);

        $this->assertNotNull($detail, 'The order must still exist and be readable.');
        $this->assertSame('Test Recipient', $detail['order']['recipient_name']);
        $this->assertSame('Dhaka', $detail['order']['city']);
    }

    public function test_order_status_history_records_the_initial_pending_state(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        $result = $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $detail = $this->order()->detail((int) $result['order_id']);

        $this->assertCount(1, $detail['history']);
        $this->assertNull($detail['history'][0]['from_status']);
        $this->assertSame('pending', $detail['history'][0]['to_status']);
    }
}
