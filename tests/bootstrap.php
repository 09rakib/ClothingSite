<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Feature tests run against a REAL but SEPARATE database
 * (onlineshopdb_test), created and migrated on demand. Using a throwaway
 * schema rather than mocks means the tests actually prove the foreign keys,
 * CHECK constraints and transactions behave as intended — which is the whole
 * point of the Phase 0 integrity work.
 *
 * The development database is never touched by the test suite.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\Config;

// Point the application at the test database before anything connects.
$baseConfig = require __DIR__ . '/../config/config.php';

$baseConfig['database']['name'] = $baseConfig['database']['name'] . '_test';
$baseConfig['app']['debug']     = true;

// Keep uploads made by tests out of the real product image directory.
$testUploadDir = sys_get_temp_dir() . '/clothingsite-test-uploads';
if (!is_dir($testUploadDir)) {
    mkdir($testUploadDir, 0775, true);
}
$baseConfig['uploads']['product_image_dir'] = $testUploadDir;

Config::load($baseConfig);

/**
 * Create the test database and run every migration into it.
 */
(static function () use ($baseConfig): void {
    $dbName = $baseConfig['database']['name'];

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $server = new mysqli(
            $baseConfig['database']['host'],
            $baseConfig['database']['user'],
            $baseConfig['database']['pass']
        );
    } catch (mysqli_sql_exception $e) {
        fwrite(STDERR, "\nCannot reach MySQL — is it running?\n" . $e->getMessage() . "\n");
        exit(1);
    }

    $safeName = str_replace('`', '``', $dbName);

    // Always start from a clean schema so tests are order-independent.
    $server->query("DROP DATABASE IF EXISTS `{$safeName}`");
    $server->query("CREATE DATABASE `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->select_db($dbName);

    foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $migration) {
        $sql = (string) file_get_contents($migration);

        $server->multi_query($sql);
        do {
            if ($result = $server->store_result()) {
                $result->free();
            }
        } while ($server->more_results() && $server->next_result());

        if ($server->errno !== 0) {
            fwrite(STDERR, "\nMigration failed in test setup: " . basename($migration) . "\n" . $server->error . "\n");
            exit(1);
        }
    }

    $server->close();
})();
