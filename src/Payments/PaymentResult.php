<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * The outcome of a gateway operation. Always returned, never thrown, for an
 * ordinary decline or refund failure — see PaymentGateway's docblock.
 */
final class PaymentResult
{
    /**
     * @param array<string,mixed> $metadata Gateway-specific detail for support
     *        investigation. Must never contain a card number, CVV, or other
     *        raw payment credential (§9).
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $transactionReference = null,
        public readonly ?string $message = null,
        public readonly array $metadata = []
    ) {
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [PaymentStatus::PAID, PaymentStatus::AUTHORIZED], true);
    }
}
