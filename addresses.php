<?php

declare(strict_types=1);

/**
 * Customer address book (PROJECT_RULES.md §13, §30 Phase 3 "Address management").
 *
 * Every write is scoped to the session customer — AddressRepository takes the
 * user id on every call and its findOwned() returns null for an address that
 * belongs to someone else, so a forged address_id in a form cannot touch
 * another customer's data (§19 "No IDOR vulnerabilities").
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Account\AddressRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

Auth::requireCustomer();

$userId      = (int) Auth::id();
$repository  = new AddressRepository();

// Where "Save" and "Cancel" return to — checkout links here with ?return=checkout.php.
$return = (string) ($_GET['return'] ?? $_POST['return'] ?? 'addresses.php');
if (!in_array($return, ['addresses.php', 'checkout.php'], true)) {
    $return = 'addresses.php';
}

$errors  = [];
$old     = ['label' => 'Home', 'recipient_name' => '', 'phone' => '', 'address_line1' => '', 'address_line2' => '', 'city' => ''];
$editing = null;

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action    = (string) ($_POST['action'] ?? 'save');
    $addressId = Http::intParam($_POST, 'address_id');

    /* ---------------- Delete ---------------- */
    if ($action === 'delete') {
        if ($addressId === null || $repository->findOwned($addressId, $userId) === null) {
            Flash::error('That address no longer exists.');
        } else {
            $repository->delete($addressId, $userId);
            Logger::info('Address deleted', ['address_id' => $addressId, 'user_id' => $userId]);
            Flash::success('Address removed.');
        }
        Http::redirect('addresses.php');
    }

    /* ---------------- Set default ---------------- */
    if ($action === 'default') {
        if ($addressId === null || $repository->findOwned($addressId, $userId) === null) {
            Flash::error('That address no longer exists.');
        } else {
            $repository->setDefault($addressId, $userId);
            Flash::success('Default address updated.');
        }
        Http::redirect('addresses.php');
    }

    /* ---------------- Save (create or update) ---------------- */
    $validator = (new Validator($_POST))
        ->label('label', 'Label')
        ->label('recipient_name', 'Recipient name')
        ->label('phone', 'Phone number')
        ->label('address_line1', 'Address')
        ->label('address_line2', 'Address (line 2)')
        ->label('city', 'City')
        ->required('label')->maxLength('label', 40)
        ->required('recipient_name')->maxLength('recipient_name', 100)
        ->required('phone')->phone('phone')
        ->required('address_line1')->maxLength('address_line1', 255)
        ->maxLength('address_line2', 255)
        ->required('city')->maxLength('city', 100);

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    $isUpdate = $addressId !== null;

    if ($isUpdate && $repository->findOwned($addressId, $userId) === null) {
        Flash::error('That address no longer exists.');
        Http::redirect('addresses.php');
    }

    if (!$isUpdate && $repository->count($userId) >= AddressRepository::MAX_PER_USER) {
        $validator->fail('label', 'You have reached the maximum number of saved addresses.');
    }

    if ($validator->passes()) {
        $data = [
            'label'          => $old['label'],
            'recipient_name' => $old['recipient_name'],
            'phone'          => $old['phone'],
            'address_line1'  => $old['address_line1'],
            'address_line2'  => $old['address_line2'] !== '' ? $old['address_line2'] : null,
            'city'           => $old['city'],
        ];

        if ($isUpdate) {
            $repository->update($addressId, $userId, $data);
            if (!empty($_POST['make_default'])) {
                $repository->setDefault($addressId, $userId);
            }
            Logger::info('Address updated', ['address_id' => $addressId, 'user_id' => $userId]);
            Flash::success('Address updated.');
        } else {
            $newId = $repository->create($userId, $data, !empty($_POST['make_default']));
            Logger::info('Address created', ['address_id' => $newId, 'user_id' => $userId]);
            Flash::success('Address saved.');
        }

        Http::redirect($return);
    }

    $errors = $validator->errors();
    if ($isUpdate) {
        $editing = ['id' => $addressId] + $old;
    }
}

