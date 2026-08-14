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

use App\Audit\AuditLogger;
use App\Catalog\CategoryRepository;
use App\Catalog\ProductImageRepository;
use App\Catalog\ProductRepository;
use App\Support\Auth;
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
    'name'                => (string) $product['name'],
    'sku'                 => (string) ($product['sku'] ?? ''),
    'description'         => (string) $product['description'],
    'price'               => (string) $product['price'],
    'stock'               => (string) $product['stock'],
    'low_stock_threshold' => (string) ($product['low_stock_threshold'] ?? ''),
    'category_id'         => (string) ($product['category_id'] ?? ''),
];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('name', 'Product name')
        ->label('sku', 'SKU')
        ->label('description', 'Description')
        ->label('price', 'Price')
        ->label('stock', 'Stock')
        ->label('low_stock_threshold', 'Low stock alert')
        ->label('category_id', 'Category')
        ->required('name')->maxLength('name', 120)
        ->maxLength('sku', 60)
        ->required('description')->maxLength('description', 500)
        ->required('price')->decimal('price', 0, 99999999)
        ->required('stock')->integer('stock', 0, 1000000)
        ->integer('low_stock_threshold', 0, 1000000)
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
                (int) $validator->value('category_id'),
                $validator->value('sku') ?: null,
                $validator->value('low_stock_threshold') === ''
                    ? null
                    : (int) $validator->value('low_stock_threshold')
            );

            // A replacement image becomes the new primary in the gallery too,
            // so the gallery and products.image never disagree.
            if ($previousImg !== null && $previousImg !== $imageName) {
                $images   = new ProductImageRepository();
                $newImage = $images->add($productId, $imageName, null, true);

                // Drop the old gallery row for the file being replaced.
                foreach ($images->forProduct($productId) as $galleryImage) {
                    if ($galleryImage['filename'] === $previousImg && $galleryImage['id'] !== $newImage) {
                        $images->delete($galleryImage['id']);
                    }
                }
            }

            Logger::info('Product updated', ['product_id' => $productId]);
            (new AuditLogger())->log((int) Auth::id(), 'product.updated', 'product', $productId, ['name' => $validator->value('name')]);

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
            <label for="sku">SKU <span class="optional">(optional)</span></label>
            <input type="text" name="sku" id="sku" value="<?= View::e($old['sku']) ?>" maxlength="60">
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
            <label for="low_stock_threshold">Low stock alert <span class="optional">(optional)</span></label>
            <input type="number" min="0" name="low_stock_threshold" id="low_stock_threshold"
                   value="<?= View::e($old['low_stock_threshold']) ?>"
                   placeholder="<?= (int) App\Support\Config::get('catalog.low_stock_threshold', 5) ?>">
            <small class="form-hint">Leave empty to use the store default (<?= (int) App\Support\Config::get('catalog.low_stock_threshold', 5) ?>).</small>
        </div>
        <div class="form-group">
            <label for="image">Primary Image</label><br>
            <img src="../assets/images/products/<?= View::e($product['image']) ?>" width="80"
                 alt="<?= View::e($product['name']) ?>" style="border-radius:6px; margin-bottom:8px;">
            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="form-hint">
                Leave empty to keep the current image. JPG, PNG, GIF or WebP, max 2&nbsp;MB.
                <a href="productimages.php?product_id=<?= (int) $productId ?>">Manage the full gallery</a>.
            </small>
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
