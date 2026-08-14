<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Account\PasswordResetRepository;

/**
 * Password reset tokens (PROJECT_RULES.md §19 "expiring single-use tokens").
 */
final class PasswordResetTest extends DatabaseTestCase
{
    private PasswordResetRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PasswordResetRepository($this->db);
    }

    public function test_issued_token_resolves_to_the_user(): void
    {
        $userId = $this->createUser();

        $token = $this->repo->issue($userId);

        $this->assertSame($userId, $this->repo->userIdForValidToken($token));
    }

    /**
     * Only a hash may ever be found in the table — the raw token must never
     * be persisted anywhere (migration 011's design rule).
     */
    public function test_only_a_hash_is_stored_not_the_raw_token(): void
    {
        $userId = $this->createUser();
        $token  = $this->repo->issue($userId);

        $row = $this->db->query('SELECT token_hash FROM password_reset_tokens ORDER BY id DESC LIMIT 1')->fetch_assoc();

        $this->assertNotSame($token, $row['token_hash']);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function test_a_garbage_token_resolves_to_nothing(): void
    {
        $this->assertNull($this->repo->userIdForValidToken('not-a-real-token'));
    }

    /**
     * The exact guarantee "single-use" requires: consuming a token, then
     * presenting it again, must fail the second time.
     */
    public function test_consumed_token_cannot_be_used_again(): void
    {
        $userId = $this->createUser();
        $token  = $this->repo->issue($userId);

        $this->assertSame($userId, $this->repo->userIdForValidToken($token));

        $this->repo->consume($token);

        $this->assertNull($this->repo->userIdForValidToken($token), 'A consumed token must not resolve again.');
    }

    public function test_expired_token_does_not_resolve(): void
    {
        $userId = $this->createUser();
        $token  = $this->repo->issue($userId);

        // Force the row into the past directly, since issue() always sets a
        // future expiry — this is the only way to exercise expiry in a test.
        $this->db->query('UPDATE password_reset_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)');

        $this->assertNull($this->repo->userIdForValidToken($token), 'An expired token must not resolve.');
    }

    /**
     * Requesting a new reset link must invalidate any earlier one, so an
     * old, possibly-forwarded email link stops working.
     */
    public function test_issuing_a_new_token_invalidates_the_previous_one(): void
    {
        $userId = $this->createUser();
        $first  = $this->repo->issue($userId);
        $second = $this->repo->issue($userId);

        $this->assertNull($this->repo->userIdForValidToken($first), 'The earlier token must be invalidated.');
        $this->assertSame($userId, $this->repo->userIdForValidToken($second));
    }
}
