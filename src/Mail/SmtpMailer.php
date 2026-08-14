<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Config;
use App\Support\Logger;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Real SMTP delivery via PHPMailer, once credentials exist
 * (config `mail.mailer` = 'smtp' and `mail.smtp.*` filled in, normally in the
 * git-ignored config/config.local.php — see config.local.example.php).
 *
 * Not the default: this project has no real mailbox/SMTP account to send
 * through, so MailerFactory returns LogMailer unless smtp is explicitly
 * configured. When it is, this class does a genuine send — there is nothing
 * simulated here, unlike the Payments UnconfiguredGateway.
 */
final class SmtpMailer implements Mailer
{
    public function send(MailMessage $message): bool
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host       = (string) Config::require('mail.smtp.host');
            $mailer->Port       = (int) Config::get('mail.smtp.port', 587);
            $mailer->SMTPAuth   = true;
            $mailer->Username   = (string) Config::require('mail.smtp.username');
            $mailer->Password   = (string) Config::require('mail.smtp.password');
            $mailer->SMTPSecure = (string) Config::get('mail.smtp.encryption', PHPMailer::ENCRYPTION_STARTTLS);

            $mailer->setFrom(
                (string) Config::get('mail.from_address', 'no-reply@shirtpantstore.local'),
                (string) Config::get('mail.from_name', 'Shirt & Pant Store')
            );
            $mailer->addAddress($message->to, $message->toName ?? '');

            $mailer->isHTML(true);
            $mailer->Subject = $message->subject;
            $mailer->Body    = $message->bodyHtml;
            if ($message->bodyText !== null) {
                $mailer->AltBody = $message->bodyText;
            }

            $mailer->send();

            return true;
        } catch (PHPMailerException $e) {
            Logger::error('SMTP mail send failed', ['to' => $message->to, 'error' => $mailer->ErrorInfo]);

            return false;
        } catch (\Throwable $e) {
            Logger::error('SMTP mailer misconfigured', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
