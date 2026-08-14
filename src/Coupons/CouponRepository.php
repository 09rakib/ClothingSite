<?php

declare(strict_types=1);

namespace App\Coupons;

use App\Support\Database;
use mysqli;
use RuntimeException;

/**
 * Coupon validation and redemption (PROJECT_RULES.md §29 "coupons").
 *
 * Money math happens once, here, using the same string-to-float-to-string
 * discipline OrderService uses for prices — never trust or reuse a discount
 * value computed anywhere else (Rule 6).
 */
final class CouponRepository
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
        $code = strtoupper(trim($code));
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Validate a coupon against an order subtotal, returning the coupon row
     * if it may be used.
     *
     * @throws RuntimeException with a message safe to show the customer.
     */
    public function validate(string $code, string $subtotal): array
    {
        $coupon = $this->findByCode($code);

        if ($coupon === null || !(bool) $coupon['active']) {
            throw new RuntimeException('That coupon code is not valid.');
        }

        if ($coupon['expires_at'] !== null && strtotime((string) $coupon['expires_at']) < time()) {
            throw new RuntimeException('That coupon has expired.');
        }

        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw new RuntimeException('That coupon has already been fully redeemed.');
        }

        if ((float) $subtotal < (float) $coupon['min_order_amount']) {
            throw new RuntimeException(sprintf(
                'This coupon requires a minimum order of ৳%s.',
                number_format((float) $coupon['min_order_amount'], 2)
            ));
        }

        return $coupon;
    }

    /**
     * @param array<string,mixed> $coupon
     */
    public function calculateDiscount(array $coupon, string $subtotal): string
    {
        $subtotalFloat = (float) $subtotal;

        $discount = $coupon['type'] === 'percent'
            ? $subtotalFloat * ((float) $coupon['value'] / 100)
            : (float) $coupon['value'];

        // A discount can never exceed the order it is applied to.
        $discount = min($discount, $subtotalFloat);

        return number_format($discount, 2, '.', '');
    }

    /**
     * Redeem a coupon for an order, inside the caller's transaction.
     *
     * Locks the coupon row and re-checks the usage limit under that lock —
     * the same "read-then-write under FOR UPDATE" pattern OrderService uses
     * for stock — so two concurrent checkouts cannot both claim the last
     * remaining use of a limited coupon.
     *
     * @throws RuntimeException if the limit was reached between validate()
     *         and this call.
     */
    public function redeem(mysqli $db, int $couponId, int $orderId, int $userId, string $discountAmount): void
    {
        $stmt = $db->prepare('SELECT usage_limit, used_count FROM coupons WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
        $coupon = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($coupon === null) {
            throw new RuntimeException('That coupon no longer exists.');
        }

        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw new RuntimeException('That coupon was just fully redeemed. Please remove it and try again.');
        }

        $update = $db->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?');
        $update->bind_param('i', $couponId);
        $update->execute();
        $update->close();

        $insert = $db->prepare(
            'INSERT INTO coupon_usages (coupon_id, order_id, user_id, discount_amount) VALUES (?, ?, ?, ?)'
        );
        $insert->bind_param('iiis', $couponId, $orderId, $userId, $discountAmount);
        $insert->execute();
        $insert->close();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id = c.id) AS redemption_count
             FROM coupons c
             ORDER BY c.created_at DESC'
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function codeTaken(string $code, ?int $ignoreId = null): bool
    {
        $code = strtoupper(trim($code));
        $sql  = 'SELECT 1 FROM coupons WHERE code = ?';
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if ($ignoreId !== null) {
            $stmt->bind_param('si', $code, $ignoreId);
        } else {
            $stmt->bind_param('s', $code);
        }
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $taken;
    }

    public function create(
        string $code,
        string $type,
        string $value,
        string $minOrderAmount,
        ?int $usageLimit,
        ?string $expiresAt,
        int $createdBy
    ): int {
        $code = strtoupper(trim($code));

        $stmt = $this->db->prepare(
            'INSERT INTO coupons (code, type, value, min_order_amount, usage_limit, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssddisi', $code, $type, $value, $minOrderAmount, $usageLimit, $expiresAt, $createdBy);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    public function update(
        int $id,
        string $type,
        string $value,
        string $minOrderAmount,
        ?int $usageLimit,
        ?string $expiresAt,
        bool $active
    ): void {
        $activeInt = $active ? 1 : 0;

        $stmt = $this->db->prepare(
            'UPDATE coupons
             SET type = ?, value = ?, min_order_amount = ?, usage_limit = ?, expires_at = ?, active = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sddisii', $type, $value, $minOrderAmount, $usageLimit, $expiresAt, $activeInt, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function setActive(int $id, bool $active): void
    {
        $activeInt = $active ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE coupons SET active = ? WHERE id = ?');
        $stmt->bind_param('ii', $activeInt, $id);
        $stmt->execute();
        $stmt->close();
    }
}
