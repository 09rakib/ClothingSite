<?php
/**
 * Database connection.
 * mysqli is set to throw exceptions on error instead of failing silently,
 * and the connection charset is fixed to utf8mb4 so names/descriptions
 * with special characters (Bangla, emoji, etc.) store correctly.
 *
 * Update the credentials below to match your local MySQL setup.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'onlineshopdb');

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Database connection failed. Please make sure MySQL is running and "onlineshopdb" has been imported from database.sql.');
}
