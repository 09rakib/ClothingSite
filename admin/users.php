<?php

declare(strict_types=1);

/**
 * Admin — user management (PROJECT_RULES.md §16 "User management").
 *
 * Accounts are suspended, not deleted (see UserRepository's docblock).
 * Role changes and suspensions both refuse to leave the store with zero
 * active admins, so an admin can never accidentally lock everyone out.
 */

$pageTitle = 'Users';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Audit\AuditLogger;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;
use App\Users\UserRepository;

$repository = new UserRepository();
$audit      = new AuditLogger();

if (Http::isPost()) {
    Csrf::verifyRequest();

    $targetId = Http::intParam($_POST, 'user_id');
    $action   = (string) ($_POST['action'] ?? '');

    if ($targetId === null || $repository->find($targetId) === null) {
        Flash::error('That user no longer exists.');
        Http::redirect('users.php');
    }

    // An admin acting on their own account here (e.g. self-suspend) is
    // blocked outright — there is no legitimate reason for it, and it is a
    // common way to accidentally lock yourself out.
    if ($targetId === (int) Auth::id()) {
        Flash::error('You cannot change your own account from here.');
        Http::redirect('users.php');
    }

    try {
        switch ($action) {
            case 'suspend':
                $repository->suspend($targetId);
                $audit->log((int) Auth::id(), 'user.suspended', 'user', $targetId);
                Flash::success('Account suspended.');
                break;

            case 'reactivate':
                $repository->reactivate($targetId);
                $audit->log((int) Auth::id(), 'user.reactivated', 'user', $targetId);
                Flash::success('Account reactivated.');
                break;

            case 'promote':
                $repository->setRole($targetId, 'admin');
                $audit->log((int) Auth::id(), 'user.role_changed', 'user', $targetId, ['to' => 'admin']);
                Flash::success('User promoted to admin.');
                break;

            case 'demote':
                $repository->setRole($targetId, 'user');
                $audit->log((int) Auth::id(), 'user.role_changed', 'user', $targetId, ['to' => 'user']);
                Flash::success('Admin demoted to customer.');
                break;

            default:
                Flash::error('Unknown action.');
        }
    } catch (RuntimeException $e) {
        Flash::error($e->getMessage());
    }

    Http::redirect('users.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$page   = Http::intParam($_GET, 'page') ?? 1;
$result = $repository->paginate($search, $page);
?>

<h1 class="page-heading">Users</h1>
<p class="page-subheading"><?= $result['total'] ?> account<?= $result['total'] === 1 ? '' : 's' ?></p>

<form method="get" action="users.php" class="shop-filters" role="search">
    <div class="filter-row">
        <label class="sr-only" for="q">Search users</label>
        <input type="search" id="q" name="q" value="<?= View::e($search) ?>"
               placeholder="Search by name or email..." class="filter-input">
        <button type="submit" class="btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="users.php" class="btn btn-outline" style="background:var(--color-primary);">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($result['items'] === []): ?>
    <div class="empty-state">No users match this search.</div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col">Orders</th>
                    <th scope="col">Joined</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['items'] as $user): ?>
                    <?php $isSelf = (int) $user['id'] === (int) Auth::id(); ?>
                    <tr class="<?= $user['status'] === 'suspended' ? 'row-archived' : '' ?>">
                        <td><?= View::e($user['name']) ?><?= $isSelf ? ' <small class="muted">(you)</small>' : '' ?></td>
                        <td><?= View::e($user['email']) ?></td>
                        <td>
                            <span class="status-pill <?= $user['role'] === 'admin' ? 'status-active' : 'status-pending' ?>">
                                <?= ucfirst($user['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-pill <?= $user['status'] === 'active' ? 'status-active' : 'status-archived' ?>">
                                <?= ucfirst($user['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $user['order_count'] ?></td>
                        <td><?= View::e(date('d M Y', strtotime((string) $user['created_at']))) ?></td>
                        <td class="actions-cell">
                            <?php if (!$isSelf): ?>
                                <?php if ($user['status'] === 'active'): ?>
                                    <form method="post" action="users.php" class="inline-form"
                                          onsubmit="return confirm('Suspend this account? They will not be able to log in.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Suspend</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="users.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm">Reactivate</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($user['role'] === 'user'): ?>
                                    <form method="post" action="users.php" class="inline-form"
                                          onsubmit="return confirm('Make this user an admin? They will get full access to the seller panel.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="promote">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Make Admin</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="users.php" class="inline-form"
                                          onsubmit="return confirm('Remove admin access from this account?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="demote">
                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Remove Admin</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="User pages">
            <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                <?php if ($i === $result['page']): ?>
                    <span class="page-link current" aria-current="page"><?= $i ?></span>
                <?php else: ?>
                    <a href="users.php<?= View::queryString(['q' => $search], ['page' => $i]) ?>" class="page-link"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
