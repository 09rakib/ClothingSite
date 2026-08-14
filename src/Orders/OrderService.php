<?php

declare(strict_types=1);

namespace App\Orders;

use App\Account\AddressRepository;
use App\Audit\AuditLogger;
use App\Inventory\InventoryRepository;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymentRequest;
use App\Payments\PaymentStatus;
use App\Payments\PaymentTransactionRepository;
use App\Support\Database;
use App\Support\Logger;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Order placement business logic (PROJECT_RULES.md §7, §8, Rule 6, Rule 9).
 *
 * PHASE 3 CHANGE: this used to write into the line-item-only `single_order`
 * table (Phase 0/2). It now writes into `orders` (one header row per checkout,
 * carrying status and a delivery-address snapshot) and `order_items` (one row
 * per product), via OrderRepository. The former single-item "Buy Now" path
 * (`placeSingleProductOrder`) is gone — Buy Now now adds one item to the cart
 * and goes straight through this same method, so there is exactly one order
 * creation path instead of two that could drift apart (§3.1).
 *
 * Guarantees, unchanged from Phase 2:
 *   - Every product row involved is locked FOR UPDATE, in id order, so
 *     concurrent checkouts cannot oversell and cannot deadlock each other.
 *   - Price is read from the database inside the lock, never from the
 *     browser (Rule 6).
 *   - A snapshot of product name/price and delivery address is stored on the
 *     order, so history stays correct after later catalog or address edits
 *     (§5, Rule 10).
 *   - The whole checkout is one transaction: any failing line rolls back
 *     every line, so a customer can never be charged for part of an order.
 */
final class OrderService
{
    private mysqli $db;
    private OrderRepository $orders;
    private AddressRepository $addresses;
    private PaymentTransactionRepository $transactions;
    private InventoryRepository $inventory;
    private AuditLogger $audit;

    public function __construct(?mysqli $db = null)
    {
        $this->db           = $db ?? Database::connection();
        $this->orders       = new OrderRepository($this->db);
        $this->addresses    = new AddressRepository($this->db);
        $this->transactions = new PaymentTransactionRepository($this->db);
        $this->inventory    = new InventoryRepository($this->db);
        $this->audit        = new AuditLogger($this->db);
    }

