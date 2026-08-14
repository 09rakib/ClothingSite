<?php
/**
 * Centralized configuration (PROJECT_RULES.md §19 "Secrets", Rule 5 "No hardcoded business rules").
 *
 * WHY this file exists:
 * Business rules such as the low-stock threshold, the allowed payment methods
 * and the upload limits used to be duplicated as magic numbers across pages
 * (e.g. `stock < 5` appeared in three files). Centralising them means changing
 * a rule is a one-line edit instead of a grep-and-hope exercise.
 *
 * SECRETS: never edit credentials here. Copy config.local.example.php to
 * config.local.php (which is git-ignored) and override there.
 */

$config = [

    /* ---------------------------------------------------------------
     | Application
     * --------------------------------------------------------------- */
    'app' => [
        'name'  => 'Shirt & Pant Store',
        'env'   => 'local',          // local | staging | production
        'debug' => true,             // MUST be false in production
        'url'   => '/ClothingSite-professional/ClothingSite',
        'currency_symbol' => '&#2547;',
    ],

    /* ---------------------------------------------------------------
     | Database
     * --------------------------------------------------------------- */
    'database' => [
        'host'    => 'localhost',
        'user'    => 'root',
        'pass'    => '',
        'name'    => 'onlineshopdb',
        'charset' => 'utf8mb4',
    ],

    /* ---------------------------------------------------------------
     | Session & security
     * --------------------------------------------------------------- */
    'security' => [
        // Session cookie hardening (§19 Authentication).
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => false,  // set true once HTTPS is enabled
        'csrf_token_name' => '_token',
        // Login rate limiting (§19 "Login rate limiting").
        'login_max_attempts'  => 5,
        'login_lockout_secs'  => 900,
    ],

    /* ---------------------------------------------------------------
     | Catalog / inventory business rules
     * --------------------------------------------------------------- */
    'catalog' => [
        // Was hardcoded as `stock < 5` in seller.php, shop.php and index.php.
        'low_stock_threshold' => 5,
        'products_per_page'   => 9,
        'sort_options'        => [
            'newest'     => 'Newest first',
            'price_asc'  => 'Price: low to high',
            'price_desc' => 'Price: high to low',
            'name_asc'   => 'Name: A to Z',
        ],
        'default_sort' => 'newest',
    ],

    /* ---------------------------------------------------------------
     | Uploads (§19 "Upload security")
     * --------------------------------------------------------------- */
    'uploads' => [
        'product_image_dir' => __DIR__ . '/../assets/images/products',
        'max_bytes'         => 2 * 1024 * 1024,   // 2 MB
        'min_width'         => 100,
        'min_height'        => 100,
        'max_width'         => 5000,
        'max_height'        => 5000,
        // Whitelist keyed by real (sniffed) MIME type => canonical extension.
        // The client-supplied MIME/filename is never trusted.
        'allowed_mime' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ],
    ],

    /* ---------------------------------------------------------------
     | Payments (§9 "Do not hardcode cash_on_delivery")
     * --------------------------------------------------------------- */
    'payments' => [
        'default_method' => 'cash_on_delivery',
        'methods' => [
            'cash_on_delivery' => ['label' => 'Cash on Delivery', 'enabled' => true],
            'bkash'            => ['label' => 'bKash',            'enabled' => false],
            'card'             => ['label' => 'Card',             'enabled' => false],
        ],
    ],

    /* ---------------------------------------------------------------
     | Mail (§20 "Email & Notifications")
     *
     * 'mailer' => 'log' (default) writes every message to
     * storage/logs/mail/ instead of a real inbox — no SMTP account exists
     * for this project. Set 'mailer' => 'smtp' and fill in smtp.* (normally
     * in the git-ignored config.local.php) to send for real.
     * --------------------------------------------------------------- */
    'mail' => [
        'mailer'      => 'log',
        'from_address' => 'no-reply@shirtpantstore.local',
        'from_name'    => 'Shirt & Pant Store',
        'smtp' => [
            'host'       => '',
            'port'       => 587,
            'username'   => '',
            'password'   => '',
            'encryption' => 'tls',
        ],
    ],

    /* ---------------------------------------------------------------
     | Reviews (§14)
     * --------------------------------------------------------------- */
    'reviews' => [
        // Only customers who actually bought (and had delivered) the product
        // may review it — a real "verified purchase" gate, not a checkbox.
        'require_verified_purchase' => true,
    ],
];

// Local overrides (git-ignored) — keeps credentials out of version control.
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (is_array($overrides)) {
        foreach ($overrides as $section => $values) {
            $config[$section] = is_array($values) && isset($config[$section]) && is_array($config[$section])
                ? array_replace_recursive($config[$section], $values)
                : $values;
        }
    }
}

return $config;
