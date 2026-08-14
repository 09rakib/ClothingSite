<?php

declare(strict_types=1);

/**
 * Health check endpoint (PROJECT_RULES.md §28 "Monitoring").
 *
 * The foundation an external uptime/monitoring service would poll — this
 * project has no such service configured (no real hosting target yet), but
 * the endpoint itself is genuinely functional: it checks the things that
 * actually fail in production (database reachable, migrations applied,
 * storage writable) rather than just returning "ok" unconditionally, which
 * would be exactly the fake success Rule 12 forbids.
 *
 * Deliberately reveals no internals on failure — no stack trace, no query,
 * no config value — only which named check failed, which is enough for an
 * operator without helping an attacker.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Support\Config;
use App\Support\Database;
use App\Support\Migrator;

header('Content-Type: application/json');

$checks  = [];
$healthy = true;

/* ---------------- Database reachable ---------------- */
try {
    $conn = Database::connection();
    $conn->query('SELECT 1');
    $checks['database'] = true;
} catch (Throwable $e) {
    $checks['database'] = false;
    $healthy = false;
}

/* ---------------- Migrations up to date ---------------- */
if ($checks['database']) {
    try {
        $migrator = new Migrator(__DIR__ . '/database/migrations');
        $pending  = $migrator->pending(Database::connection());
        $checks['migrations_current'] = $pending === [];
        if ($pending !== []) {
            $healthy = false;
        }
    } catch (Throwable $e) {
        $checks['migrations_current'] = false;
        $healthy = false;
    }
}

/* ---------------- Storage writable (logs, uploads) ---------------- */
$storageWritable = is_writable(__DIR__ . '/storage/logs')
    && is_writable((string) Config::get('uploads.product_image_dir', __DIR__ . '/assets/images/products'));
$checks['storage_writable'] = $storageWritable;
if (!$storageWritable) {
    $healthy = false;
}

http_response_code($healthy ? 200 : 503);

echo json_encode([
    'status' => $healthy ? 'ok' : 'unhealthy',
    'checks' => $checks,
    'time'   => date('c'),
], JSON_PRETTY_PRINT);
