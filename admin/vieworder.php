<?php

declare(strict_types=1);

/**
 * Admin — order detail and status transition
 * (PROJECT_RULES.md §7 "Every status change should be authorized... recorded
 * in order_status_history... record who changed it").
 *
 * The status dropdown only ever offers the transitions OrderStatus says are
 * legal from the order's current status — the server re-validates this
 * independently in OrderRepository::transitionStatus, so a forged POST value
 * outside that set is rejected even if the dropdown were tampered with
 * client-side.
 */

$pageTitle = 'Order Detail';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Notifications\NotificationService;
use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Orders\PaymentMethod;
use App\Payments\PaymentStatus;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\View;

$orderId = Http::intParam($_GET, 'order_id') ?? Http::intParam($_POST, 'order_id');

if ($orderId === null) {
    Flash::error('No order was selected.');
    Http::redirect('orders.php');
}

$service = new OrderService();

if (Http::isPost()) {
    Csrf::verifyRequest();

    $validator = (new Validator($_POST))
        ->label('to_status', 'New status')
        ->label('note', 'Note')
        ->required('to_status')
        ->maxLength('note', 500);

    if ($validator->passes()) {
        try {
            $service->updateStatus(
                $orderId,
                $validator->value('to_status'),
                (int) Auth::id(),
                $validator->value('note') ?: null
            );

            // Notify the customer. Sent after the transition already
            // committed and never allowed to undo it if delivery fails
            // (§20 "Email sending should not block checkout").
            $notifyOrder = $service->detail($orderId);
            if ($notifyOrder !== null) {
                $customerStmt = Database::connection()->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
                $notifyUserId = (int) $notifyOrder['order']['user_id'];
                $customerStmt->bind_param('i', $notifyUserId);
                $customerStmt->execute();
                $customer = $customerStmt->get_result()->fetch_assoc();
                $customerStmt->close();

                if ($customer) {
                    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host     = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $basePath = rtrim((string) Config::get('app.url', ''), '/');
                    $orderUrl = "{$scheme}://{$host}{$basePath}/orderdetail.php?reference=" . urlencode((string) $notifyOrder['order']['order_reference']);

                    (new NotificationService())->sendOrderStatusUpdate(
                        (string) $customer['email'],
                        (string) $customer['name'],
                        (string) $notifyOrder['order']['order_reference'],
                        OrderStatus::label($validator->value('to_status')),
                        $orderUrl
                    );
                }
            }

            Flash::success('Order status updated.');
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());
        } catch (Throwable $e) {
            Logger::error('Order status update failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            Flash::error('Could not update the order status. Please try again.');
        }
    } else {
        Flash::error($validator->firstError());
    }

    Http::redirect('vieworder.php?order_id=' . $orderId);
}

$detail = $service->detail($orderId);

if ($detail === null) {
    Flash::error('That order no longer exists.');
    Http::redirect('orders.php');
}

$order       = $detail['order'];
$items       = $detail['items'];
$history     = $detail['history'];
$payments    = $detail['payments'];
$nextOptions = OrderStatus::allowedNext((string) $order['status']);
?>

<p><a href="orders.php">&laquo; Back to Orders</a></p>

<h1 class="page-heading">
    Order <?= View::e($order['order_reference']) ?>
    <span class="status-pill <?= View::e(OrderStatus::cssClass($order['status'])) ?>">
        <?= View::e(OrderStatus::label($order['status'])) ?>
    </span>
</h1>
<p class="page-subheading">Placed <?= View::e(date('d M Y, g:i a', strtotime((string) $order['created_at']))) ?></p>

<div class="admin-split">
    <div class="admin-card admin-card-wide">
        <h2 class="card-title">Items</h2>
        <table class="plain-table">
            <thead>
                <tr>
                    <th scope="col">Product</th>
                    <th scope="col">Unit Price</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= View::e($item['product_name']) ?></td>
                        <td><?= View::money($item['unit_price']) ?></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= View::money($item['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" scope="row">Subtotal</th>
                    <th><?= View::money($order['subtotal']) ?></th>
                </tr>
                <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
                    <tr>
                        <th colspan="3" scope="row">
                            Discount<?= !empty($order['coupon_code']) ? ' (' . View::e($order['coupon_code']) . ')' : '' ?>
                        </th>
                        <th class="movement-positive">&minus;<?= View::money($order['discount_amount']) ?></th>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th colspan="3" scope="row">Total</th>
                    <th><?= View::money($order['total']) ?></th>
                </tr>
            </tfoot>
        </table>

        <h2 class="card-title mt-16">Status Timeline</h2>
        <ol class="order-timeline">
            <?php foreach ($history as $event): ?>
                <li>
                    <span class="status-pill <?= View::e(OrderStatus::cssClass($event['to_status'])) ?>">
                        <?= View::e(OrderStatus::label($event['to_status'])) ?>
                    </span>
                    <span class="order-timeline-date">
                        <?= View::e(date('d M Y, g:i a', strtotime((string) $event['created_at']))) ?>
                        <?php if (!empty($event['changed_by_name'])): ?>
                            &middot; by <?= View::e($event['changed_by_name']) ?>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($event['note'])): ?>
                        <p class="note"><?= View::e($event['note']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <div>
        <div class="admin-card">
            <h2 class="card-title">Customer</h2>
            <p><?= View::e($order['recipient_name']) ?></p>
            <p class="muted"><?= View::e($order['phone']) ?></p>
            <p>
                <?= View::e($order['address_line1']) ?><?= $order['address_line2'] ? ', ' . View::e($order['address_line2']) : '' ?><br>
                <?= View::e($order['city']) ?>
            </p>

            <h2 class="card-title mt-16">Payment</h2>
            <p><?= View::e(PaymentMethod::label($order['payment_method'])) ?></p>

            <?php if ($payments === []): ?>
                <p class="muted"><?= $order['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></p>
            <?php else: ?>
                <ul class="plain-list">
                    <?php foreach ($payments as $txn): ?>
                        <li>
                            <span class="status-pill <?= $txn['status'] === 'paid' ? 'status-active' : ($txn['status'] === 'failed' ? 'status-archived' : 'status-pending') ?>">
                                <?= View::e(PaymentStatus::label($txn['status'])) ?>
                            </span>
                            <?= View::money($txn['amount']) ?>
                            <small class="muted">
                                &middot; <?= View::e(date('d M Y, g:i a', strtotime((string) $txn['created_at']))) ?>
                                <?php if (!empty($txn['transaction_reference'])): ?>
                                    &middot; ref <?= View::e($txn['transaction_reference']) ?>
                                <?php endif; ?>
                            </small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($order['customer_note'])): ?>
                <h2 class="card-title mt-16">Customer Note</h2>
                <p class="muted"><?= View::e($order['customer_note']) ?></p>
            <?php endif; ?>
        </div>

        <div class="admin-card mt-16">
            <h2 class="card-title">Update Status</h2>

            <?php if ($nextOptions === []): ?>
                <p class="muted">This order has reached a final status.</p>
            <?php else: ?>
                <form method="post" action="vieworder.php" novalidate>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">

                    <div class="form-group">
                        <label for="to_status">New status</label>
                        <select name="to_status" id="to_status" required>
                            <?php foreach ($nextOptions as $option): ?>
                                <option value="<?= View::e($option) ?>"><?= View::e(OrderStatus::label($option)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="note">Note <span class="optional">(optional)</span></label>
                        <textarea id="note" name="note" maxlength="500" placeholder="Reason, tracking number, etc."></textarea>
                    </div>

                    <button type="submit" class="btn btn-block">Update Status</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
