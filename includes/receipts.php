<?php
/**
 * Order receipts.
 *
 * One place that turns an order into the text a customer receives, so the
 * copy sent when the order is placed and any later copy say the same thing
 * about the same order.
 *
 * The figures are read from the order and its line snapshots, never
 * recomputed from the catalogue: a product renamed or repriced next week must
 * not change what a receipt from today says was bought.
 *
 * Nothing here claims a message was delivered. mail_send() reports what
 * actually happened, and the caller decides what to tell the customer.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

/**
 * The receipt for one order, as plain text.
 *
 * @return array{subject: string, body: string}|null  null when there is no such order
 */
function receipt_build(int $orderId): ?array
{
    $stmt = db()->prepare(
        'SELECT o.*, u.email AS account_email
           FROM orders o
           LEFT JOIN users u ON u.user_id = o.user_id
          WHERE o.order_id = :id'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY order_item_id');
    $items->execute(['id' => $orderId]);
    $items = $items->fetchAll();

    $store = setting('store_name', 'MarkMe');
    $reference = order_reference($order);

    $lines = [];
    $lines[] = 'Thank you for your order from ' . $store . '.';
    $lines[] = '';
    $lines[] = 'Order #' . $reference;
    $lines[] = 'Placed ' . date('F j, Y \a\t g:i A', strtotime((string)$order['created_at']));
    $lines[] = str_repeat('-', 56);
    $lines[] = '';

    foreach ($items as $item) {
        $lines[] = $item['product_name_snapshot'];

        /* The customisation is what makes one bookmark different from the
           next, so it belongs on the receipt rather than just the quantity. */
        $detail = array_filter([
            $item['shape_name_snapshot'] ? 'Shape: ' . $item['shape_name_snapshot'] : null,
            $item['design_name_snapshot'] ? 'Design: ' . $item['design_name_snapshot'] : null,
            $item['custom_text'] ? 'Text: "' . $item['custom_text'] . '"' : null,
            $item['custom_image_path'] ? 'Your uploaded photo' : null,
        ]);
        foreach ($detail as $d) {
            $lines[] = '    ' . $d;
        }

        $lines[] = sprintf(
            '    %d x %s = %s',
            (int)$item['quantity'],
            format_price((float)$item['unit_price']),
            format_price((float)$item['line_total'])
        );
        $lines[] = '';
    }

    $lines[] = str_repeat('-', 56);
    $lines[] = 'Total: ' . format_price((float)$order['total_amount']);
    $lines[] = 'Payment method: ' . $order['payment_method'];

    /* What the customer still has to do, if anything. A receipt that does not
       say "this is not paid yet" is a receipt that gets filed and forgotten. */
    $payment = function_exists('payment_for_order') ? payment_for_order($orderId) : null;
    if ($order['payment_method'] === 'PayMongo') {
        $lines[] = 'Payment status: ' . payment_status_label($payment['status'] ?? null);
        if (!$payment || $payment['status'] !== 'paid') {
            $lines[] = '';
            $lines[] = 'This order is saved but not paid yet. You can finish paying from';
            $lines[] = 'My Orders at ' . BASE_URL . '/customer/orders.php';
        }
    } elseif ($order['payment_method'] === 'Bank Transfer') {
        $lines[] = '';
        $lines[] = 'We will send our bank details shortly. Your bookmark goes into';
        $lines[] = 'production once the transfer is confirmed.';
    } else {
        $lines[] = '';
        $lines[] = 'Pay the courier in cash when your order arrives.';
    }

    $lines[] = '';
    $lines[] = 'Delivering to:';
    $lines[] = '  ' . $order['full_name'];
    $lines[] = '  ' . $order['contact_number'];
    $lines[] = '  ' . $order['delivery_address'];
    $lines[] = '';
    $lines[] = 'Track this order: ' . BASE_URL . '/customer/order_detail.php?id=' . $orderId;
    $lines[] = '';
    $lines[] = $store;

    return [
        'subject' => $store . ' receipt for order #' . $reference,
        'body' => implode("\n", $lines),
    ];
}

/**
 * Build and send the receipt for an order.
 *
 * @return array{sent: bool, reason: string, outbox: ?string, address: string}
 */
function receipt_send(int $orderId, ?string $to = null): array
{
    $receipt = receipt_build($orderId);

    if (!$receipt) {
        return ['sent' => false, 'reason' => 'No such order.', 'outbox' => null, 'address' => ''];
    }

    if ($to === null || trim($to) === '') {
        $stmt = db()->prepare(
            'SELECT u.email FROM orders o JOIN users u ON u.user_id = o.user_id WHERE o.order_id = :id'
        );
        $stmt->execute(['id' => $orderId]);
        $to = (string)$stmt->fetchColumn();
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'No valid email address on the account.', 'outbox' => null, 'address' => (string)$to];
    }

    $result = mail_send($to, $receipt['subject'], $receipt['body']);

    /* Logged either way, and without the body: a receipt names what someone
       bought and where they live, which does not belong in the audit trail.
       
       "Not configured" is kept separate from "failed" on purpose. One is a
       setup step nobody has done yet; the other is something breaking. Filing
       them under the same word would have every receipt look like an incident
       until SMTP is switched on. */
    if ($result['sent']) {
        $action = 'order.receipt_sent';
        $summary = 'Emailed the receipt for order #' . $orderId;
    } elseif (!mail_configured()) {
        $action = 'order.receipt_unsent';
        $summary = 'Receipt for order #' . $orderId . ' was written to the outbox: no SMTP configured';
    } else {
        $action = 'order.receipt_failed';
        $summary = 'Could not email the receipt for order #' . $orderId;
    }

    log_activity($action, $summary, 'order', $orderId, null, 'Order Management');

    return $result + ['address' => $to];
}
