<?php

declare(strict_types=1);

/**
 * Customer / admin login.
 *
 * Security notes (PROJECT_RULES.md §19):
 *   - CSRF token on the form.
 *   - Attempts are rate limited to slow down credential stuffing.
 *   - The error message never reveals whether an email exists, so the form
 *     cannot be used to enumerate accounts.
 *   - Session id and CSRF token are both rotated on success (Auth::login).
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Cart\CartService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Http;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Validator;
use App\Support\View;

// Already signed in — no reason to show the form again.
if (Auth::check()) {
    Http::redirect(Auth::isAdmin() ? 'admin/seller.php' : 'index.php');
}

$errorMessage = '';
$oldEmail     = '';

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('email', 'Email')
        ->label('password', 'Password')
        ->required('email')->email('email')
        ->required('password');

    $oldEmail = $validator->value('email');

    // Throttle per email so one attacker cannot lock out every account.
    $throttleKey = 'login:' . strtolower($oldEmail);

    if (RateLimiter::tooManyAttempts($throttleKey)) {
        $minutes      = (int) ceil(RateLimiter::secondsRemaining($throttleKey) / 60);
        $errorMessage = "Too many failed attempts. Please try again in {$minutes} minute(s).";
    } elseif ($validator->fails()) {
        $errorMessage = $validator->firstError();
    } else {
        $conn = Database::connection();

        $stmt = $conn->prepare('SELECT id, name, password, role, status FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $oldEmail);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $passwordCorrect = $user && password_verify($validator->value('password'), $user['password']);

        if ($passwordCorrect && $user['status'] === 'suspended') {
            // The password was correct, so this account genuinely belongs to
            // whoever is signing in — telling them it is suspended here is
            // not an enumeration risk the way a generic wrong-password
            // message protects against (§16 "User management"). The rate
            // limiter hit below still applies once, not twice.
            $errorMessage = 'This account has been suspended. Please contact support.';
        } elseif ($passwordCorrect) {
            // Re-hash transparently if PHP's default cost/algorithm changed.
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($validator->value('password'), PASSWORD_DEFAULT);
                $rehash  = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $rehash->bind_param('si', $newHash, $user['id']);
                $rehash->execute();
                $rehash->close();
            }

            RateLimiter::clear($throttleKey);
            Auth::login((int) $user['id'], (string) $user['name'], (string) $user['role']);

            // Carry anything the visitor collected before logging in into
            // their account, so a guest cart is never silently lost (§8).
            if ($user['role'] !== Auth::ROLE_ADMIN) {
                try {
                    (new CartService())->mergeGuestCartIntoUser((int) $user['id']);
                } catch (Throwable $e) {
                    // A merge failure must never block a valid login.
                    Logger::error('Guest cart merge failed', [
                        'user_id' => $user['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            Http::redirect($user['role'] === Auth::ROLE_ADMIN ? 'admin/seller.php' : 'index.php');
        }

        RateLimiter::hit($throttleKey);

        // Deliberately identical for "no such account" and "wrong password"
        // so the form cannot confirm which emails are registered. Does not
        // overwrite the suspended-account message set above, which is
        // intentionally more specific (see that branch's comment).
        if ($errorMessage === '') {
            $errorMessage = 'Incorrect email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Shirt &amp; Pant Store</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-wrap">
    <div class="auth-banner">
        <img src="assets/images/login-banner.png" alt="">
        <div class="auth-banner-text">
            <h2>Welcome Back</h2>
            <p>Log in to track orders and check out faster.</p>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
            <h1>Login</h1>

            <?= App\Support\Flash::render() ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error" role="alert"><?= View::e($errorMessage) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= View::e($oldEmail) ?>" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <p class="note" style="text-align:right; margin-top:-8px;"><a href="forgot-password.php">Forgot password?</a></p>
                <button type="submit" class="btn btn-block">Login</button>
            </form>

            <p class="note">New here? <a href="register.php">Create an account</a></p>
        </div>
    </div>
</div>

</body>
</html>
