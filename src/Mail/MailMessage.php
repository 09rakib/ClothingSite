<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A single outgoing email. Plain value object so a Mailer implementation only
 * ever sees what it needs to send — nothing order/user/domain-specific leaks
 * into the mail layer (same reasoning as Payments\PaymentRequest).
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly ?string $bodyText = null,
        public readonly ?string $toName = null
    ) {
    }
}
