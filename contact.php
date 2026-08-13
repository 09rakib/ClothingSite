<?php

declare(strict_types=1);

/**
 * Contact form.
 *
 * The previous version rendered a success message without saving or sending
 * anything, which PROJECT_RULES.md Rule 12 forbids ("Do not show success
 * unless the actual operation succeeded"). The message is now persisted and
 * the visitor is given a real reference number they can quote.
 *
 * Email delivery is intentionally still absent — that needs the notification
 * abstraction from §20 — so the confirmation promises a reply, not an email.
 */

$pageTitle = 'Contact';
require_once __DIR__ . '/includes/header.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Validator;
use App\Support\View;

$errors = [];
$old    = ['name' => Auth::check() ? Auth::name() : '', 'email' => '', 'message' => ''];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('name', 'Full name')
        ->label('email', 'Email')
        ->label('message', 'Message')
        ->required('name')->maxLength('name', 100)
        ->required('email')->email('email')->maxLength('email', 150)
        ->required('message')->minLength('message', 10)->maxLength('message', 5000);

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    // Simple spam brake (§22 "rate-limit submissions").
    $throttleKey = 'contact:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (RateLimiter::tooManyAttempts($throttleKey, 5, 3600)) {
        $validator->fail('message', 'You have sent several messages recently. Please try again later.');
    }

    if ($validator->passes()) {
        try {
            $conn = Database::connection();

            // Short, human-quotable reference.
            $reference = 'MSG-' . strtoupper(bin2hex(random_bytes(4)));
            $userId    = Auth::id();
            $name      = $validator->value('name');
            $email     = $validator->value('email');
            $message   = $validator->value('message');
            $ip        = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;

            $stmt = $conn->prepare(
                'INSERT INTO contact_messages (reference, user_id, name, email, message, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sissss', $reference, $userId, $name, $email, $message, $ip);
            $stmt->execute();
            $stmt->close();

            RateLimiter::hit($throttleKey, 3600);
            Logger::info('Contact message stored', ['reference' => $reference]);

            Flash::success("Thanks for reaching out! Your reference is {$reference} — we'll get back to you soon.");
            Http::redirect('contact.php');
        } catch (Throwable $e) {
            Logger::error('Contact message failed to save', ['error' => $e->getMessage()]);
            // Honest failure: the visitor is told it did not go through.
            $validator->fail('message', 'Sorry, we could not save your message. Please try again or email us directly.');
        }
    }

    $errors = $validator->errors();
}
?>

<div class="container">
    <h1 class="page-heading">Contact Us</h1>
    <p class="page-subheading">Questions about an order? Reach out any time.</p>

    <div class="contact-grid">
        <div>
            <?php if ($errors !== []): ?>
                <div class="alert alert-error" role="alert">
                    <?php foreach ($errors as $message): ?>
                        <div><?= View::e($message) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="contact.php" novalidate>
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" value="<?= View::e($old['name']) ?>" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= View::e($old['email']) ?>" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" maxlength="5000" required><?= View::e($old['message']) ?></textarea>
                </div>
                <button type="submit" class="btn">Send Message</button>
            </form>
        </div>

        <div class="contact-info-card">
            <p><strong>Email</strong><br>support@shirtpantstore.com</p>
            <p><strong>Phone</strong><br>+880 1700-000000</p>
            <p><strong>Address</strong><br>Dhaka, Bangladesh</p>
            <p><strong>Hours</strong><br>Sunday – Thursday, 10am – 6pm</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
