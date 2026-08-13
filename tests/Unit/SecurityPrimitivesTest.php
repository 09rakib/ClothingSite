<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Orders\PaymentMethod;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\OneTimeToken;
use App\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Security primitives introduced in Phase 0 (§24 "Security tests").
 *
 * These run in CLI where PHP has no session cookie, so $_SESSION is driven
 * directly — the classes read and write the same superglobal either way.
 */
final class SecurityPrimitivesTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST    = [];
    }

    /* ---------------- CSRF ---------------- */

    public function test_csrf_token_is_stable_within_a_session(): void
    {
        $first  = Csrf::token();
        $second = Csrf::token();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first), 'Token should be 32 random bytes hex-encoded.');
    }

    public function test_csrf_accepts_only_the_matching_token(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::isValid($token));
        $this->assertFalse(Csrf::isValid('wrong-token'));
        $this->assertFalse(Csrf::isValid(''));
        $this->assertFalse(Csrf::isValid(null));
    }

    public function test_csrf_rejects_everything_when_no_token_was_issued(): void
    {
        $_SESSION = [];

        $this->assertFalse(Csrf::isValid('anything'));
    }

    public function test_csrf_rotate_invalidates_the_previous_token(): void
    {
        $original = Csrf::token();
        Csrf::rotate();

        $this->assertFalse(Csrf::isValid($original), 'A pre-login token must not survive rotation.');
        $this->assertTrue(Csrf::isValid(Csrf::token()));
    }

    public function test_csrf_field_renders_escaped_hidden_input(): void
    {
        $html = Csrf::field();

        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString(Csrf::token(), $html);
    }

    /* ---------------- Idempotency ---------------- */

    /**
     * This is the guarantee that stops a double-clicked Buy Now button from
     * creating two orders (§8).
     */
    public function test_one_time_token_can_only_be_consumed_once(): void
    {
        $token = OneTimeToken::issue('place_order');

        $this->assertTrue(OneTimeToken::consume('place_order', $token), 'First use must succeed.');
        $this->assertFalse(OneTimeToken::consume('place_order', $token), 'Replay must be rejected.');
    }

    public function test_one_time_token_is_scoped_to_its_action(): void
    {
        $token = OneTimeToken::issue('place_order');

        $this->assertFalse(OneTimeToken::consume('delete_product', $token));
        $this->assertTrue(OneTimeToken::consume('place_order', $token));
    }

    public function test_one_time_token_rejects_unknown_and_empty_values(): void
    {
        OneTimeToken::issue('place_order');

        $this->assertFalse(OneTimeToken::consume('place_order', 'never-issued'));
        $this->assertFalse(OneTimeToken::consume('place_order', ''));
        $this->assertFalse(OneTimeToken::consume('place_order', null));
    }

    /* ---------------- Rate limiting ---------------- */

    public function test_rate_limiter_blocks_after_the_configured_attempts(): void
    {
        $key = 'login:test@example.com';
        $max = (int) Config::get('security.login_max_attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            $this->assertFalse(RateLimiter::tooManyAttempts($key), "Attempt {$i} should be allowed.");
            RateLimiter::hit($key);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($key), 'Should lock out after max attempts.');
    }

    public function test_rate_limiter_clears_on_success(): void
    {
        $key = 'login:clear@example.com';

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($key);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts($key));

        RateLimiter::clear($key);

        $this->assertFalse(RateLimiter::tooManyAttempts($key));
    }

    /* ---------------- Payment methods ---------------- */

    /**
     * §9 forbids hardcoding cash_on_delivery. The order service must reject a
     * method that is not switched on in config.
     */
    public function test_only_enabled_payment_methods_are_accepted(): void
    {
        $this->assertTrue(PaymentMethod::isEnabled('cash_on_delivery'));
        $this->assertFalse(PaymentMethod::isEnabled('bkash'), 'bKash is disabled in config.');
        $this->assertFalse(PaymentMethod::isEnabled('totally_made_up'));
    }

    public function test_default_payment_method_is_enabled(): void
    {
        $this->assertTrue(PaymentMethod::isEnabled(PaymentMethod::default()));
    }
}
