<?php

declare(strict_types=1);

/**
 * Public blog listing (PROJECT_RULES.md §21), backed by the real
 * `blog_posts` table now instead of a hardcoded array.
 */

$pageTitle = 'Blog';
require_once __DIR__ . '/includes/header.php';

use App\Blog\BlogRepository;
use App\Support\Http;
use App\Support\View;

$page   = Http::intParam($_GET, 'page') ?? 1;
$result = (new BlogRepository())->paginatePublished($page);
?>

<div class="container">
    <h1 class="page-heading">Blog</h1>
    <p class="page-subheading">Style notes and fabric care tips</p>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state">No posts yet. Check back soon.</div>
    <?php else: ?>
        <div class="blog-grid">
            <?php foreach ($result['items'] as $post): ?>
                <a href="blogpost.php?slug=<?= urlencode((string) $post['slug']) ?>" class="blog-card blog-card-link">
                    <?php if (!empty($post['featured_image'])): ?>
                        <img src="assets/images/blog/<?= View::e($post['featured_image']) ?>" alt="" loading="lazy" class="blog-card-image">
                    <?php endif; ?>
                    <div class="blog-date"><?= View::e(date('F Y', strtotime((string) $post['published_at']))) ?></div>
                    <h3><?= View::e($post['title']) ?></h3>
                    <p><?= View::e((string) $post['excerpt']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($result['pages'] > 1): ?>
            <nav class="pagination" aria-label="Blog pages">
                <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                    <?php if ($i === $result['page']): ?>
                        <span class="page-link current" aria-current="page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="blog.php?page=<?= $i ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
