<?php

declare(strict_types=1);

/**
 * Admin — product list with archive/restore actions.
 *
 * Archived products stay visible here (greyed out) so an accidental archive
 * can be undone. Both actions post to deleteproduct.php with a CSRF token
 * rather than being GET links.
 */

$pageTitle = 'View Products';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\ProductRepository;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\View;

$products          = (new ProductRepository())->allForAdmin();
$lowStockThreshold = (int) Config::get('catalog.low_stock_threshold', 5);
?>

<h1 class="page-heading">Products</h1>
<p class="page-subheading">All items currently listed in the store</p>

<?php if ($products === []): ?>
    <div class="empty-state">
        No products yet.<br>
        <a href="addProduct.php" class="btn mt-16">Add Your First Product</a>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Image</th>
                    <th scope="col">Name</th>
                    <th scope="col">Category</th>
                    <th scope="col">Price</th>
                    <th scope="col">Stock</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $row): ?>
                    <?php
                    $isArchived = $row['deleted_at'] !== null;
                    $stock      = (int) $row['stock'];
                    ?>
                    <tr class="<?= $isArchived ? 'row-archived' : '' ?>">
                        <td>
                            <img src="../assets/images/products/<?= View::e($row['image']) ?>"
                                 class="table-img" alt="<?= View::e($row['name']) ?>" loading="lazy">
                        </td>
                        <td><?= View::e($row['name']) ?></td>
                        <td><?= View::e($row['category_name'] ?? 'Uncategorized') ?></td>
                        <td><?= View::money($row['price']) ?></td>
                        <td>
                            <span class="stock-badge <?= $stock > 0 && $stock < $lowStockThreshold ? 'low' : '' ?>">
                                <?= $stock ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isArchived): ?>
                                <span class="status-pill status-archived">Archived</span>
                            <?php else: ?>
                                <span class="status-pill status-active">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions-cell">
                            <a href="updateproduct.php?product_id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-success">Update</a>

                            <?php if ($isArchived): ?>
                                <form method="post" action="deleteproduct.php" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <button type="submit" class="btn btn-sm">Restore</button>
                                </form>
                            <?php else: ?>
                                <?php
                                /*
                                 * POST form, not a link — archiving changes
                                 * state. The confirm() is a courtesy; the real
                                 * protection is the CSRF token and the POST
                                 * method requirement on the server.
                                 */
                                ?>
                                <form method="post" action="deleteproduct.php" class="inline-form"
                                      onsubmit="return confirm('Archive this product? It will be hidden from the shop, but past orders keep their history.');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="action" value="archive">
                                    <button type="submit" class="btn btn-sm btn-danger">Archive</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
