<?php

declare(strict_types=1);

namespace App\Payments;

use App\Support\Database;
use mysqli;

/**
 * Payment ledger data access (PROJECT_RULES.md §9 "Payment transaction
 * records").
 *
 * The idempotency key carries a UNIQUE index at the database level (migration
 * 009), so record() is safe to call more than once with the same key — the
 * second call simply returns the existing row instead of creating a duplicate
 * charge record. That is the actual idempotency guarantee; the check in code
 * below is a fast path, not the only line of defence.
 */
final class PaymentTransactionRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Record a new transaction, or return the existing one if this
     * idempotency key was already used.
     */
    public function record(
        int $orderId,
        string $gateway,
        string $status,
        string $amount,
        string $idempotencyKey,
        ?string $transactionReference = null,
        ?string $currency = 'BDT'
    ): int {
        $existing = $this->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO payment_transactions
                (order_id, gateway, status, amount, currency, idempotency_key, transaction_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issssss',
            $orderId,
            $gateway,
            $status,
            $amount,
            $currency,
            $idempotencyKey,
            $transactionReference
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function updateStatus(int $id, string $status, ?string $transactionReference = null): void
    {
        if ($transactionReference !== null) {
            $stmt = $this->db->prepare(
                'UPDATE payment_transactions SET status = ?, transaction_reference = ? WHERE id = ?'
            );
            $stmt->bind_param('ssi', $status, $transactionReference, $id);
        } else {
            $stmt = $this->db->prepare('UPDATE payment_transactions SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $status, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE idempotency_key = ? LIMIT 1');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_transactions WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * The transaction that represents an order's original charge — the
     * earliest row for that order, as opposed to any later refund entry.
     *
     * @return array<string,mixed>|null
     */
    public function latestForOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
