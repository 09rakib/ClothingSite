<?php

/**
 * BACKWARD-COMPATIBILITY SHIM.
 *
 * Database credentials and connection handling now live in
 * config/config.php and src/Support/Database.php. This file remains only so
 * that any page still doing `require_once 'includes/db.php'` and using `$conn`
 * keeps working during the incremental refactor (PROJECT_RULES.md Rule 2 —
 * prefer incremental, testable refactoring over a big-bang rewrite).
 *
 * New code should use App\Support\Database::connection() directly, or better,
 * a repository from src/Catalog or src/Orders.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$conn = App\Support\Database::connection();
