<?php

declare(strict_types=1);

namespace App\Payments;

use App\Orders\PaymentMethod;
use RuntimeException;

/**
 * Resolves a payment method key to its PaymentGateway implementation
 * (PROJECT_RULES.md §9 — order code asks for a gateway, never for a provider
 * by name).
 *
 * Adding a real bKash or card integration later means writing one class that
 * implements PaymentGateway and registering it here — nothing in
 * OrderService, checkout.php, or the order status machine changes.
 */
final class PaymentGatewayFactory
{
    public static function for(string $method): PaymentGateway
    {
        if (!PaymentMethod::isEnabled($method)) {
            throw new RuntimeException("The \"{$method}\" payment method is not available.");
        }

        return match ($method) {
            'cash_on_delivery' => new CashOnDeliveryGateway(),
            // Registered but inert until real credentials exist — see
            // UnconfiguredGateway's docblock. Config keeps both disabled, so
            // PaymentMethod::isEnabled() above already blocks a real checkout
            // from reaching this branch; it exists for completeness and for
            // admin tooling that may probe a specific gateway directly.
            'bkash' => new UnconfiguredGateway('bKash'),
            'card'  => new UnconfiguredGateway('card'),
            default => throw new RuntimeException("Unknown payment method: {$method}"),
        };
    }
}
