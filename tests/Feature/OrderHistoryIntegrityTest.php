<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalog\ProductRepository;
use App\Orders\OrderService;
use mysqli_sql_exception;

/**
 * Regression tests for the single worst defect in the original schema
 * (PROJECT_RULES.md §6.1, Rule 10, §24 "Regression").
 *
 * Before Phase 0, `single_order.product_id` cascaded on delete: removing one
 * product wiped every order containing it, plus the payment rows behind them.
 * These tests fail loudly if that behaviour is ever reintroduced.
 */
final class OrderHistoryIntegrityTest extends DatabaseTestCase
{
    public function test_product_cannot_be_hard_deleted_while_orders_reference_it(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        (new OrderService())->placeSingleProductOrder($userId, $productId);

        $this->assertSame(1, $this->countRows('single_order'));

        // The RESTRICT foreign key must refuse this outright.
        $this->expectException(mysqli_sql_exception::class);

        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
    }

    public function test_archiving_a_product_preserves_its_order_history(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Archivable Shirt');

        $orders = new OrderService();
        $orders->placeSingleProductOrder($userId, $productId);

        (new ProductRepository())->archive($productId);

        // The order and its payment must both survive.
        $this->assertSame(1, $this->countRows('single_order'), 'Order history must survive archiving.');
        $this->assertSame(1, $this->countRows('payments'), 'Payment history must survive archiving.');

        $history = $orders->historyForUser($userId);
        $this->assertCount(1, $history);
        $this->assertSame('Archivable Shirt', $history[0]['product_name']);
    }

    public function test_archived_product_disappears_from_the_storefront(): void
    {
        $productId  = $this->createProduct('Hidden Shirt');
        $repository = new ProductRepository();

        $this->assertNotNull($repository->findActive($productId));

        $repository->archive($productId);

        $this->assertNull($repository->findActive($productId), 'Archived products must not be purchasable.');

        $listing = $repository->paginateActive();
        $this->assertSame(0, $listing['total'], 'Archived products must not appear in the shop.');

        // Still visible to the admin so it can be restored.
        $this->assertNotNull($repository->find($productId));
    }

    public function test_archived_product_can_be_restored(): void
    {
        $productId  = $this->createProduct();
        $repository = new ProductRepository();

        $repository->archive($productId);
        $this->assertNull($repository->findActive($productId));

        $repository->restore($productId);
        $this->assertNotNull($repository->findActive($productId));
    }

    /**
     * The snapshot is what keeps an old order readable after the catalog
     * changes (§5 "An old order must remain correct even if the product is
     * later renamed, repriced, archived, or deleted").
     */
    public function test_order_keeps_the_name_and_price_from_purchase_time(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct('Original Name', '850.00');

        $orders = new OrderService();
        $orders->placeSingleProductOrder($userId, $productId);

        // Rename and reprice the product after the sale.
        $this->db->query("UPDATE products SET name = 'Renamed Product', price = 2500.00 WHERE id = {$productId}");

        $history = $orders->historyForUser($userId);

        $this->assertSame('Original Name', $history[0]['product_name'], 'Order must show the name as it was when purchased.');
        $this->assertSame('850.00', $history[0]['unit_price'], 'Order must keep the price paid, not the current price.');
        $this->assertSame('850.00', $history[0]['total_amount']);
    }

    public function test_customer_cannot_be_deleted_while_orders_exist(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        (new OrderService())->placeSingleProductOrder($userId, $productId);

        $this->expectException(mysqli_sql_exception::class);

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }
}
