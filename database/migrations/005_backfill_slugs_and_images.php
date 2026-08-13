<?php

declare(strict_types=1);

/**
 * 005 — Backfill slugs and seed the product image gallery.
 *
 * WHY this is a PHP migration rather than SQL:
 * generating a URL slug means transliterating non-ASCII characters and
 * resolving collisions with a numeric suffix. Expressing that in portable SQL
 * would mean a second, subtly different implementation of the rule — exactly
 * the duplication PROJECT_RULES.md §3.1 warns against. Reusing App\Support\
 * Slugger guarantees existing rows get slugs identical to the ones new
 * products will receive.
 *
 * Runs after 004 has added the (nullable) columns, and adds the UNIQUE indexes
 * at the end, once every row is guaranteed to hold a distinct value.
 */

use App\Support\Slugger;

return static function (mysqli $db): void {

    /* ---------------------------------------------------------
     | Categories
     * --------------------------------------------------------- */
    $rows = $db->query('SELECT id, name FROM categories WHERE slug IS NULL OR slug = ""')
        ->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare('UPDATE categories SET slug = ? WHERE id = ?');
    foreach ($rows as $row) {
        $slug = Slugger::unique($db, (string) $row['name'], 'categories', 'slug', (int) $row['id'], 'category');
        $update->bind_param('si', $slug, $row['id']);
        $update->execute();
    }
    $update->close();

    /* ---------------------------------------------------------
     | Products
     * --------------------------------------------------------- */
    $rows = $db->query('SELECT id, name FROM products WHERE slug IS NULL OR slug = ""')
        ->fetch_all(MYSQLI_ASSOC);

    $update = $db->prepare('UPDATE products SET slug = ? WHERE id = ?');
    foreach ($rows as $row) {
        $slug = Slugger::unique($db, (string) $row['name'], 'products', 'slug', (int) $row['id'], 'product');
        $update->bind_param('si', $slug, $row['id']);
        $update->execute();
    }
    $update->close();

    /* ---------------------------------------------------------
     | Seed the gallery from the existing single image column.
     |
     | Every product keeps working exactly as before — it simply now has a
     | one-image gallery that the admin can extend.
     * --------------------------------------------------------- */
    $db->query(
        "INSERT INTO product_images (product_id, filename, is_primary, sort_order)
         SELECT p.id, p.image, 1, 0
         FROM products p
         WHERE p.image <> ''
           AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)"
    );

    /* ---------------------------------------------------------
     | Now that every row has a distinct slug, enforce it.
     |
     | MariaDB has no ADD UNIQUE INDEX IF NOT EXISTS for named indexes across
     | all versions, so existence is checked first to keep the migration
     | re-runnable.
     * --------------------------------------------------------- */
    $addUniqueIndex = static function (mysqli $db, string $table, string $index, string $column): void {
        $exists = $db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$table}'
               AND INDEX_NAME = '{$index}'
             LIMIT 1"
        );

        if ($exists && $exists->num_rows > 0) {
            return;
        }

        $db->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index}` (`{$column}`)");
    };

    $addUniqueIndex($db, 'categories', 'uniq_categories_slug', 'slug');
    $addUniqueIndex($db, 'products', 'uniq_products_slug', 'slug');
};
