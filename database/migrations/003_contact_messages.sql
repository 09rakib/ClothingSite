-- =========================================================
-- 003 — Contact messages
--
-- WHY this is in Phase 0 rather than waiting for the Phase 6 support inbox:
-- contact.php displayed "Thanks for reaching out! We'll get back to you soon."
-- without storing or sending anything. PROJECT_RULES.md Rule 12 ("No fake
-- success") and §22 ("Never trust a success message that is shown before the
-- operation actually succeeds") make that a defect, not a missing feature.
--
-- This table is the minimum needed for the confirmation to be truthful. The
-- full ticketing workflow (assignment, replies, notifications) remains Phase 6;
-- the `status` column is included now so that work does not need a schema
-- change to the same table later.
-- =========================================================

CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL UNIQUE,     -- shown to the sender so they can quote it
    user_id INT NULL,                          -- set when the sender was logged in
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'new',
    ip_address VARBINARY(16) NULL,             -- for spam/rate-limit investigation
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_contact_status (status),
    INDEX idx_contact_created_at (created_at),
    INDEX idx_contact_email (email)
) ENGINE=InnoDB;
