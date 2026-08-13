<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;

/**
 * Applies migration files to a database.
 *
 * WHY this is a class rather than inline script code: the CLI runner
 * (database/migrate.php) and the test bootstrap both need to apply the exact
 * same files in the exact same way. Duplicating the loop meant the test schema
 * could silently drift from the real one — precisely the class of bug
 * PROJECT_RULES.md §3.1 warns about.
 *
 * Two file types are supported:
 *   *.sql — one or more statements, executed with multi_query.
 *   *.php — returns a callable that receives the mysqli connection, for
 *           changes that need application logic (e.g. generating slugs).
 */
final class Migrator
{
    public function __construct(private string $migrationsDir)
    {
    }

    /**
     * Every migration file, ordered by filename.
     *
     * @return array<int,string> absolute paths
     */
    public function all(): array
    {
        $files = array_merge(
            glob($this->migrationsDir . '/*.sql') ?: [],
            glob($this->migrationsDir . '/*.php') ?: []
        );

        usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }

    /**
     * Create the ledger table that records which migrations have run.
     */
    public function ensureLedger(mysqli $conn): void
    {
        $conn->query(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
    }

    /**
     * Names of migrations already applied.
     *
     * @return array<string,true>
     */
    public function applied(mysqli $conn): array
    {
        $this->ensureLedger($conn);

        $applied = [];
        $rows    = $conn->query('SELECT migration FROM migrations ORDER BY migration');
        while ($row = $rows->fetch_assoc()) {
            $applied[(string) $row['migration']] = true;
        }

        return $applied;
    }

    /**
     * Migration files that have not run yet.
     *
     * @return array<int,string>
     */
    public function pending(mysqli $conn): array
    {
        $applied = $this->applied($conn);

        return array_values(array_filter(
            $this->all(),
            static fn(string $file): bool => !isset($applied[basename($file)])
        ));
    }

    /**
     * Apply one migration file. Does not record it in the ledger — the caller
     * decides whether to track it (the test bootstrap does not need to).
     *
     * @throws RuntimeException when the migration fails.
     */
    public function apply(mysqli $conn, string $file): void
    {
        $name = basename($file);

        try {
            if (str_ends_with($name, '.php')) {
                $migration = require $file;

                if (!is_callable($migration)) {
                    throw new RuntimeException("PHP migration {$name} must return a callable.");
                }

                $migration($conn);

                return;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                return;
            }

            $conn->multi_query($sql);
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());

            // A statement after the first can fail without multi_query throwing.
            if ($conn->errno !== 0) {
                throw new mysqli_sql_exception($conn->error, $conn->errno);
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Migration {$name} failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Apply every pending migration, recording each in the ledger.
     *
     * @param callable(string):void|null $onEach Progress reporter.
     * @return array<int,string> names applied
     */
    public function migrate(mysqli $conn, ?callable $onEach = null): array
    {
        $this->ensureLedger($conn);

        $record  = $conn->prepare('INSERT INTO migrations (migration) VALUES (?)');
        $applied = [];

        foreach ($this->pending($conn) as $file) {
            $name = basename($file);

            $this->apply($conn, $file);

            $record->bind_param('s', $name);
            $record->execute();

            $applied[] = $name;

            if ($onEach !== null) {
                $onEach($name);
            }
        }

        $record->close();

        return $applied;
    }

    /**
     * Apply every migration to a freshly created schema, ignoring the ledger.
     * Used by the test bootstrap, which rebuilds the test database each run.
     */
    public function applyAllFresh(mysqli $conn): void
    {
        foreach ($this->all() as $file) {
            $this->apply($conn, $file);
        }
    }
}
