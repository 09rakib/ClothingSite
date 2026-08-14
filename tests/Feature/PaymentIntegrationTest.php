<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderRepository;
use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Payments\PaymentStatus;

/**
 * The payment ledger as seen through checkout and the order status machine
 * (PROJECT_RULES.md §9 — the order system must not contain provider-specific
 * code, but it must still end up with a correct, auditable payment record).
 */
final class PaymentIntegrationTest extends DatabaseTestCase
{
    private function order(): OrderService
    {
        return new OrderService($this->db);
    }

    public function test_checkout_creates_exactly_one_pending_transaction(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );

        $this->assertSame(1, $this->countRows('payment_transactions'));

        $detail = $this->order()->detail((int) $result['order_id']);
        $this->assertCount(1, $detail['payments']);
        $this->assertSame(PaymentStatus::PENDING, $detail['payments'][0]['status']);
        $this->assertSame('500.00', $detail['payments'][0]['amount']);
        $this->assertSame($result['reference'], $detail['payments'][0]['idempotency_key']);
    }

    /**
     * A failed checkout (rolled back order) must leave no payment record
     * behind either — the ledger and the order must never disagree.
     */
    public function test_failed_checkout_leaves_no_payment_transaction(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Scarce', '500.00', 1);

        try {
            $this->order()->placeOrderFromCart(
                $userId,
                [['product_id' => $productId, 'quantity' => 5]],
                $addressId
            );
            $this->fail('Expected the order to be rejected.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, $this->countRows('payment_transactions'));
    }

    /**
     * Delivering a COD order is the payment event — the ledger must flip to
     * paid at that point, not before.
     */
    public function test_delivering_an_order_marks_its_transaction_paid(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();
        $admin     = $this->createUser('admin@test.com', 'admin');

        $result = $this->order()->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );
        $orderId = (int) $result['order_id'];

        $repo = new OrderRepository($this->db);
        $repo->transitionStatus($orderId, OrderStatus::CONFIRMED, $admin);
        $repo->transitionStatus($orderId, OrderStatus::PROCESSING, $admin);
        $repo->transitionStatus($orderId, OrderStatus::SHIPPED, $admin);

        $beforeDelivery = $this->order()->detail($orderId)['payments'][0]['status'];
        $this->assertSame(PaymentStatus::PENDING, $beforeDelivery, 'Must not be paid before delivery.');

        // updateStatus (not the bare repository) is what syncs the ledger.
        $this->order()->updateStatus($orderId, OrderStatus::DELIVERED, $admin);

        $afterDelivery = $this->order()->detail($orderId)['payments'][0]['status'];
        $this->assertSame(PaymentStatus::PAID, $afterDelivery);
    }

    public function test_refunding_a_returned_order_marks_its_transaction_refunded(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();
        $admin     = $this->createUser('admin2@test.com', 'admin');

        $result  = $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);
        $orderId = (int) $result['order_id'];
        $service = $this->order();

        $service->updateStatus($orderId, OrderStatus::CONFIRMED, $admin);
        $service->updateStatus($orderId, OrderStatus::PROCESSING, $admin);
        $service->updateStatus($orderId, OrderStatus::SHIPPED, $admin);
        $service->updateStatus($orderId, OrderStatus::DELIVERED, $admin);
        $service->updateStatus($orderId, OrderStatus::RETURNED, $admin);
        $service->updateStatus($orderId, OrderStatus::REFUNDED, $admin);

        $final = $service->detail($orderId)['payments'][0]['status'];
        $this->assertSame(PaymentStatus::REFUNDED, $final);
    }

    /**
     * A status change unrelated to payment (e.g. Confirmed) must leave the
     * ledger exactly as it was.
     */
    public function test_non_settlement_transitions_do_not_touch_the_ledger(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();
        $admin     = $this->createUser('admin3@test.com', 'admin');

        $result  = $this->order()->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);
        $orderId = (int) $result['order_id'];

        $this->order()->updateStatus($orderId, OrderStatus::CONFIRMED, $admin);

        $this->assertSame(1, $this->countRows('payment_transactions'), 'No extra ledger row.');
        $this->assertSame(PaymentStatus::PENDING, $this->order()->detail($orderId)['payments'][0]['status']);
    }
}
