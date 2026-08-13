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
 * Usage:
 *   php database/migrate.php            Apply all pending migrations
 *   php database/migrate.php --status   Show applied/pending without changing anything
 *   php database/migrate.php --backup   Dump the database before migrating
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Support\Config;
use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migrations may only be run from the command line.\n");
}

$options    = $argv ?? [];
$statusOnly = in_array('--status', $options, true);
$doBackup   = in_array('--backup', $options, true);

$migrationsDir = __DIR__ . '/migrations';

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

    $user = (string) Config::require('database.user');
    $pass = (string) Config::get('database.pass', '');

    $command = sprintf(
        '"%s" -h %s -u %s %s %s --routines --single-transaction --result-file="%s"',
        $mysqldump,
        escapeshellarg((string) Config::require('database.host')),
        escapeshellarg($user),
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

$conn = Database::connection();

// Ledger of applied migrations.
$conn->query(
    'CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$applied = [];
$rows    = $conn->query('SELECT migration FROM migrations ORDER BY migration');
while ($row = $rows->fetch_assoc()) {
    $applied[$row['migration']] = true;
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pending = array_values(array_filter(
    $files,
    static fn(string $file): bool => !isset($applied[basename($file)])
));

if ($statusOnly) {
    echo "Applied migrations:\n";
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

$record = $conn->prepare('INSERT INTO migrations (migration) VALUES (?)');

foreach ($pending as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);

    if ($sql === false || trim($sql) === '') {
        echo "  SKIP {$name} (empty)\n";
        continue;
    }

    echo "  Applying {$name}... ";

    try {
        // multi_query lets one migration file contain several statements.
        $conn->multi_query($sql);
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        // Surface an error raised by any statement after the first.
        if ($conn->errno !== 0) {
            throw new mysqli_sql_exception($conn->error, $conn->errno);
        }

        $record->bind_param('s', $name);
        $record->execute();

        echo "done\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  Migration {$name} was not recorded. Fix the SQL and re-run.\n";
        exit(1);
    }
}

$record->close();

echo "Migrations complete.\n";
