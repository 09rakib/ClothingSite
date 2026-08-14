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

$categoryRepo = new CategoryRepository();
$productRepo  = new ProductRepository();
$categories   = $categoryRepo->all();

$errors = [];
$old    = [
    'name'                => '',
    'sku'                 => '',
    'description'         => '',
    'price'               => '',
    'stock'               => '',
    'low_stock_threshold' => '',
    'category_id'         => '',
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

    if (!ImageUploader::wasProvided($_FILES['image'] ?? null)) {
        $validator->fail('image', 'A product image is required.');
    }

    if ($validator->passes()) {
        try {
            // Store the image first: if it is rejected, no half-created
            // product row is left behind.
            $imageName = ImageUploader::store($_FILES['image']);

            $newProductId = $productRepo->create(
                $validator->value('name'),
                $validator->value('description'),
                $validator->value('price'),
                (int) $validator->value('stock'),
                $imageName,
                (int) $validator->value('category_id'),
                $validator->value('sku') ?: null,
                // Empty means "use the store-wide default from config".
                $validator->value('low_stock_threshold') === ''
                    ? null
                    : (int) $validator->value('low_stock_threshold')
            );

            // Seed the gallery so the product page and the admin image manager
            // both see this upload as the primary image.
            (new ProductImageRepository())->add($newProductId, $imageName, null, true);

            Logger::info('Product created', ['product_id' => $newProductId]);
            (new AuditLogger())->log((int) Auth::id(), 'product.created', 'product', $newProductId, ['name' => $validator->value('name')]);

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
            <label for="sku">SKU <span class="optional">(optional)</span></label>
            <input type="text" name="sku" id="sku" value="<?= View::e($old['sku']) ?>" maxlength="60">
            <small class="form-hint">Your own stock-keeping code, shown on the product page.</small>
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
