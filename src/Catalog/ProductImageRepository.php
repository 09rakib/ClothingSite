<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Database;
use App\Support\ImageUploader;
use mysqli;

/**
 * Product image gallery (PROJECT_RULES.md §11 "multiple images / primary image").
 *
 * DESIGN NOTE — why products.image still exists:
 * every listing query and every existing template reads products.image. Rather
 * than rewrite all of them at once (Rule 2 — prefer incremental refactoring),
 * that column is kept as a denormalised cache of the primary image and is
 * updated in lockstep whenever the primary changes here. The gallery table is
 * the source of truth; products.image is the fast path for list views.
 */
final class ProductImageRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * All images for a product, primary first.
     *
     * @return array<int,array{id:int,filename:string,alt_text:?string,is_primary:bool,sort_order:int}>
     */
    public function forProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, filename, alt_text, is_primary, sort_order
             FROM product_images
             WHERE product_id = ?
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(
            static fn(array $row): array => [
                'id'         => (int) $row['id'],
                'filename'   => (string) $row['filename'],
                'alt_text'   => $row['alt_text'] !== null ? (string) $row['alt_text'] : null,
                'is_primary' => (bool) $row['is_primary'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $rows
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $imageId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, filename, alt_text, is_primary FROM product_images WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $imageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function count(int $productId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM product_images WHERE product_id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $count;
    }

    /**
     * Attach an already-stored file to a product.
     *
     * The first image added automatically becomes the primary one, so a
     * product can never end up with a gallery but no primary image.
     */
    public function add(int $productId, string $filename, ?string $altText = null, bool $makePrimary = false): int
    {
        $isFirst   = $this->count($productId) === 0;
        $isPrimary = $makePrimary || $isFirst;

        $nextSort = (int) $this->db
            ->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM product_images WHERE product_id = " . $productId)
            ->fetch_assoc()['n'];

        $primaryFlag = $isPrimary ? 1 : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, filename, alt_text, is_primary, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issii', $productId, $filename, $altText, $primaryFlag, $nextSort);
        $stmt->execute();
        $imageId = (int) $this->db->insert_id;
        $stmt->close();

        if ($isPrimary) {
            $this->makePrimary($imageId);
        }

        return $imageId;
    }

    /**
     * Promote one image to primary, demoting the rest and syncing the
     * denormalised products.image column.
     */
    public function makePrimary(int $imageId): void
    {
        $image = $this->find($imageId);
        if ($image === null) {
            return;
        }

        $productId = (int) $image['product_id'];

        $clear = $this->db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?');
        $clear->bind_param('i', $productId);
        $clear->execute();
        $clear->close();

        $set = $this->db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?');
        $set->bind_param('i', $imageId);
        $set->execute();
        $set->close();

        $this->syncPrimaryToProduct($productId);
    }

    /**
     * Remove an image, deleting the file from disk as well.
     *
     * Refuses to remove the last remaining image: a product with no image at
     * all would render a broken placeholder on every listing page.
     *
     * @return bool false when the deletion was refused.
     */
    public function delete(int $imageId): bool
    {
        $image = $this->find($imageId);
        if ($image === null) {
            return false;
        }

        $productId = (int) $image['product_id'];

        if ($this->count($productId) <= 1) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM product_images WHERE id = ?');
        $stmt->bind_param('i', $imageId);
        $stmt->execute();
        $stmt->close();

        ImageUploader::delete((string) $image['filename']);

        // If the primary was removed, promote whatever is now first.
        if ((bool) $image['is_primary']) {
            $next = $this->forProduct($productId);
            if ($next !== []) {
                $this->makePrimary($next[0]['id']);
            }
        } else {
            $this->syncPrimaryToProduct($productId);
        }

        return true;
    }

    /**
     * Copy the current primary filename into products.image.
     *
     * Keeping the two in sync here means list views can keep reading the
     * single column without joining the gallery on every row.
     */
    public function syncPrimaryToProduct(int $productId): void
    {
        $stmt = $this->db->prepare(
            'SELECT filename FROM product_images
             WHERE product_id = ?
             ORDER BY is_primary DESC, sort_order ASC, id ASC
             LIMIT 1'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }

        $filename = (string) $row['filename'];
        $update   = $this->db->prepare('UPDATE products SET image = ? WHERE id = ?');
        $update->bind_param('si', $filename, $productId);
        $update->execute();
        $update->close();
    }

    /**
     * Delete every image file for a product (used only on hard delete paths).
     */
    public function deleteAllFiles(int $productId): void
    {
        foreach ($this->forProduct($productId) as $image) {
            ImageUploader::delete($image['filename']);
        }
    }
}
