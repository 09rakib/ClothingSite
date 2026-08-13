<?php
$pageTitle = 'Shop';
require_once __DIR__ . '/includes/header.php';

$stmt = $conn->prepare('SELECT id, name, description, price, stock, image FROM products ORDER BY name ASC');
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container">
    <h1 class="page-heading">Shop All Products</h1>
    <p class="page-subheading">Browse our full collection of shirts and pants</p>

    <?php if ($result->num_rows === 0): ?>
        <div class="empty-state">No products available right now. Please check back later.</div>
    <?php else: ?>
        <div class="product-grid">
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="product-card">
                    <img src="assets/images/products/<?php echo htmlspecialchars($row['image']); ?>" alt="<?php echo htmlspecialchars($row['name']); ?>">
                    <div class="product-body">
                        <h3><?php echo htmlspecialchars($row['name']); ?></h3>
                        <p class="product-desc"><?php echo htmlspecialchars($row['description']); ?></p>
                        <div class="product-meta">
                            <span class="product-price">&#2547; <?php echo number_format($row['price'], 2); ?></span>
                            <span class="stock-badge <?php echo $row['stock'] < 5 ? 'low' : ''; ?>">
                                <?php echo $row['stock'] > 0 ? $row['stock'] . ' in stock' : 'Out of stock'; ?>
                            </span>
                        </div>

                        <?php if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'user'): ?>
                            <?php if ($row['stock'] > 0): ?>
                                <a href="singleorder.php?product_id=<?php echo (int) $row['id']; ?>" class="btn btn-block">Buy Now</a>
                            <?php else: ?>
                                <span class="btn btn-block" style="background:#9ca3af; cursor:not-allowed;">Out of Stock</span>
                            <?php endif; ?>
                        <?php elseif (isset($_SESSION['user_id'])): ?>
                            <span class="btn btn-block" style="background:#9ca3af; cursor:not-allowed;">Admin View</span>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-block">Login to Buy</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
