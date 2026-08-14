<?php

declare(strict_types=1);

namespace App\Account;

use App\Support\Database;
use mysqli;

/**
 * Customer address book (PROJECT_RULES.md §13, §30 Phase 5 "Address book").
 *
 * WHY this exists now, ahead of the rest of Phase 5: checkout cannot honestly
 * ask "where should this ship?" while the only address on file is whatever the
 * customer typed at registration, and §13 explicitly warns against storing
 * only one address on the user record. This repository is deliberately small
 * — it does not attempt profile editing or the rest of Phase 5.
 *
 * Every method that takes an address id also takes the owning user id and
 * checks it, so a forged id from the request can never touch another
 * customer's address (§19 "No IDOR vulnerabilities").
 */
final class AddressRepository
{
    /** Enough for any real customer; guards against runaway address lists. */
    public const MAX_PER_USER = 15;

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, recipient_name, phone, address_line1, address_line2, city, is_default
             FROM addresses
             WHERE user_id = ?
             ORDER BY is_default DESC, id DESC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function count(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM addresses WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $count;
    }

    /**
     * Fetch an address, scoped to its owner. Returns null for a wrong owner
     * exactly as it would for a nonexistent id, so a caller cannot use timing
     * or error differences to probe which ids exist.
     *
     * @return array<string,mixed>|null
     */
    public function findOwned(int $addressId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, label, recipient_name, phone, address_line1, address_line2, city, is_default
             FROM addresses
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('ii', $addressId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function defaultForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, recipient_name, phone, address_line1, address_line2, city, is_default
             FROM addresses
             WHERE user_id = ?
             ORDER BY is_default DESC, id DESC
             LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @param array{label:string,recipient_name:string,phone:string,address_line1:string,address_line2:?string,city:string} $data
     */
    public function create(int $userId, array $data, bool $makeDefault = false): int
    {
        // The very first address a customer saves becomes their default
        // automatically, so checkout always has something pre-selected.
        $makeDefault = $makeDefault || $this->count($userId) === 0;

        $stmt = $this->db->prepare(
            'INSERT INTO addresses (user_id, label, recipient_name, phone, address_line1, address_line2, city, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->bind_param(
            'issssss',
            $userId,
            $data['label'],
            $data['recipient_name'],
            $data['phone'],
            $data['address_line1'],
            $data['address_line2'],
            $data['city']
        );
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        if ($makeDefault) {
            $this->setDefault($id, $userId);
        }

        return $id;
    }

    public function update(int $addressId, int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE addresses
             SET label = ?, recipient_name = ?, phone = ?, address_line1 = ?, address_line2 = ?, city = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param(
            'ssssssii',
            $data['label'],
            $data['recipient_name'],
            $data['phone'],
            $data['address_line1'],
            $data['address_line2'],
            $data['city'],
            $addressId,
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete an address. If it was the default, another address (if any) is
     * promoted, so a customer with saved addresses is never left without a
     * default silently.
     */
    public function delete(int $addressId, int $userId): void
    {
        $wasDefault = $this->findOwned($addressId, $userId)['is_default'] ?? false;

        $stmt = $this->db->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $addressId, $userId);
        $stmt->execute();
        $stmt->close();

        if ($wasDefault) {
            $next = $this->forUser($userId);
            if ($next !== []) {
                $this->setDefault((int) $next[0]['id'], $userId);
            }
        }
    }

    /**
     * Promote one address to default, demoting the rest.
     */
    public function setDefault(int $addressId, int $userId): void
    {
        if ($this->findOwned($addressId, $userId) === null) {
            return;
        }

        $clear = $this->db->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?');
        $clear->bind_param('i', $userId);
        $clear->execute();
        $clear->close();

        $set = $this->db->prepare('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?');
        $set->bind_param('ii', $addressId, $userId);
        $set->execute();
        $set->close();
    }
}
