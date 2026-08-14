<?php

declare(strict_types=1);

/**
 * Admin — audit log viewer (PROJECT_RULES.md §23).
 */

$pageTitle = 'Audit Log';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Audit\AuditLogger;
use App\Support\View;

$entityType = trim((string) ($_GET['entity_type'] ?? ''));
$entries    = (new AuditLogger())->recent($entityType !== '' ? $entityType : null, null, 150);
?>

<h1 class="page-heading">Audit Log</h1>
<p class="page-subheading">Recent administrative actions</p>

<form method="get" action="auditlog.php" class="shop-filters">
    <div class="filter-row">
        <label class="sr-only" for="entity_type">Entity type</label>
        <select name="entity_type" id="entity_type" class="filter-input">
            <option value="">All types</option>
            <?php foreach (['order', 'product', 'category', 'user', 'blog_post', 'review'] as $type): ?>
                <option value="<?= $type ?>" <?= $entityType === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
    </div>
</form>

<?php if ($entries === []): ?>
    <div class="empty-state">No audit entries yet.</div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Action</th>
                    <th scope="col">Entity</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= View::e(date('d M Y, g:i a', strtotime((string) $entry['created_at']))) ?></td>
                        <td><?= View::e($entry['actor_name'] ?? 'System') ?></td>
                        <td><code class="slug-code"><?= View::e($entry['action']) ?></code></td>
                        <td><?= View::e($entry['entity_type']) ?><?= $entry['entity_id'] ? ' #' . (int) $entry['entity_id'] : '' ?></td>
                        <td class="review-cell"><?= View::e($entry['metadata'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
