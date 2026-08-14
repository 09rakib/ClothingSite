<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\LogMailer;
use App\Mail\MailerFactory;
use App\Mail\MailMessage;
use App\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

/**
 * Mail abstraction (PROJECT_RULES.md §20).
 *
 * LogMailer is a real, working default — not a stub — so these tests verify
 * it actually captures the message content, not just that it returns true.
 */
final class MailerTest extends TestCase
{
    private ?string $writtenFile = null;

    protected function tearDown(): void
    {
        // Clean up the one file this test wrote, without touching anything
        // else that might be in the mail log directory.
        if ($this->writtenFile !== null && is_file($this->writtenFile)) {
            unlink($this->writtenFile);
        }
    }

    public function test_log_mailer_writes_the_message_to_disk(): void
    {
        $mailer  = new LogMailer();
        $unique  = 'unit-test-' . bin2hex(random_bytes(4));
        $message = new MailMessage(
            to: 'reader@example.com',
            subject: "Subject {$unique}",
            bodyHtml: "<p>Body {$unique}</p>",
            toName: 'Reader'
        );

        $this->assertTrue($mailer->send($message));

        $dir   = __DIR__ . '/../../storage/logs/mail';
        $files = glob($dir . '/*reader-example-com*.html') ?: [];

        $this->assertNotEmpty($files, 'Expected a log file to be written for this recipient.');

        // Find the one we just wrote by its unique marker, in case other
        // tests/log files for the same address already exist.
        $match = null;
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), $unique)) {
                $match = $file;
                break;
            }
        }

        $this->assertNotNull($match, 'Could not find the log file for this specific message.');
        $this->writtenFile = $match;

        $content = (string) file_get_contents($match);
        $this->assertStringContainsString("Subject {$unique}", $content);
        $this->assertStringContainsString("Body {$unique}", $content);
    }

    public function test_factory_defaults_to_log_mailer(): void
    {
        $this->assertInstanceOf(LogMailer::class, MailerFactory::make());
    }
}
