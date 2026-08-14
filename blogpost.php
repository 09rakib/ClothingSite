<?php

declare(strict_types=1);

/**
 * Public blog post detail page, addressed by slug.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Blog\BlogRepository;
use App\Support\Http;
use App\Support\View;

$slug = trim((string) ($_GET['slug'] ?? ''));
$post = $slug !== '' ? (new BlogRepository())->findPublishedBySlug($slug) : null;

if ($post === null) {
    http_response_code(404);
    $pageTitle = 'Post not found';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
        <div class="empty-state">
            <h1 class="page-heading">Post not found</h1>
            <a href="blog.php" class="btn mt-16">Back to Blog</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle       = (string) $post['title'];
$metaDescription = (string) $post['excerpt'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container container-narrow">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="index.php">Home</a>
        <span aria-hidden="true">&rsaquo;</span>
        <a href="blog.php">Blog</a>
        <span aria-hidden="true">&rsaquo;</span>
        <span aria-current="page"><?= View::e($post['title']) ?></span>
    </nav>

    <article class="blog-post">
        <?php if (!empty($post['featured_image'])): ?>
            <img src="assets/images/blog/<?= View::e($post['featured_image']) ?>" alt="" class="blog-post-image">
        <?php endif; ?>

        <h1 class="page-heading"><?= View::e($post['title']) ?></h1>
        <p class="page-subheading">
            <?= View::e(date('F j, Y', strtotime((string) $post['published_at']))) ?>
            <?php if (!empty($post['author_name'])): ?> &middot; by <?= View::e($post['author_name']) ?><?php endif; ?>
        </p>

        <div class="blog-post-body"><?= nl2br(View::e($post['body'])) ?></div>
    </article>

    <p class="mt-16"><a href="blog.php">&laquo; Back to Blog</a></p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
