<?php

declare(strict_types=1);

/**
 * Customer profile: edit name/phone, change password (PROJECT_RULES.md §13
 * "Customer account should include: Profile, Phone, Password change").
 *
 * Two independent forms on one page, each with its own action value, so a
 * validation error in one never clears input in the other.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

Auth::requireCustomer();

$userId = (int) Auth::id();
$conn   = Database::connection();

$profileErrors  = [];
$passwordErrors = [];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action = (string) ($_POST['action'] ?? '');

    /* ---------------- Update profile ---------------- */
    if ($action === 'profile') {
        $validator = (new Validator($_POST))
            ->label('fullname', 'Full name')
            ->label('phone', 'Phone number')
            ->required('fullname')->maxLength('fullname', 100)
            ->required('phone')->phone('phone');

        if ($validator->passes()) {
            $name  = $validator->value('fullname');
            $phone = $validator->value('phone');

            $stmt = $conn->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?');
            $stmt->bind_param('ssi', $name, $phone, $userId);
            $stmt->execute();
            $stmt->close();

            // The session's cached display name must be refreshed too, or
            // the header would keep showing the old name until next login.
            Session::set('user_name', $name);

            Logger::info('Profile updated', ['user_id' => $userId]);
            Flash::success('Profile updated.');
            Http::redirect('profile.php');
        }

        $profileErrors = $validator->errors();
    }

    /* ---------------- Change password ---------------- */
    if ($action === 'password') {
        $validator = (new Validator($_POST))
            ->label('current_password', 'Current password')
            ->label('new_password', 'New password')
            ->label('confirm_password', 'Password confirmation')
            ->required('current_password')
            ->required('new_password')->minLength('new_password', 8)->maxLength('new_password', 200)
            ->required('confirm_password')->matches('confirm_password', 'new_password');

        if ($validator->passes()) {
            $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $currentHash = (string) ($stmt->get_result()->fetch_assoc()['password'] ?? '');
            $stmt->close();

            if (!password_verify($validator->value('current_password'), $currentHash)) {
                $validator->fail('current_password', 'Your current password is incorrect.');
            } else {
                $newHash = password_hash($validator->value('new_password'), PASSWORD_DEFAULT);
                $update  = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $update->bind_param('si', $newHash, $userId);
                $update->execute();
                $update->close();

                Logger::info('Password changed', ['user_id' => $userId]);
                Flash::success('Password changed.');
                Http::redirect('profile.php');
            }
        }

        $passwordErrors = $validator->errors();
    }
}

$stmt = $conn->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <h1 class="page-heading">My Profile</h1>
    <p class="page-subheading">Manage your account details</p>

    <div class="admin-split">
        <div class="admin-card">
            <h2 class="card-title">Account Details</h2>

            <?php if ($profileErrors !== []): ?>
                <div class="alert alert-error" role="alert">
                    <?php foreach ($profileErrors as $message): ?>
                        <div><?= View::e($message) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="profile.php" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" value="<?= View::e($user['email']) ?>" disabled>
                    <small class="form-hint">Email cannot be changed here — contact support if you need to.</small>
                </div>
                <div class="form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="fullname" maxlength="100" required
                           value="<?= View::e($user['name']) ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" maxlength="20" required
                           value="<?= View::e($user['phone']) ?>">
                </div>

                <button type="submit" class="btn btn-block">Save Changes</button>
            </form>
        </div>

        <div class="admin-card">
            <h2 class="card-title">Change Password</h2>

            <?php if ($passwordErrors !== []): ?>
                <div class="alert alert-error" role="alert">
                    <?php foreach ($passwordErrors as $message): ?>
                        <div><?= View::e($message) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="profile.php" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="password">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                    <small class="form-hint">At least 8 characters.</small>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-block">Change Password</button>
            </form>
        </div>
    </div>

    <p class="mt-16">
        <a href="addresses.php" class="btn btn-outline" style="background:var(--color-primary);">Manage Addresses</a>
        <a href="wishlist.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">My Wishlist</a>
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
