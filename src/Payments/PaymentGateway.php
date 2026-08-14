<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Payment provider abstraction (PROJECT_RULES.md §9 "Payment Architecture").
 *
 * WHY this exists: 'cash_on_delivery' used to be a literal string baked into
 * the order-creation code (Phase 0/2). §9 requires each payment method to be
 * implemented independently behind a common interface, with "the order
 * system should not contain provider-specific code." OrderService now only
 * ever calls these four methods — it has no idea whether it is talking to
 * COD, bKash, or a card processor.
 *
 * Every implementation must return a PaymentResult and must never throw for
 * an ordinary decline/failure — a declined payment is a normal, expected
 * PaymentResult with status FAILED, not an exception. Exceptions are reserved
 * for configuration problems (e.g. a gateway with no credentials).
 */
interface PaymentGateway
{
    /**
     * Start a payment for an order. For an offline method like COD this
     * simply records the intent; for a redirect-based gateway this would
     * return a checkout URL in PaymentResult::metadata (not built yet — no
     * such gateway is connected, see BkashGateway/CardGateway).
     *
     * $idempotencyKey must make repeated calls with the same key safe: calling
     * createPayment() twice for the same order must never create two charges.
     */
    public function createPayment(PaymentRequest $request): PaymentResult;

    /**
     * Re-check a payment's true status directly with the provider.
     *
     * PROJECT_RULES.md §9: "Never mark an online payment as successful merely
     * because the browser returned to a success URL. Verify server-to-server
     * with the payment provider." This method is that server-to-server check.
     */
    public function verifyPayment(string $transactionReference): PaymentResult;

    public function cancelPayment(string $transactionReference): PaymentResult;

    /**
     * Refund all or part of a payment. $amount defaults to the full amount
     * when null.
     */
    public function refundPayment(string $transactionReference, ?string $amount = null): PaymentResult;
}
