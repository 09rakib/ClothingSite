<?php

declare(strict_types=1);

/**
 * Customer registration.
 *
 * Validation now runs through the shared Validator so the rules are declared
 * once and enforced server-side. The old inline JS checks were removed as the
 * source of truth — the browser is never trusted (PROJECT_RULES.md Rule 6) —
 * and HTML5 attributes cover the same UX affordance.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Notifications\NotificationService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

if (Auth::check()) {
    Http::redirect(Auth::isAdmin() ? 'admin/seller.php' : 'index.php');
}

$errors = [];
$old    = ['fullname' => '', 'email' => '', 'phone' => '', 'address' => ''];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('fullname', 'Full name')
        ->label('email', 'Email')
        ->label('phone', 'Phone number')
        ->label('address', 'Address')
        ->label('password', 'Password')
        ->label('confirm', 'Password confirmation')
        ->required('fullname')->maxLength('fullname', 100)
        ->required('email')->email('email')->maxLength('email', 150)
        ->required('phone')->phone('phone')
        ->required('address')->maxLength('address', 255)
        ->required('password')->minLength('password', 8)->maxLength('password', 200)
        ->required('confirm')->matches('confirm', 'password');

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    if ($validator->passes()) {
        $conn = Database::connection();

        $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $email = $validator->value('email');
        $check->bind_param('s', $email);
        $check->execute();
        $emailTaken = $check->get_result()->num_rows > 0;
        $check->close();

        if ($emailTaken) {
            $validator->fail('email', 'This email is already registered.');
        } else {
            try {
                $hashed  = password_hash($validator->value('password'), PASSWORD_DEFAULT);
                $name    = $validator->value('fullname');
                $phone   = $validator->value('phone');
                $address = $validator->value('address');
                $role    = Auth::ROLE_CUSTOMER;

                $insert = $conn->prepare(
                    'INSERT INTO users (name, email, password, phone, address, role)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insert->bind_param('ssssss', $name, $email, $hashed, $phone, $address, $role);
                $insert->execute();
                $insert->close();

                // Notification failure must never block registration itself
                // (§20 "Email sending should not block checkout" — the same
                // rule applies here).
                (new NotificationService())->sendWelcome($email, $name);

                // POST/Redirect/GET: a refresh must not resubmit the form.
                Flash::success('Registration successful! You can now log in.');
                Http::redirect('login.php');
            } catch (mysqli_sql_exception $e) {
                Logger::error('Registration failed', ['error' => $e->getMessage()]);
                $validator->fail('email', 'Something went wrong. Please try again.');
            }
        }
    }

    $errors = $validator->errors();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | Shirt &amp; Pant Store</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="auth-wrap">
    <div class="auth-banner">
        <img src="assets/images/login-banner.png" alt="">
        <div class="auth-banner-text">
            <h2>Join Us</h2>
            <p>Create an account to start ordering.</p>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
            <h1>Create Account</h1>

            <?php if ($errors !== []): ?>
                <div class="alert alert-error" role="alert">
                    <?php foreach ($errors as $message): ?>
                        <div><?= View::e($message) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" novalidate>
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="fullname" value="<?= View::e($old['fullname']) ?>" maxlength="100" required autocomplete="name">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= View::e($old['email']) ?>" maxlength="150" required autocomplete="email">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= View::e($old['phone']) ?>" maxlength="20" required autocomplete="tel">
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?= View::e($old['address']) ?>" maxlength="255" required autocomplete="street-address">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
                    <small class="form-hint">At least 8 characters.</small>
                </div>
                <div class="form-group">
                    <label for="confirm">Confirm Password</label>
                    <input type="password" id="confirm" name="confirm" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-block">Register</button>
            </form>

            <p class="note">Already have an account? <a href="login.php">Login</a></p>
        </div>
    </div>
</div>

</body>
</html>
