<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Database;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that hit the real (test) database.
 *
 * Each test starts from a known, empty set of business tables so results do
 * not depend on execution order.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected mysqli $db;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->truncateAll();
    }

    /**
     * Empty every business table so each test starts from a known state.
     *
     * `categories` is included because migration 001 seeds Shirts/Pants/Casual
     * Wear; leaving those rows in place would make createCategory('Shirts')
     * collide with the unique index.
     */
    protected function truncateAll(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        // product_images is listed explicitly: TRUNCATE does not fire the
        // ON DELETE CASCADE, so without this its rows survive and leak into
        // the next test.
        foreach ([
            'audit_logs',
            'blog_posts',
            'inventory_movements',
            'reviews',
            'wishlist_items',
            'password_reset_tokens',
            'payment_transactions',
            'order_status_history',
            'order_items',
            'orders',
            'addresses',
            'payments',
            'single_order',
            'contact_messages',
            'cart_items',
            'carts',
            'product_images',
            'products',
            'categories',
            'users',
        ] as $table) {
            $this->db->query("TRUNCATE TABLE {$table}");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function createUser(string $email = 'customer@test.com', string $role = 'user'): int
    {
        $hash = password_hash('Password@123', PASSWORD_DEFAULT);

        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password, phone, address, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $name    = 'Test User';
        $phone   = '01700000000';
        $address = 'Dhaka';
        $stmt->bind_param('ssssss', $name, $email, $hash, $phone, $address, $role);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    protected function createCategory(string $name = 'Shirts'): int
    {
        $stmt = $this->db->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    protected function createProduct(
        string $name = 'Test Shirt',
        string $price = '500.00',
        int $stock = 10
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, stock, image, category_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $description = 'A test product';
        $image       = 'test.jpg';
        $stmt->bind_param('ssdis', $name, $description, $price, $stock, $image);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Create a delivery address for a user and return its id, for tests that
     * exercise checkout (which now requires a real, owned address_id).
     */
    protected function createAddress(int $userId, bool $default = true): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO addresses (user_id, label, recipient_name, phone, address_line1, city, is_default)
             VALUES (?, "Home", "Test Recipient", "01700000000", "123 Test Road", "Dhaka", ?)'
        );
        $isDefault = $default ? 1 : 0;
        $stmt->bind_param('ii', $userId, $isDefault);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    protected function stockOf(int $productId): int
    {
        $stmt = $this->db->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stock = (int) $stmt->get_result()->fetch_assoc()['stock'];
        $stmt->close();

        return $stock;
    }

    protected function countRows(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS c FROM {$table}")->fetch_assoc()['c'];
    }
}
