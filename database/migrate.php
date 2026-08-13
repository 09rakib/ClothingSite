<?php

declare(strict_types=1);

/**
 * Migration runner (PROJECT_RULES.md Rule 8, §6.10 "All schema changes must be
 * migration-based").
 *
 * WHY: the project previously had a single database.sql that had to be
 * re-imported by hand, which meant an existing database could not be upgraded
 * without losing data. Migrations are numbered, applied once, and recorded in a
 * `migrations` table so running this script is safe and repeatable.
 *
 * The apply logic itself lives in App\Support\Migrator so that the test
 * bootstrap builds its schema from exactly the same code path.
 *
 * Usage:
 *   php database/migrate.php            Apply all pending migrations
 *   php database/migrate.php --status   Show applied/pending without changing anything
 *   php database/migrate.php --backup   Dump the database before migrating
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Support\Config;
use App\Support\Database;
use App\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migrations may only be run from the command line.\n");
}

$options    = $argv ?? [];
$statusOnly = in_array('--status', $options, true);
$doBackup   = in_array('--backup', $options, true);

/**
 * Ensure the target database exists before connecting to it, so a brand-new
 * machine can go from nothing to a working schema with one command.
 */
function ensureDatabaseExists(): void
{
    $name = (string) Config::require('database.name');

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $server = new mysqli(
        (string) Config::require('database.host'),
        (string) Config::require('database.user'),
        (string) Config::get('database.pass', '')
    );

    // The name comes from server-side config, not user input, but it is still
    // escaped with backticks to keep the statement well-formed.
    $safeName = str_replace('`', '``', $name);
    $server->query("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->close();
}

/**
 * Dump the database to database/backups (§28 "Backup").
 */
function backupDatabase(): void
{
    $name    = (string) Config::require('database.name');
    $dir     = __DIR__ . '/backups';
    $outFile = $dir . '/' . $name . '-' . date('Ymd-His') . '.sql';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if (!is_file($mysqldump)) {
        $mysqldump = 'mysqldump';
    }

    $pass = (string) Config::get('database.pass', '');

    $command = sprintf(
        '"%s" -h %s -u %s %s %s --routines --single-transaction --result-file="%s"',
        $mysqldump,
        escapeshellarg((string) Config::require('database.host')),
        escapeshellarg((string) Config::require('database.user')),
        $pass !== '' ? '-p' . escapeshellarg($pass) : '',
        escapeshellarg($name),
        $outFile
    );

    exec($command . ' 2>&1', $output, $exitCode);

    if ($exitCode === 0 && is_file($outFile)) {
        echo "  Backup written to {$outFile}\n";
    } else {
        echo "  WARNING: backup failed (" . implode(' ', $output) . ")\n";
        echo "  Continuing without a backup is risky. Ctrl+C now if this matters.\n";
    }
}

ensureDatabaseExists();

$conn     = Database::connection();
$migrator = new Migrator(__DIR__ . '/migrations');

$applied = $migrator->applied($conn);
$pending = $migrator->pending($conn);

if ($statusOnly) {
    echo "Applied migrations:\n";
    if ($applied === []) {
        echo "  (none)\n";
    }
    foreach (array_keys($applied) as $migration) {
        echo "  [x] {$migration}\n";
    }

    echo "\nPending migrations:\n";
    if ($pending === []) {
        echo "  (none)\n";
    }
    foreach ($pending as $file) {
        echo "  [ ] " . basename($file) . "\n";
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to migrate. Database is up to date.\n";
    exit(0);
}

echo count($pending) . " migration(s) pending.\n";

if ($doBackup) {
    echo "Backing up database first...\n";
    backupDatabase();
}

try {
    $migrator->migrate($conn, static function (string $name): void {
        echo "  Applied {$name}\n";
    });
} catch (Throwable $e) {
    echo "\nFAILED\n";
    echo '  ' . $e->getMessage() . "\n";
    echo "  The failing migration was not recorded. Fix it and re-run.\n";
    exit(1);
}

echo "Migrations complete.\n";
