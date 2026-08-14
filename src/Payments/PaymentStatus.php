<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Payment transaction states (PROJECT_RULES.md §9: "Use payment states such
 * as: pending, authorized, paid, failed, cancelled, refunded,
 * partially_refunded").
 */
final class PaymentStatus
{
    public const PENDING             = 'pending';
    public const AUTHORIZED          = 'authorized';
    public const PAID                = 'paid';
    public const FAILED              = 'failed';
    public const CANCELLED           = 'cancelled';
    public const REFUNDED            = 'refunded';
    public const PARTIALLY_REFUNDED  = 'partially_refunded';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::AUTHORIZED,
            self::PAID,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING            => 'Pending',
            self::AUTHORIZED         => 'Authorized',
            self::PAID               => 'Paid',
            self::FAILED             => 'Failed',
            self::CANCELLED          => 'Cancelled',
            self::REFUNDED           => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially Refunded',
            default                  => ucfirst($status),
        };
    }
}
