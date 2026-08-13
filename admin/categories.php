<?php

declare(strict_types=1);

/**
 * Admin — category management (PROJECT_RULES.md §30 Phase 1 "Categories",
 * §16 "Admin should be a real back office").
 *
 * Previously categories could only be changed by editing the database by hand.
 * Create, rename and delete all live here, on one screen, because the list is
 * short enough that separate pages would be friction rather than clarity.
 *
 * Deleting a category does NOT delete its products: products.category_id is
 * ON DELETE SET NULL, so they become uncategorised and stay on sale. The
 * confirmation states how many products are affected before the admin commits
 * (§6.3 "intentional ON DELETE behavior").
 */

$pageTitle = 'Categories';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\CategoryRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

$repository = new CategoryRepository();

$errors  = [];
$old     = ['name' => '', 'description' => ''];
$editing = null;

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action     = (string) ($_POST['action'] ?? 'create');
    $categoryId = Http::intParam($_POST, 'category_id');

    /* ---------------- Delete ---------------- */
    if ($action === 'delete') {
        if ($categoryId === null || !$repository->exists($categoryId)) {
            Flash::error('That category no longer exists.');
        } else {
            $category = $repository->find($categoryId);
            $affected = $repository->productCount($categoryId);

            $repository->delete($categoryId);
            Logger::info('Category deleted', ['category_id' => $categoryId]);

            Flash::success(sprintf(
                '"%s" deleted.%s',
                (string) $category['name'],
                $affected > 0
                    ? sprintf(' %d product(s) are now uncategorised and remain on sale.', $affected)
                    : ''
            ));
        }

        Http::redirect('categories.php');
    }

    /* ---------------- Create / update ---------------- */
    $validator = (new Validator($_POST))
        ->label('name', 'Category name')
        ->label('description', 'Description')
        ->required('name')->maxLength('name', 80)
        ->maxLength('description', 300);

    $old['name']        = $validator->value('name');
    $old['description'] = $validator->value('description');

    $isUpdate = $action === 'update' && $categoryId !== null;

    if ($isUpdate && !$repository->exists($categoryId)) {
        Flash::error('That category no longer exists.');
        Http::redirect('categories.php');
    }

    // The unique index would reject a duplicate anyway; checking first turns a
    // database error into a readable message.
    if ($validator->passes() && $repository->nameTaken($old['name'], $isUpdate ? $categoryId : null)) {
        $validator->fail('name', 'A category with that name already exists.');
    }

    if ($validator->passes()) {
        $description = $old['description'] === '' ? null : $old['description'];

        if ($isUpdate) {
            $repository->update($categoryId, $old['name'], $description);
            Logger::info('Category updated', ['category_id' => $categoryId]);
            Flash::success('Category updated.');
        } else {
            $newId = $repository->create($old['name'], $description);
            Logger::info('Category created', ['category_id' => $newId]);
            Flash::success('Category created.');
        }

        Http::redirect('categories.php');
    }

    $errors = $validator->errors();

    // Keep the form in edit mode so the admin does not lose their place.
    if ($isUpdate) {
        $editing = ['id' => $categoryId] + $old;
    }
}

// GET ?edit=N pre-fills the form.
$editId = Http::intParam($_GET, 'edit');
if ($editing === null && $editId !== null) {
    $found = $repository->find($editId);
    if ($found !== null) {
        $editing = [
            'id'          => (int) $found['id'],
            'name'        => (string) $found['name'],
            'description' => (string) ($found['description'] ?? ''),
        ];
    }
}

$categories = $repository->allForAdmin();
?>

<h1 class="page-heading">Categories</h1>
<p class="page-subheading">Group products so customers can filter the shop</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $message): ?>
            <div><?= View::e($message) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-split">
    <div class="admin-card">
        <h2 class="card-title"><?= $editing ? 'Edit Category' : 'Add Category' ?></h2>

        <form method="post" action="categories.php" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="category_id" value="<?= (int) $editing['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" maxlength="80" required
                       value="<?= View::e($editing['name'] ?? $old['name']) ?>">
            </div>

            <div class="form-group">
                <label for="description">Description <span class="optional">(optional)</span></label>
                <textarea id="description" name="description" maxlength="300"><?= View::e($editing['description'] ?? $old['description']) ?></textarea>
            </div>

            <button type="submit" class="btn btn-block"><?= $editing ? 'Save Changes' : 'Add Category' ?></button>

            <?php if ($editing): ?>
                <a href="categories.php" class="btn btn-block btn-outline mt-16" style="background:var(--color-primary);">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="admin-card admin-card-wide">
        <h2 class="card-title">All Categories (<?= count($categories) ?>)</h2>

        <?php if ($categories === []): ?>
            <div class="empty-state">No categories yet. Add your first one on the left.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Slug</th>
                            <th scope="col">Products</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td>
                                    <?= View::e($category['name']) ?>
                                    <?php if ($category['description'] !== null && $category['description'] !== ''): ?>
                                        <br><small class="muted"><?= View::e($category['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code class="slug-code"><?= View::e($category['slug']) ?></code></td>
                                <td>
                                    <?= $category['active_count'] ?> active
                                    <?php if ($category['product_count'] > $category['active_count']): ?>
                                        <br><small class="muted"><?= $category['product_count'] - $category['active_count'] ?> archived</small>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <a href="categories.php?edit=<?= (int) $category['id'] ?>" class="btn btn-sm btn-success">Edit</a>

                                    <form method="post" action="categories.php" class="inline-form"
                                          onsubmit="return confirm(<?= View::e(json_encode(
                                              $category['product_count'] > 0
                                                  ? "Delete \"{$category['name']}\"? Its {$category['product_count']} product(s) will become uncategorised but stay on sale."
                                                  : "Delete \"{$category['name']}\"?"
                                          )) ?>);">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
