<?php

declare(strict_types=1);

namespace App\Users;

use App\Support\Database;
use mysqli;
use RuntimeException;

/**
 * Admin user management (PROJECT_RULES.md §16 "User management").
 *
 * Accounts are suspended, never deleted, from this page — a hard delete would
 * either fail against the orders.user_id RESTRICT foreign key (Phase 0's own
 * fix) or require cascading away a customer's order history, which Rule 10
 * forbids. Suspension blocks login while preserving everything.
 */
final class UserRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function paginate(string $search = '', int $page = 1, int $perPage = 20): array
    {
        $where  = [];
        $params = [];
        $types  = '';

        if ($search !== '') {
            $where[]  = '(name LIKE ? OR email LIKE ?)';
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            $params   = [$like, $like];
            $types   .= 'ss';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM users {$whereSql}");
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $perPage = max(1, min($perPage, 100));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $sql = "SELECT id, name, email, phone, role, status, created_at,
                       (SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id) AS order_count
                FROM users
                {$whereSql}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";

        $stmt       = $this->db->prepare($sql);
        $listParams = [...$params, $perPage, $offset];
        $stmt->bind_param($types . 'ii', ...$listParams);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function countAdmins(): int
    {
        return (int) $this->db
            ->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND status = 'active'")
            ->fetch_assoc()['c'];
    }

    /**
     * Suspend an account, blocking future logins.
     *
     * @throws RuntimeException if this would leave the store with no active admin.
     */
    public function suspend(int $userId): void
    {
        $user = $this->find($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        if ($user['role'] === 'admin' && $this->countAdmins() <= 1) {
            throw new RuntimeException('Cannot suspend the only active admin account.');
        }

        $stmt = $this->db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function reactivate(int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Promote a customer to admin, or demote an admin back to customer.
     *
     * @throws RuntimeException if this would demote the last active admin.
     */
    public function setRole(int $userId, string $role): void
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            throw new RuntimeException('Unknown role.');
        }

        $user = $this->find($userId);
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        if ($user['role'] === 'admin' && $role === 'user' && $this->countAdmins() <= 1) {
            throw new RuntimeException('Cannot demote the only active admin account.');
        }

        $stmt = $this->db->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->bind_param('si', $role, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function isActive(int $userId): bool
    {
        $user = $this->find($userId);

        return $user !== null && $user['status'] === 'active';
    }
}
