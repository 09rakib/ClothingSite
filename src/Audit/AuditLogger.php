<?php

declare(strict_types=1);

namespace App\Audit;

use App\Support\Database;
use mysqli;

/**
 * Business audit trail (PROJECT_RULES.md §23 "Separate technical logs from
 * business audit logs").
 *
 * WHY this is separate from App\Support\Logger: Logger writes to a rotating
 * text file for developers chasing an exception. This writes to a queryable
 * table for "who archived this product, and when" — the audience, retention
 * needs, and query patterns are different, which is exactly the distinction
 * §23 draws.
 *
 * A logging failure must never break the admin action it is recording —
 * every call site wraps this in a way that a write error here cannot bubble
 * up and undo, say, a status change that already committed.
 */
final class AuditLogger
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @param array<string,mixed> $metadata Never include passwords, tokens,
     *        or card data here (§23).
     */
    public function log(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $metadata = []
    ): void {
        $json = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ip   = isset($_SERVER['REMOTE_ADDR']) ? @inet_pton((string) $_SERVER['REMOTE_ADDR']) : null;
        $ip   = $ip !== false ? $ip : null;

        // 's' is binary-safe in mysqli's bind_param (no null-termination),
        // so the packed IP address does not need send_long_data — that API
        // exists for streaming genuinely large blobs, not a 4/16-byte value.
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ississ', $actorId, $action, $entityType, $entityId, $json, $ip);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Most recent entries, optionally filtered to one entity, for the admin
     * audit log viewer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(?string $entityType = null, ?int $entityId = null, int $limit = 100): array
    {
        $where  = [];
        $params = [];
        $types  = '';

        if ($entityType !== null) {
            $where[]  = 'entity_type = ?';
            $params[] = $entityType;
            $types   .= 's';
        }
        if ($entityId !== null) {
            $where[]  = 'entity_id = ?';
            $params[] = $entityId;
            $types   .= 'i';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $limit    = max(1, min($limit, 500));

        $sql = "SELECT a.*, u.name AS actor_name
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.actor_id
                {$whereSql}
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT ?";

        $stmt        = $this->db->prepare($sql);
        $listParams  = [...$params, $limit];
        $stmt->bind_param($types . 'i', ...$listParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}
