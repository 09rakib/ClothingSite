<?php

declare(strict_types=1);

/**
 * 008 — Copy legacy single_order/payments rows into orders/order_items.
 *
 * WHY a PHP migration: the historical rows need to be grouped by
 * order_reference, summed into an order total, and matched against each
 * customer's address book — logic that is far clearer as code than as one
 * large SQL statement, and the customer's OWN address book does not exist yet
 * at this point in the migration sequence, so a synthetic address has to be
 * built from data already on the users table.
 *
 * Rule 10 "Historical data is sacred": single_order and payments are only
 * ever READ here. Nothing is deleted or rewritten in them.
 *
 * ASSUMPTION, stated plainly: legacy orders predate the status feature, so
 * there is no way to know whether they were actually delivered. They are
 * migrated with status 'delivered' because every one of them is a completed
 * cash-on-delivery sale from before this system tracked anything else, and an
 * order_status_history row records this assumption explicitly so it is never
 * mistaken for a real status change made by staff.
 */

return static function (mysqli $db): void {
    // Nothing to do if this has already run (e.g. re-running migrations in a
    // fresh environment where 001 already seeded orders directly — it never
    // does, but this keeps the migration idempotent regardless).
    $already = $db->query('SELECT COUNT(*) AS c FROM orders')->fetch_assoc();
    if ((int) $already['c'] > 0) {
        return;
    }

    $references = $db->query(
        'SELECT DISTINCT order_reference FROM single_order WHERE order_reference IS NOT NULL'
    )->fetch_all(MYSQLI_ASSOC);

    if ($references === []) {
        return;
    }

    $insertOrder = $db->prepare(
        'INSERT INTO orders
            (order_reference, user_id, status, subtotal, total, payment_method, payment_status,
             recipient_name, phone, address_line1, city, created_at)
         VALUES (?, ?, "delivered", ?, ?, ?, "paid", ?, ?, ?, ?, ?)'
    );

    $insertItem = $db->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $insertHistory = $db->prepare(
        'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note, created_at)
         VALUES (?, NULL, "delivered", NULL, ?, ?)'
    );

    $migrationNote = 'Migrated from legacy order records; status assumed delivered because these orders predate status tracking.';

    foreach ($references as $refRow) {
        $reference = (string) $refRow['order_reference'];

        $lineStmt = $db->prepare(
            'SELECT so.id, so.user_id, so.product_id,
                    COALESCE(so.product_name, p.name, "Unavailable product") AS product_name,
                    COALESCE(so.unit_price, so.total_amount) AS unit_price,
                    COALESCE(so.quantity, 1) AS quantity,
                    so.total_amount, so.created_at,
                    pay.payment_method
             FROM single_order so
             LEFT JOIN products p ON p.id = so.product_id
             LEFT JOIN payments pay ON pay.order_id = so.id
             WHERE so.order_reference = ?
             ORDER BY so.id ASC'
        );
        $lineStmt->bind_param('s', $reference);
        $lineStmt->execute();
        $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();

        if ($lines === []) {
            continue;
        }

        $userId        = (int) $lines[0]['user_id'];
        $createdAt     = (string) $lines[0]['created_at'];
        $paymentMethod = $lines[0]['payment_method'] ?? 'cash_on_delivery';

        $total = 0.0;
        foreach ($lines as $line) {
            $total += (float) $line['total_amount'];
        }
        $totalStr = number_format($total, 2, '.', '');

        // Legacy orders predate the address book; the best available
        // information is the address on the user's account at migration time.
        $userStmt = $db->prepare('SELECT name, phone, address FROM users WHERE id = ? LIMIT 1');
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        $recipientName = $user['name'] ?? 'Unknown';
        $phone         = $user['phone'] ?? '';
        $address       = $user['address'] ?? 'Address not recorded';
        $city          = 'Not recorded';

        $insertOrder->bind_param(
            'sissssssss',
            $reference,
            $userId,
            $totalStr,
            $totalStr,
            $paymentMethod,
            $recipientName,
            $phone,
            $address,
            $city,
            $createdAt
        );
        $insertOrder->execute();
        $orderId = (int) $db->insert_id;

        foreach ($lines as $line) {
            $productId   = (int) $line['product_id'];
            $productName = (string) $line['product_name'];
            $unitPrice   = (string) $line['unit_price'];
            $quantity    = (int) $line['quantity'];
            $lineTotal   = (string) $line['total_amount'];
            $lineCreated = (string) $line['created_at'];

            $insertItem->bind_param(
                'iisdiss',
                $orderId,
                $productId,
                $productName,
                $unitPrice,
                $quantity,
                $lineTotal,
                $lineCreated
            );
            $insertItem->execute();
        }

        $insertHistory->bind_param('iss', $orderId, $migrationNote, $createdAt);
        $insertHistory->execute();
    }

    $insertOrder->close();
    $insertItem->close();
    $insertHistory->close();
};
