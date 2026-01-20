<?php
$conn = new mysqli("localhost", "root", "", "onlineshopdb");
if ($conn->connect_error) {
    die("Database connection failed");
}
?>
