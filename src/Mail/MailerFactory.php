<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Config;

/**
 * Resolves the configured mail driver. Defaults to LogMailer — see its
 * docblock for why that default is honest rather than a "fake success" stub.
 */
final class MailerFactory
{
    public static function make(): Mailer
    {
        return match ((string) Config::get('mail.mailer', 'log')) {
            'smtp'  => new SmtpMailer(),
            default => new LogMailer(),
        };
    }
}
