<?php

declare(strict_types=1);

/**
 * Application bootstrap — the single entry point every page goes through.
 *
 * Responsibilities:
 *   1. Autoload classes from src/ under the App\ namespace.
 *   2. Load configuration (and local secret overrides).
 *   3. Start a hardened session.
 *   4. Apply baseline security headers.
 *
 * WHY a hand-written autoloader rather than requiring Composer at runtime:
 * PROJECT_RULES.md §3.2 forbids building a fake framework, but the app must
 * also keep working on a plain XAMPP install where `composer install` has not
 * been run. Composer's autoloader is used when present (it is what the test
 * suite relies on) and this PSR-4 fallback covers the web request otherwise.
 */

use App\Support\Config;
use App\Support\Http;
use App\Support\Session;

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix  = 'App\\';
        $baseDir = __DIR__ . '/';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

Config::load();

// Errors are logged, never rendered to visitors in production (§19).
if ((bool) Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

if (PHP_SAPI !== 'cli') {
    Http::securityHeaders();
    Session::start();
}
