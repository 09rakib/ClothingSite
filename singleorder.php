<?php
session_start();
include "db.php";

/* User must be logged in */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* Product must be selected */
if (!isset($_GET['product_id'])) {
    header("Location: index.php");
    exit;
}

$product_id = (int) $_GET['product_id'];

/* Get product info */
$sql_product = "SELECT price, stock FROM products WHERE id = '$product_id'";
$result_product = mysqli_query($conn, $sql_product);

if (!$result_product || mysqli_num_rows($result_product) == 0) {
    die("Product not found");
}

$product = mysqli_fetch_assoc($result_product);

/* Check stock */
if ($product['stock'] <= 0) {
    die("Product out of stock");
}

$total_amount = $product['price'];

/* Insert order */
$sql_order = "INSERT INTO single_order (user_id, product_id, total_amount)
              VALUES ('$user_id', '$product_id', '$total_amount')";

if (!mysqli_query($conn, $sql_order)) {
    die("Order Error: " . mysqli_error($conn));
}

$order_id = mysqli_insert_id($conn);

/* Reduce stock */
$sql_update_stock = "UPDATE products 
                     SET stock = stock - 1 
                     WHERE id = '$product_id'";

if (!mysqli_query($conn, $sql_update_stock)) {
    die("Stock Update Error: " . mysqli_error($conn));
}

/* Insert payment */
$payment_method = "cash_on_delivery";

$sql_payment = "INSERT INTO payments (order_id, user_id,total_amount, payment_method)
                VALUES ('$order_id', '$user_id','$total_amount' ,'$payment_method')";

if (!mysqli_query($conn, $sql_payment)) {
    die("Payment Error: " . mysqli_error($conn));
}

echo " Order placed successfully! <a href='index.php'>Buy More</a>";
?>
