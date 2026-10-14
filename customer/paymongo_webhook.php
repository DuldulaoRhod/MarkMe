<?php
/**
 * PayMongo webhook endpoint.
 *
 * The authoritative notification: it arrives whether or not the customer ever
 * came back to the site, which is why an order can settle even if they closed
 * the tab on the provider's page.
 *
 * Nothing in the body is believed until the signature checks out. The raw body
 * is read before any parsing, because the signature covers the exact bytes
 * sent - re-encoding parsed JSON changes them and the comparison would never
 * match.
 *
 * No session, no CSRF token: the caller is PayMongo, not a browser, and the
 * signature is what authenticates it. For the same reason this file must stay
 * reachable without logging in.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

/** Answer and stop. The body is for PayMongo's delivery log, not for a person. */
$respond = static function (int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['message' => $message]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, 'Method not allowed.');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    $respond(400, 'Empty body.');
}

$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!paymongo_verify_signature($raw, $signature)) {
    /* Forged, replayed, or sent before the endpoint secret was configured.
       Logged without the body: an unverified payload is attacker-controlled
       and does not belong in the audit trail. */
    error_log('paymongo_webhook: rejected a request with an invalid signature');
    $respond(401, 'Invalid signature.');
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    $respond(400, 'Unreadable body.');
}

$eventId = (string)($event['data']['id'] ?? '');
$eventType = (string)($event['data']['attributes']['type'] ?? '');
$resource = $event['data']['attributes']['data'] ?? [];
$sessionId = (string)($resource['id'] ?? '');

if ($eventId === '' || $sessionId === '') {
    $respond(400, 'Missing event or resource id.');
}

$payment = payment_by_reference($sessionId);
if (!$payment) {
    /* A session we never created, or one belonging to another installation
       sharing the account. Accepted so PayMongo stops retrying, acted on not
       at all. */
    $respond(200, 'No matching payment; ignored.');
}

/* Recorded before it is acted on. The unique key means the second delivery of
   the same event cannot insert, and that is the signal to do nothing - which
   is what keeps a retried webhook from settling an order twice. */
if (!payment_event_is_new($eventId, $eventType, (int)$payment['payment_id'])) {
    $respond(200, 'Already handled.');
}

/* Already settled: there is nothing a later event can add, and asking PayMongo
   again would be a network call whose only possible outcomes are "no change"
   and "we could not reach them" - and the second would answer 500, which asks
   PayMongo to deliver it again, forever, over a payment that is already done. */
if ($payment['status'] !== 'pending') {
    $respond(200, 'Payment already settled.');
}

/* The event says something happened; PayMongo is then asked directly what the
   session actually looks like. Taking the amount and status from the payload
   would mean trusting a body that only proves someone holds the signing
   secret - this way the figures come from an authenticated request we made. */
$outcome = payment_verify_and_settle($payment);

if ($outcome['reason'] === 'provider unreachable') {
    // A 500 asks PayMongo to retry later, which is what we want: the payment
    // is real and we simply could not confirm it yet.
    $respond(500, 'Could not verify with the provider; please retry.');
}

$respond(200, 'Processed.');
