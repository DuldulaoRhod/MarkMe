<?php
/**
 * PayMongo client.
 *
 * Everything here follows the published API rather than a guess:
 *
 *   base            https://api.paymongo.com/v1
 *   auth            HTTP Basic, secret key as the username, empty password
 *   amounts         the smallest currency unit - 1.00 is sent as 100
 *   create session  POST /checkout_sessions
 *   read session    GET  /checkout_sessions/{id}
 *   webhook sig     Paymongo-Signature: t=<ts>,te=<test>,li=<live>
 *                   HMAC-SHA256 of "<ts>.<raw body>" with the endpoint secret,
 *                   compared against te in test mode and li in live
 *
 * The secret key never leaves the server: it is read from config.php, which is
 * gitignored, and is used only in the Authorization header of these calls. It
 * is never rendered, never logged, and never sent to the browser.
 *
 * Nothing here decides that an order is paid. These functions report what
 * PayMongo said; includes/payments.php is what acts on it.
 */
require_once __DIR__ . '/functions.php';

const PAYMONGO_BASE = 'https://api.paymongo.com/v1';

/** Centavos for a peso amount. 1.00 is sent as 100. */
function paymongo_centavos(float $pesos): int
{
    // Rounded before casting: (int)(19.99 * 100) is 1998 on a binary float.
    return (int)round($pesos * 100);
}

/** Is the integration configured enough to call? */
function paymongo_configured(): bool
{
    return defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY !== '';
}

/** Test or live, decided by the key itself rather than a separate setting. */
function paymongo_is_test(): bool
{
    return !paymongo_configured() || str_starts_with(PAYMONGO_SECRET_KEY, 'sk_test');
}

/**
 * One API call.
 *
 * Returns ['ok' => bool, 'status' => int, 'data' => array, 'error' => string].
 * A network failure, a timeout and a 500 are all failures - never silently a
 * success - because the caller decides whether money moved.
 *
 * @param string $method GET or POST
 */
function paymongo_request(string $method, string $path, ?array $payload = null): array
{
    if (!paymongo_configured()) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'PayMongo is not configured.'];
    }

    $ch = curl_init(PAYMONGO_BASE . $path);
    $headers = [
        // Basic auth: secret key as the username, password empty.
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        // Certificates are verified. Turning this off to make a call succeed
        // is how a payment integration starts trusting anybody on the path.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        // The key is never included in anything logged.
        error_log('paymongo: transport failure on ' . $method . ' ' . $path . ' - ' . $curlError);
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Could not reach the payment provider.'];
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        error_log('paymongo: unreadable response on ' . $method . ' ' . $path);
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => 'Unreadable response from the payment provider.'];
    }

    if ($status < 200 || $status >= 300) {
        $detail = $decoded['errors'][0]['detail'] ?? 'The payment provider refused the request.';
        error_log('paymongo: HTTP ' . $status . ' on ' . $method . ' ' . $path . ' - ' . $detail);
        /* The provider's wording is logged, not shown: it can name internal
           fields and limits that mean nothing to a shopper. The message stays
           neutral because this covers reads as well as writes - "could not be
           started" would be wrong when the failure was checking an existing
           payment. */
        return ['ok' => false, 'status' => $status, 'data' => $decoded, 'error' => 'The payment could not be processed right now.'];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded, 'error' => ''];
}

/**
 * Create a hosted checkout session.
 *
 * $lineItems are [name, amount_centavos, quantity]; the amounts come from the
 * caller, which computed them from the database, never from the browser.
 *
 * @return array{ok: bool, id: string, url: string, error: string}
 */
function paymongo_create_checkout(
    array $lineItems,
    string $referenceNumber,
    string $successUrl,
    string $cancelUrl,
    string $description = '',
    array $billing = []
): array {
    $items = [];
    foreach ($lineItems as $item) {
        $items[] = [
            'name' => mb_substr((string)$item['name'], 0, 120),
            'amount' => (int)$item['amount_centavos'],
            'currency' => 'PHP',
            'quantity' => max(1, (int)$item['quantity']),
        ];
    }

    $attributes = [
        'line_items' => $items,
        // Card plus the e-wallets a Philippine shopper expects. Each still has
        // to be enabled on the PayMongo account before it appears.
        'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'reference_number' => $referenceNumber,
        'description' => $description !== '' ? mb_substr($description, 0, 200) : 'MarkMe order',
        'send_email_receipt' => false,
    ];
    if ($billing) {
        $attributes['billing'] = $billing;
    }

    $result = paymongo_request('POST', '/checkout_sessions', [
        'data' => ['attributes' => $attributes],
    ]);

    if (!$result['ok']) {
        return ['ok' => false, 'id' => '', 'url' => '', 'error' => $result['error']];
    }

    $id = (string)($result['data']['data']['id'] ?? '');
    $url = (string)($result['data']['data']['attributes']['checkout_url'] ?? '');

    if ($id === '' || $url === '') {
        error_log('paymongo: checkout session response missing id or url');
        return ['ok' => false, 'id' => '', 'url' => '', 'error' => 'The payment could not be started.'];
    }

    return ['ok' => true, 'id' => $id, 'url' => $url, 'error' => ''];
}

