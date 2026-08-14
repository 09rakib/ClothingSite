<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Payments\PaymentStatus;
use App\Payments\PaymentTransactionRepository;

/**
 * Payment ledger data access, in particular the idempotency guarantee
 * (PROJECT_RULES.md §9 "Payment transaction records", §8 "idempotency").
 */
final class PaymentTransactionTest extends DatabaseTestCase
{
    private PaymentTransactionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PaymentTransactionRepository($this->db);
    }

    /** Minimal order row to attach a transaction to. */
    private function makeOrder(): int
    {
        $userId = $this->createUser();
        $stmt   = $this->db->prepare(
            'INSERT INTO orders
                (order_reference, user_id, subtotal, total, payment_method,
                 recipient_name, phone, address_line1, city)
             VALUES ("ORD-FIXTURE01", ?, "100.00", "100.00", "cash_on_delivery", "T", "0170", "Road", "Dhaka")'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function test_record_creates_a_transaction(): void
    {
        $orderId = $this->makeOrder();

        $id = $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'ORD-FIXTURE01');

        $this->assertSame(1, $this->countRows('payment_transactions'));
        $found = $this->repo->findByIdempotencyKey('ORD-FIXTURE01');
        $this->assertSame($id, (int) $found['id']);
        $this->assertSame('pending', $found['status']);
    }

    /**
     * The exact guarantee the UNIQUE index on idempotency_key exists for: a
     * retried checkout must never create a second charge record.
     */
    public function test_recording_the_same_idempotency_key_twice_does_not_duplicate(): void
    {
        $orderId = $this->makeOrder();

        $first  = $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'ORD-FIXTURE01');
        $second = $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'ORD-FIXTURE01');

        $this->assertSame($first, $second, 'The second call must return the existing row, not create a new one.');
        $this->assertSame(1, $this->countRows('payment_transactions'));
    }

    public function test_update_status_changes_the_row(): void
    {
        $orderId = $this->makeOrder();
        $id      = $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'ORD-FIXTURE01');

        $this->repo->updateStatus($id, PaymentStatus::PAID);

        $this->assertSame('paid', $this->repo->findByIdempotencyKey('ORD-FIXTURE01')['status']);
    }

    public function test_for_order_returns_every_transaction_in_order(): void
    {
        $orderId = $this->makeOrder();
        $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'ORD-FIXTURE01');

        $rows = $this->repo->forOrder($orderId);

        $this->assertCount(1, $rows);
        $this->assertSame($orderId, (int) $rows[0]['order_id']);
    }

    public function test_latest_for_order_is_the_most_recent_row(): void
    {
        $orderId = $this->makeOrder();
        $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::PENDING, '100.00', 'key-1');
        $this->repo->record($orderId, 'cash_on_delivery', PaymentStatus::REFUNDED, '100.00', 'key-2');

        $latest = $this->repo->latestForOrder($orderId);

        $this->assertSame('key-2', $latest['idempotency_key']);
    }
}
