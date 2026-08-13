<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Read-only access to config/config.php using dot notation.
 *
 * WHY: pages should ask for `Config::get('catalog.low_stock_threshold')`
 * rather than repeating a literal 5, so a business rule change happens in
 * exactly one place (PROJECT_RULES.md Rule 5).
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $items = null;

    /**
     * Load configuration once per request.
     *
     * @param array<string,mixed>|null $override Used by tests to inject config.
     */
    public static function load(?array $override = null): void
    {
        if ($override !== null) {
            self::$items = $override;
            return;
        }

        self::$items = require __DIR__ . '/../../config/config.php';
    }

    /**
     * Fetch a value using dot notation, e.g. "database.host".
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$items === null) {
            self::load();
        }

        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Fetch a value that the application cannot run without.
     */
    public static function require(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required configuration key: {$key}");
        }

        return $value;
    }
}
