<?php

declare(strict_types=1);

/**
 * Admin — coupon management (PROJECT_RULES.md §29 "coupons").
 */

$pageTitle = 'Coupons';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Audit\AuditLogger;
use App\Coupons\CouponRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Validator;
use App\Support\View;

$coupons = new CouponRepository();
$audit   = new AuditLogger();

$errors  = [];
$old     = ['code' => '', 'type' => 'percent', 'value' => '', 'min_order_amount' => '0', 'usage_limit' => '', 'expires_at' => ''];
$editing = null;

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action    = (string) ($_POST['action'] ?? 'save');
    $couponId  = Http::intParam($_POST, 'coupon_id');

    if ($action === 'toggle') {
        $coupon = $couponId !== null ? $coupons->find($couponId) : null;
        if ($coupon === null) {
            Flash::error('That coupon no longer exists.');
        } else {
            $coupons->setActive($couponId, !(bool) $coupon['active']);
            $audit->log((int) Auth::id(), 'coupon.toggled', 'coupon', $couponId, ['active' => !(bool) $coupon['active']]);
            Flash::success((bool) $coupon['active'] ? 'Coupon deactivated.' : 'Coupon activated.');
        }
        Http::redirect('coupons.php');
    }

    $validator = (new Validator($_POST))
        ->label('code', 'Code')
        ->label('type', 'Type')
        ->label('value', 'Value')
        ->label('min_order_amount', 'Minimum order amount')
        ->label('usage_limit', 'Usage limit')
        ->label('expires_at', 'Expiry date')
        ->required('code')->maxLength('code', 30)
        ->required('type')->inList('type', ['percent', 'fixed'])
        ->required('value')->decimal('value', 0, 999999)
        ->decimal('min_order_amount', 0, 999999)
        ->integer('usage_limit', 1, 1000000);

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    // Percent discounts above 100% make no sense.
    if ($validator->passes() && $old['type'] === 'percent' && (float) $old['value'] > 100) {
        $validator->fail('value', 'A percentage discount cannot exceed 100.');
    }

    $isUpdate = $couponId !== null;
    if ($isUpdate && $coupons->find($couponId) === null) {
        Flash::error('That coupon no longer exists.');
        Http::redirect('coupons.php');
    }

    if ($validator->passes() && !$isUpdate && $coupons->codeTaken($old['code'])) {
        $validator->fail('code', 'A coupon with that code already exists.');
    }

    if ($validator->passes()) {
        $minOrder    = $old['min_order_amount'] !== '' ? $old['min_order_amount'] : '0';
        $usageLimit  = $old['usage_limit'] !== '' ? (int) $old['usage_limit'] : null;
        $expiresAt   = $old['expires_at'] !== '' ? $old['expires_at'] . ' 23:59:59' : null;

        if ($isUpdate) {
            $coupons->update($couponId, $old['type'], $old['value'], $minOrder, $usageLimit, $expiresAt, true);
            $audit->log((int) Auth::id(), 'coupon.updated', 'coupon', $couponId, ['code' => $old['code']]);
            Flash::success('Coupon updated.');
        } else {
            $newId = $coupons->create($old['code'], $old['type'], $old['value'], $minOrder, $usageLimit, $expiresAt, (int) Auth::id());
            $audit->log((int) Auth::id(), 'coupon.created', 'coupon', $newId, ['code' => $old['code']]);
            Flash::success('Coupon created.');
        }

        Http::redirect('coupons.php');
    }

    $errors = $validator->errors();
    if ($isUpdate) {
        $editing = ['id' => $couponId] + $old;
    }
}

$editId = Http::intParam($_GET, 'edit');
if ($editing === null && $editId !== null) {
    $found = $coupons->find($editId);
    if ($found !== null) {
        $editing = [
            'id'               => (int) $found['id'],
            'code'             => (string) $found['code'],
            'type'             => (string) $found['type'],
            'value'            => (string) $found['value'],
            'min_order_amount' => (string) $found['min_order_amount'],
            'usage_limit'      => $found['usage_limit'] !== null ? (string) $found['usage_limit'] : '',
            'expires_at'       => $found['expires_at'] !== null ? substr((string) $found['expires_at'], 0, 10) : '',
        ];
    }
}

$allCoupons = $coupons->all();
?>