// GET ?edit=N pre-fills the form.
$editId = Http::intParam($_GET, 'edit');
if ($editing === null && $editId !== null) {
    $found = $repository->findOwned($editId, $userId);
    if ($found !== null) {
        $editing = [
            'id'             => (int) $found['id'],
            'label'          => (string) $found['label'],
            'recipient_name' => (string) $found['recipient_name'],
            'phone'          => (string) $found['phone'],
            'address_line1'  => (string) $found['address_line1'],
            'address_line2'  => (string) ($found['address_line2'] ?? ''),
            'city'           => (string) $found['city'],
        ];
    }
}

$addressBook = $repository->forUser($userId);

$pageTitle = 'My Addresses';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <h1 class="page-heading">My Addresses</h1>
    <p class="page-subheading">Manage the delivery addresses used at checkout</p>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <?php foreach ($errors as $message): ?>
                <div><?= View::e($message) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="admin-split">
        <div class="admin-card">
            <h2 class="card-title"><?= $editing ? 'Edit Address' : 'Add Address' ?></h2>

            <form method="post" action="addresses.php" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="return" value="<?= View::e($return) ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="address_id" value="<?= (int) $editing['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="label">Label</label>
                    <input type="text" id="label" name="label" maxlength="40" placeholder="Home, Office..."
                           value="<?= View::e($editing['label'] ?? $old['label']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="recipient_name">Recipient Name</label>
                    <input type="text" id="recipient_name" name="recipient_name" maxlength="100"
                           value="<?= View::e($editing['recipient_name'] ?? $old['recipient_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" maxlength="20"
                           value="<?= View::e($editing['phone'] ?? $old['phone']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="address_line1">Address</label>
                    <input type="text" id="address_line1" name="address_line1" maxlength="255"
                           value="<?= View::e($editing['address_line1'] ?? $old['address_line1']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="address_line2">Address Line 2 <span class="optional">(optional)</span></label>
                    <input type="text" id="address_line2" name="address_line2" maxlength="255"
                           value="<?= View::e($editing['address_line2'] ?? $old['address_line2']) ?>">
                </div>
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" maxlength="100"
                           value="<?= View::e($editing['city'] ?? $old['city']) ?>" required>
                </div>
                <div class="form-group form-check">
                    <input type="checkbox" id="make_default" name="make_default" value="1">
                    <label for="make_default">Set as default address</label>
                </div>

                <button type="submit" class="btn btn-block"><?= $editing ? 'Save Changes' : 'Add Address' ?></button>
                <?php if ($editing): ?>
                    <a href="addresses.php" class="btn btn-block btn-outline mt-16" style="background:var(--color-primary);">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="admin-card admin-card-wide">
            <h2 class="card-title">Saved Addresses (<?= count($addressBook) ?>)</h2>

            <?php if ($addressBook === []): ?>
                <div class="empty-state">No addresses saved yet. Add one on the left.</div>
            <?php else: ?>
                <div class="address-grid">
                    <?php foreach ($addressBook as $addr): ?>
                        <div class="address-card <?= $addr['is_default'] ? 'is-default' : '' ?>">
                            <div class="address-card-header">
                                <strong><?= View::e($addr['label']) ?></strong>
                                <?php if ($addr['is_default']): ?>
                                    <span class="status-pill status-active">Default</span>
                                <?php endif; ?>
                            </div>
                            <p><?= View::e($addr['recipient_name']) ?> &middot; <?= View::e($addr['phone']) ?></p>
                            <p class="muted">
                                <?= View::e($addr['address_line1']) ?><?= $addr['address_line2'] ? ', ' . View::e($addr['address_line2']) : '' ?><br>
                                <?= View::e($addr['city']) ?>
                            </p>

                            <div class="address-card-actions">
                                <a href="addresses.php?edit=<?= (int) $addr['id'] ?>" class="btn btn-sm btn-success">Edit</a>

                                <?php if (!$addr['is_default']): ?>
                                    <form method="post" action="addresses.php" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="default">
                                        <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                                        <button type="submit" class="btn btn-sm">Set Default</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="addresses.php" class="inline-form"
                                      onsubmit="return confirm('Delete this address?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="address_id" value="<?= (int) $addr['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($return === 'checkout.php'): ?>
                <p class="mt-16"><a href="checkout.php" class="btn">Back to Checkout</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
