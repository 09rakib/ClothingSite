<?php

declare(strict_types=1);

/**
 * Request a password reset link (PROJECT_RULES.md §19 "Password reset with
 * expiring single-use tokens").
 *
 * The response is identical whether or not the email is registered — exactly
 * the same anti-enumeration pattern login.php already uses for "incorrect
 * email or password" — so this page cannot be used to test which addresses
 * have accounts.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Account\PasswordResetRepository;
use App\Notifications\NotificationService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Http;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Validator;
use App\Support\View;

if (Auth::check()) {
    Http::redirect(Auth::isAdmin() ? 'admin/seller.php' : 'index.php');
}

$submitted    = false;
$errorMessage = '';

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))->label('email', 'Email')->required('email')->email('email');
    $email     = $validator->value('email');

    $throttleKey = 'forgot-password:' . strtolower($email !== '' ? $email : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    if (RateLimiter::tooManyAttempts($throttleKey, 5, 900)) {
        $errorMessage = 'Too many requests. Please try again later.';
    } elseif ($validator->fails()) {
        $errorMessage = $validator->firstError();
    } else {
        RateLimiter::hit($throttleKey, 900);

        $conn = Database::connection();
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND role = 'user' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $rawToken = (new PasswordResetRepository())->issue((int) $user['id']);

            // Absolute URL: the customer opens this from their email client,
            // not from a page inside this site, so a relative link would not
            // resolve correctly.
            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $basePath = rtrim((string) Config::get('app.url', ''), '/');
            $resetUrl = "{$scheme}://{$host}{$basePath}/reset-password.php?token=" . urlencode($rawToken);

            $sent = (new NotificationService())->sendPasswordReset(
                $email,
                (string) $user['name'],
                $resetUrl,
                PasswordResetRepository::ttlMinutes()
            );

            if (!$sent) {
                Logger::warning('Password reset email could not be delivered', ['email' => $email]);
            }
        }

        // Identical outcome whether or not $user was found (§19 anti-enumeration).
        $submitted = true;
    }
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="auth-banner">
        <img src="assets/images/login-banner.png" alt="">
        <div class="auth-banner-text">
            <h2>Forgot Password?</h2>
            <p>We'll email you a link to reset it.</p>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
            <h1>Reset Your Password</h1>

            <?php if ($submitted): ?>
                <div class="alert alert-success" role="status">
                    If that email is registered, a reset link has been sent. It expires in
                    <?= PasswordResetRepository::ttlMinutes() ?> minutes.
                </div>
            <?php else: ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
                <?php endif; ?>

                <form method="POST" action="forgot-password.php" novalidate>
                    <?= Csrf::field() ?>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required autocomplete="email">
                    </div>
                    <button type="submit" class="btn btn-block">Send Reset Link</button>
                </form>
            <?php endif; ?>

            <p class="note"><a href="login.php">Back to login</a></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
