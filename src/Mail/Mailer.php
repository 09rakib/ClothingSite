<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Outbound email abstraction (PROJECT_RULES.md §20 "Email & Notifications").
 *
 * WHY this exists: nothing in this codebase previously sent email at all —
 * contact.php explicitly documented that it didn't. Callers (registration,
 * checkout, password reset) ask a Mailer to send a MailMessage and never know
 * or care which driver is behind it, exactly like PaymentGateway for payments.
 *
 * Two implementations exist:
 *   - LogMailer (default): writes the message to storage/logs/mail/ instead
 *     of a real inbox. This is a genuine, working default — not a stub — the
 *     same convention Laravel and Symfony ship as their local "log" mail
 *     driver, so a developer can read exactly what would have been sent.
 *   - SmtpMailer: really sends via PHPMailer over SMTP, once real credentials
 *     are added to config.local.php. Disabled (mailer=log) until then.
 *
 * send() never throws for a delivery failure — callers must not let a mail
 * problem break registration or checkout (§20 "Email sending should not block
 * checkout"). It returns false and the caller logs/ignores as appropriate.
 */
interface Mailer
{
    public function send(MailMessage $message): bool;
}
