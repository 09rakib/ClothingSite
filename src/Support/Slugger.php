<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * URL slug generation (PROJECT_RULES.md §11 "Use slugs for public URLs",
 * §26 "SEO-friendly product URLs").
 *
 * WHY slugs at all: `product.php?id=6` tells neither a person nor a search
 * engine anything. `product.php?slug=denim-pant` is readable, stable and
 * indexable.
 *
 * The slug is generated from the product name but is NOT derived at read time:
 * it is stored, so renaming a product later does not silently break every link
 * that already points at it.
 */
final class Slugger
{
    /**
     * Convert arbitrary text into a lowercase, hyphen-separated slug.
     *
     * Non-ASCII characters (Bangla product names, accented Latin) are
     * transliterated where possible and dropped otherwise, so the result is
     * always URL-safe.
     */
    public static function make(string $text, string $fallback = 'item'): string
    {
        $slug = trim($text);

        // Transliterate to ASCII when the intl-free iconv path is available.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if (is_string($converted) && $converted !== '') {
                $slug = $converted;
            }
        }

        $slug = strtolower($slug);

        // iconv//TRANSLIT can emit forms like "a" for accented letters.
        $slug = preg_replace('/[\'"`^~]/', '', $slug) ?? $slug;

        // Anything that is not a letter, digit or hyphen becomes a separator.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        // Keep slugs comfortably inside the column width.
        if (strlen($slug) > 180) {
            $slug = rtrim(substr($slug, 0, 180), '-');
        }

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * Generate a slug that is unique within a table.
     *
     * Collisions get a numeric suffix ("denim-pant", "denim-pant-2", ...).
     * $ignoreId lets a product keep its own slug when being edited.
     */
    public static function unique(
        mysqli $db,
        string $text,
        string $table,
        string $column = 'slug',
        ?int $ignoreId = null,
        string $fallback = 'item'
    ): string {
        // Table and column names are supplied by application code, never by
        // user input, but they are still restricted to a safe character set
        // because they cannot be bound as parameters.
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
            throw new \InvalidArgumentException('Unsafe table or column name passed to Slugger.');
        }

        $base = self::make($text, $fallback);
        $slug = $base;
        $n    = 1;

        $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ?";
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
        }
        $sql .= ' LIMIT 1';

        while (true) {
            $stmt = $db->prepare($sql);
            if ($ignoreId !== null) {
                $stmt->bind_param('si', $slug, $ignoreId);
            } else {
                $stmt->bind_param('s', $slug);
            }
            $stmt->execute();
            $taken = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$taken) {
                return $slug;
            }

            $n++;
            $slug = $base . '-' . $n;
        }
    }
}
