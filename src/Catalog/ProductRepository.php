<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Config;
use App\Support\Database;
use mysqli;

/**
 * All product database access lives here (PROJECT_RULES.md §3.2
 * "Repositories/Query layer", §3.1 "no database queries scattered through views").
 *
 * WHY: the same `SELECT ... FROM products` appeared in index.php, shop.php and
 * two admin pages, each with slightly different columns and none of them aware
 * of soft deletes. Centralising the queries means the "archived products must
 * not appear in the storefront" rule is enforced in one place and cannot be
 * forgotten by a new page.
 */
final class ProductRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Storefront listing with search, category filter, sorting and pagination
     * (§12). All filtering happens in SQL against indexed columns.
     *
     * @param array{search?:string,category?:int|null,sort?:string,page?:int,per_page?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginateActive(array $filters = []): array
    {
        $search   = trim((string) ($filters['search'] ?? ''));
        $category = $filters['category'] ?? null;
        $sort     = (string) ($filters['sort'] ?? Config::get('catalog.default_sort', 'newest'));
        $perPage  = (int) ($filters['per_page'] ?? Config::get('catalog.products_per_page', 9));
        $page     = max(1, (int) ($filters['page'] ?? 1));

        $perPage = max(1, min($perPage, 60));

        // Only non-archived products are ever visible in the storefront.
        $where  = ['p.deleted_at IS NULL', "p.status = 'active'"];
        $params = [];
        $types  = '';

        if ($search !== '') {
            $where[]  = '(p.name LIKE ? OR p.description LIKE ?)';
            $like     = '%' . $this->escapeLike($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ss';
        }

        if ($category !== null && $category !== '' && (int) $category > 0) {
            $where[]  = 'p.category_id = ?';
            $params[] = (int) $category;
            $types   .= 'i';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Count first so we know how many pages exist.
        $countSql  = "SELECT COUNT(*) AS total FROM products p {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // ORDER BY cannot be parameterised, so it is mapped from a fixed
        // whitelist — a user-supplied sort value never reaches the SQL.
        $orderBy = $this->orderByClause($sort);

        $sql = "SELECT p.id, p.name, p.description, p.price, p.stock, p.image,
                       p.category_id, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                {$whereSql}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?";

        $stmt        = $this->db->prepare($sql);
        $listParams  = [...$params, $perPage, $offset];
        $stmt->bind_param($types . 'ii', ...$listParams);
        $stmt->execute();

        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Newest active products for the home page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function latestActive(int $limit = 3): array
    {
        $limit = max(1, min($limit, 24));

        $stmt = $this->db->prepare(
            "SELECT id, name, description, price, stock, image
             FROM products
             WHERE deleted_at IS NULL AND status = 'active'
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Find one active (purchasable) product.
     *
     * @return array<string,mixed>|null
     */
    public function findActive(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, description, price, stock, image, category_id
             FROM products
             WHERE id = ? AND deleted_at IS NULL AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Find any product including archived ones (admin use).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Admin listing — includes archived products so they can be restored.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allForAdmin(bool $includeArchived = true): array
    {
        $sql = "SELECT p.id, p.name, p.description, p.price, p.stock, p.image,
                       p.status, p.deleted_at, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id";

        if (!$includeArchived) {
            $sql .= " WHERE p.deleted_at IS NULL";
        }

        $sql .= ' ORDER BY p.deleted_at IS NOT NULL, p.created_at DESC';

        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    public function create(
        string $name,
        string $description,
        string $price,
        int $stock,
        string $image,
        ?int $categoryId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, stock, image, category_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssdisi', $name, $description, $price, $stock, $image, $categoryId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function update(
        int $id,
        string $name,
        string $description,
        string $price,
        int $stock,
        string $image,
        ?int $categoryId
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE products
             SET name = ?, description = ?, price = ?, stock = ?, image = ?, category_id = ?
             WHERE id = ?'
        );
        $stmt->bind_param('ssdisii', $name, $description, $price, $stock, $image, $categoryId, $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Soft delete — archive instead of destroying the row (§6.2, Rule 10).
     *
     * WHY not DELETE: order history references products. A hard delete would
     * either fail against the RESTRICT foreign key or, under the old CASCADE,
     * silently erase the customer's order history. Archiving hides the product
     * from the storefront while every past order stays intact and readable.
     */
    public function archive(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE products
             SET status = 'archived', deleted_at = NOW()
             WHERE id = ? AND deleted_at IS NULL"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    public function restore(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE products
             SET status = 'active', deleted_at = NULL
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * True when a product has order history and therefore must never be
     * hard-deleted.
     */
    public function hasOrders(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM single_order WHERE product_id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Dashboard counters.
     *
     * @return array{total:int,low_stock:int,archived:int,out_of_stock:int}
     */
    public function stats(): array
    {
        $threshold = (int) Config::get('catalog.low_stock_threshold', 5);

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN stock < ? AND stock > 0 THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS out_of_stock
             FROM products
             WHERE deleted_at IS NULL"
        );
        $stmt->bind_param('i', $threshold);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $archived = (int) $this->db
            ->query('SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NOT NULL')
            ->fetch_assoc()['c'];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'low_stock'    => (int) ($row['low_stock'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 0),
            'archived'     => $archived,
        ];
    }

    /**
     * Map a whitelisted sort key to SQL. Anything unknown falls back to the
     * configured default, so a crafted `?sort=` value cannot inject SQL.
     */
    private function orderByClause(string $sort): string
    {
        return match ($sort) {
            'price_asc'  => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            'name_asc'   => 'p.name ASC, p.id DESC',
            default      => 'p.created_at DESC, p.id DESC',
        };
    }

    /**
     * Escape LIKE wildcards so a search for "100%" is a literal search rather
     * than a pattern that matches everything.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
