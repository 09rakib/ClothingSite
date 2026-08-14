<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Logger;

/**
 * Default mail driver: writes each message to storage/logs/mail/ instead of
 * a real inbox.
 *
 * WHY this is the default rather than a "please configure SMTP" stub like
 * UnconfiguredGateway: unlike a payment gateway, there is no real-money
 * problem with a local mail capture — it is standard practice for local/dev
 * environments (the same role Laravel's `MAIL_MAILER=log` plays) and lets
 * password reset, order confirmation, etc. be built and tested completely
 * without needing real SMTP credentials this project does not have.
 */
final class LogMailer implements Mailer
{
    public function send(MailMessage $message): bool
    {
        $dir = __DIR__ . '/../../storage/logs/mail';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Logger::error('LogMailer could not create mail log directory');

            return false;
        }

        $filename = sprintf(
            '%s_%s_%s.html',
            date('Ymd-His'),
            preg_replace('/[^a-z0-9]+/i', '-', $message->to),
            substr(bin2hex(random_bytes(4)), 0, 8)
        );

        $content = sprintf(
            "<!-- To: %s\n     Subject: %s\n     Sent (logged): %s -->\n%s",
            htmlspecialchars($message->toName ? "{$message->toName} <{$message->to}>" : $message->to),
            htmlspecialchars($message->subject),
            date('Y-m-d H:i:s'),
            $message->bodyHtml
        );

        $written = @file_put_contents($dir . '/' . $filename, $content, LOCK_EX);

        if ($written === false) {
            Logger::error('LogMailer failed to write mail log', ['to' => $message->to]);

            return false;
        }

        Logger::info('Mail captured (log driver)', ['to' => $message->to, 'subject' => $message->subject, 'file' => $filename]);

        return true;
    }
}
