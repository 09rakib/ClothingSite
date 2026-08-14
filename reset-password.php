<?php

declare(strict_types=1);

/**
 * Consume a password reset token and set a new password.
 *
 * The token is validated (correct hash, unused, not expired) before any form
 * is shown, and consumed atomically with the password update so the same
 * link can never be used twice — a replayed link after a successful reset
 * shows the same "invalid or expired" state as a token that was never valid.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Account\PasswordResetRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Validator;
use App\Support\View;

if (Auth::check()) {
    Http::redirect(Auth::isAdmin() ? 'admin/seller.php' : 'index.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$resetRepo = new PasswordResetRepository();

$userId = $token !== '' ? $resetRepo->userIdForValidToken($token) : null;
$errorMessage = '';

if ($token === '' || $userId === null) {
    $pageTitle = 'Invalid Reset Link';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container-narrow">
        <div class="alert alert-error" role="alert">
            This password reset link is invalid or has expired.
        </div>
        <p class="text-center mt-16"><a href="forgot-password.php" class="btn">Request a New Link</a></p>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('password', 'Password')
        ->label('confirm', 'Password confirmation')
        ->required('password')->minLength('password', 8)->maxLength('password', 200)
        ->required('confirm')->matches('confirm', 'password');

    if ($validator->passes()) {
        $conn   = Database::connection();
        $hashed = password_hash($validator->value('password'), PASSWORD_DEFAULT);

        $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $update->bind_param('si', $hashed, $userId);
        $update->execute();
        $update->close();

        // One-time use: this exact link can never be replayed after this.
        $resetRepo->consume($token);

        Flash::success('Your password has been reset. You can now log in.');
        Http::redirect('login.php');
    }

    $errorMessage = $validator->firstError();
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-banner">
        <img src="assets/images/login-banner.png" alt="">
        <div class="auth-banner-text">
            <h2>Choose a New Password</h2>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
            <h1>Reset Password</h1>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
            <?php endif; ?>

            <form method="POST" action="reset-password.php" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="token" value="<?= View::e($token) ?>">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
                    <small class="form-hint">At least 8 characters.</small>
                </div>
                <div class="form-group">
                    <label for="confirm">Confirm New Password</label>
                    <input type="password" id="confirm" name="confirm" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-block">Reset Password</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
