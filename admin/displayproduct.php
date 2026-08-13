<?php
$pageTitle = 'View Products';
require_once __DIR__ . '/../includes/admin-header.php';

$result = $conn->query(
    'SELECT p.id, p.name, p.description, p.price, p.stock, p.image, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     ORDER BY p.created_at DESC'
);
?>

<h1 class="page-heading">Products</h1>
<p class="page-subheading">All items currently listed in the store</p>

<?php if ($result->num_rows === 0): ?>
    <div class="empty-state">
        No products yet.<br>
        <a href="addProduct.php" class="btn mt-16">Add Your First Product</a>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><img src="../assets/images/products/<?php echo htmlspecialchars($row['image']); ?>" class="table-img" alt=""></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?></td>
                        <td>&#2547; <?php echo number_format($row['price'], 2); ?></td>
                        <td><?php echo (int) $row['stock']; ?></td>
                        <td>
                            <a href="updateproduct.php?product_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-success">Update</a>
                            <a href="deleteproduct.php?product_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this product?');">Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
