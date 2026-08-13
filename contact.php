<?php
$pageTitle = 'Contact';
require_once __DIR__ . '/includes/header.php';

$submitted = false;
if (isset($_POST['submit'])) {
    // This demo form does not send an email — it just confirms receipt.
    // Wire this up to mail() or an email API if real delivery is needed.
    $submitted = true;
}
?>

<div class="container">
    <h1 class="page-heading">Contact Us</h1>
    <p class="page-subheading">Questions about an order? Reach out any time.</p>

    <div class="contact-grid">
        <div>
            <?php if ($submitted): ?>
                <div class="alert alert-success">Thanks for reaching out! We'll get back to you soon.</div>
            <?php endif; ?>

            <form method="POST" action="contact.php">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" required></textarea>
                </div>
                <button type="submit" name="submit" class="btn">Send Message</button>
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
