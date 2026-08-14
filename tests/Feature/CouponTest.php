<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Coupons\CouponRepository;
use RuntimeException;

/**
 * Coupon validation and redemption (PROJECT_RULES.md §29).
 */
final class CouponTest extends DatabaseTestCase
{
    private CouponRepository $coupons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coupons = new CouponRepository($this->db);
    }

    private function makeCoupon(array $overrides = []): int
    {
        $admin = $this->createUser('admin@test.com', 'admin');

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

    public function test_valid_coupon_passes_validation(): void
    {
        $this->makeCoupon();

        $coupon = $this->coupons->validate('SAVE10', '500.00');

        $this->assertSame('SAVE10', $coupon['code']);
    }

    public function test_code_is_case_insensitive(): void
    {
        $this->makeCoupon(['code' => 'SUMMER']);

        $coupon = $this->coupons->validate('summer', '100.00');

        $this->assertSame('SUMMER', $coupon['code']);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->coupons->validate('DOESNOTEXIST', '100.00');
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $id = $this->makeCoupon();
        $this->coupons->setActive($id, false);

        $this->expectException(RuntimeException::class);

        $this->coupons->validate('SAVE10', '100.00');
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $this->makeCoupon(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        $this->coupons->validate('SAVE10', '100.00');
    }

    public function test_future_expiry_is_accepted(): void
    {
        $this->makeCoupon(['expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $coupon = $this->coupons->validate('SAVE10', '100.00');
        $this->assertSame('SAVE10', $coupon['code']);
    }

    public function test_below_minimum_order_is_rejected(): void
    {
        $this->makeCoupon(['min_order_amount' => '500.00']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('minimum order');

        $this->coupons->validate('SAVE10', '100.00');
    }

    public function test_usage_limit_reached_is_rejected(): void
    {
        $id = $this->makeCoupon(['usage_limit' => 1]);
        $this->db->query("UPDATE coupons SET used_count = 1 WHERE id = {$id}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fully redeemed');

        $this->coupons->validate('SAVE10', '100.00');
    }

    /* ---------------- Discount calculation ---------------- */

    public function test_percent_discount_is_calculated_correctly(): void
    {
        $this->makeCoupon(['type' => 'percent', 'value' => '20.00']);
        $coupon = $this->coupons->validate('SAVE10', '500.00');

        $discount = $this->coupons->calculateDiscount($coupon, '500.00');

        $this->assertSame('100.00', $discount);
    }

    public function test_fixed_discount_is_calculated_correctly(): void
    {
        $this->makeCoupon(['type' => 'fixed', 'value' => '150.00']);
        $coupon = $this->coupons->validate('SAVE10', '500.00');

        $discount = $this->coupons->calculateDiscount($coupon, '500.00');

        $this->assertSame('150.00', $discount);
    }

    /**
     * A discount must never exceed the order it applies to — a customer
     * cannot end up with a negative total.
     */
    public function test_fixed_discount_larger_than_subtotal_is_capped(): void
    {
        $this->makeCoupon(['type' => 'fixed', 'value' => '1000.00']);
        $coupon = $this->coupons->validate('SAVE10', '300.00');

        $discount = $this->coupons->calculateDiscount($coupon, '300.00');

        $this->assertSame('300.00', $discount, 'Discount must be capped at the subtotal.');
    }

    public function test_percent_discount_cannot_exceed_subtotal(): void
    {
        $this->makeCoupon(['type' => 'percent', 'value' => '100.00']);
        $coupon = $this->coupons->validate('SAVE10', '250.00');

        $discount = $this->coupons->calculateDiscount($coupon, '250.00');

        $this->assertSame('250.00', $discount);
    }

    /* ---------------- Redemption ---------------- */

    public function test_redeem_increments_used_count_and_records_usage(): void
    {
        $couponId = $this->makeCoupon();
        $userId   = $this->createUser();
        $orderId  = $this->makeFixtureOrder($userId);

        $this->coupons->redeem($this->db, $couponId, $orderId, $userId, '50.00');

        $coupon = $this->coupons->find($couponId);
        $this->assertSame(1, (int) $coupon['used_count']);
        $this->assertSame(1, $this->countRows('coupon_usages'));
    }

    /**
     * The exact race this repository's FOR UPDATE lock exists to prevent:
     * a limit that filled up between validate() and redeem() must still be
     * caught, not silently over-redeemed.
     */
    public function test_redeem_rejects_a_limit_reached_between_validate_and_redeem(): void
    {
        $couponId = $this->makeCoupon(['usage_limit' => 1]);
        $userId   = $this->createUser();
        $orderId  = $this->makeFixtureOrder($userId);

        // Simulate another checkout claiming the only slot first.
        $this->db->query("UPDATE coupons SET used_count = 1 WHERE id = {$couponId}");

        $this->expectException(RuntimeException::class);

        $this->coupons->redeem($this->db, $couponId, $orderId, $userId, '10.00');
    }

    public function test_code_taken_detects_duplicates_case_insensitively(): void
    {
        $this->makeCoupon(['code' => 'WELCOME']);

        $this->assertTrue($this->coupons->codeTaken('welcome'));
        $this->assertFalse($this->coupons->codeTaken('NEWCODE'));
    }

    private function makeFixtureOrder(int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders
                (order_reference, user_id, subtotal, total, payment_method,
                 recipient_name, phone, address_line1, city)
             VALUES ("ORD-FIXTURE02", ?, "500.00", "500.00", "cash_on_delivery", "T", "0170", "Road", "Dhaka")'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }
}
