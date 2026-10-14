<?php
/**
 * What a provider response means for an order.
 *
 * includes/paymongo.php talks to PayMongo; this decides what to believe. The
 * separation matters: everything that marks an order paid is in one file, and
 * it only ever acts on a response it fetched or a webhook it verified.
 *
 * Three rules hold throughout:
 *
 *   1. A customer arriving at the success URL proves they loaded a URL. The
 *      order is settled by a server-to-server read of the session, never by
 *      the redirect.
 *   2. The amount and currency are compared against what we asked for. A
 *      session that came back paid for a different amount is not our payment.
 *   3. Marking an order paid is done once. The second attempt - a refreshed
 *      tab, a retried webhook, a double click - finds the work already done
 *      and changes nothing.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/paymongo.php';

/** The payment row for an order, newest first, or null. */
function payment_for_order(int $orderId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM payments WHERE order_id = :id ORDER BY payment_id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $orderId]);

    return $stmt->fetch() ?: null;
}

/** The payment row for a provider reference, or null. */
function payment_by_reference(string $reference): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE provider_reference = :ref LIMIT 1');
    $stmt->execute(['ref' => $reference]);

    return $stmt->fetch() ?: null;
}

/**
 * Record that a checkout session was opened for an order.
 *
 * The unique key on provider_reference is what makes this safe to call twice:
 * a repeated request cannot create a second row for the same session.
 */