<h1 class="page-heading">Coupons</h1>
<p class="page-subheading">Discount codes customers can apply at checkout</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $message): ?><div><?= View::e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-split">
    <div class="admin-card">
        <h2 class="card-title"><?= $editing ? 'Edit Coupon' : 'New Coupon' ?></h2>

        <form method="post" action="coupons.php" novalidate>
            <?= Csrf::field() ?>
            <?php if ($editing): ?><input type="hidden" name="coupon_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

            <div class="form-group">
                <label for="code">Code</label>
                <input type="text" id="code" name="code" maxlength="30" required style="text-transform:uppercase;"
                       value="<?= View::e($editing['code'] ?? $old['code']) ?>" <?= $editing ? 'readonly' : '' ?>>
            </div>
            <div class="form-group">
                <label for="type">Type</label>
                <select name="type" id="type">
                    <option value="percent" <?= ($editing['type'] ?? $old['type']) === 'percent' ? 'selected' : '' ?>>Percentage off</option>
                    <option value="fixed" <?= ($editing['type'] ?? $old['type']) === 'fixed' ? 'selected' : '' ?>>Fixed amount off</option>
                </select>
            </div>
            <div class="form-group">
                <label for="value">Value</label>
                <input type="number" step="0.01" min="0" id="value" name="value" required
                       value="<?= View::e($editing['value'] ?? $old['value']) ?>">
                <small class="form-hint">Percentage (0-100) or a fixed &#2547; amount, depending on type.</small>
            </div>
            <div class="form-group">
                <label for="min_order_amount">Minimum Order Amount</label>
                <input type="number" step="0.01" min="0" id="min_order_amount" name="min_order_amount"
                       value="<?= View::e($editing['min_order_amount'] ?? $old['min_order_amount']) ?>">
            </div>
            <div class="form-group">
                <label for="usage_limit">Usage Limit <span class="optional">(optional)</span></label>
                <input type="number" min="1" id="usage_limit" name="usage_limit"
                       value="<?= View::e($editing['usage_limit'] ?? $old['usage_limit']) ?>" placeholder="Unlimited">
            </div>
            <div class="form-group">
                <label for="expires_at">Expires <span class="optional">(optional)</span></label>
                <input type="date" id="expires_at" name="expires_at"
                       value="<?= View::e($editing['expires_at'] ?? $old['expires_at']) ?>">
            </div>

            <button type="submit" class="btn btn-block"><?= $editing ? 'Save Changes' : 'Create Coupon' ?></button>
            <?php if ($editing): ?>
                <a href="coupons.php" class="btn btn-block btn-outline mt-16" style="background:var(--color-primary);">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="admin-card admin-card-wide">
        <h2 class="card-title">All Coupons (<?= count($allCoupons) ?>)</h2>

        <?php if ($allCoupons === []): ?>
            <div class="empty-state">No coupons yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Code</th>
                            <th scope="col">Discount</th>
                            <th scope="col">Min Order</th>
                            <th scope="col">Used</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCoupons as $coupon): ?>
                            <?php
                            $isExpired = $coupon['expires_at'] !== null && strtotime((string) $coupon['expires_at']) < time();
                            $isMaxed   = $coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit'];
                            ?>
                            <tr class="<?= !$coupon['active'] || $isExpired || $isMaxed ? 'row-archived' : '' ?>">
                                <td><code class="slug-code"><?= View::e($coupon['code']) ?></code></td>
                                <td><?= $coupon['type'] === 'percent' ? (int) $coupon['value'] . '%' : View::money($coupon['value']) ?></td>
                                <td><?= View::money($coupon['min_order_amount']) ?></td>
                                <td><?= (int) $coupon['redemption_count'] ?><?= $coupon['usage_limit'] !== null ? ' / ' . (int) $coupon['usage_limit'] : '' ?></td>
                                <td><?= $coupon['expires_at'] !== null ? View::e(date('d M Y', strtotime((string) $coupon['expires_at']))) : 'Never' ?></td>
                                <td>
                                    <?php if (!$coupon['active']): ?>
                                        <span class="status-pill status-archived">Inactive</span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="status-pill status-archived">Expired</span>
                                    <?php elseif ($isMaxed): ?>
                                        <span class="status-pill status-archived">Fully redeemed</span>
                                    <?php else: ?>
                                        <span class="status-pill status-active">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <a href="coupons.php?edit=<?= (int) $coupon['id'] ?>" class="btn btn-sm btn-success">Edit</a>
                                    <form method="post" action="coupons.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="coupon_id" value="<?= (int) $coupon['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $coupon['active'] ? 'btn-danger' : '' ?>">
                                            <?= $coupon['active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
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
