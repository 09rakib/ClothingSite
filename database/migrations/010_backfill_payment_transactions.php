<?php

declare(strict_types=1);

/**
 * 010 — Backfill payment_transactions for orders created before Phase 4.
 *
 * Every existing order (including the ones migration 008 backfilled from the
 * legacy single_order table) already has a payment_method and a
 * payment_status on the orders row. This creates the matching ledger entry so
 * the new payment_transactions table is a complete history from day one,
 * without touching the orders rows themselves (Rule 10).
 */

return static function (mysqli $db): void {
    $already = $db->query('SELECT COUNT(*) AS c FROM payment_transactions')->fetch_assoc();
    if ((int) $already['c'] > 0) {
        return;
    }

    $orders = $db->query(
        'SELECT id, order_reference, payment_method, payment_status, total, created_at FROM orders'
    )->fetch_all(MYSQLI_ASSOC);

    if ($orders === []) {
        return;
    }

    $insert = $db->prepare(
        'INSERT INTO payment_transactions
            (order_id, gateway, status, amount, currency, idempotency_key, created_at)
         VALUES (?, ?, ?, ?, "BDT", ?, ?)'
    );

    foreach ($orders as $order) {
        $status = $order['payment_status'] === 'paid' ? 'paid' : 'pending';
        // order_reference is already unique, making it a safe idempotency key.
        $key = (string) $order['order_reference'];

        $orderId  = (int) $order['id'];
        $gateway  = (string) $order['payment_method'];
        $amount   = (string) $order['total'];
        $created  = (string) $order['created_at'];

        $insert->bind_param('isssss', $orderId, $gateway, $status, $amount, $key, $created);
        $insert->execute();
    }

    $insert->close();
};
