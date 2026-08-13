<?php

declare(strict_types=1);

/**
 * Admin — create a product.
 *
 * Upload handling moved to ImageUploader, which verifies the real MIME type
 * from file content and generates the stored filename server-side. The old
 * code trusted `$_FILES['image']['name']` and the client-side `accept`
 * attribute (PROJECT_RULES.md §19 "Upload security").
 */

$pageTitle = 'Add Product';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\CategoryRepository;
use App\Catalog\ProductRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\ImageUploader;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

$categoryRepo = new CategoryRepository();
$productRepo  = new ProductRepository();
$categories   = $categoryRepo->all();

$errors = [];
$old    = ['name' => '', 'description' => '', 'price' => '', 'stock' => '', 'category_id' => ''];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('name', 'Product name')
        ->label('description', 'Description')
        ->label('price', 'Price')
        ->label('stock', 'Stock')
        ->label('category_id', 'Category')
        ->required('name')->maxLength('name', 120)
        ->required('description')->maxLength('description', 500)
        ->required('price')->decimal('price', 0, 99999999)
        ->required('stock')->integer('stock', 0, 1000000)
        ->required('category_id')->inList('category_id', $categoryRepo->validIds());

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    if (!ImageUploader::wasProvided($_FILES['image'] ?? null)) {
        $validator->fail('image', 'A product image is required.');
    }

    if ($validator->passes()) {
        try {
            // Store the image first: if it is rejected, no half-created
            // product row is left behind.
            $imageName = ImageUploader::store($_FILES['image']);

            $productRepo->create(
                $validator->value('name'),
                $validator->value('description'),
                $validator->value('price'),
                (int) $validator->value('stock'),
                $imageName,
                (int) $validator->value('category_id')
            );

            Logger::info('Product created', ['name' => $validator->value('name')]);

            // POST/Redirect/GET so a refresh cannot create a second product.
            Flash::success('Product added successfully.');
            Http::redirect('displayproduct.php');
        } catch (RuntimeException $e) {
            $validator->fail('image', $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('Product creation failed', ['error' => $e->getMessage()]);
            $validator->fail('name', 'Could not save the product. Please try again.');
        }
    }

    $errors = $validator->errors();
}
?>

<h1 class="page-heading">Add Product</h1>
<p class="page-subheading">Create a new product listing</p>

<div class="admin-card">
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $message): ?>
                <div><?= View::e($message) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
        <?= Csrf::field() ?>
        <div class="form-group">
            <label for="name">Product Name</label>
            <input type="text" name="name" id="name" value="<?= View::e($old['name']) ?>" maxlength="120" required>
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" maxlength="500" required><?= View::e($old['description']) ?></textarea>
        </div>
        <div class="form-group">
            <label for="price">Price (&#2547;)</label>
            <input type="number" step="0.01" min="0" name="price" id="price" value="<?= View::e($old['price']) ?>" required>
        </div>
        <div class="form-group">
            <label for="stock">Stock</label>
            <input type="number" min="0" name="stock" id="stock" value="<?= View::e($old['stock']) ?>" required>
        </div>
        <div class="form-group">
            <label for="image">Product Image</label>
            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <small class="form-hint">JPG, PNG, GIF or WebP. Maximum 2&nbsp;MB.</small>
        </div>
        <div class="form-group">
            <label for="category_id">Category</label>
            <select name="category_id" id="category_id" required>
                <option value="">Select Category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $old['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= View::e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-block">Add Product</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
