<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session bootstrapping with hardened cookie flags
 * (PROJECT_RULES.md §19 "Secure session cookies / HttpOnly / SameSite").
 *
 * WHY centralised: the old code repeated `if (session_status() === ...)`
 * in seven files, so cookie hardening could only be applied in one of them.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Cookie params must be set before the session starts to take effect.
        if (PHP_SAPI !== 'cli') {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => (bool) Config::get('security.cookie_httponly', true),
                'samesite' => (string) Config::get('security.cookie_samesite', 'Lax'),
                'secure'   => (bool) Config::get('security.cookie_secure', false),
            ]);
        }

        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Fully destroy the session, including its cookie.
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
