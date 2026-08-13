-- =========================================================
-- 006 — Shopping cart (Phase 2)
--
-- DESIGN DECISIONS (PROJECT_RULES.md §8 requires the guest-cart question to be
-- answered explicitly rather than left implicit):
--
-- 1. GUEST CARTS ARE SUPPORTED.
--    A cart belongs to EITHER a logged-in customer (user_id) or an anonymous
--    visitor identified by a random cookie token. Requiring login before a
--    visitor may even collect items is a well-known way to lose customers, and
--    retrofitting guest support later would mean rewriting the cart rather
--    than extending it. On login the guest cart is merged into the customer's
--    cart and then discarded.
--
-- 2. CART ITEMS DO NOT DETERMINE THE PRICE CHARGED.
--    price_at_add is stored only so the customer can be told "this price
--    changed since you added it". Every subtotal and total is recomputed from
--    the live products.price at read and at checkout, because §8 forbids
--    trusting any price that did not come from the database at that moment.
--
-- 3. ADDING TO CART DOES NOT RESERVE STOCK.
--    Stock is authoritative at checkout, where the product row is locked.
--    Reserving stock in the cart would let one abandoned cart block real
--    sales; §10 lists reservation as optional and it is not needed yet.
--
-- 4. order_reference GROUPS ONE CHECKOUT.
--    single_order still holds one row per product. A checkout containing three
--    products therefore writes three rows sharing one reference, so the
--    customer sees a single order rather than three unrelated ones. The full
--    orders/order_items restructure remains Phase 3; this is the minimum that
--    makes a multi-item checkout coherent without pre-empting that work.
-- =========================================================

CREATE TABLE IF NOT EXISTS carts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Exactly one of these identifies the owner. Both are nullable because a
    -- cart is either a guest cart (token) or a customer cart (user_id).
    user_id INT NULL,
    token CHAR(64) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- One active cart per customer, and tokens must not collide.
    UNIQUE KEY uniq_carts_user (user_id),
    UNIQUE KEY uniq_carts_token (token),
    INDEX idx_carts_updated_at (updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,

    -- Display-only: used to warn the customer when the price has moved.
    -- Never used to compute what they are charged.
    price_at_add DECIMAL(10,2) NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- Deleting a cart removes its lines; that is not historical data.
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- Products are soft-deleted, so RESTRICT here would be pointless; a cart
    -- line pointing at an archived product is filtered out when the cart is
    -- read, and CASCADE keeps things tidy on a genuine hard delete.
    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE,

    -- Adding the same product twice increments the existing line instead of
    -- creating a duplicate.
    UNIQUE KEY uniq_cart_items_cart_product (cart_id, product_id),
    INDEX idx_cart_items_cart (cart_id),
    CONSTRAINT chk_cart_items_quantity_positive CHECK (quantity > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Group the rows written by a single checkout.
-- ---------------------------------------------------------
ALTER TABLE single_order
    ADD COLUMN IF NOT EXISTS order_reference VARCHAR(20) NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_single_order_reference (order_reference);

-- Existing orders each become their own single-line reference, so the order
-- history renders consistently for rows created before this migration.
UPDATE single_order
SET order_reference = CONCAT('ORD-', LPAD(id, 6, '0'))
WHERE order_reference IS NULL;
