<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Coupons\CouponRepository;
use App\Orders\OrderService;
use RuntimeException;

/**
 * Coupons applied during a real checkout (PROJECT_RULES.md §8, Rule 6, §29).
 */
final class CouponCheckoutTest extends DatabaseTestCase
{
    private CouponRepository $coupons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coupons = new CouponRepository($this->db);
    }

    private function makeCoupon(array $overrides = []): int
    {
        $admin = $this->createUser('admin' . uniqid('', true) . '@test.com', 'admin');

        return $this->coupons->create(
            $overrides['code'] ?? 'SAVE10',
            $overrides['type'] ?? 'percent',
            $overrides['value'] ?? '10.00',
            $overrides['min_order_amount'] ?? '0.00',
            $overrides['usage_limit'] ?? null,
            $overrides['expires_at'] ?? null,
            $admin
        );
    }

    public function test_checkout_applies_the_coupon_discount_to_the_total(): void
    {
        $this->makeCoupon(['type' => 'percent', 'value' => '10.00']);
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $result = (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            null,
            null,
            'SAVE10'
        );

        $this->assertSame('500.00', $result['subtotal']);
        $this->assertSame('50.00', $result['discount_amount']);
        $this->assertSame('450.00', $result['total']);
        $this->assertSame('SAVE10', $result['coupon_code']);
    }

    public function test_order_row_stores_the_discount_and_coupon_code(): void
    {
        $this->makeCoupon(['type' => 'fixed', 'value' => '100.00']);
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $result = (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            null,
            null,
            'SAVE10'
        );

        $order = $this->db->query("SELECT * FROM orders WHERE id = {$result['order_id']}")->fetch_assoc();

        $this->assertSame('SAVE10', $order['coupon_code']);
        $this->assertSame('100.00', $order['discount_amount']);
        $this->assertSame('400.00', $order['total']);
    }

    public function test_checkout_redeems_the_coupon(): void
    {
        $couponId  = $this->makeCoupon();
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            null,
            null,
            'SAVE10'
        );

        $coupon = $this->coupons->find($couponId);
        $this->assertSame(1, (int) $coupon['used_count']);
        $this->assertSame(1, $this->countRows('coupon_usages'));
    }

    public function test_invalid_coupon_rejects_the_whole_checkout(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $this->expectException(RuntimeException::class);

        (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId,
            null,
            null,
            'NOSUCHCODE'
        );
    }

    /**
     * A rejected coupon must roll back the entire order, exactly like an
     * out-of-stock line does — no order, no stock change, no redemption.
     */
    public function test_invalid_coupon_leaves_no_partial_state(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        try {
            (new OrderService($this->db))->placeOrderFromCart(
                $userId,
                [['product_id' => $productId, 'quantity' => 1]],
                $addressId,
                null,
                null,
                'NOSUCHCODE'
            );
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, $this->countRows('orders'));
        $this->assertSame(10, $this->stockOf($productId), 'Stock must be untouched.');
    }

    public function test_checkout_without_a_coupon_has_zero_discount(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $result = (new OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );

        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertNull($result['coupon_code']);
        $this->assertSame($result['subtotal'], $result['total']);
    }

    /**
     * A single-use coupon must not be redeemable by two concurrent
     * checkouts — the second must fail cleanly.
     */
    public function test_a_fully_used_coupon_is_rejected_on_a_second_checkout(): void
    {
        $this->makeCoupon(['usage_limit' => 1]);
        $userA     = $this->createUser('a@test.com');
        $addressA  = $this->createAddress($userA);
        $userB     = $this->createUser('b@test.com');
        $addressB  = $this->createAddress($userB);
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $orders = new OrderService($this->db);
        $orders->placeOrderFromCart($userA, [['product_id' => $productId, 'quantity' => 1]], $addressA, null, null, 'SAVE10');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fully redeemed');

        $orders->placeOrderFromCart($userB, [['product_id' => $productId, 'quantity' => 1]], $addressB, null, null, 'SAVE10');
    }
}
