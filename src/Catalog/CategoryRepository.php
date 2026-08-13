<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Database;
use App\Support\Slugger;
use mysqli;

/**
 * Category data access and management (PROJECT_RULES.md §30 Phase 1
 * "Categories", §16 admin back office).
 */
final class CategoryRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int,array{id:int,name:string,slug:string,description:?string}>
     */
    public function all(): array
    {
        $rows = $this->db->query('SELECT id, name, slug, description FROM categories ORDER BY name ASC')
            ->fetch_all(MYSQLI_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id'          => (int) $row['id'],
                'name'        => (string) $row['name'],
                'slug'        => (string) ($row['slug'] ?? ''),
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
            ],
            $rows
        );
    }

    /**
     * Categories with a count of their visible products — used to build the
     * storefront filter without an N+1 query per category.
     *
     * @return array<int,array{id:int,name:string,slug:string,product_count:int}>
     */
    public function allWithProductCounts(): array
    {
        $rows = $this->db->query(
            "SELECT c.id, c.name, c.slug, COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN products p
                    ON p.category_id = c.id
                   AND p.deleted_at IS NULL
                   AND p.status = 'active'
             GROUP BY c.id, c.name, c.slug
             ORDER BY c.name ASC"
        )->fetch_all(MYSQLI_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'slug'          => (string) ($row['slug'] ?? ''),
                'product_count' => (int) $row['product_count'],
            ],
            $rows
        );
    }

    /**
     * Admin listing: includes archived products in the count so a category is
     * not shown as empty when it still holds archived stock.
     *
     * @return array<int,array{id:int,name:string,slug:string,description:?string,product_count:int,active_count:int}>
     */
    public function allForAdmin(): array
    {
        $rows = $this->db->query(
            "SELECT c.id, c.name, c.slug, c.description,
                    COUNT(p.id) AS product_count,
                    SUM(CASE WHEN p.deleted_at IS NULL AND p.status = 'active' THEN 1 ELSE 0 END) AS active_count
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id, c.name, c.slug, c.description
             ORDER BY c.name ASC"
        )->fetch_all(MYSQLI_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'slug'          => (string) ($row['slug'] ?? ''),
                'description'   => $row['description'] !== null ? (string) $row['description'] : null,
                'product_count' => (int) $row['product_count'],
                'active_count'  => (int) $row['active_count'],
            ],
            $rows
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, slug, description FROM categories WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, slug, description FROM categories WHERE slug = ? LIMIT 1');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function create(string $name, ?string $description = null): int
    {
        $slug = Slugger::unique($this->db, $name, 'categories', 'slug', null, 'category');

        $stmt = $this->db->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $slug, $description);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Rename a category.
     *
     * The slug is regenerated only when the name actually changed, so existing
     * category URLs stay valid across unrelated edits (e.g. a description fix).
     */
    public function update(int $id, string $name, ?string $description = null): void
    {
        $existing = $this->find($id);
        $slug     = (string) ($existing['slug'] ?? '');

        if ($existing === null || (string) $existing['name'] !== $name || $slug === '') {
            $slug = Slugger::unique($this->db, $name, 'categories', 'slug', $id, 'category');
        }

        $stmt = $this->db->prepare('UPDATE categories SET name = ?, slug = ?, description = ? WHERE id = ?');
        $stmt->bind_param('sssi', $name, $slug, $description, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Delete a category.
     *
     * Safe because products.category_id is ON DELETE SET NULL: the products
     * survive and simply become uncategorised. Callers should still warn the
     * admin how many products will be affected (§6.3 "intentional ON DELETE
     * behavior").
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * How many products (including archived) reference this category.
     */
    public function productCount(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM products WHERE category_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $count;
    }

    /**
     * True when another category already uses this name.
     */
    public function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE name = ?';
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if ($ignoreId !== null) {
            $stmt->bind_param('si', $name, $ignoreId);
        } else {
            $stmt->bind_param('s', $name);
        }
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $taken;
    }

    public function exists(int $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * Valid category ids, used by the validator to reject a forged value.
     *
     * @return array<int,string>
     */
    public function validIds(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['id'],
            $this->all()
        );
    }
}
