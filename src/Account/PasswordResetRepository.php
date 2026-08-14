<?php

declare(strict_types=1);

namespace App\Account;

use App\Support\Database;
use mysqli;

/**
 * Password reset token storage (PROJECT_RULES.md §19 "Password reset with
 * expiring single-use tokens").
 *
 * Only a hash of the token is ever persisted — see the migration's comment.
 * The raw token is generated here, returned once to the caller (who emails
 * it), and never stored anywhere.
 */
final class PasswordResetRepository
{
    private const TOKEN_TTL_MINUTES = 60;

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Issue a fresh token for a user, invalidating any previous ones so only
     * the most recently requested link can ever be used.
     *
     * @return string the RAW token — put this in the emailed link, never store it.
     */
    public function issue(int $userId): string
    {
        $invalidate = $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_TTL_MINUTES . ' MINUTE))'
        );
        $stmt->bind_param('is', $userId, $hash);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    public static function ttlMinutes(): int
    {
        return self::TOKEN_TTL_MINUTES;
    }

    /**
     * Resolve a raw token (from the URL) to its owning user id, only if it is
     * unused and not expired.
     */
    public function userIdForValidToken(string $rawToken): ?int
    {
        $hash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Mark a token consumed so it cannot be replayed to reset the password
     * twice.
     */
    public function consume(string $rawToken): void
    {
        $hash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = ?'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
    }
}
