<?php

declare(strict_types=1);

/**
 * Admin — inventory (PROJECT_RULES.md §10 "Inventory must be treated as a
 * separate business domain", §30 Phase 6 "Inventory management, Stock
 * movement history").
 *
 * Two views on one page: current stock levels (from ProductRepository, the
 * fast-read source), and the movement ledger (from InventoryRepository, the
 * audit trail explaining how stock got to that number).
 */

$pageTitle = 'Inventory';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Catalog\ProductRepository;
use App\Inventory\InventoryRepository;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Validator;
use App\Support\View;

$products  = new ProductRepository();
$inventory = new InventoryRepository();

if (Http::isPost()) {
    Csrf::verifyRequest();

    $productId = Http::intParam($_POST, 'product_id');

    $validator = (new Validator($_POST))
        ->label('delta', 'Adjustment')
        ->label('reason', 'Reason')
        ->required('delta')->integer('delta', -100000, 100000)
        ->required('reason')->maxLength('reason', 255);

    if ($productId === null || $products->find($productId) === null) {
        Flash::error('That product no longer exists.');
    } elseif ($validator->fails()) {
        Flash::error($validator->firstError());
    } else {
        try {
            $inventory->adjust(
                $productId,
                (int) $validator->value('delta'),
                $validator->value('reason'),
                (int) Auth::id()
            );
            Flash::success('Stock adjusted.');
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
        }
    }

    Http::redirect('inventory.php');
}

$threshold = (int) Config::get('catalog.low_stock_threshold', 5);
$allProducts = $products->allForAdmin();
$recentMovements = $inventory->recent(50);
?>

<h1 class="page-heading">Inventory</h1>
<p class="page-subheading">Current stock and adjustment history</p>

<div class="admin-split">
    <div class="admin-card admin-card-wide">
        <h2 class="card-title">Stock Levels</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Stock</th>
                        <th scope="col">Adjust</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allProducts as $product): ?>
                        <?php if ($product['deleted_at'] !== null) continue; ?>
                        <?php $stock = (int) $product['stock']; ?>
                        <tr>
                            <td><?= View::e($product['name']) ?></td>
                            <td>
                                <span class="stock-badge <?= $stock > 0 && $stock < $threshold ? 'low' : '' ?>">
                                    <?= $stock ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" action="inventory.php" class="inventory-adjust-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="number" name="delta" placeholder="+/-" required class="qty-input" style="width:70px;">
                                    <input type="text" name="reason" placeholder="Reason" required maxlength="255" class="filter-input" style="width:140px; padding:6px 8px;">
                                    <button type="submit" class="btn btn-sm">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-card">
        <h2 class="card-title">Recent Movements</h2>
        <?php if ($recentMovements === []): ?>
            <p class="muted">No movements recorded yet.</p>
        <?php else: ?>
            <ul class="plain-list movement-list">
                <?php foreach ($recentMovements as $move): ?>
                    <li class="movement-item">
                        <span class="<?= (int) $move['quantity_change'] >= 0 ? 'movement-positive' : 'movement-negative' ?>">
                            <?= (int) $move['quantity_change'] >= 0 ? '+' : '' ?><?= (int) $move['quantity_change'] ?>
                        </span>
                        <a href="../product.php?slug=<?= urlencode((string) $move['product_slug']) ?>"><?= View::e($move['product_name']) ?></a>
                        <span class="status-pill status-pending"><?= View::e(ucwords(str_replace('_', ' ', $move['type']))) ?></span>
                        <br>
                        <small class="muted">
                            <?= View::e(date('d M Y, g:i a', strtotime((string) $move['created_at']))) ?>
                            <?php if (!empty($move['created_by_name'])): ?> &middot; by <?= View::e($move['created_by_name']) ?><?php endif; ?>
                            <?php if (!empty($move['reference'])): ?> &middot; <?= View::e($move['reference']) ?><?php endif; ?>
                            <?php if (!empty($move['reason'])): ?><br>“<?= View::e($move['reason']) ?>”<?php endif; ?>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
