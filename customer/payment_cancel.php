<?php
/**
 * Where PayMongo sends a customer who backed out.
 *
 * Cancelling is not failing: the order stays as it is, unpaid, and can be
 * paid later. Nothing here changes the payment's status, because the customer
 * leaving the provider's page is not the provider saying the payment is dead -
 * only PayMongo can say that, and the webhook or a later check will.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$orderId = (int)($_GET['order_id'] ?? 0);
$user = current_user();

$stmt = db()->prepare('SELECT order_id FROM orders WHERE order_id = :id AND user_id = :uid');
$stmt->execute(['id' => $orderId, 'uid' => (int)$user['user_id']]);

if (!$stmt->fetch()) {
    flash_set('error', 'That order could not be found.');
    redirect('/customer/orders.php');
}

log_activity('payment.abandoned', 'Customer left the payment page for order #' . $orderId, 'order', $orderId);

flash_set('error', 'Payment was not completed. Your order is saved and still waiting to be paid.');
redirect('/customer/order_confirmation.php?order_id=' . $orderId);
