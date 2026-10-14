<?php
/**
 * Where PayMongo sends the customer after the hosted page.
 *
 * Arriving here proves the browser loaded a URL. It proves nothing about
 * money, and anybody can type this address with any order_id on the end. So
 * this page settles nothing on its own: it reads the session back from
 * PayMongo server-to-server and acts only on that answer.
 *
 * The order is also checked to belong to the signed-in customer, so one
 * person's reference cannot be used to look at another person's order.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$orderId = (int)($_GET['order_id'] ?? 0);
$user = current_user();

$stmt = db()->prepare('SELECT * FROM orders WHERE order_id = :id AND user_id = :uid');
$stmt->execute(['id' => $orderId, 'uid' => (int)$user['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    // Someone else's order, or none. Same answer either way.
    flash_set('error', 'That order could not be found.');
    redirect('/customer/orders.php');
}

$payment = payment_for_order($orderId);

if (!$payment) {
    flash_set('error', 'No payment was started for that order.');
    redirect('/customer/order_confirmation.php?order_id=' . $orderId);
}

/* The only thing that settles anything. Safe to run again on a refresh: a
   payment that is already settled is left exactly as it is. */
$outcome = payment_verify_and_settle($payment);

$payment = payment_for_order($orderId);
$status = $payment['status'] ?? 'pending';

if ($status === 'paid') {
    flash_set('success', 'Payment received. Thank you!');
} elseif ($outcome['reason'] === 'provider unreachable') {
    flash_set(
        'error',
        'We could not reach the payment provider to confirm this just now. '
        . 'If you completed the payment it will be confirmed shortly.'
    );
} elseif ($status === 'pending') {
    flash_set('error', 'This payment has not been confirmed yet. It will update once the provider confirms it.');
} else {
    flash_set('error', payment_status_label($status) . '. You can try paying again from your orders.');
}

redirect('/customer/order_confirmation.php?order_id=' . $orderId);
