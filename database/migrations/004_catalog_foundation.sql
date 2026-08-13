-- =========================================================
-- 004 — Catalog foundation (Phase 1)
--
-- Adds the catalog fields PROJECT_RULES.md §11 and §30 Phase 1 call for:
-- URL slugs, SKU, a per-product low-stock override, and support for multiple
-- product images with one primary image.
--
-- Slugs are added NULLable here; migration 005 backfills them using the same
-- Slugger the application uses, and only then is the UNIQUE index added.
-- Doing it in that order means the migration cannot fail on a database that
-- already contains products.
-- =========================================================

-- ---------------------------------------------------------
-- Categories: slug + description for category landing pages
-- ---------------------------------------------------------
ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS slug VARCHAR(200) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS description VARCHAR(300) NULL AFTER slug,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ---------------------------------------------------------
-- Products: slug, SKU, per-product low-stock threshold
--
-- low_stock_threshold is NULLable on purpose: NULL means "use the store-wide
-- default from config", so the global setting keeps working and only products
-- that genuinely need a different rule carry one (§30 Phase 1
-- "Configurable low-stock threshold").
-- ---------------------------------------------------------
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS slug VARCHAR(200) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS sku VARCHAR(60) NULL AFTER slug,
    ADD COLUMN IF NOT EXISTS low_stock_threshold INT NULL AFTER stock;

-- ---------------------------------------------------------
-- Product images (§11 "Support: multiple images - primary image")
--
-- products.image is kept as the canonical primary image so nothing that reads
-- it breaks; this table holds the full gallery. Migration 005 seeds one row
-- per existing product from products.image.
--
-- ON DELETE CASCADE is correct here — unlike order history, a gallery image
-- has no meaning once its product row is gone. Products are soft-deleted
-- anyway, so this only fires on a genuine hard delete.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    alt_text VARCHAR(200) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_product_images_product (product_id),
    INDEX idx_product_images_primary (product_id, is_primary),
    INDEX idx_product_images_sort (product_id, sort_order)
) ENGINE=InnoDB;
