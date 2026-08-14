<?php

declare(strict_types=1);

namespace App\Payments;

use RuntimeException;

/**
 * Placeholder for a payment method whose real integration has not been
 * built yet (currently bKash and Card).
 *
 * WHY this exists instead of a fake bKash/Card implementation: PROJECT_RULES
 * Rule 12 forbids showing success unless the operation actually succeeded,
 * and §9 requires real gateway credentials and server-to-server verification
 * for any online method. Simulating a successful bKash payment with no real
 * bKash account behind it would be exactly the fake success Rule 12 forbids —
 * a customer or a demo audience could be shown "Payment successful" for money
 * that was never actually charged anywhere.
 *
 * This class exists so the PaymentGateway interface has a concrete, complete
 * set of implementations (satisfying §9's abstraction) while being honest
 * that the method is not really connected: every call fails loudly with a
 * clear configuration error instead of pretending to work.
 *
 * PaymentMethod.php already keeps these methods switched off in config
 * (`enabled => false`), so PaymentGatewayFactory never routes a real checkout
 * here — this is a second line of defence, not the only one.
 */
final class UnconfiguredGateway implements PaymentGateway
{
    public function __construct(private readonly string $methodName)
    {
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        throw $this->notConfigured();
    }

    public function verifyPayment(string $transactionReference): PaymentResult
    {
        throw $this->notConfigured();
    }

    public function cancelPayment(string $transactionReference): PaymentResult
    {
        throw $this->notConfigured();
    }

    public function refundPayment(string $transactionReference, ?string $amount = null): PaymentResult
    {
        throw $this->notConfigured();
    }

    private function notConfigured(): RuntimeException
    {
        return new RuntimeException(sprintf(
            '%s is not connected yet — add merchant credentials and a real gateway implementation before enabling it.',
            ucfirst($this->methodName)
        ));
    }
}
