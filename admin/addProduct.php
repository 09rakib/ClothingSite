<?php
$pageTitle = 'Add Product';
require_once __DIR__ . '/../includes/admin-header.php';

$success = '';
$error = '';

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name');

if (isset($_POST['submit'])) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = $_POST['price'] ?? '';
    $stock       = $_POST['stock'] ?? '';
    $category_id = $_POST['category_id'] ?? '';

    if ($name === '' || $description === '' || $price === '' || $stock === '' || $category_id === '' || empty($_FILES['image']['name'])) {
        $error = 'All fields, including an image, are required.';
    } else {
        $imageName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', basename($_FILES['image']['name']));
        $uploadPath = __DIR__ . '/../assets/images/products/' . $imageName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
            $stmt = $conn->prepare('INSERT INTO products (name, description, price, stock, image, category_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssdisi', $name, $description, $price, $stock, $imageName, $category_id);

            if ($stmt->execute()) {
                $success = 'Product added successfully!';
            } else {
                $error = 'Could not save the product. Please try again.';
            }
            $stmt->close();
        } else {
            $error = 'Image upload failed. Please try again.';
        }
    }
}
?>

<h1 class="page-heading">Add Product</h1>
<p class="page-subheading">Create a new product listing</p>

<div class="admin-card">
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="productForm">
        <div class="form-group">
            <label for="name">Product Name</label>
            <input type="text" name="name" id="name" required>
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" required></textarea>
        </div>
        <div class="form-group">
            <label for="price">Price (&#2547;)</label>
            <input type="number" step="0.01" min="0" name="price" id="price" required>
        </div>
        <div class="form-group">
            <label for="stock">Stock</label>
            <input type="number" min="0" name="stock" id="stock" required>
        </div>
        <div class="form-group">
            <label for="image">Product Image</label>
            <input type="file" name="image" id="image" accept="image/*" required>
        </div>
        <div class="form-group">
            <label for="category_id">Category</label>
            <select name="category_id" id="category_id" required>
                <option value="">Select Category</option>
                <?php while ($row = $categories->fetch_assoc()): ?>
                    <option value="<?php echo (int) $row['id']; ?>"><?php echo htmlspecialchars($row['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <button type="submit" name="submit" class="btn btn-block">Add Product</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
