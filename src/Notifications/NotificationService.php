<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\MailerFactory;
use App\Mail\MailMessage;
use App\Support\Config;
use App\Support\Logger;
use App\Support\View;
use Throwable;

/**
 * Composes and sends the customer-facing emails this store needs
 * (PROJECT_RULES.md §20: registration, password reset, order placed).
 *
 * Every send() here is wrapped so a mail failure can never propagate into the
 * caller's transaction or request — §20 "Email sending should not block
 * checkout" is treated as a rule for every notification, not only checkout's.
 */
final class NotificationService
{
    public function sendWelcome(string $email, string $name): bool
    {
        $storeName = (string) Config::get('app.name', 'Shirt & Pant Store');

        return $this->safeSend(new MailMessage(
            to: $email,
            toName: $name,
            subject: "Welcome to {$storeName}",
            bodyHtml: $this->wrap(
                "<h2>Welcome, " . View::e($name) . "!</h2>"
                . "<p>Your account at {$storeName} is ready. You can now browse the shop, save delivery addresses, and track your orders.</p>"
            )
        ));
    }

    /**
     * $resetUrl already contains the raw, unhashed token — see
     * PasswordResetRepository, which stores only a hash of it.
     */
    public function sendPasswordReset(string $email, string $name, string $resetUrl, int $expiresInMinutes): bool
    {
        return $this->safeSend(new MailMessage(
            to: $email,
            toName: $name,
            subject: 'Reset your password',
            bodyHtml: $this->wrap(
                '<h2>Reset your password</h2>'
                . '<p>We received a request to reset your password. This link expires in '
                . (int) $expiresInMinutes . ' minutes and can only be used once.</p>'
                . '<p><a href="' . View::e($resetUrl) . '" style="display:inline-block;padding:10px 18px;background:#1e3c72;color:#fff;text-decoration:none;border-radius:6px;">Reset Password</a></p>'
                . '<p>If you did not request this, you can safely ignore this email — your password will not change.</p>'
            )
        ));
    }

    /**
     * @param array<int,array{product_name:string,quantity:int,unit_price:string,line_total:string}> $items
     */
    public function sendOrderConfirmation(
        string $email,
        string $name,
        string $reference,
        array $items,
        string $total,
        string $orderUrl
    ): bool {
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . View::e($item['product_name']) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . (int) $item['quantity'] . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . View::money($item['line_total']) . '</td>'
                . '</tr>';
        }

        return $this->safeSend(new MailMessage(
            to: $email,
            toName: $name,
            subject: "Order confirmed — {$reference}",
            bodyHtml: $this->wrap(
                '<h2>Thanks for your order, ' . View::e($name) . '!</h2>'
                . '<p>Order <strong>' . View::e($reference) . '</strong> has been placed.</p>'
                . '<table style="width:100%;border-collapse:collapse;">'
                . '<thead><tr><th align="left" style="padding:6px 10px;">Product</th><th align="left" style="padding:6px 10px;">Qty</th><th align="left" style="padding:6px 10px;">Total</th></tr></thead>'
                . "<tbody>{$rows}</tbody>"
                . '</table>'
                . '<p><strong>Order total: ' . View::money($total) . '</strong></p>'
                . '<p><a href="' . View::e($orderUrl) . '">Track this order</a></p>'
            )
        ));
    }

    public function sendOrderStatusUpdate(string $email, string $name, string $reference, string $statusLabel, string $orderUrl): bool
    {
        return $this->safeSend(new MailMessage(
            to: $email,
            toName: $name,
            subject: "Order {$reference} is now {$statusLabel}",
            bodyHtml: $this->wrap(
                '<h2>Order update</h2>'
                . '<p>Your order <strong>' . View::e($reference) . '</strong> is now <strong>' . View::e($statusLabel) . '</strong>.</p>'
                . '<p><a href="' . View::e($orderUrl) . '">View order details</a></p>'
            )
        ));
    }

    private function wrap(string $bodyHtml): string
    {
        $storeName = View::e(Config::get('app.name', 'Shirt & Pant Store'));

        return '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;color:#1f2937;">'
            . $bodyHtml
            . "<hr style=\"margin-top:24px;border:none;border-top:1px solid #e5e7eb;\"><p style=\"font-size:12px;color:#6b7280;\">{$storeName}</p>"
            . '</div>';
    }

    private function safeSend(MailMessage $message): bool
    {
        try {
            $sent = MailerFactory::make()->send($message);

            if (!$sent) {
                Logger::warning('Notification email not sent', ['to' => $message->to, 'subject' => $message->subject]);
            }

            return $sent;
        } catch (Throwable $e) {
            Logger::error('Notification email failed', ['to' => $message->to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
