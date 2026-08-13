-- =========================================================
-- 002 — Order history integrity + product soft delete
--
-- Fixes the single most dangerous defect in the original schema
-- (PROJECT_RULES.md §6.1, §6.4, Rule 10 "Historical data is sacred").
--
-- THE PROBLEM
-- `single_order.product_id` referenced products with ON DELETE CASCADE, and
-- `payments.order_id` cascaded from single_order. Deleting one product
-- therefore silently deleted every order that ever contained it *and* the
-- matching payment rows — destroying revenue history with no warning.
--
-- THE FIX (three parts, all required together)
--   1. Order rows now carry a snapshot of the product as it was at purchase
--      time, so history stays accurate even if the product is later renamed,
--      repriced or archived.
--   2. The historical foreign keys become RESTRICT, so the database itself
--      refuses to erase order history.
--   3. Products gain soft delete (deleted_at/status). "Deleting" a product now
--      archives it: it disappears from the storefront but its orders survive.
--
-- Note: this file uses MariaDB's `IF NOT EXISTS` DDL extensions so it can be
-- re-run safely. XAMPP ships MariaDB, which the project targets.
-- =========================================================

-- ---------------------------------------------------------
-- 1. Product soft delete + catalog fields (§11)
-- ---------------------------------------------------------
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'archived') NOT NULL DEFAULT 'active' AFTER category_id,
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ---------------------------------------------------------
-- 2. Order snapshot columns (§5 "Order items must store a snapshot")
--    An old order must remain correct even if the product changes.
-- ---------------------------------------------------------
ALTER TABLE single_order
    ADD COLUMN IF NOT EXISTS product_name VARCHAR(120) NULL AFTER product_id,
    ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NULL AFTER product_name,
    ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1 AFTER unit_price;

-- Backfill snapshots for orders placed before this migration existed.
-- Uses the current product row as the best available approximation; from now
-- on the snapshot is written at purchase time and never changes.
UPDATE single_order so
    JOIN products p ON p.id = so.product_id
SET so.product_name = p.name
WHERE so.product_name IS NULL;

UPDATE single_order
SET unit_price = total_amount / GREATEST(quantity, 1)
WHERE unit_price IS NULL;

-- ---------------------------------------------------------
-- 3. Replace destructive cascades with RESTRICT (§6.3, §6.4)
--    The database now refuses to delete a product or customer that has
--    order history, instead of quietly deleting the history with it.
-- ---------------------------------------------------------
ALTER TABLE single_order
    DROP FOREIGN KEY IF EXISTS single_order_ibfk_1,
    DROP FOREIGN KEY IF EXISTS single_order_ibfk_2;

ALTER TABLE payments
    DROP FOREIGN KEY IF EXISTS payments_ibfk_1,
    DROP FOREIGN KEY IF EXISTS payments_ibfk_2;

ALTER TABLE single_order
    ADD CONSTRAINT fk_single_order_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_single_order_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES single_order(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- ---------------------------------------------------------
-- 4. Indexes (§6.5) — these columns are filtered/sorted on every page load.
-- ---------------------------------------------------------
ALTER TABLE products
    ADD INDEX IF NOT EXISTS idx_products_category (category_id),
    ADD INDEX IF NOT EXISTS idx_products_status (status),
    ADD INDEX IF NOT EXISTS idx_products_deleted_at (deleted_at),
    ADD INDEX IF NOT EXISTS idx_products_created_at (created_at),
    ADD INDEX IF NOT EXISTS idx_products_price (price),
    ADD INDEX IF NOT EXISTS idx_products_name (name);

ALTER TABLE single_order
    ADD INDEX IF NOT EXISTS idx_single_order_user (user_id),
    ADD INDEX IF NOT EXISTS idx_single_order_product (product_id),
    ADD INDEX IF NOT EXISTS idx_single_order_created_at (created_at);

ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_payments_user (user_id),
    ADD INDEX IF NOT EXISTS idx_payments_order (order_id),
    ADD INDEX IF NOT EXISTS idx_payments_created_at (created_at);

-- ---------------------------------------------------------
-- 5. Prevent negative stock at the database level (§10)
--    Application logic already guards this, but a constraint means a bug or a
--    manual query cannot drive inventory below zero either.
-- ---------------------------------------------------------
UPDATE products SET stock = 0 WHERE stock < 0;

ALTER TABLE products
    ADD CONSTRAINT chk_products_stock_non_negative CHECK (stock >= 0);
