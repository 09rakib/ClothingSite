<?php
$pageTitle = 'Update Product';
require_once __DIR__ . '/../includes/admin-header.php';

if (!isset($_GET['product_id'])) {
    header('Location: displayproduct.php');
    exit;
}

$product_id = (int) $_GET['product_id'];
$error = '';

$stmt = $conn->prepare('SELECT * FROM products WHERE id = ?');
$stmt->bind_param('i', $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: displayproduct.php');
    exit;
}

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name');

if (isset($_POST['submit'])) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = $_POST['price'] ?? '';
    $stock       = $_POST['stock'] ?? '';
    $category_id = $_POST['category_id'] ?? '';
    $imageName   = $product['image'];

    if (!empty($_FILES['image']['name'])) {
        $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', basename($_FILES['image']['name']));
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../assets/images/products/' . $imageName);
    }

    $update = $conn->prepare('UPDATE products SET name=?, description=?, price=?, stock=?, image=?, category_id=? WHERE id=?');
    $update->bind_param('ssdisii', $name, $description, $price, $stock, $imageName, $category_id, $product_id);

    if ($update->execute()) {
        header('Location: displayproduct.php');
        exit;
    }
    $error = 'Could not update the product. Please try again.';
    $update->close();
}
?>

<h1 class="page-heading">Update Product</h1>
<p class="page-subheading">Editing: <?php echo htmlspecialchars($product['name']); ?></p>

<div class="admin-card">
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label for="name">Product Name</label>
            <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" required><?php echo htmlspecialchars($product['description']); ?></textarea>
        </div>
        <div class="form-group">
            <label for="price">Price (&#2547;)</label>
            <input type="number" step="0.01" min="0" name="price" id="price" value="<?php echo htmlspecialchars($product['price']); ?>" required>
        </div>
        <div class="form-group">
            <label for="stock">Stock</label>
            <input type="number" min="0" name="stock" id="stock" value="<?php echo (int) $product['stock']; ?>" required>
        </div>
        <div class="form-group">
            <label>Current Image</label>
            <img src="../assets/images/products/<?php echo htmlspecialchars($product['image']); ?>" width="80" style="border-radius:6px; margin-bottom:8px;">
            <input type="file" name="image" accept="image/*">
        </div>
        <div class="form-group">
            <label for="category_id">Category</label>
            <select name="category_id" id="category_id" required>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo $cat['id'] == $product['category_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <button type="submit" name="submit" class="btn btn-block">Update Product</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
