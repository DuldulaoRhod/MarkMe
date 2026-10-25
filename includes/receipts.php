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

    
    // Build the HTML item rows from the saved order snapshots.
    $htmlItems = '';

    foreach ($items as $item) {
        $name = e((string)$item['product_name_snapshot']);
        $shape = e((string)($item['shape_name_snapshot'] ?? ''));
        $design = e((string)($item['design_name_snapshot'] ?? ''));
        $customText = e((string)($item['custom_text'] ?? ''));
        $quantity = (int)$item['quantity'];
        $unitPrice = e(format_price((float)$item['unit_price']));
        $lineTotal = e(format_price((float)$item['line_total']));

        $details = '';

        if ($shape !== '') {
            $details .= '<div style="color:#706b80;font-size:13px;margin-top:5px;">Shape: '
                . $shape . '</div>';
        }

        if ($design !== '') {
            $details .= '<div style="color:#706b80;font-size:13px;margin-top:4px;">Design: '
                . $design . '</div>';
        }

        if ($customText !== '') {
            $details .= '<div style="color:#706b80;font-size:13px;margin-top:4px;">Custom text: &ldquo;'
                . $customText . '&rdquo;</div>';
        }

        if (!empty($item['custom_image_path'])) {
            $details .= '<div style="color:#706b80;font-size:13px;margin-top:4px;">Includes your uploaded photo</div>';
        }

        $htmlItems .= '
            <tr>
              <td style="padding:18px 0;border-bottom:1px solid #eeeaf3;">
                <div style="font-weight:700;color:#292640;font-size:15px;">'
                    . $name .
                '</div>'
                    . $details .
                '<div style="color:#706b80;font-size:13px;margin-top:8px;">'
                    . $quantity . ' × ' . $unitPrice .
                '</div>
              </td>
              <td align="right" valign="top" style="padding:18px 0;border-bottom:1px solid #eeeaf3;font-weight:700;color:#292640;white-space:nowrap;">'
                    . $lineTotal .
              '</td>
            </tr>';
    }

    $safeStore = e($store);
    $safeReference = e($reference);
    $placed = e(date(
        'F j, Y \a\t g:i A',
        strtotime((string)$order['created_at'])
    ));
    $total = e(format_price((float)$order['total_amount']));
    $paymentMethod = e((string)$order['payment_method']);
    $customerName = e((string)$order['full_name']);
    $phone = e((string)$order['contact_number']);
    $address = e((string)$order['delivery_address']);

    $trackingUrl = BASE_URL
        . '/customer/order_detail.php?id=' . (int)$orderId;
    $ordersUrl = BASE_URL . '/customer/orders.php';

    $paymentHtml = '';

    if ($order['payment_method'] === 'PayMongo') {
        $payment = function_exists('payment_for_order')
            ? payment_for_order($orderId)
            : null;

        $paid = $payment && ($payment['status'] ?? '') === 'paid';

        $paymentHtml = $paid
            ? '<strong style="color:#18794e;">Payment confirmed</strong>'
            : '<strong style="color:#9a6700;">Payment pending</strong>
               <p style="margin:8px 0 0;color:#514a64;font-size:14px;line-height:1.6;">
                 Your order has been saved but payment is not yet confirmed.
                 Complete your payment from
                 <a href="' . e($ordersUrl) . '" style="color:#65528c;">My Orders</a>.
               </p>';
    } elseif ($order['payment_method'] === 'Bank Transfer') {
        $paymentHtml = '<p style="margin:8px 0 0;color:#514a64;font-size:14px;line-height:1.6;">
          We will send our bank details shortly. Production begins once your transfer is confirmed.
        </p>';
    } else {
        $paymentHtml = '<p style="margin:8px 0 0;color:#514a64;font-size:14px;line-height:1.6;">
          Please pay the courier in cash when your order arrives.
        </p>';
    }

    
