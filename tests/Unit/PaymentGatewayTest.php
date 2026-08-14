<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Payments\CashOnDeliveryGateway;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymentRequest;
use App\Payments\PaymentStatus;
use App\Payments\UnconfiguredGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The payment abstraction (PROJECT_RULES.md §9).
 */
final class PaymentGatewayTest extends TestCase
{
    /* ---------------- Factory ---------------- */

    public function test_factory_resolves_the_enabled_cash_on_delivery_gateway(): void
    {
        $gateway = PaymentGatewayFactory::for('cash_on_delivery');

        $this->assertInstanceOf(CashOnDeliveryGateway::class, $gateway);
    }

    /**
     * bKash/Card are disabled in config, so the factory must refuse them
     * before ever constructing a gateway — the same rule the order service
     * already checks via PaymentMethod::isEnabled().
     */
    public function test_factory_refuses_a_disabled_payment_method(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not available');

        PaymentGatewayFactory::for('bkash');
    }

    public function test_factory_rejects_an_unknown_method(): void
    {
        $this->expectException(RuntimeException::class);

        PaymentGatewayFactory::for('dogecoin');
    }

    /* ---------------- Cash on Delivery ---------------- */

    public function test_cod_create_payment_is_pending_not_paid(): void
    {
        // COD has no online authorization step: money moves only when the
        // courier hands over the parcel, so a fresh payment must never claim
        // to be PAID (Rule 12 "No fake success").
        $gateway = new CashOnDeliveryGateway();

        $result = $gateway->createPayment(new PaymentRequest(
            orderId: 1,
            orderReference: 'ORD-TEST0001',
            amount: '500.00',
            idempotencyKey: 'ORD-TEST0001'
        ));

        $this->assertSame(PaymentStatus::PENDING, $result->status);
        $this->assertFalse($result->isSuccessful(), 'Pending must not count as successful.');
    }

    public function test_cod_refund_is_recorded_but_explains_it_is_manual(): void
    {
        $gateway = new CashOnDeliveryGateway();

        $result = $gateway->refundPayment('ORD-TEST0001');

        $this->assertSame(PaymentStatus::REFUNDED, $result->status);
        $this->assertStringContainsString('manually', $result->message);
    }

    public function test_cod_cancel_returns_cancelled(): void
    {
        $gateway = new CashOnDeliveryGateway();

        $result = $gateway->cancelPayment('ORD-TEST0001');

        $this->assertSame(PaymentStatus::CANCELLED, $result->status);
    }

    /* ---------------- Unconfigured (bKash/Card) ---------------- */

    /**
     * The whole point of this class: every operation fails loudly rather than
     * simulating a payment that was never actually charged anywhere.
     */
    public function test_unconfigured_gateway_refuses_every_operation(): void
    {
        $gateway = new UnconfiguredGateway('bKash');
        $request = new PaymentRequest(1, 'ORD-X', '100.00', 'ORD-X');

        foreach ([
            static fn() => $gateway->createPayment($request),
            static fn() => $gateway->verifyPayment('ref'),
            static fn() => $gateway->cancelPayment('ref'),
            static fn() => $gateway->refundPayment('ref'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected an unconfigured gateway operation to throw.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not connected', $e->getMessage());
            }
        }
    }

    /* ---------------- PaymentResult ---------------- */

    public function test_only_paid_and_authorized_count_as_successful(): void
    {
        $this->assertTrue((new \App\Payments\PaymentResult(PaymentStatus::PAID))->isSuccessful());
        $this->assertTrue((new \App\Payments\PaymentResult(PaymentStatus::AUTHORIZED))->isSuccessful());

        foreach ([PaymentStatus::PENDING, PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::REFUNDED] as $status) {
            $this->assertFalse(
                (new \App\Payments\PaymentResult($status))->isSuccessful(),
                "{$status} must not count as successful."
            );
        }
    }
}
