<?php
$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';

/* Show a small preview of featured products on the home page */
$stmt = $conn->prepare('SELECT id, name, description, price, stock, image FROM products ORDER BY created_at DESC LIMIT 3');
$stmt->execute();
$result = $stmt->get_result();
?>

<section class="hero">
    <h1>Quality Shirts &amp; Pants, Delivered to Your Door</h1>
    <p>Everyday essentials made from comfortable fabric, at honest prices.</p>
    <a href="shop.php" class="btn btn-outline">Browse All Products</a>
</section>

<div class="container">
    <h2 class="page-heading">Featured Products</h2>
    <p class="page-subheading">A few of our customer favorites</p>

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
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="singleorder.php?product_id=<?php echo (int) $row['id']; ?>" class="btn btn-block">Buy Now</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-block">Login to Buy</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <p class="text-center mt-16">
        <a href="shop.php" class="btn">View Full Shop</a>
    </p>
</div>

<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
