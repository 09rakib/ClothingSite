<?php

declare(strict_types=1);

/**
 * Admin — review moderation (PROJECT_RULES.md §14 "Admin can hide/remove
 * abusive content").
 *
 * Hiding is the default action (reversible); delete is available for
 * genuinely abusive content that should not be recoverable.
 */

$pageTitle = 'Reviews';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Reviews\ReviewRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;

$repository = new ReviewRepository();

if (Http::isPost()) {
    Csrf::verifyRequest();

    $reviewId = Http::intParam($_POST, 'review_id');
    $action   = (string) ($_POST['action'] ?? '');

    if ($reviewId === null || $repository->find($reviewId) === null) {
        Flash::error('That review no longer exists.');
    } elseif ($action === 'hide') {
        $repository->setStatus($reviewId, 'hidden');
        Flash::success('Review hidden.');
    } elseif ($action === 'show') {
        $repository->setStatus($reviewId, 'visible');
        Flash::success('Review restored.');
    } elseif ($action === 'delete') {
        $repository->delete($reviewId);
        Flash::success('Review deleted.');
    }

    Http::redirect('reviews.php');
}

$reviews = $repository->allForAdmin();
?>

<h1 class="page-heading">Reviews</h1>
<p class="page-subheading"><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?></p>

<?php if ($reviews === []): ?>
    <div class="empty-state">No reviews yet.</div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Reviewer</th>
                    <th scope="col">Rating</th>
                    <th scope="col">Review</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $review): ?>
                    <tr class="<?= $review['status'] === 'hidden' ? 'row-archived' : '' ?>">
                        <td><a href="../product.php?slug=<?= urlencode((string) $review['product_slug']) ?>"><?= View::e($review['product_name']) ?></a></td>
                        <td><?= View::e($review['reviewer_name']) ?></td>
                        <td><?= str_repeat('★', (int) $review['rating']) ?></td>
                        <td class="review-cell">
                            <?php if (!empty($review['title'])): ?><strong><?= View::e($review['title']) ?></strong><br><?php endif; ?>
                            <?= View::e(mb_strimwidth((string) $review['body'], 0, 140, '…')) ?>
                        </td>
                        <td>
                            <span class="status-pill <?= $review['status'] === 'visible' ? 'status-active' : 'status-archived' ?>">
                                <?= $review['status'] === 'visible' ? 'Visible' : 'Hidden' ?>
                            </span>
                        </td>
                        <td><?= View::e(date('d M Y', strtotime((string) $review['created_at']))) ?></td>
                        <td class="actions-cell">
                            <?php if ($review['status'] === 'visible'): ?>
                                <form method="post" action="reviews.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="hide">
                                    <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Hide</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="reviews.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="show">
                                    <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Restore</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="reviews.php" class="inline-form"
                                  onsubmit="return confirm('Permanently delete this review?');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
