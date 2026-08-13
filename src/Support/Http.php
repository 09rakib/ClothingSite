<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HTTP helpers enforcing correct method usage
 * (PROJECT_RULES.md §19 "HTTP methods", Phase 0).
 *
 * WHY: the old code deleted products and placed orders through plain <a href>
 * GET links. That is both a CSRF hole and a correctness bug — a crawler or a
 * link prefetcher can fire a state change. requirePost() makes the safe path
 * the easy path: one call gives method enforcement plus CSRF verification.
 */
final class Http
{
    /**
     * Reject anything that is not a CSRF-verified POST.
     */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('This action requires a POST request.');
        }

        Csrf::verifyRequest();
    }

    /**
     * True when the current request is a POST (used to branch in page files).
     */
    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Redirect and stop. Always exits so no code runs after a redirect.
     */
    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    /**
     * Send baseline security headers (§19 "Headers").
     *
     * CSP is intentionally permissive for inline styles/scripts because the
     * existing pages use them; tightening it requires extracting those first,
     * which is deliberately out of scope for Phase 0.
     */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if ((bool) Config::get('security.cookie_secure', false)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Read an integer from a request array, returning null when absent or
     * not a well-formed integer. Prevents "abc" silently becoming 0.
     */
    public static function intParam(array $source, string $key): ?int
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return null;
        }

        $value = trim((string) $source[$key]);
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