    /**
     * Place an order for every line in a cart, atomically.
     *
     * @param array<int,array{product_id:int,quantity:int}> $lines
     * @return array{reference:string,order_id:int,total:string,lines:array<int,array<string,mixed>>,payment_method:string}
     * @throws RuntimeException with a message safe to show the customer.
     */
    public function placeOrderFromCart(
        int $userId,
        array $lines,
        int $addressId,
        ?string $paymentMethod = null,
        ?string $customerNote = null
    ): array {
        if ($lines === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $paymentMethod ??= PaymentMethod::default();
        if (!PaymentMethod::isEnabled($paymentMethod)) {
            throw new RuntimeException('That payment method is not available.');
        }

        // Ownership-checked here, before the transaction opens, so a forged
        // address id from another customer fails fast with a clear message
        // rather than as a generic database error (§19 "No IDOR").
        $address = $this->addresses->findOwned($addressId, $userId);
        if ($address === null) {
            throw new RuntimeException('Please choose a valid delivery address.');
        }

        if (trim((string) $address['address_line1']) === '' || trim((string) $address['city']) === '') {
            throw new RuntimeException('That delivery address is incomplete. Please edit it before checking out.');
        }

        if ($customerNote !== null) {
            $customerNote = mb_substr(trim($customerNote), 0, 500);
            if ($customerNote === '') {
                $customerNote = null;
            }
        }

        // Collapse duplicate product ids and sort, so concurrent checkouts
        // always take row locks in the same order and cannot deadlock.
        $wanted = [];
        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $quantity  = (int) $line['quantity'];

            if ($quantity < 1) {
                throw new RuntimeException('Quantity must be at least 1.');
            }

            $wanted[$productId] = ($wanted[$productId] ?? 0) + $quantity;
        }
        ksort($wanted);

        return Database::transaction(function (mysqli $db) use ($userId, $wanted, $paymentMethod, $address, $customerNote): array {
            $reference = self::generateReference();
            $total     = 0.0;
            $placed    = [];

            $lockStmt = $db->prepare(
                "SELECT id, name, price, stock
                 FROM products
                 WHERE id = ? AND deleted_at IS NULL AND status = 'active'
                 FOR UPDATE"
            );

            $lineData = [];

            foreach ($wanted as $productId => $quantity) {
                $lockStmt->bind_param('i', $productId);
                $lockStmt->execute();
                $product = $lockStmt->get_result()->fetch_assoc();

                if (!$product) {
                    throw new RuntimeException('A product in your cart is no longer available. Please review your cart.');
                }

                $name      = (string) $product['name'];
                $available = (int) $product['stock'];

                if ($available <= 0) {
                    throw new RuntimeException("\"{$name}\" is out of stock. Please remove it from your cart.");
                }
                if ($available < $quantity) {
                    throw new RuntimeException("Only {$available} of \"{$name}\" left. Please lower the quantity.");
                }

                $unitPrice = (string) $product['price'];
                $lineTotal = number_format((float) $unitPrice * $quantity, 2, '.', '');
                $total    += (float) $lineTotal;

                $lineData[] = [
                    'product_id'   => $productId,
                    'product_name' => $name,
                    'unit_price'   => $unitPrice,
                    'quantity'     => $quantity,
                    'line_total'   => $lineTotal,
                ];
            }
            $lockStmt->close();

            $totalStr = number_format($total, 2, '.', '');

            $orderId = $this->orders->createOrder(
                $reference,
                $userId,
                $totalStr,
                $totalStr,
                $paymentMethod,
                [
                    'recipient_name' => (string) $address['recipient_name'],
                    'phone'          => (string) $address['phone'],
                    'address_line1'  => (string) $address['address_line1'],
                    'address_line2'  => $address['address_line2'] !== null ? (string) $address['address_line2'] : null,
                    'city'           => (string) $address['city'],
                ],
                $customerNote
            );

            // Payment abstraction (§9): OrderService asks a PaymentGateway to
            // start the payment and records the result in the ledger — it has
            // no idea whether that gateway is COD or a future real provider.
            // The order_reference is reused as the idempotency key, so a
            // retried checkout transaction could never create two charge
            // records for the same order even if it somehow ran twice.
            $gateway = PaymentGatewayFactory::for($paymentMethod);
            $payment = $gateway->createPayment(new PaymentRequest(
                orderId: $orderId,
                orderReference: $reference,
                amount: $totalStr,
                idempotencyKey: $reference
            ));

            $this->transactions->record(
                $orderId,
                $paymentMethod,
                $payment->status,
                $totalStr,
                $reference,
                $payment->transactionReference
            );

            $stockStmt = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

            foreach ($lineData as $line) {
                $this->orders->addItem(
                    $orderId,
                    $line['product_id'],
                    $line['product_name'],
                    $line['unit_price'],
                    $line['quantity'],
                    $line['line_total']
                );

                $stockStmt->bind_param('iii', $line['quantity'], $line['product_id'], $line['quantity']);
                $stockStmt->execute();

                if ($stockStmt->affected_rows !== 1) {
                    throw new RuntimeException("\"{$line['product_name']}\" just went out of stock. Please try again.");
                }

                // Ledger entry alongside the stock decrement already just
                // performed above (§10 "stock deduction" as a tracked event,
                // not only a number that silently changed).
                $this->inventory->recordMovement(
                    $line['product_id'],
                    -$line['quantity'],
                    'sale',
                    null,
                    $reference
                );

                $placed[] = $line;
            }
            $stockStmt->close();

            Logger::info('Order placed', [
                'reference' => $reference,
                'order_id'  => $orderId,
                'user_id'   => $userId,
                'lines'     => count($placed),
            ]);

            return [
                'reference'      => $reference,
                'order_id'       => $orderId,
                'total'          => $totalStr,
                'lines'          => $placed,
                'payment_method' => $paymentMethod,
            ];
        });
    }

    /**
     * Human-quotable order reference.
     *
     * Random rather than sequential so the reference does not leak how many
     * orders the store has taken.
     */
    private static function generateReference(): string
    {
        return 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Order summaries for a customer's history list (no line items — the
     * detail/tracking page fetches those only when a specific order is opened).
     *
     * @return array<int,array<string,mixed>>
     */
    public function historyForUser(int $userId): array
    {
        return $this->orders->forUser($userId);
    }

