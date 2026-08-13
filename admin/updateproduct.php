<?php

declare(strict_types=1);

/**
 * Admin — edit a product.
 *
 * Notable fixes over the previous version:
 *   - The replaced image file is now deleted, instead of being orphaned in
 *     assets/images/products forever.
 *   - A failed image upload no longer silently keeps the old image while
 *     pretending the upload worked; the error is surfaced.
 *   - The form is CSRF protected and re-validated server-side.
 */

$pageTitle = 'Update Product';
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

$productRepo  = new ProductRepository();
$categoryRepo = new CategoryRepository();

// GET carries the id for the edit form; POST carries it as a hidden field.
$productId = Http::intParam($_POST, 'product_id') ?? Http::intParam($_GET, 'product_id');

if ($productId === null) {
    Flash::error('No product was selected.');
    Http::redirect('displayproduct.php');
}

$product = $productRepo->find($productId);

if ($product === null) {
    Flash::error('That product no longer exists.');
    Http::redirect('displayproduct.php');
}

$categories = $categoryRepo->all();
$errors     = [];

$old = [
    'name'        => (string) $product['name'],
    'description' => (string) $product['description'],
    'price'       => (string) $product['price'],
    'stock'       => (string) $product['stock'],
    'category_id' => (string) ($product['category_id'] ?? ''),
];

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

    if ($validator->passes()) {
        $imageName   = (string) $product['image'];
        $previousImg = null;

        try {
            // Only replace the image when a new file was actually submitted.
            if (ImageUploader::wasProvided($_FILES['image'] ?? null)) {
                $imageName   = ImageUploader::store($_FILES['image']);
                $previousImg = (string) $product['image'];
            }

            $productRepo->update(
                $productId,
                $validator->value('name'),
                $validator->value('description'),
                $validator->value('price'),
                (int) $validator->value('stock'),
                $imageName,
                (int) $validator->value('category_id')
            );

            // Remove the superseded file only after the row is safely updated,
            // so a failed update never leaves a product pointing at a
            // deleted image.
            if ($previousImg !== null && $previousImg !== $imageName) {
                ImageUploader::delete($previousImg);
            }

            Logger::info('Product updated', ['product_id' => $productId]);

            Flash::success('Product updated successfully.');
            Http::redirect('displayproduct.php');
        } catch (RuntimeException $e) {
            $validator->fail('image', $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('Product update failed', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
            $validator->fail('name', 'Could not update the product. Please try again.');
        }
    }

    $errors = $validator->errors();
}
?>

<h1 class="page-heading">Update Product</h1>
<p class="page-subheading">Editing: <?= View::e($product['name']) ?></p>

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
        <input type="hidden" name="product_id" value="<?= (int) $productId ?>">

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
            <label for="image">Current Image</label><br>
            <img src="../assets/images/products/<?= View::e($product['image']) ?>" width="80"
                 alt="<?= View::e($product['name']) ?>" style="border-radius:6px; margin-bottom:8px;">
            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="form-hint">Leave empty to keep the current image. JPG, PNG, GIF or WebP, max 2&nbsp;MB.</small>
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
        <button type="submit" class="btn btn-block">Update Product</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
