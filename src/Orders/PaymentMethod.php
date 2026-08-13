<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Config;

/**
 * Payment method registry (PROJECT_RULES.md §9 "Do not hardcode
 * cash_on_delivery").
 *
 * WHY this exists now rather than in Phase 4: the literal string
 * 'cash_on_delivery' was baked into singleorder.php, so adding bKash later
 * would have meant editing the order code itself. This class is the seam —
 * the order system asks which methods are enabled and validates the customer's
 * choice, without knowing anything about a specific provider.
 *
 * The full PaymentGateway interface (createPayment/verifyPayment/refund) is
 * deliberately deferred to Phase 4; this only removes the hardcoding.
 */
final class PaymentMethod
{
    /**
     * Methods currently switched on in config.
     *
     * @return array<string,string> key => human label
     */
    public static function enabled(): array
    {
        $methods = (array) Config::get('payments.methods', []);
        $enabled = [];

        foreach ($methods as $key => $definition) {
            if (!empty($definition['enabled'])) {
                $enabled[(string) $key] = (string) ($definition['label'] ?? $key);
            }
        }

        return $enabled;
    }

    /**
     * @return array<int,string>
     */
    public static function enabledKeys(): array
    {
        return array_keys(self::enabled());
    }

    public static function isEnabled(string $method): bool
    {
        return array_key_exists($method, self::enabled());
    }

    /**
     * The method to use when the customer did not choose one.
     */
    public static function default(): string
    {
        $default = (string) Config::get('payments.default_method', 'cash_on_delivery');

        if (self::isEnabled($default)) {
            return $default;
        }

        // Fall back to the first enabled method so checkout never breaks
        // because of a misconfigured default.
        $keys = self::enabledKeys();

        return $keys[0] ?? 'cash_on_delivery';
    }

    public static function label(string $method): string
    {
        return self::enabled()[$method]
            ?? ucwords(str_replace('_', ' ', $method));
    }
}