    /**
     * Full detail for one order: header, items and status timeline.
     *
     * Ownership is NOT checked here — callers (customer pages) must verify
     * order['user_id'] against the session user themselves via
     * Auth::requireOwnership(), exactly like every other customer-scoped
     * resource (§19 "No IDOR"). Admin pages intentionally skip that check.
     *
     * @return array{order:array<string,mixed>,items:array<int,array<string,mixed>>,history:array<int,array<string,mixed>>,payments:array<int,array<string,mixed>>}|null
     */
    public function detail(int $orderId): ?array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return null;
        }

        return [
            'order'    => $order,
            'items'    => $this->orders->itemsFor($orderId),
            'history'  => $this->orders->statusHistory($orderId),
            'payments' => $this->transactions->forOrder($orderId),
        ];
    }

    public function detailByReference(string $reference): ?array
    {
        $order = $this->orders->findByReference($reference);

        return $order === null ? null : $this->detail((int) $order['id']);
    }

    /**
     * Admin: move an order to a new status.
     *
     * When the new status is Delivered or Refunded, the payment ledger is
     * updated to match: OrderRepository::transitionStatus already caches
     * "paid" onto orders.payment_status for Delivered, and this method
     * additionally asks the payment gateway to settle/refund the underlying
     * transaction, keeping the ledger (the source of truth) in sync with that
     * cache rather than the other way around.
     *
     * @throws RuntimeException when the transition is not allowed.
     */
    public function updateStatus(int $orderId, string $newStatus, int $adminUserId, ?string $note = null): void
    {
        $before = $this->orders->find($orderId);
        $fromStatus = $before !== null ? (string) $before['status'] : null;

        $this->orders->transitionStatus($orderId, $newStatus, $adminUserId, $note);

        if ($newStatus === OrderStatus::DELIVERED || $newStatus === OrderStatus::REFUNDED) {
            $this->settlePaymentForStatus($orderId, $newStatus);
        }

        if ($newStatus === OrderStatus::RETURNED) {
            $this->restockReturnedOrder($orderId);
        }

        $this->audit->log($adminUserId, 'order.status_changed', 'order', $orderId, [
            'from' => $fromStatus,
            'to'   => $newStatus,
            'note' => $note,
        ]);

        Logger::info('Order status changed', [
            'order_id' => $orderId,
            'to'       => $newStatus,
            'admin_id' => $adminUserId,
        ]);
    }

    /**
     * Restock every item of a returned order (§10 "Stock return").
     *
     * A restock failure is logged but never rolls back the status change
     * itself — same reasoning as settlePaymentForStatus: the admin's action
     * already committed, and a secondary bookkeeping failure must not undo it.
     * A mismatch here is a reconciliation task, not a reason to trap the order
     * in an inconsistent status.
     */
    private function restockReturnedOrder(int $orderId): void
    {
        try {
            $order = $this->orders->find($orderId);
            $items = $this->orders->itemsFor($orderId);

            if ($order === null || $items === []) {
                return;
            }

            $reference = (string) $order['order_reference'];

            Database::transaction(function (mysqli $db) use ($items, $reference): void {
                $stockStmt = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');

                foreach ($items as $item) {
                    $productId = (int) $item['product_id'];
                    $quantity  = (int) $item['quantity'];

                    $stockStmt->bind_param('ii', $quantity, $productId);
                    $stockStmt->execute();

                    $this->inventory->recordMovement($productId, $quantity, 'return', null, $reference);
                }

                $stockStmt->close();
            });
        } catch (Throwable $e) {
            Logger::error('Restock after return failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reflect a status change in the payment ledger.
     *
     * Delivered: for cash on delivery — the only connected gateway — the
     * courier handing over the parcel *is* the payment event, so the ledger
     * is marked paid directly rather than through a gateway "verify" call
     * that would be meaningless for an offline method. A future online
     * gateway would already show PAID from checkout itself (§9: never assume
     * success — real gateways confirm via their own webhook/verify step at
     * charge time, not at delivery time), so this branch only ever applies to
     * COD in practice.
     *
     * Refunded: this genuinely is a per-gateway action (§9), so it goes
     * through PaymentGateway::refundPayment().
     *
     * A gateway failure here is logged but never re-thrown — the shipping
     * status change the admin just made must not be rolled back because a
     * ledger call afterwards failed. The resulting mismatch is a
     * reconciliation concern for Phase 8 hardening, not a checkout-time one.
     */
    private function settlePaymentForStatus(int $orderId, string $newStatus): void
    {
        $order  = $this->orders->find($orderId);
        $latest = $this->transactions->latestForOrder($orderId);

        if ($order === null || $latest === null) {
            return;
        }

        try {
            if ($newStatus === OrderStatus::DELIVERED) {
                $this->transactions->updateStatus((int) $latest['id'], PaymentStatus::PAID);

                return;
            }

            $gateway   = PaymentGatewayFactory::for((string) $order['payment_method']);
            $reference = (string) ($latest['transaction_reference'] ?? $latest['idempotency_key']);
            $result    = $gateway->refundPayment($reference);

            $this->transactions->updateStatus((int) $latest['id'], $result->status, $result->transactionReference);
        } catch (Throwable $e) {
            Logger::error('Payment settlement failed after status change', [
                'order_id' => $orderId,
                'to'       => $newStatus,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function repository(): OrderRepository
    {
        return $this->orders;
    }

    /**
     * Aggregate figures for the admin dashboard.
     *
     * @return array{by_status:array<string,int>,total_orders:int,total_revenue:string,orders_today:int,revenue_today:string,customers:int}
     */
    public function stats(): array
    {
        return $this->orders->stats();
    }
}
