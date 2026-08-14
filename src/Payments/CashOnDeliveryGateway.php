<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Cash on delivery (PROJECT_RULES.md §9 "Implement methods independently:
 * Cash on Delivery, bKash, Card, future providers").
 *
 * COD has no online authorization step — money changes hands physically when
 * the courier hands over the parcel. So:
 *   - createPayment() records a PENDING transaction; there is nothing to
 *     charge yet.
 *   - The transaction moves to PAID when the order is marked Delivered
 *     (wired in OrderRepository::transitionStatus), not here — this class has
 *     no way to know delivery happened.
 *   - refundPayment() cannot move real money (there is no online charge to
 *     reverse); it records a REFUNDED ledger entry so the transaction history
 *     stays accurate, with a message making clear the actual cash refund is a
 *     manual, out-of-band process for staff to complete.
 */
final class CashOnDeliveryGateway implements PaymentGateway
{
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::PENDING,
            message: 'Cash on delivery — payment collected on receipt of the parcel.'
        );
    }

    public function verifyPayment(string $transactionReference): PaymentResult
    {
        // There is no third party to verify against; the ledger row already
        // holds the truth for COD (updated when the order is marked Delivered).
        return new PaymentResult(
            status: PaymentStatus::PENDING,
            message: 'Cash on delivery has no external status to verify.'
        );
    }

    public function cancelPayment(string $transactionReference): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::CANCELLED,
            message: 'Order cancelled before delivery; no cash was collected.'
        );
    }

    public function refundPayment(string $transactionReference, ?string $amount = null): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::REFUNDED,
            message: 'Recorded as refunded. Cash on delivery has no online charge to reverse — '
                . 'complete the physical refund to the customer manually.'
        );
    }
}
