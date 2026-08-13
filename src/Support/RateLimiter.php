<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session-backed attempt throttling (PROJECT_RULES.md §19 "Login rate limiting").
 *
 * WHY session-backed: it stops the common case — a script hammering the login
 * form in one browser session — without adding a database table or a Redis
 * dependency that §25 explicitly warns against introducing prematurely.
 *
 * LIMITATION, stated honestly: an attacker who discards cookies between
 * requests gets a fresh counter. Blocking that properly needs a shared store
 * keyed by IP, which belongs with the Phase 8 hardening work. This is a real
 * improvement over no limit at all, not a complete defence.
 */
final class RateLimiter
{
    private const SESSION_KEY = '_rate_limits';

    /**
     * True when the caller should be refused for now.
     */
    public static function tooManyAttempts(string $key, ?int $maxAttempts = null, ?int $decaySeconds = null): bool
    {
        $maxAttempts  ??= (int) Config::get('security.login_max_attempts', 5);
        $decaySeconds ??= (int) Config::get('security.login_lockout_secs', 900);

        $entry = self::entry($key);

        // Window expired — forget the old attempts.
        if ($entry['first_at'] + $decaySeconds < time()) {
            self::clear($key);

            return false;
        }

        return $entry['count'] >= $maxAttempts;
    }

    /**
     * Record one failed attempt.
     */
    public static function hit(string $key, ?int $decaySeconds = null): void
    {
        $decaySeconds ??= (int) Config::get('security.login_lockout_secs', 900);

        Session::start();
        $entry = self::entry($key);

        if ($entry['first_at'] + $decaySeconds < time()) {
            $entry = ['count' => 0, 'first_at' => time()];
        }

        $entry['count']++;
        $_SESSION[self::SESSION_KEY][$key] = $entry;
    }

    /**
     * Seconds until the caller may try again.
     */
    public static function secondsRemaining(string $key, ?int $decaySeconds = null): int
    {
        $decaySeconds ??= (int) Config::get('security.login_lockout_secs', 900);
        $entry = self::entry($key);

        return max(0, ($entry['first_at'] + $decaySeconds) - time());
    }

    /**
     * Reset after a successful attempt.
     */
    public static function clear(string $key): void
    {
        Session::start();
        unset($_SESSION[self::SESSION_KEY][$key]);
    }

    /**
     * @return array{count:int,first_at:int}
     */
    private static function entry(string $key): array
    {
        Session::start();
        $entry = $_SESSION[self::SESSION_KEY][$key] ?? null;

        if (!is_array($entry) || !isset($entry['count'], $entry['first_at'])) {
            return ['count' => 0, 'first_at' => time()];
        }

        return ['count' => (int) $entry['count'], 'first_at' => (int) $entry['first_at']];
    }
}
