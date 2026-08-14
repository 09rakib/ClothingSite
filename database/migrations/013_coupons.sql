-- =========================================================
-- 013 — Coupons (Phase 7)
--
-- WHY coupons but not a full "promotions" campaign engine: §29 lists
-- "promotions if a more advanced campaign system is required" as
-- conditional, and nothing in this project has such a requirement yet.
-- Coupons cover the concrete need (give a customer a discount code); a
-- broader automatic-sale/BOGO engine would be exactly the unneeded
-- complexity Rule "do not design for hypothetical future requirements"
-- warns against. Extending later needs only new coupon 'type' values, not a
-- redesign.
-- =========================================================

CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    type ENUM('percent', 'fixed') NOT NULL,
    value DECIMAL(10,2) NOT NULL,           -- percent (0-100) or a fixed BDT amount, per `type`
    min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    usage_limit INT NULL,                   -- NULL = unlimited
    used_count INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at TIMESTAMP NULL DEFAULT NULL, -- NULL = never expires
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_coupons_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,

    UNIQUE KEY uniq_coupons_code (code),
    INDEX idx_coupons_active (active),
    CONSTRAINT chk_coupons_value_non_negative CHECK (value >= 0),
    CONSTRAINT chk_coupons_used_count_non_negative CHECK (used_count >= 0)
) ENGINE=InnoDB;

-- One row per redemption — the historical record of which order got which
-- discount, kept even if the coupon itself is later deactivated or edited.
CREATE TABLE IF NOT EXISTS coupon_usages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT NOT NULL,
    order_id INT NOT NULL,
    user_id INT NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- RESTRICT: a redemption is part of an order's financial history
    -- (Rule 10), so the coupon it points to must not be hard-deletable while
    -- redemptions reference it. Deactivate instead (see `active` above).
    CONSTRAINT fk_coupon_usages_coupon
        FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_coupon_usages_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coupon_usages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,

    UNIQUE KEY uniq_coupon_usages_order (order_id), -- one coupon per order
    INDEX idx_coupon_usages_coupon (coupon_id),
    INDEX idx_coupon_usages_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Orders gain discount fields. `total` already exists (post-discount, what
-- was actually charged); `subtotal` already exists too (pre-discount). This
-- just names the coupon that produced the difference, for the receipt/detail
-- view and for analytics.
-- ---------------------------------------------------------
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(30) NULL AFTER total,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER coupon_code;
