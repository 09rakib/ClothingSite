<?php

declare(strict_types=1);

/**
 * Admin — contact/support inbox (PROJECT_RULES.md §22 "Contact & Support:
 * provide admin inbox, track status: new/in_progress/resolved/closed").
 *
 * contact_messages has existed since Phase 0 (contact.php has stored real
 * messages since then), but nothing could ever read them back — this is the
 * missing other half of that feature.
 */

$pageTitle = 'Contact Messages';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;

$conn = Database::connection();

if (Http::isPost()) {
    Csrf::verifyRequest();

    $messageId = Http::intParam($_POST, 'message_id');
    $status    = (string) ($_POST['status'] ?? '');

    if (!in_array($status, ['new', 'in_progress', 'resolved', 'closed'], true)) {
        Flash::error('Unknown status.');
    } elseif ($messageId === null) {
        Flash::error('No message was selected.');
    } else {
        $stmt = $conn->prepare('UPDATE contact_messages SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $messageId);
        $stmt->execute();
        $stmt->close();
        Flash::success('Status updated.');
    }

    Http::redirect('contacts.php' . (isset($_GET['status']) ? '?status=' . urlencode((string) $_GET['status']) : ''));
}

$statusFilter = (string) ($_GET['status'] ?? '');
$allowedStatuses = ['new', 'in_progress', 'resolved', 'closed'];

$where = '';
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where = "WHERE status = '" . $conn->real_escape_string($statusFilter) . "'";
}

$messages = $conn->query("SELECT * FROM contact_messages {$where} ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

$counts = $conn->query('SELECT status, COUNT(*) AS c FROM contact_messages GROUP BY status')->fetch_all(MYSQLI_ASSOC);
$countByStatus = array_fill_keys($allowedStatuses, 0);
foreach ($counts as $row) {
    $countByStatus[$row['status']] = (int) $row['c'];
}
?>

<h1 class="page-heading">Contact Messages</h1>
<p class="page-subheading"><?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?></p>

<div class="status-breakdown mb-16">
    <a href="contacts.php" class="status-breakdown-item">
        <span class="status-pill status-pending">All</span>
        <span class="status-breakdown-count"><?= array_sum($countByStatus) ?></span>
    </a>
    <?php foreach ($allowedStatuses as $status): ?>
        <a href="contacts.php?status=<?= $status ?>" class="status-breakdown-item">
            <span class="status-pill <?= $status === 'resolved' || $status === 'closed' ? 'status-active' : 'status-pending' ?>">
                <?= ucwords(str_replace('_', ' ', $status)) ?>
            </span>
            <span class="status-breakdown-count"><?= $countByStatus[$status] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($messages === []): ?>
    <div class="empty-state">No messages<?= $statusFilter !== '' ? ' with this status' : '' ?>.</div>
<?php else: ?>
    <div class="contact-inbox">
        <?php foreach ($messages as $msg): ?>
            <div class="admin-card admin-card-wide contact-message-card">
                <div class="card-title-row">
                    <div>
                        <strong><?= View::e($msg['name']) ?></strong>
                        &lt;<?= View::e($msg['email']) ?>&gt;
                        <br>
                        <small class="muted">
                            Ref <?= View::e($msg['reference']) ?> &middot;
                            <?= View::e(date('d M Y, g:i a', strtotime((string) $msg['created_at']))) ?>
                        </small>
                    </div>
                    <span class="status-pill <?= $msg['status'] === 'resolved' || $msg['status'] === 'closed' ? 'status-active' : 'status-pending' ?>">
                        <?= ucwords(str_replace('_', ' ', $msg['status'])) ?>
                    </span>
                </div>

                <p><?= nl2br(View::e($msg['message'])) ?></p>

                <form method="post" action="contacts.php<?= $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '' ?>" class="contact-status-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="message_id" value="<?= (int) $msg['id'] ?>">
                    <label class="sr-only" for="status-<?= (int) $msg['id'] ?>">Status</label>
                    <select name="status" id="status-<?= (int) $msg['id'] ?>">
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?= $status ?>" <?= $msg['status'] === $status ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_', ' ', $status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm">Update</button>
                    <a href="mailto:<?= View::e($msg['email']) ?>?subject=Re: your message (<?= View::e($msg['reference']) ?>)" class="btn btn-sm btn-success">Reply by Email</a>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
