<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Support\Database;
use mysqli;

/**
 * Product reviews (PROJECT_RULES.md §14).
 *
 * DESIGN DECISION: eligibility is strict. §14 says "Only eligible customers
 * may review" and "Prefer verified-purchase reviews" — this project reads
 * that as requiring a real, delivered purchase to review at all, not merely
 * preferring one among open submissions. A review system anyone can post to
 * regardless of purchase is exactly the kind of unverified content Rule 12's
 * spirit ("no fake success/no fake trust signals") argues against, so
 * `isEligible()` is a real query against delivered order_items, not a
 * checkbox, and `verified_purchase` is always 1 as a result — the column
 * exists for future-proofing if eligibility rules ever loosen.
 */
final class ReviewRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * True when this customer has a Delivered order containing this product.
     */
    public function isEligible(int $userId, int $productId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE o.user_id = ?
               AND oi.product_id = ?
               AND o.status = 'delivered'
             LIMIT 1"
        );
        $stmt->bind_param('ii', $userId, $productId);
        $stmt->execute();
        $eligible = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $eligible;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUser(int $productId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM reviews WHERE product_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $productId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Create the customer's review for this product, or update it if one
     * already exists (§14 "unless business rules allow updates" — they do).
     */
    public function upsert(int $productId, int $userId, int $rating, ?string $title, string $body): int
    {
        $existing = $this->findByUser($productId, $userId);

        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE reviews SET rating = ?, title = ?, body = ?, status = "visible" WHERE id = ?'
            );
            $id = (int) $existing['id'];
            $stmt->bind_param('issi', $rating, $title, $body, $id);
            $stmt->execute();
            $stmt->close();

            return $id;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO reviews (product_id, user_id, rating, title, body, verified_purchase)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('iiiss', $productId, $userId, $rating, $title, $body);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Visible reviews for a product's page, newest first, with the
     * reviewer's name.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id, r.rating, r.title, r.body, r.verified_purchase, r.created_at, u.name AS reviewer_name
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? AND r.status = 'visible'
             ORDER BY r.created_at DESC"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Average rating and count, computed safely (§14 "Average rating should
     * be calculated safely") — only visible reviews count, and a product with
     * none returns a defined zero rather than a division-by-zero risk.
     *
     * @return array{count:int,average:float}
     */
    public function summaryForProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c, COALESCE(AVG(rating), 0) AS avg_rating
             FROM reviews
             WHERE product_id = ? AND status = 'visible'"
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'count'   => (int) $row['c'],
            'average' => round((float) $row['avg_rating'], 1),
        ];
    }

    /**
     * Admin moderation queue: everything, newest first, with product/reviewer
     * names — visible and hidden alike so a hidden review can be restored.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allForAdmin(): array
    {
        return $this->db->query(
            "SELECT r.id, r.rating, r.title, r.body, r.status, r.created_at,
                    u.name AS reviewer_name, p.name AS product_name, p.slug AS product_slug
             FROM reviews r
             JOIN users u ON u.id = r.user_id
             JOIN products p ON p.id = r.product_id
             ORDER BY r.created_at DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function setStatus(int $reviewId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE reviews SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $reviewId);
        $stmt->execute();
        $stmt->close();
    }

    public function delete(int $reviewId): void
    {
        $stmt = $this->db->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->bind_param('i', $reviewId);
        $stmt->execute();
        $stmt->close();
    }

    public function find(int $reviewId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM reviews WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $reviewId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
