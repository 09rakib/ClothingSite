<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small presentation helpers used by templates.
 *
 * WHY: `htmlspecialchars($x)` repeated hundreds of times is easy to forget
 * once, and one omission is an XSS hole. `View::e()` is short enough that
 * there is no incentive to skip it.
 */
final class View
{
    /**
     * Escape a value for HTML output.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format a money amount with the configured currency symbol.
     *
     * The symbol is a pre-escaped HTML entity from config, so it is
     * concatenated rather than escaped again.
     */
    public static function money(mixed $amount): string
    {
        $symbol = (string) Config::get('app.currency_symbol', '&#2547;');

        return $symbol . ' ' . number_format((float) $amount, 2);
    }

    /**
     * Human-readable label for a payment method key (§9 — no hardcoded
     * "Cash On Delivery" strings scattered through templates).
     */
    public static function paymentLabel(string $method): string
    {
        $label = Config::get('payments.methods.' . $method . '.label');

        return self::e(is_string($label) ? $label : ucwords(str_replace('_', ' ', $method)));
    }

    /**
     * Build a query string preserving current filters, overriding some keys.
     * Used by pagination and sort links so filters survive navigation.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $overrides
     */
    public static function queryString(array $current, array $overrides = []): string
    {
        $merged = array_merge($current, $overrides);
        $merged = array_filter(
            $merged,
            static fn($value): bool => $value !== null && $value !== ''
        );

        return $merged === [] ? '' : '?' . http_build_query($merged);
    }
}
