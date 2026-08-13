<?php
$pageTitle = 'Order Confirmation';
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['product_id'])) {
    header('Location: shop.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$product_id = (int) $_GET['product_id'];
$errorMessage = '';
$product = null;

$stmt = $conn->prepare('SELECT name, price, stock FROM products WHERE id = ?');
$stmt->bind_param('i', $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $errorMessage = 'Product not found.';
} else {
    $product = $result->fetch_assoc();

    if ($product['stock'] <= 0) {
        $errorMessage = 'Sorry, this product is out of stock.';
    } else {
        $total_amount = $product['price'];

        try {
            $conn->begin_transaction();

            $orderStmt = $conn->prepare('INSERT INTO single_order (user_id, product_id, total_amount) VALUES (?, ?, ?)');
            $orderStmt->bind_param('iid', $user_id, $product_id, $total_amount);
            $orderStmt->execute();
            $order_id = $conn->insert_id;
            $orderStmt->close();

            $stockStmt = $conn->prepare('UPDATE products SET stock = stock - 1 WHERE id = ? AND stock > 0');
            $stockStmt->bind_param('i', $product_id);
            $stockStmt->execute();
            $stockStmt->close();

            $payment_method = 'cash_on_delivery';
            $paymentStmt = $conn->prepare('INSERT INTO payments (order_id, user_id, total_amount, payment_method) VALUES (?, ?, ?, ?)');
            $paymentStmt->bind_param('iids', $order_id, $user_id, $total_amount, $payment_method);
            $paymentStmt->execute();
            $paymentStmt->close();

            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $errorMessage = 'Something went wrong while placing your order. Please try again.';
        }
    }
}
$stmt->close();
?>

<div class="container-narrow text-center">
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div>
        <a href="shop.php" class="btn">Back to Shop</a>
    <?php else: ?>
        <div class="alert alert-success">
            Order placed successfully! <strong><?php echo htmlspecialchars($product['name']); ?></strong>
            (&#2547; <?php echo number_format($total_amount, 2); ?>) will be delivered via cash on delivery.
        </div>
        <a href="myorder.php" class="btn">View My Orders</a>
        <a href="shop.php" class="btn btn-outline" style="background:var(--color-primary); margin-left:8px;">Buy More</a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
