-- =========================================================
-- 007 — Orders/order_items restructure + address book (Phase 3)
--
-- WHY: single_order (Phase 0/2) stores one row per product per checkout,
-- grouped only by an order_reference string. That is workable for order
-- history but has no natural home for an order-level status
-- (Pending/Confirmed/.../Delivered) — a "status" would have to be stamped
-- onto every line of a multi-item order and kept in sync by hand. §7 asks for
-- a real status machine with a history of who changed what and when, and §5
-- explicitly recommends an orders + order_items split for exactly this reason.
--
-- HISTORICAL DATA IS NOT TOUCHED (Rule 10): single_order and payments remain
-- exactly as they are, untouched, as an immutable historical record. Migration
-- 008 (PHP) copies their data forward into the new tables so existing orders
-- appear correctly in the new UI; it does not delete or rewrite the old rows.
--
-- Going forward, all order creation and reads use orders/order_items only.
-- =========================================================

-- ---------------------------------------------------------
-- Address book (§13 "Never store only one address directly on the user
-- record if multiple shipping addresses may be required").
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(40) NOT NULL DEFAULT 'Home',
    recipient_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address_line1 VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- An address only has meaning while its owner's account exists, and
    -- orders keep their own text snapshot (below), so deleting the user's
    -- addresses along with the user is safe.
    CONSTRAINT fk_addresses_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_addresses_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Orders (one row per checkout)
--
-- The delivery address is stored as a text snapshot, NOT a foreign key to
-- addresses. An order must still show the address it was placed with even if
-- the customer later edits or deletes that address book entry (§5, Rule 10).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_reference VARCHAR(20) NOT NULL,
    user_id INT NOT NULL,

    status ENUM(
        'pending', 'confirmed', 'processing', 'shipped', 'delivered',
        'cancelled', 'failed', 'returned', 'refunded'
    ) NOT NULL DEFAULT 'pending',

    subtotal DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,

    payment_method VARCHAR(50) NOT NULL,
    -- Minimal payment state for now; a full payment_transactions table with
    -- gateway verification is Phase 4 ("Payment Architecture").
    payment_status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',

    -- Delivery address snapshot.
    recipient_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address_line1 VARCHAR(255) NOT NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,

    customer_note VARCHAR(500) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- RESTRICT, matching the Phase 0 fix to single_order: a customer with
    -- order history can never be hard-deleted out from under their orders.
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    UNIQUE KEY uniq_orders_reference (order_reference),
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_status (status),
    INDEX idx_orders_created_at (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Order line items
--
-- product_name/unit_price are a purchase-time snapshot, exactly like
-- single_order before it — an order must read correctly even after the
-- product is renamed, repriced or archived (§5).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Items are children of their order, not independent historical rows, so
    -- they cascade with it. In practice orders are never deleted (Rule 10).
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    -- RESTRICT for the same reason as single_order.product_id in migration
    -- 002: a product with order history must not be hard-deletable.
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id),
    CONSTRAINT chk_order_items_quantity_positive CHECK (quantity > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Order status history (§7 "Every status change should be recorded, record
-- who changed it, record timestamp, optionally record a note/reason").
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    -- NULL = the system (e.g. the status set when the order was placed),
    -- otherwise the admin user who made the change.
    changed_by INT NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_status_history_changed_by
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_order_status_history_order (order_id)
) ENGINE=InnoDB;
