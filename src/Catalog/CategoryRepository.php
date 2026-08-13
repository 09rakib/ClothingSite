<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Database;
use mysqli;

/**
 * Category data access.
 *
 * Kept separate from ProductRepository so category management (Phase 6) has a
 * natural home and product queries do not grow a second responsibility.
 */
final class CategoryRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    public function all(): array
    {
        $rows = $this->db->query('SELECT id, name FROM categories ORDER BY name ASC')
            ->fetch_all(MYSQLI_ASSOC);

        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows
        );
    }

    /**
     * Categories with a count of their visible products — used to build the
     * storefront filter without an N+1 query per category.
     *
     * @return array<int,array{id:int,name:string,product_count:int}>
     */
    public function allWithProductCounts(): array
    {
        $rows = $this->db->query(
            "SELECT c.id, c.name, COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN products p
                    ON p.category_id = c.id
                   AND p.deleted_at IS NULL
                   AND p.status = 'active'
             GROUP BY c.id, c.name
             ORDER BY c.name ASC"
        )->fetch_all(MYSQLI_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'product_count' => (int) $row['product_count'],
            ],
            $rows
        );
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM categories WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $found;
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
