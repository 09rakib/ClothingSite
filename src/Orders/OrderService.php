<?php

declare(strict_types=1);

namespace App\Orders;

use App\Account\AddressRepository;
use App\Support\Database;
use App\Support\Logger;
use mysqli;
use RuntimeException;

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

    public function __construct(?mysqli $db = null)
    {
        $this->db        = $db ?? Database::connection();
        $this->orders    = new OrderRepository($this->db);
        $this->addresses = new AddressRepository($this->db);
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
     * @return array{order:array<string,mixed>,items:array<int,array<string,mixed>>,history:array<int,array<string,mixed>>}|null
     */
    public function detail(int $orderId): ?array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return null;
        }

        return [
            'order'   => $order,
            'items'   => $this->orders->itemsFor($orderId),
            'history' => $this->orders->statusHistory($orderId),
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
     * @throws RuntimeException when the transition is not allowed.
     */
    public function updateStatus(int $orderId, string $newStatus, int $adminUserId, ?string $note = null): void
    {
        $this->orders->transitionStatus($orderId, $newStatus, $adminUserId, $note);

        Logger::info('Order status changed', [
            'order_id' => $orderId,
            'to'       => $newStatus,
            'admin_id' => $adminUserId,
        ]);
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
