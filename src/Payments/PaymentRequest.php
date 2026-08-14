<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Everything a gateway needs to start a payment. A plain value object rather
 * than passing an Order around, so a gateway implementation cannot reach into
 * unrelated order fields it has no business touching.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderReference,
        public readonly string $amount,
        public readonly string $idempotencyKey,
        public readonly string $currency = 'BDT'
    ) {
    }
}
