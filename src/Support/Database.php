<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;

/**
 * Single shared mysqli connection.
 *
 * WHY a class instead of the old includes/db.php global:
 * the credentials now come from config (so they can be overridden per
 * environment without editing code), and one connection object is reused
 * for the whole request instead of being re-created by each include.
 *
 * mysqli is put into exception mode so a failing query aborts loudly rather
 * than silently returning false and corrupting a multi-step operation.
 */
final class Database
{
    private static ?mysqli $connection = null;

    public static function connection(): mysqli
    {
        if (self::$connection instanceof mysqli) {
            return self::$connection;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $connection = new mysqli(
                (string) Config::require('database.host'),
                (string) Config::require('database.user'),
                (string) Config::get('database.pass', ''),
                (string) Config::require('database.name')
            );
            $connection->set_charset((string) Config::get('database.charset', 'utf8mb4'));
        } catch (mysqli_sql_exception $e) {
            // Never leak credentials or SQL internals to the browser.
            Logger::error('Database connection failed', ['error' => $e->getMessage()]);

            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }

            http_response_code(500);
            exit('Database connection failed. Please make sure MySQL is running and the database has been migrated (php database/migrate.php).');
        }

        self::$connection = $connection;

        return self::$connection;
    }

    /**
     * Run a callback inside a transaction, rolling back on any exception.
     *
     * WHY: PROJECT_RULES.md Rule 9 requires transactions for order creation,
     * stock changes and other multi-table writes. Wrapping them here means no
     * caller can forget the rollback path.
     *
     * @template T
     * @param callable(mysqli):T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $connection = self::connection();
        $connection->begin_transaction();

        try {
            $result = $callback($connection);
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Reset the shared connection (used by tests).
     */
    public static function reset(): void
    {
        if (self::$connection instanceof mysqli) {
            self::$connection->close();
        }
        self::$connection = null;
    }
}
