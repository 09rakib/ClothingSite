-- =========================================================
-- 011 — Password reset, wishlist, reviews (Phase 5)
-- =========================================================

-- ---------------------------------------------------------
-- Password reset tokens (§19 "Password reset with expiring single-use
-- tokens").
--
-- Only a SHA-256 hash of the token is ever stored. The raw token exists only
-- in the emailed link and in memory for the moment it is checked — if this
-- table were ever exposed, the hashes alone cannot be used to reset anyone's
-- password (the same principle as never storing a plain-text user password).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE KEY uniq_password_reset_tokens_hash (token_hash),
    INDEX idx_password_reset_tokens_user (user_id),
    INDEX idx_password_reset_tokens_expires (expires_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Wishlist (§15). One flat table rather than a header + items pair: unlike
-- the cart, there is no guest wishlist (it requires an account) and no
-- concept of quantity, so a header row would carry nothing a header needs to.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS wishlist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_wishlist_items_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    -- A wishlist entry has no historical value once the product is truly
    -- gone; unlike order_items it is not a financial record, so CASCADE
    -- (rather than the RESTRICT used for order history) is correct here.
    CONSTRAINT fk_wishlist_items_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE KEY uniq_wishlist_items_user_product (user_id, product_id),
    INDEX idx_wishlist_items_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Reviews (§14). One row per customer per product — "business rules allow
-- updates" (§14), so a customer edits their existing review rather than
-- accumulating duplicates, enforced by the unique key.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(120) NULL,
    body VARCHAR(2000) NOT NULL,

    -- Computed and stored at submission time from real order history — see
    -- ReviewRepository::eligibleProductIds(). Storing it means a later
    -- deletion of the order does not retroactively change what the review
    -- claims, and list queries never need to re-derive it with a join.
    verified_purchase TINYINT(1) NOT NULL DEFAULT 0,

    status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reviews_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reviews_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE KEY uniq_reviews_product_user (product_id, user_id),
    INDEX idx_reviews_product_status (product_id, status),
    CONSTRAINT chk_reviews_rating_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;
