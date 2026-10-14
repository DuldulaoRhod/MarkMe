<?php
/**
 * Pay for an order that was placed but never paid.
 *
 * The customer who closed the PayMongo tab, lost connection, or chose to pay
 * later still has an order sitting unpaid. This is how they finish it.
 *
 * POST only, with a CSRF token: it spends money, so it must not be reachable
 * by a link someone else can get the customer to follow, or by a crawler
 * walking the site.
 *
 * The amount is never taken from the request. payment_start_checkout() reads
 * it from the order row, which is the same figure the provider's answer is
 * checked against afterwards.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/orders.php');
}
csrf_check();

$orderId = (int)($_POST['order_id'] ?? 0);
$user = current_user();

/* Scoped to the signed-in customer, so an order id belonging to somebody else
   simply does not exist as far as this page is concerned. */
$stmt = db()->prepare(
    'SELECT order_id, total_amount, payment_method, status, full_name, contact_number
       FROM orders WHERE order_id = :id AND user_id = :uid'
);
$stmt->execute(['id' => $orderId, 'uid' => (int)$user['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'That order could not be found.');
    redirect('/customer/orders.php');
}

if ($order['payment_method'] !== 'PayMongo') {
    // Cash on delivery and bank transfer are settled off-site; there is
    // nothing to pay here.
    flash_set('error', 'This order is not paid online.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

if ($order['status'] === 'Cancelled') {
    flash_set('error', 'This order was cancelled and cannot be paid.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

$payment = payment_for_order($orderId);
if ($payment && $payment['status'] === 'paid') {
    flash_set('success', 'This order is already paid.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

/* Configuration is checked last. Whether this shop can take a card today says
   nothing about the order, and a cancelled or already-paid order deserves to
   hear that rather than a message about our setup. */
if (!paymongo_configured()) {
    flash_set('error', 'Online payment is unavailable right now. Please contact us to settle this order.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

$session = payment_start_checkout($order, array_filter([
    'name' => $order['full_name'],
    'phone' => $order['contact_number'],
    'email' => $user['email'] ?? null,
]));

if ($session['paid']) {
    /* Asking PayMongo about the old session turned up a payment that had gone
       through without us hearing about it. Now settled, so the order is done. */
    flash_set('success', 'Your payment came through - thank you!');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

if (!$session['ok']) {
    flash_set('error', $session['error'] . ' Your order is still saved.');
    redirect('/customer/order_detail.php?id=' . $orderId);
}

log_activity('payment.retried', 'Customer resumed payment for order #' . $orderId, 'order', $orderId);

// Off to PayMongo's hosted page. No card detail is collected here, or anywhere
// in MarkMe.
header('Location: ' . $session['url']);
exit;