function payment_record_attempt(int $orderId, string $reference, int $centavos): bool
{
    try {
        db()->prepare(
            'INSERT INTO payments (order_id, provider, provider_reference, amount_centavos, currency, status)
             VALUES (:order_id, :provider, :ref, :amount, :currency, :status)'
        )->execute([
            'order_id' => $orderId,
            'provider' => 'paymongo',
            'ref' => $reference,
            'amount' => $centavos,
            'currency' => 'PHP',
            'status' => 'pending',
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('payment_record_attempt: ' . $e->getMessage());
        return false;
    }
}

/**
 * Settle a payment from a verified provider report.
 *
 * $report is what paymongo_fetch_checkout() returned, or the equivalent built
 * from a webhook whose signature already checked out. This function does not
 * fetch anything itself, so there is no path where an unverified payload
 * reaches it by accident - the caller has to have done the verifying.
 *
 * @return array{changed: bool, status: string, reason: string}
 */
function payment_settle(array $payment, array $report): array
{
    $pdo = db();

    /* A report we never actually got. Only payment_verify_and_settle() and the
       webhook should reach here, and both check first - but a status is
       absent rather than false in a failed report, and "absent" must never be
       read as an answer about money. */
    if (!($report['ok'] ?? false)) {
        return ['changed' => false, 'status' => (string)$payment['status'], 'reason' => 'provider unreachable'];
    }

    // Already settled: nothing to do, and nothing to undo. This is what makes
    // a duplicate webhook and a refreshed success page harmless.
    if ($payment['status'] !== 'pending') {
        return ['changed' => false, 'status' => $payment['status'], 'reason' => 'already settled'];
    }

    if (!$report['paid']) {
        $status = match ($report['status']) {
            'expired' => 'expired',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };

        if ($status !== 'pending') {
            $pdo->prepare(
                'UPDATE payments SET status = :s, provider_status = :ps, verified_at = NOW()
                  WHERE payment_id = :id AND status = \'pending\''
            )->execute([
                's' => $status,
                'ps' => $report['status'],
                'id' => (int)$payment['payment_id'],
            ]);
        }

        return ['changed' => $status !== 'pending', 'status' => $status, 'reason' => 'not paid'];
    }

    /* Paid - but for what? A session reported as paid for a different amount
       or currency is not this order's payment, and treating it as one is how a
       ten-peso charge settles a thousand-peso basket. */
    if ((int)$report['amount'] !== (int)$payment['amount_centavos']) {
        error_log(sprintf(
            'payment_settle: amount mismatch on payment %d - expected %d, provider said %d',
            (int)$payment['payment_id'],
            (int)$payment['amount_centavos'],
            (int)$report['amount']
        ));
        log_activity(
            'payment.mismatch',
            'Payment reported a different amount from the order and was not accepted',
            'order',
            (int)$payment['order_id'],
            ['role' => 'guest', 'username' => 'PayMongo'],
            'Order Management'
        );

        return ['changed' => false, 'status' => 'pending', 'reason' => 'amount mismatch'];
    }

    if ($report['currency'] !== '' && strtoupper($report['currency']) !== strtoupper((string)$payment['currency'])) {
        error_log('payment_settle: currency mismatch on payment ' . (int)$payment['payment_id']);
        return ['changed' => false, 'status' => 'pending', 'reason' => 'currency mismatch'];
    }

    /* The write. Guarded by status = 'pending' in the WHERE, so two requests
       racing each other cannot both apply it: the second updates no rows and
       reports no change. */
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE payments
                SET status = \'paid\', provider_status = :ps, provider_payment_id = :pid,
                    verified_at = NOW()
              WHERE payment_id = :id AND status = \'pending\''
        );
        $stmt->execute([
            'ps' => $report['status'],
            'pid' => $report['payment_id'],
            'id' => (int)$payment['payment_id'],
        ]);

        if ($stmt->rowCount() === 0) {
            // Somebody else settled it between the check above and here.
            $pdo->rollBack();
            return ['changed' => false, 'status' => 'paid', 'reason' => 'already settled'];
        }

        /* The order moves out of Pending only now. Its own status stays the
           shop's business - Processing means staff can start work - and the
           money question is answered by the payments table beside it. */
        $pdo->prepare(
            "UPDATE orders SET status = 'Processing'
              WHERE order_id = :id AND status = 'Pending'"
        )->execute(['id' => (int)$payment['order_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('payment_settle: ' . $e->getMessage());
        return ['changed' => false, 'status' => 'pending', 'reason' => 'write failed'];
    }

    // Logged after the commit, so a rolled-back settlement cannot leave an
    // entry claiming the order was paid.
    log_activity(
        'payment.paid',
        'Payment confirmed for order #' . (int)$payment['order_id']
            . ' (' . format_price((int)$payment['amount_centavos'] / 100) . ')',
        'order',
        (int)$payment['order_id'],
        ['role' => 'guest', 'username' => 'PayMongo'],
        'Order Management'
    );

    return ['changed' => true, 'status' => 'paid', 'reason' => ''];
}

/**
 * Fetch the session from PayMongo and settle from that.
 *
 * The one call a return page should make: it does the verifying itself, so a
 * page cannot accidentally settle an order from its own query string.
 */
function payment_verify_and_settle(array $payment): array
{
    $report = paymongo_fetch_checkout((string)$payment['provider_reference']);

    if (!$report['ok']) {
        // A provider that cannot be reached leaves the payment pending. It is
        // never read as a failure, because we do not know that it failed.
        return ['changed' => false, 'status' => (string)$payment['status'], 'reason' => 'provider unreachable'];
    }

    return payment_settle($payment, $report);
}

/**
 * Start - or pick up again - the checkout for an order.
 *
 * Used both when the order is placed and when the customer comes back to pay
 * for one they abandoned, so the two paths cannot drift apart.
 *
 * The amount is read from the order row every time. Nothing the browser sends
 * reaches it, so a tampered form cannot change what is charged.
 *
 * An attempt that is still open is reused rather than replaced: two live
 * sessions for one order would be two ways to charge the same basket. A
 * session PayMongo reports as dead is written off first, which is what frees
 * the order for a fresh one.
 *
 * @return array{ok: bool, url: string, paid: bool, error: string}
 */
function payment_start_checkout(array $order, array $billing = []): array
{
    $orderId = (int)$order['order_id'];
    $centavos = paymongo_centavos((float)$order['total_amount']);
    $fail = static fn(string $message): array => ['ok' => false, 'url' => '', 'paid' => false, 'error' => $message];

    $existing = payment_for_order($orderId);

    if ($existing && $existing['status'] === 'pending') {
        $report = paymongo_fetch_checkout((string)$existing['provider_reference']);

        if (!$report['ok']) {
            /* Not knowing is not the same as dead. Creating a second session
               here would risk charging twice, so the customer is asked to try
               again instead. */
            return $fail($report['error'] !== '' ? $report['error'] : 'Could not reach the payment provider.');
        }

        if ($report['paid']) {
            // It was paid after all - they just never came back to the site.
            payment_settle($existing, $report);
            return ['ok' => false, 'url' => '', 'paid' => true, 'error' => ''];
        }

        $dead = in_array($report['status'], ['expired', 'failed', 'cancelled'], true);

        if (!$dead && $report['url'] !== '') {
            return ['ok' => true, 'url' => $report['url'], 'paid' => false, 'error' => ''];
        }

        // Record the dead session so it stops counting as an open attempt.
        payment_settle($existing, $report);
    }

    /* The reference carries the attempt number. It is what shows up on the
       PayMongo side, and one order paid on a second try should not be two
       entries reading the same. */
    $attempt = (int)db()->query(
        'SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId
    )->fetchColumn();
    $reference = 'MARKME-' . $orderId . ($attempt > 0 ? '-' . ($attempt + 1) : '');

    $session = paymongo_create_checkout(
        [[
            'name' => 'MarkMe order #' . $orderId,
            'amount_centavos' => $centavos,
            'quantity' => 1,
        ]],
        $reference,
        BASE_URL . '/customer/payment_return.php?order_id=' . $orderId,
        BASE_URL . '/customer/payment_cancel.php?order_id=' . $orderId,
        'Order #' . $orderId . ' at MarkMe',
        $billing
    );

    if (!$session['ok']) {
        return $fail($session['error']);
    }

    if (!payment_record_attempt($orderId, $session['id'], $centavos)) {
        /* The session exists at PayMongo but we have no row to settle against,
           so a payment made now could never be matched to this order. Better to
           stop than to send the customer to pay into a gap. */
        return $fail('The payment could not be started. Please try again.');
    }

    return ['ok' => true, 'url' => $session['url'], 'paid' => false, 'error' => ''];
}

/**
 * Has this provider event been handled before?
 *
 * Recorded first, acted on second. The unique key means a repeated delivery
 * fails to insert, which is the signal that it is a repeat.
 */
function payment_event_is_new(string $eventId, string $eventType, ?int $paymentId = null): bool
{
    if ($eventId === '') {
        return false;
    }

    try {
        db()->prepare(
            'INSERT INTO payment_events (provider, provider_event_id, event_type, payment_id)
             VALUES (:provider, :eid, :type, :pid)'
        )->execute([
            'provider' => 'paymongo',
            'eid' => $eventId,
            'type' => mb_substr($eventType, 0, 60),
            'pid' => $paymentId,
        ]);

        return true;
    } catch (PDOException $e) {
        // 23000 is the integrity violation the unique key raises; anything
        // else is a real problem and is logged.
        if ($e->getCode() !== '23000') {
            error_log('payment_event_is_new: ' . $e->getMessage());
        }
        return false;
    }
}

/** Contextual colour for a payment status badge, matching status_variant(). */
function payment_status_variant(?string $status): string
{
    return [
        'pending' => 'warning',
        'paid' => 'success',
        'failed' => 'danger',
        'cancelled' => 'danger',
        'expired' => 'danger',
    ][$status ?? ''] ?? 'secondary';
}

/** Is this an order the customer still owes money on? */
function payment_is_outstanding(array $order, ?array $payment): bool
{
    return $order['payment_method'] === 'PayMongo'
        && $order['status'] !== 'Cancelled'
        && ($payment === null || $payment['status'] !== 'paid');
}

/**
 * Can the customer pay this one right now?
 *
 * Separate from payment_is_outstanding(): with no keys configured the order is
 * still unpaid and should say so, but offering a button that can only fail
 * would be worse than saying nothing.
 */
function payment_can_pay_now(array $order, ?array $payment): bool
{
    return payment_is_outstanding($order, $payment) && paymongo_configured();
}

/** How a payment status should read on screen. */
function payment_status_label(?string $status): string
{
    return [
        'pending' => 'Awaiting payment',
        'paid' => 'Paid',
        'failed' => 'Payment failed',
        'cancelled' => 'Payment cancelled',
        'expired' => 'Payment expired',
    ][$status ?? ''] ?? 'Not applicable';
}
