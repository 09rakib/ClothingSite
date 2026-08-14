<?php

declare(strict_types=1);

namespace App\Orders;

/**
 * The order lifecycle (PROJECT_RULES.md §7 "Order System Design").
 *
 * WHY a class instead of scattering status strings through the codebase:
 * §7 explicitly says "Do not allow arbitrary status changes. Define valid
 * transitions explicitly." A raw ENUM column enforces which VALUES are legal
 * but not which CHANGES are legal — nothing stops a click from moving an order
 * straight from "pending" to "delivered". This class is the one place that
 * knows the shipping lifecycle, so both the admin UI and OrderRepository ask
 * it the same question and can never disagree.
 */
final class OrderStatus
{
    public const PENDING    = 'pending';
    public const CONFIRMED  = 'confirmed';
    public const PROCESSING = 'processing';
    public const SHIPPED    = 'shipped';
    public const DELIVERED  = 'delivered';
    public const CANCELLED  = 'cancelled';
    public const FAILED     = 'failed';
    public const RETURNED   = 'returned';
    public const REFUNDED   = 'refunded';

    /**
     * Every legal status, in the order they normally occur.
     *
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::PROCESSING,
            self::SHIPPED,
            self::DELIVERED,
            self::CANCELLED,
            self::FAILED,
            self::RETURNED,
            self::REFUNDED,
        ];
    }

    /**
     * Allowed next statuses from a given status (§7's example transition table).
     *
     * @return array<int,string>
     */
    public static function allowedNext(string $status): array
    {
        return match ($status) {
            self::PENDING    => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED  => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::SHIPPED, self::CANCELLED],
            self::SHIPPED    => [self::DELIVERED, self::FAILED],
            self::DELIVERED  => [self::RETURNED],
            self::RETURNED   => [self::REFUNDED],
            // Cancelled, Failed and Refunded are terminal: nothing may follow.
            default          => [],
        };
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedNext($from), true);
    }

    public static function isTerminal(string $status): bool
    {
        return self::allowedNext($status) === [];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING    => 'Pending',
            self::CONFIRMED  => 'Confirmed',
            self::PROCESSING => 'Processing',
            self::SHIPPED    => 'Shipped',
            self::DELIVERED  => 'Delivered',
            self::CANCELLED  => 'Cancelled',
            self::FAILED     => 'Failed',
            self::RETURNED   => 'Returned',
            self::REFUNDED   => 'Refunded',
            default          => ucfirst($status),
        };
    }

    /**
     * CSS class hook for the status pill — grouped by what the colour should
     * communicate, not by the exact status (§27 "Never rely only on color").
     * The label text is always rendered alongside this, never the colour alone.
     */
    public static function cssClass(string $status): string
    {
        return match ($status) {
            self::DELIVERED               => 'status-active',
            self::CANCELLED, self::FAILED => 'status-archived',
            self::RETURNED, self::REFUNDED => 'status-low',
            default                        => 'status-pending',
        };
    }
}
