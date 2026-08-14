-- =========================================================
-- 009 — Payment transaction ledger (Phase 4)
--
-- WHY: `orders.payment_status` (added in migration 007) is a single flag —
-- fine for "is this paid or not" but useless the moment there is more than
-- one attempt, a partial refund, or a second payment method. §9 asks for a
-- payment abstraction with real states (pending/authorized/paid/failed/
-- cancelled/refunded/partially_refunded) and for every provider to be
-- implemented independently behind it.
--
-- `orders.payment_status` is KEPT as a fast-read cache — exactly the same
-- pattern products.image caches the primary product_images row (migration
-- 004). This table is the source of truth; the cached column is updated
-- alongside it so existing pages that read orders.payment_status directly
-- keep working.
-- =========================================================

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,

    -- 'cash_on_delivery', 'bkash', 'card', ... — matches PaymentMethod keys.
    gateway VARCHAR(50) NOT NULL,

    status ENUM(
        'pending', 'authorized', 'paid', 'failed',
        'cancelled', 'refunded', 'partially_refunded'
    ) NOT NULL DEFAULT 'pending',

    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BDT',

    -- The gateway's own transaction id, once one exists (COD never gets one).
    transaction_reference VARCHAR(120) NULL,

    -- Guarantees exactly one transaction per checkout attempt even if the
    -- create-order transaction were somehow retried (§8 "idempotency
    -- protection", extended down to the payment layer as defense in depth
    -- alongside the checkout page's one-time token).
    idempotency_key VARCHAR(64) NOT NULL,

    -- Raw gateway response/detail for support investigation. Never used to
    -- store card numbers or CVV (§9 "Never store raw card numbers or CVV") —
    -- enforced by code review of what gateways are allowed to write here, not
    -- by the column itself.
    metadata TEXT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    -- CASCADE: a transaction is a child record of its order, the same
    -- relationship order_items has to orders. Orders themselves are never
    -- hard-deleted (Rule 10), so this only matters in theory.
    CONSTRAINT fk_payment_transactions_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,

    UNIQUE KEY uniq_payment_transactions_idempotency (idempotency_key),
    INDEX idx_payment_transactions_order (order_id),
    INDEX idx_payment_transactions_status (status)
) ENGINE=InnoDB;
