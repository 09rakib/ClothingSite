<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal file logger for technical errors (PROJECT_RULES.md §23).
 *
 * WHY separate from audit logs: this records *technical* failures (DB errors,
 * upload failures, exceptions) for developers. Business/audit events such as
 * "admin changed a price" belong in the audit_logs table instead, because they
 * must be queryable and retained.
 *
 * Never log passwords, tokens, card data or session identifiers.
 */
final class Logger
{
    /** Keys whose values are replaced before writing. */
    private const REDACT = ['password', 'confirm', 'token', '_token', 'card', 'cvv', 'secret'];

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // Logging must never break the request.
        }

        $line = sprintf(
            "[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Strip sensitive values so secrets never reach the log file (§23).
     */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }
            foreach (self::REDACT as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $context[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $context;
    }
}
