<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Orders\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * The order status machine (PROJECT_RULES.md §7 "Do not allow arbitrary
 * status changes. Define valid transitions explicitly.").
 */
final class OrderStatusTest extends TestCase
{
    public function test_the_normal_lifecycle_is_allowed_step_by_step(): void
    {
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::CONFIRMED));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::CONFIRMED, OrderStatus::PROCESSING));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::PROCESSING, OrderStatus::SHIPPED));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::SHIPPED, OrderStatus::DELIVERED));
    }

    /**
     * This is the exact defect the class exists to prevent: skipping steps.
     */
    public function test_skipping_steps_is_rejected(): void
    {
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::SHIPPED));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::DELIVERED));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::CONFIRMED, OrderStatus::DELIVERED));
    }

    public function test_status_cannot_move_backwards(): void
    {
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::SHIPPED, OrderStatus::PROCESSING));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::DELIVERED, OrderStatus::PENDING));
    }

    public function test_cancellation_is_allowed_before_shipping(): void
    {
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::CANCELLED));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::CONFIRMED, OrderStatus::CANCELLED));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::PROCESSING, OrderStatus::CANCELLED));
    }

    public function test_a_shipped_order_cannot_be_cancelled(): void
    {
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::SHIPPED, OrderStatus::CANCELLED));
    }

    public function test_returns_and_refunds_only_follow_delivery(): void
    {
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::DELIVERED, OrderStatus::RETURNED));
        $this->assertTrue(OrderStatus::canTransition(OrderStatus::RETURNED, OrderStatus::REFUNDED));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::RETURNED));
        $this->assertFalse(OrderStatus::canTransition(OrderStatus::SHIPPED, OrderStatus::REFUNDED));
    }

    public function test_terminal_statuses_allow_nothing_further(): void
    {
        foreach ([OrderStatus::CANCELLED, OrderStatus::FAILED, OrderStatus::REFUNDED] as $terminal) {
            $this->assertTrue(OrderStatus::isTerminal($terminal), "{$terminal} should be terminal.");
            $this->assertSame([], OrderStatus::allowedNext($terminal));
        }
    }

    public function test_non_terminal_statuses_have_at_least_one_next_step(): void
    {
        foreach ([OrderStatus::PENDING, OrderStatus::CONFIRMED, OrderStatus::PROCESSING, OrderStatus::SHIPPED, OrderStatus::DELIVERED] as $status) {
            $this->assertFalse(OrderStatus::isTerminal($status));
            $this->assertNotEmpty(OrderStatus::allowedNext($status));
        }
    }

    public function test_is_valid_rejects_unknown_strings(): void
    {
        $this->assertTrue(OrderStatus::isValid('pending'));
        $this->assertFalse(OrderStatus::isValid('made_up_status'));
        $this->assertFalse(OrderStatus::isValid(''));
    }

    public function test_every_status_has_a_human_label(): void
    {
        foreach (OrderStatus::all() as $status) {
            $this->assertNotSame($status, OrderStatus::label($status), "{$status} should have a distinct display label.");
        }
    }
}
