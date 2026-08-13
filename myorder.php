<?php
$pageTitle = 'My Orders';
require_once __DIR__ . '/includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['user_role'] === 'admin') {
    header('Location: admin/seller.php');
    exit;
}

$stmt = $conn->prepare(
    'SELECT p.order_id, pr.name AS product_name, p.total_amount, p.payment_method, p.created_at
     FROM payments p
     JOIN single_order so ON p.order_id = so.id
     JOIN products pr ON so.product_id = pr.id
     WHERE p.user_id = ?
     ORDER BY p.created_at DESC'
);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container">
    <h1 class="page-heading">My Orders</h1>
    <p class="page-subheading">Everything you've bought from us so far</p>

    <?php if ($result->num_rows === 0): ?>
        <div class="empty-state">
            You haven't placed any orders yet.<br>
            <a href="shop.php" class="btn mt-16">Start Shopping</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Product</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo (int) $row['order_id']; ?></td>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td>&#2547; <?php echo number_format($row['total_amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $row['payment_method']))); ?></td>
                            <td><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
