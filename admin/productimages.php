<?php

declare(strict_types=1);

/**
 * Admin — manage a product's image gallery
 * (PROJECT_RULES.md §11 "multiple images / primary image", §30 Phase 1).
 *
 * Uploads go through the same hardened ImageUploader as the main product form:
 * the real MIME type is sniffed from file content and the stored filename is
 * generated server-side, so nothing here trusts the browser.
 */

$pageTitle = 'Product Images';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\ProductImageRepository;
use App\Catalog\ProductRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\ImageUploader;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

$productRepo = new ProductRepository();
$imageRepo   = new ProductImageRepository();

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

$errors = [];

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action  = (string) ($_POST['action'] ?? 'upload');
    $imageId = Http::intParam($_POST, 'image_id');

    /* ---------------- Set primary ---------------- */
    if ($action === 'primary' && $imageId !== null) {
        $image = $imageRepo->find($imageId);

        // Ownership check: the image must belong to THIS product, otherwise a
        // forged image_id could repoint another product's gallery (§19 IDOR).
        if ($image === null || (int) $image['product_id'] !== $productId) {
            Flash::error('That image does not belong to this product.');
        } else {
            $imageRepo->makePrimary($imageId);
            Logger::info('Product primary image changed', ['product_id' => $productId, 'image_id' => $imageId]);
            Flash::success('Primary image updated.');
        }

        Http::redirect('productimages.php?product_id=' . $productId);
    }

    /* ---------------- Delete ---------------- */
    if ($action === 'delete' && $imageId !== null) {
        $image = $imageRepo->find($imageId);

        if ($image === null || (int) $image['product_id'] !== $productId) {
            Flash::error('That image does not belong to this product.');
        } elseif (!$imageRepo->delete($imageId)) {
            // Refused rather than silently ignored, so the admin knows why.
            Flash::error('A product must keep at least one image. Upload a replacement first.');
        } else {
            Logger::info('Product image deleted', ['product_id' => $productId, 'image_id' => $imageId]);
            Flash::success('Image removed.');
        }

        Http::redirect('productimages.php?product_id=' . $productId);
    }

    /* ---------------- Upload ---------------- */
    $validator = (new Validator($_POST))
        ->label('alt_text', 'Alt text')
        ->maxLength('alt_text', 200);

    if (!ImageUploader::wasProvided($_FILES['image'] ?? null)) {
        $validator->fail('image', 'Please choose an image to upload.');
    }

    if ($validator->passes()) {
        try {
            $filename = ImageUploader::store($_FILES['image']);
            $altText  = $validator->value('alt_text') ?: null;

            $imageRepo->add($productId, $filename, $altText, !empty($_POST['make_primary']));

            Logger::info('Product image added', ['product_id' => $productId]);
            Flash::success('Image added to the gallery.');
            Http::redirect('productimages.php?product_id=' . $productId);
        } catch (RuntimeException $e) {
            $validator->fail('image', $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('Product image upload failed', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
            $validator->fail('image', 'Could not save the image. Please try again.');
        }
    }

    $errors = $validator->errors();
}

$gallery = $imageRepo->forProduct($productId);
?>

<h1 class="page-heading">Product Images</h1>
<p class="page-subheading"><?= View::e($product['name']) ?></p>

<p class="mt-16 mb-16">
    <a href="updateproduct.php?product_id=<?= $productId ?>" class="btn btn-sm btn-success">Edit product details</a>
    <a href="displayproduct.php" class="btn btn-sm">Back to products</a>
</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $message): ?>
            <div><?= View::e($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-split">
    <div class="admin-card">
        <h2 class="card-title">Add Image</h2>

        <form method="post" action="productimages.php" enctype="multipart/form-data" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="product_id" value="<?= $productId ?>">

            <div class="form-group">
                <label for="image">Image file</label>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <small class="form-hint">JPG, PNG, GIF or WebP. Maximum 2&nbsp;MB.</small>
            </div>

            <div class="form-group">
                <label for="alt_text">Alt text <span class="optional">(optional)</span></label>
                <input type="text" id="alt_text" name="alt_text" maxlength="200"
                       placeholder="Describes the image for screen readers">
            </div>

            <div class="form-group form-check">
                <input type="checkbox" id="make_primary" name="make_primary" value="1">
                <label for="make_primary">Make this the primary image</label>
            </div>

            <button type="submit" class="btn btn-block">Upload Image</button>
        </form>
    </div>

    <div class="admin-card admin-card-wide">
        <h2 class="card-title">Gallery (<?= count($gallery) ?>)</h2>

        <?php if ($gallery === []): ?>
            <div class="empty-state">No images yet.</div>
        <?php else: ?>
            <div class="image-manage-grid">
                <?php foreach ($gallery as $image): ?>
                    <div class="image-manage-card <?= $image['is_primary'] ? 'is-primary' : '' ?>">
                        <img src="../assets/images/products/<?= View::e($image['filename']) ?>"
                             alt="<?= View::e($image['alt_text'] ?? $product['name']) ?>" loading="lazy">

                        <?php if ($image['is_primary']): ?>
                            <span class="status-pill status-active">Primary</span>
                        <?php endif; ?>

                        <div class="image-manage-actions">
                            <?php if (!$image['is_primary']): ?>
                                <form method="post" action="productimages.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="primary">
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                    <input type="hidden" name="image_id" value="<?= $image['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Set primary</button>
                                </form>
                            <?php endif; ?>

                            <?php if (count($gallery) > 1): ?>
                                <form method="post" action="productimages.php" class="inline-form"
                                      onsubmit="return confirm('Remove this image? The file will be deleted.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                    <input type="hidden" name="image_id" value="<?= $image['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
