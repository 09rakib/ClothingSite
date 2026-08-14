-- =========================================================
-- 012 — User status, inventory ledger, blog CMS, audit log (Phase 6)
-- =========================================================

-- ---------------------------------------------------------
-- Account status (§16 "User management") — lets an admin suspend an
-- account (blocks login) without deleting it, preserving its order history.
-- ---------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER role;

-- ---------------------------------------------------------
-- Inventory movement ledger (§10 "Do not only store the current stock
-- number. Maintain an inventory movement/history trail for important stock
-- changes.").
--
-- Every deduction at checkout, every restock on a return, and every manual
-- admin adjustment writes one row here. products.stock remains the fast-read
-- current value — this table is how "why is stock at this number" gets
-- answered, the same current-value/history-trail split every other ledger in
-- this codebase uses (payment_transactions, order_status_history).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,

    -- Positive = stock added (restock/return), negative = stock removed (sale).
    quantity_change INT NOT NULL,

    type ENUM('sale', 'return', 'restock', 'manual_adjustment') NOT NULL,
    reason VARCHAR(255) NULL,
    reference VARCHAR(50) NULL, -- order_reference for sale/return, NULL for manual entries

    -- NULL for system-generated rows (sale/return triggered by the order
    -- flow); set for anything an admin did by hand.
    created_by INT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_inventory_movements_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_movements_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_inventory_movements_product (product_id),
    INDEX idx_inventory_movements_type (type),
    INDEX idx_inventory_movements_created_at (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Blog CMS (§21) — replaces the hardcoded array blog.php used to render.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL,
    excerpt VARCHAR(300) NULL,
    body TEXT NOT NULL,
    featured_image VARCHAR(255) NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    author_id INT NULL,
    published_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_blog_posts_author
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    UNIQUE KEY uniq_blog_posts_slug (slug),
    INDEX idx_blog_posts_status_published (status, published_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Audit log (§23 "Separate technical logs from business audit logs").
--
-- Technical errors already go to storage/logs/*.log via App\Support\Logger;
-- this table is specifically for business-significant admin actions that
-- must be queryable and retained, per §23's own distinction.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,          -- NULL = system-initiated
    action VARCHAR(100) NOT NULL,      -- e.g. 'product.archived', 'order.status_changed'
    entity_type VARCHAR(50) NOT NULL,  -- e.g. 'product', 'order', 'user'
    entity_id INT NULL,
    metadata TEXT NULL,                -- JSON detail; never passwords/tokens/card data
    ip_address VARBINARY(16) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_audit_logs_entity (entity_type, entity_id),
    INDEX idx_audit_logs_actor (actor_id),
    INDEX idx_audit_logs_created_at (created_at)
) ENGINE=InnoDB;
