<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$product = $_POST['product_name'];
$price = $_POST['price'];

mysqli_query($conn, "INSERT INTO orders (user_id,total) VALUES ($user_id,$price)");
$order_id = mysqli_insert_id($conn);

mysqli_query($conn, "INSERT INTO order_items (order_id,product_name,price,quantity)
VALUES ($order_id,'$product',$price,1)");

header("Location: myorders.php");
