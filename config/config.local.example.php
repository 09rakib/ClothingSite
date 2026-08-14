<?php
/**
 * Copy this file to config.local.php and put real credentials there.
 * config.local.php is git-ignored so secrets never reach the repository
 * (PROJECT_RULES.md §19 "Secrets", §29 "Do not commit .env / secrets").
 *
 * Only the keys you want to override need to be present; they are merged
 * recursively over config/config.php.
 */

return [
    'app' => [
        'env'   => 'local',
        'debug' => true,
    ],

    'database' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'onlineshopdb',
    ],

    // In production also set:
    // 'security' => ['cookie_secure' => true],

    // To send real email instead of logging it to storage/logs/mail/:
    // 'mail' => [
    //     'mailer' => 'smtp',
    //     'smtp' => [
    //         'host'     => 'smtp.example.com',
    //         'port'     => 587,
    //         'username' => 'apikey-or-username',
    //         'password' => 'secret',
    //     ],
    // ],
];
