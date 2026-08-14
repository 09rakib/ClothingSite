<?php

declare(strict_types=1);

namespace App\Blog;

use App\Support\Database;
use App\Support\Slugger;
use mysqli;

/**
 * Blog CMS (PROJECT_RULES.md §21), replacing the hardcoded array blog.php
 * used to render.
 */
final class BlogRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Published posts for the public listing, newest first, paginated.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function paginatePublished(int $page = 1, int $perPage = 6): array
    {
        $total = (int) $this->db
            ->query("SELECT COUNT(*) AS c FROM blog_posts WHERE status = 'published' AND published_at <= NOW()")
            ->fetch_assoc()['c'];

        $perPage = max(1, min($perPage, 30));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT p.id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at, u.name AS author_name
             FROM blog_posts p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.status = 'published' AND p.published_at <= NOW()
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, u.name AS author_name
             FROM blog_posts p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= NOW()
             LIMIT 1"
        );
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function allForAdmin(): array
    {
        return $this->db->query(
            "SELECT p.*, u.name AS author_name
             FROM blog_posts p
             LEFT JOIN users u ON u.id = p.author_id
             ORDER BY p.created_at DESC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * A blank excerpt is derived from the body so the public listing never
     * shows an empty summary card.
     */
    private function deriveExcerpt(?string $excerpt, string $body): string
    {
        $excerpt = trim((string) $excerpt);
        if ($excerpt !== '') {
            return mb_substr($excerpt, 0, 300);
        }

        return mb_strimwidth(trim(strip_tags($body)), 0, 200, '…');
    }

    public function create(
        string $title,
        ?string $excerpt,
        string $body,
        ?string $featuredImage,
        string $status,
        int $authorId
    ): int {
        $excerpt = $this->deriveExcerpt($excerpt, $body);
        $slug = Slugger::unique($this->db, $title, 'blog_posts', 'slug', null, 'post');
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

        $stmt = $this->db->prepare(
            'INSERT INTO blog_posts (title, slug, excerpt, body, featured_image, status, author_id, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssssis', $title, $slug, $excerpt, $body, $featuredImage, $status, $authorId, $publishedAt);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function update(
        int $id,
        string $title,
        ?string $excerpt,
        string $body,
        ?string $featuredImage,
        string $status
    ): void {
        $excerpt  = $this->deriveExcerpt($excerpt, $body);
        $existing = $this->find($id);
        $slug     = (string) ($existing['slug'] ?? '');

        if ($existing === null || (string) $existing['title'] !== $title || $slug === '') {
            $slug = Slugger::unique($this->db, $title, 'blog_posts', 'slug', $id, 'post');
        }

        // Publishing for the first time stamps published_at now; publishing
        // again (already published) keeps the original date rather than
        // bumping it to the top of the feed on every edit.
        $publishedAt = $existing['published_at'] ?? null;
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $stmt = $this->db->prepare(
            'UPDATE blog_posts
             SET title = ?, slug = ?, excerpt = ?, body = ?, featured_image = ?, status = ?, published_at = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssssssi', $title, $slug, $excerpt, $body, $featuredImage, $status, $publishedAt, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM blog_posts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}