$html = '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order receipt - ' . $safeReference . '</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f2f8; font-family: Arial, Helvetica, sans-serif; color: #292640;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #f4f2f8; padding: 28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width: 100%; max-width: 600px; background: #ffffff; border-radius: 14px; overflow: hidden;">

                    <!-- Store Header -->
                    <tr>
                        <td align="center" style="background: #a4470f; padding: 30px 20px;">
                            <div style="font-size: 30px; font-weight: 800; letter-spacing: 1px; color: #ffffff;">
                                ' . $safeStore . '
                            </div>
                            <div style="font-size: 11px; letter-spacing: 3px; color: #fbe8dc; margin-top: 7px;">
                                PERSONALIZED BOOKMARKS
                            </div>
                        </td>
                    </tr>

                    <!-- Receipt Content -->
                    <tr>
                        <td style="padding: 32px 28px 20px;">
                            <div style="text-align: center; font-size: 24px; font-weight: 700; color: #292640;">
                                Thank you for your order!
                            </div>

                            <p style="text-align: center; color: #706b80; font-size: 14px; line-height: 1.6; margin: 10px 0 26px;">
                                We have received your order details. Keep this email for your records.
                            </p>

                            <!-- Order Number -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #fdf1e8; border: 1px solid #eed8c9; border-radius: 10px;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <div style="font-size: 11px; color: #706b80; letter-spacing: 1px;">
                                            ORDER NUMBER
                                        </div>
                                        <div style="font-size: 17px; font-weight: 700; color: #292640; margin-top: 6px;">
                                            ' . $safeReference . '
                                        </div>
                                        <div style="font-size: 13px; color: #706b80; margin-top: 8px;">
                                            ' . $placed . '
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Order Summary -->
                            <h2 style="font-size: 18px; color: #292640; margin: 28px 0 8px;">
                                Order summary
                            </h2>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                ' . $htmlItems . '

                                <tr>
                                    <td style="padding: 22px 0; font-size: 16px; font-weight: 700; color: #292640;">
                                        Total amount
                                    </td>
                                    <td align="right" style="padding: 22px 0; font-size: 22px; font-weight: 800; color: #a4470f; white-space: nowrap;">
                                        ' . $total . '
                                    </td>
                                </tr>
                            </table>

                            <!-- Payment Information -->
                            <div style="border: 1px solid #eed8c9; background: #fdf8f4; border-radius: 10px; padding: 17px; margin-top: 4px;">
                                <div style="font-size: 11px; letter-spacing: 1px; color: #706b80;">
                                    PAYMENT METHOD
                                </div>
                                <div style="font-size: 16px; font-weight: 700; color: #292640; margin-top: 7px;">
                                    ' . $paymentMethod . '
                                </div>
                                <div style="margin-top: 10px;">
                                    ' . $paymentHtml . '
                                </div>
                            </div>

                            <!-- Delivery Details -->
                            <h2 style="font-size: 18px; color: #292640; margin: 28px 0 12px;">
                                Delivery details
                            </h2>

                            <div style="border-left: 3px solid #a4470f; padding: 4px 0 4px 14px; font-size: 14px; line-height: 1.8; color: #514a64;">
                                <strong style="font-size: 15px; color: #292640;">
                                    ' . $customerName . '
                                </strong>
                                <br>
                                ' . $phone . '
                                <br>
                                ' . nl2br($address) . '
                            </div>

                            <!-- Track Order Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top: 28px;">
                                <tr>
                                    <td align="center" bgcolor="#a4470f" style="border-radius: 8px;">
                                        <a href="' . e($trackingUrl) . '" style="display: block; padding: 15px 20px; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px;">
                                            TRACK YOUR ORDER
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Tracking Link Fallback -->
                            <p style="font-size: 13px; line-height: 1.7; color: #706b80; text-align: center; margin: 24px 0 0;">
                                If the button does not work, open this link:
                                <br>
                                <a href="' . e($trackingUrl) . '" style="color: #a4470f; word-break: break-all;">
                                    ' . e($trackingUrl) . '
                                </a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background: #f7f5fb; padding: 22px; color: #706b80; font-size: 12px; line-height: 1.7;">
                            Thank you for choosing
                            <strong style="color: #a4470f;">' . $safeStore . '</strong>.
                            <br>
                            Made personal, made for you.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>';


    return [
        'subject' => $store . ' receipt for order #' . $reference,
        'body' => implode("\n", $lines),
        'html' => $html,
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

    $result = mail_send(
    $to,
    $receipt['subject'],
    $receipt['body'],
    $receipt['html'] ?? null
);

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