/**
 * Read a checkout session back from PayMongo.
 *
 * This is the only thing that establishes whether money moved. A customer
 * arriving at the success URL proves they loaded a URL, nothing more.
 *
 * The checkout url comes back too: a customer who abandoned the page can be
 * sent to the same session rather than a new one, and two live sessions for one
 * order would be two ways to charge the same basket.
 *
 * @return array{ok: bool, paid: bool, status: string, amount: int, currency: string,
 *               payment_id: string, reference: string, url: string, error: string}
 */
function paymongo_fetch_checkout(string $sessionId): array
{
    $blank = [
        'ok' => false, 'paid' => false, 'status' => '', 'amount' => 0,
        'currency' => '', 'payment_id' => '', 'reference' => '', 'url' => '', 'error' => '',
    ];

    if ($sessionId === '') {
        // array_merge, not +: the union operator keeps the left-hand value and
        // would silently throw this message away.
        return array_merge($blank, ['error' => 'No payment reference.']);
    }

    $result = paymongo_request('GET', '/checkout_sessions/' . rawurlencode($sessionId));
    if (!$result['ok']) {
        return array_merge($blank, ['error' => $result['error']]);
    }

    $attr = $result['data']['data']['attributes'] ?? [];
    $payments = $attr['payments'] ?? [];

    /* A session produces at most one successful payment, so the first one
       reported as paid is the payment. Anything else leaves paid false. */
    $paid = false;
    $paymentId = '';
    $amount = 0;
    $currency = '';
    $status = (string)($attr['status'] ?? '');

    foreach ($payments as $payment) {
        $pAttr = $payment['attributes'] ?? [];
        $pStatus = (string)($pAttr['status'] ?? '');
        if ($pStatus === 'paid') {
            $paid = true;
            $paymentId = (string)($payment['id'] ?? '');
            $amount = (int)($pAttr['amount'] ?? 0);
            $currency = (string)($pAttr['currency'] ?? '');
            $status = $pStatus;
            break;
        }
        // Remember the latest unsuccessful one so the status is not just
        // "active" when something actually failed.
        $status = $pStatus !== '' ? $pStatus : $status;
    }

    return [
        'ok' => true,
        'paid' => $paid,
        'status' => $status,
        'amount' => $amount,
        'currency' => $currency,
        'payment_id' => $paymentId,
        'reference' => (string)($attr['reference_number'] ?? ''),
        'url' => (string)($attr['checkout_url'] ?? ''),
        'error' => '',
    ];
}

/**
 * Verify a webhook signature.
 *
 * The header carries a timestamp and two signatures: te for test-mode events
 * and li for live ones. The signed string is the timestamp, a dot, and the
 * raw body - raw, because re-encoding parsed JSON changes the bytes and the
 * signature would never match.
 *
 * $tolerance guards against a captured request being replayed later.
 */
function paymongo_verify_signature(string $rawBody, string $header, int $tolerance = 300): bool
{
    if (!defined('PAYMONGO_WEBHOOK_SECRET') || PAYMONGO_WEBHOOK_SECRET === '' || $header === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $header) as $piece) {
        $bits = explode('=', trim($piece), 2);
        if (count($bits) === 2) {
            $parts[$bits[0]] = $bits[1];
        }
    }

    $timestamp = $parts['t'] ?? '';
    $expected = paymongo_is_test() ? ($parts['te'] ?? '') : ($parts['li'] ?? '');

    if ($timestamp === '' || $expected === '') {
        return false;
    }
    if ($tolerance > 0 && abs(time() - (int)$timestamp) > $tolerance) {
        return false;
    }

    $computed = hash_hmac('sha256', $timestamp . '.' . $rawBody, PAYMONGO_WEBHOOK_SECRET);

    // Constant time: a timing difference here would leak the signature a byte
    // at a time.
    return hash_equals($computed, $expected);
}
