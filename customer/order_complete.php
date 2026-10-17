<?php
/**
 * The customer confirming an order arrived.
 *
 * Only one move is possible here: On Shipping -> Completed. Everything before
 * that is the shop's to decide - a customer cannot mark an order complete that
 * has not been sent, and cannot reopen one that is already closed.
 *
 * The same transition staff can make from the back office, from the one side
 * that actually knows whether the parcel turned up.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/orders.php');
}
csrf_check();

$orderId = (int)($_POST['order_id'] ?? 0);
$user = current_user();

/* Scoped to the signed-in customer, so another customer's order id simply
   does not exist as far as this page is concerned. */
$stmt = db()->prepare('SELECT order_id, status FROM orders WHERE order_id = :id AND user_id = :uid');
$stmt->execute(['id' => $orderId, 'uid' => (int)$user['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'That order could not be found.');
    redirect('/customer/orders.php');
}

if ($order['status'] !== 'On Shipping') {
    /* Says what is true of the order rather than "not allowed": an order that
       has not shipped yet is a waiting game, not a permissions problem. */
    flash_set('error', $order['status'] === 'Completed'
        ? 'This order is already marked as received.'
        : 'This order has not been shipped yet, so it cannot be confirmed as received.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

/* Guarded by the status in the WHERE clause, so a double-click or a resent
   form cannot apply it twice - the second one updates no rows. */
$update = db()->prepare(
    "UPDATE orders SET status = 'Completed'
      WHERE order_id = :id AND user_id = :uid AND status = 'On Shipping'"
);
$update->execute(['id' => $orderId, 'uid' => (int)$user['user_id']]);

if ($update->rowCount() === 0) {
    flash_set('error', 'That order was already updated.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

log_activity(
    'order.status',
    'Customer confirmed delivery of order #' . $orderId . ' (On Shipping to Completed)',
    'order',
    $orderId,
    null,
    'Order Management'
);

flash_set('success', 'Thank you for confirming. Your order is now marked as completed.');
redirect('/customer/order_detail.php?id=' . $orderId);
