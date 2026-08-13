<?php
$pageTitle = 'Blog';
require_once __DIR__ . '/includes/header.php';

/* Static placeholder posts — replace with a real `posts` table later if needed */
$posts = [
    [
        'title' => 'How to Pick the Right Shirt Fit',
        'date'  => 'January 2026',
        'excerpt' => 'A quick guide to reading size charts and choosing a fit that actually looks good.',
    ],
    [
        'title' => 'Caring for Cotton Fabric',
        'date'  => 'December 2025',
        'excerpt' => 'Simple washing and ironing tips to keep your shirts looking new for longer.',
    ],
    [
        'title' => 'Building a Minimal Wardrobe',
        'date'  => 'November 2025',
        'excerpt' => 'A handful of shirts and pants that mix and match into weeks of outfits.',
    ],
];
?>

<div class="container">
    <h1 class="page-heading">Blog</h1>
    <p class="page-subheading">Style notes and fabric care tips</p>

    <div class="blog-grid">
        <?php foreach ($posts as $post): ?>
            <div class="blog-card">
                <div class="blog-date"><?php echo htmlspecialchars($post['date']); ?></div>
                <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                <p><?php echo htmlspecialchars($post['excerpt']); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
