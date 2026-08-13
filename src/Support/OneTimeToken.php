<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single-use form tokens providing idempotency
 * (PROJECT_RULES.md §8 "double-clicking Place Order must not create duplicate
 * orders").
 *
 * WHY separate from Csrf: the CSRF token deliberately stays valid for the
 * whole session so a user can have several tabs open. Idempotency needs the
 * opposite property — a token that is destroyed the first time it is used.
 * Combining the two would break one guarantee or the other.
 *
 * Usage:
 *   In the form:   OneTimeToken::field('place_order')
 *   On submit:     if (!OneTimeToken::consume('place_order', $_POST['_once'] ?? null)) { ...duplicate... }
 */
final class OneTimeToken
{
    private const SESSION_KEY = '_once_tokens';
    private const FIELD_NAME  = '_once';

    /** Tokens older than this are discarded to stop the session growing. */
    private const TTL_SECONDS = 3600;

    /**
     * Issue a token for a named action and render it as a hidden input.
     */
    public static function field(string $action): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD_NAME,
            htmlspecialchars(self::issue($action), ENT_QUOTES, 'UTF-8')
        );
    }

    public static function issue(string $action): string
    {
        Session::start();
        self::prune();

        $token = bin2hex(random_bytes(16));
        $_SESSION[self::SESSION_KEY][$action][$token] = time();

        return $token;
    }

    /**
     * Consume a token. Returns true only the first time a given token is
     * presented; every later attempt (a refresh or double-click) returns false.
     */
    public static function consume(string $action, ?string $token): bool
    {
        Session::start();

        if (!is_string($token) || $token === '') {
            return false;
        }

        if (!isset($_SESSION[self::SESSION_KEY][$action][$token])) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY][$action][$token]);

        return true;
    }

    /**
     * Read the token out of the submitted form.
     */
    public static function fromRequest(): ?string
    {
        $token = $_POST[self::FIELD_NAME] ?? null;

        return is_string($token) ? $token : null;
    }

    /**
     * Drop expired tokens so a long-lived session does not accumulate them.
     */
    private static function prune(): void
    {
        $cutoff = time() - self::TTL_SECONDS;

        foreach ($_SESSION[self::SESSION_KEY] ?? [] as $action => $tokens) {
            foreach ($tokens as $token => $issuedAt) {
                if ($issuedAt < $cutoff) {
                    unset($_SESSION[self::SESSION_KEY][$action][$token]);
                }
            }
            if (empty($_SESSION[self::SESSION_KEY][$action])) {
                unset($_SESSION[self::SESSION_KEY][$action]);
            }
        }
    }
}
