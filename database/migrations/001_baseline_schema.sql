-- =========================================================
-- 001 — Baseline schema
--
-- Represents the schema as it existed before the Phase 0 upgrade, so that a
-- fresh machine and an already-populated database converge on the same state.
-- Everything is IF NOT EXISTS / INSERT IGNORE, making this file safe to run
-- against a database that was previously created from the old database.sql.
--
-- The database itself is created by database/migrate.php before this runs.
-- =========================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,           -- bcrypt hash, never plain text
    phone VARCHAR(20) NOT NULL,
    address VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    image VARCHAR(255) NOT NULL,
    category_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS single_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'cash_on_delivery',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES single_order(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Seed data (idempotent)
-- Admin    -> admin@shop.com     / Admin@123
-- Customer -> customer@shop.com  / Customer@123
-- ---------------------------------------------------------
INSERT IGNORE INTO users (name, email, password, phone, address, role) VALUES
('Store Admin', 'admin@shop.com', '$2y$10$iC5s6fecpv2AN9eGWPaTl.SlGdGoigVi7t.IOuFoTZEyfMKDCy/06', '01700000000', 'Dhaka, Bangladesh', 'admin'),
('Demo Customer', 'customer@shop.com', '$2y$10$3TrQbhS4fO49TMQgcr0G0O3CWGIDqwwEV3UcghOvy1neE89dfeQiy', '01800000000', 'Mirpur, Dhaka', 'user');

INSERT IGNORE INTO categories (name) VALUES
('Shirts'),
('Pants'),
('Casual Wear');

-- Demo products are only inserted into a brand-new, empty catalog.
-- WHY the NOT EXISTS guard: this migration also runs against databases that
-- were already populated from the original database.sql. Without the guard,
-- applying it there would duplicate every product.
INSERT INTO products (name, description, price, stock, image, category_id)
SELECT * FROM (
    SELECT 'Classic Formal Shirt' AS name, 'Slim-fit cotton formal shirt, perfect for office wear.' AS description, 850.00 AS price, 25 AS stock, '632341584033b-square.jpg' AS image, (SELECT id FROM categories WHERE name = 'Shirts') AS category_id
    UNION ALL SELECT 'Premium Casual Shirt', 'Breathable casual shirt for everyday comfort.', 920.00, 18, 'shirt2.png', (SELECT id FROM categories WHERE name = 'Casual Wear')
    UNION ALL SELECT 'Slim Fit Pant', 'Comfortable slim-fit trouser suitable for all occasions.', 990.00, 20, 'pant1.png', (SELECT id FROM categories WHERE name = 'Pants')
    UNION ALL SELECT 'Printed Casual Shirt', 'Trendy printed shirt for a relaxed weekend look.', 780.00, 15, 'genji.jpg', (SELECT id FROM categories WHERE name = 'Casual Wear')
    UNION ALL SELECT 'Cotton Polo Shirt', 'Soft cotton polo shirt, available in multiple colors.', 650.00, 30, 'genji2.png', (SELECT id FROM categories WHERE name = 'Shirts')
    UNION ALL SELECT 'Denim Pant', 'Durable denim pant with a modern fit.', 1150.00, 12, '6965d431c8911-square.jpg', (SELECT id FROM categories WHERE name = 'Pants')
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM products);
