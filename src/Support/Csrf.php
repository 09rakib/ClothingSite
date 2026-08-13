<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF protection for every state-changing browser form
 * (PROJECT_RULES.md §19 "CSRF", Phase 0).
 *
 * WHY a per-session token rather than per-form: it is simple enough that no
 * developer is tempted to skip it, while still blocking cross-origin POSTs.
 * The token is compared with hash_equals() so the check is not vulnerable to
 * timing analysis.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    /**
     * Return the current token, generating one on first use.
     */
    public static function token(): string
    {
        Session::start();

        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Hidden input to drop inside every POST form.
     */
    public static function field(): string
    {
        $name = (string) Config::get('security.csrf_token_name', '_token');

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Constant-time comparison of a submitted token against the session token.
     */
    public static function isValid(?string $submitted): bool
    {
        Session::start();

        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Abort the request unless the POST body carries a valid token.
     *
     * Called by Http::requirePost() so protecting an endpoint is one line.
     */
    public static function verifyRequest(): void
    {
        $name  = (string) Config::get('security.csrf_token_name', '_token');
        $token = $_POST[$name] ?? null;

        if (!self::isValid(is_string($token) ? $token : null)) {
            Logger::warning('CSRF token mismatch', [
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // 403 rather than Laravel's non-standard 419: Apache rewrites
            // status codes it does not recognise to 500, which would report a
            // successful security block as a server fault.
            http_response_code(403);
            exit('Your session has expired or the request could not be verified. Please go back, reload the page and try again.');
        }
    }

    /**
     * Rotate the token — call after login/logout so a token captured before
     * authentication cannot be replayed afterwards.
     */
    public static function rotate(): void
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
