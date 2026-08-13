-- =========================================================
-- Shirt & Pant Store — Database Schema
-- Import this file to create the "onlineshopdb" database
-- with all required tables and a small set of demo data.
--
-- Usage (phpMyAdmin): create an empty database named
-- "onlineshopdb", then Import this file into it.
--
-- Usage (CLI): mysql -u root -p onlineshopdb < database.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS onlineshopdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE onlineshopdb;

-- ---------------------------------------------------------
-- Users (customers + admin/seller accounts)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,           -- stores a bcrypt hash, never plain text
    phone VARCHAR(20) NOT NULL,
    address VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Product categories
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Products
-- ---------------------------------------------------------
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

-- ---------------------------------------------------------
-- Orders (one row per "Buy Now" purchase)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS single_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Payments (one row per order, cash-on-delivery by default)
-- ---------------------------------------------------------
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
-- Seed data
-- ---------------------------------------------------------

-- Demo accounts
-- Admin login   -> admin@shop.com     / Admin@123
-- Customer login -> customer@shop.com / Customer@123
INSERT INTO users (name, email, password, phone, address, role) VALUES
('Store Admin', 'admin@shop.com', '$2y$10$iC5s6fecpv2AN9eGWPaTl.SlGdGoigVi7t.IOuFoTZEyfMKDCy/06', '01700000000', 'Dhaka, Bangladesh', 'admin'),
('Demo Customer', 'customer@shop.com', '$2y$10$3TrQbhS4fO49TMQgcr0G0O3CWGIDqwwEV3UcghOvy1neE89dfeQiy', '01800000000', 'Mirpur, Dhaka', 'user');

-- Categories
INSERT INTO categories (name) VALUES
('Shirts'),
('Pants'),
('Casual Wear');

-- Sample products (images already included in assets/images/products)
INSERT INTO products (name, description, price, stock, image, category_id) VALUES
('Classic Formal Shirt', 'Slim-fit cotton formal shirt, perfect for office wear.', 850.00, 25, '632341584033b-square.jpg', 1),
('Premium Casual Shirt', 'Breathable casual shirt for everyday comfort.', 920.00, 18, 'shirt2.png', 3),
('Slim Fit Pant', 'Comfortable slim-fit trouser suitable for all occasions.', 990.00, 20, 'pant1.png', 2),
('Printed Casual Shirt', 'Trendy printed shirt for a relaxed weekend look.', 780.00, 15, 'genji.jpg', 3),
('Cotton Polo Shirt', 'Soft cotton polo shirt, available in multiple colors.', 650.00, 30, 'genji2.png', 1),
('Denim Pant', 'Durable denim pant with a modern fit.', 1150.00, 12, '6965d431c8911-square.jpg', 2);
