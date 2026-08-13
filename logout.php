<?php

declare(strict_types=1);

/**
 * Log the current user out.
 *
 * Logout changes server state, so it requires a CSRF-verified POST
 * (PROJECT_RULES.md §19 "HTTP methods"). As a plain GET link, any page on the
 * internet could have logged a visitor out with an <img src="...logout.php">.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Http;

Http::requirePost();

Auth::logout();

Http::redirect('index.php');
