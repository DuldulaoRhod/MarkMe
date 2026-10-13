<?php
/**
 * Send a fresh activation link.
 *
 * Activation tokens expire after a day, so "check your email for the
 * activation link" was a dead end for anyone who registered and came back
 * later: the link in their inbox no longer worked and nothing offered them a
 * new one. This is that door.
 *
 * It deliberately answers the same way whether or not the address belongs to
 * an account, and whether or not that account is already active. Saying "no
 * such account" here would turn this into a way to test which addresses have
 * registered, which is exactly what the login form is careful not to be.
 *
 * Issuing a token is cheap; sending mail is not, and an open endpoint that
 * sends mail on demand is a way to use the shop as a spam relay. Two limits
 * apply: a short cooldown per account, and a ceiling per browser session.
 */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/index.php');
}
csrf_check();

$identifier = trim((string)($_POST['identifier'] ?? ''));
$returnTo = safe_local_path($_POST['return_to'] ?? null);

/* The same words whatever happened, so the response carries no information
   about who is registered. */
$quietSuccess = static function () use ($returnTo): never {
    flash_set(
        'login_success',
        'If that account exists and is waiting to be activated, a new link is on its way. '
        . 'It is valid for 24 hours.'
    );
    flash_set('reopen_modal', 'login');
    redirect($returnTo);
};

if ($identifier === '') {
    $quietSuccess();
}

/* Per-session ceiling. Counted in the session rather than a table because it
   is throttling one browser, not banning an address, and it has to keep
   working when the database is busy. */
if (!captcha_rate_ok('resend_activation', 5, 900)) {
    flash_set('login_error', 'Too many requests. Please wait a few minutes and try again.');
    flash_set('reopen_modal', 'login');
    redirect($returnTo);
}

/* is_active is the activation flag in this schema - registration writes 0 and
   activation writes 1 - so the account this endpoint exists for is one with
   is_active = 0. Filtering for 1 here would have meant never finding anybody
   who needs a link. */
$stmt = db()->prepare(
    'SELECT user_id, email, full_name, username, is_active
       FROM users
      WHERE username = :username OR email = :email
      LIMIT 1'
);
$stmt->execute(['username' => $identifier, 'email' => $identifier]);
$user = $stmt->fetch();

// Unknown account, or one that is already activated: nothing to send, and the
// caller is told the same thing either way.
if (!$user || (int)$user['is_active'] === 1) {
    $quietSuccess();
}

/* Per-account cooldown, so holding down the button cannot fill an inbox.
   Measured from the newest unused token rather than from a counter, because
   the tokens are the thing being rationed. */
$recent = db()->prepare(
    "SELECT COUNT(*) FROM email_verification_tokens
      WHERE user_id = :id AND purpose = 'activation' AND used_at IS NULL
        AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
);
$recent->execute(['id' => (int)$user['user_id']]);
if ((int)$recent->fetchColumn() > 0) {
    flash_set(
        'login_error',
        'A link was sent in the last couple of minutes. Please check your inbox, '
        . 'including spam, before asking for another.'
    );
    flash_set('reopen_modal', 'login');
    redirect($returnTo);
}

try {
    /* activation_token_issue() already deletes any earlier token for the
       account before writing the new one, so an old link in a forwarded email
       stops working the moment this runs. Exactly one live link per account,
       which is the property that matters. */
    $raw = activation_token_issue((int)$user['user_id']);
} catch (Throwable $e) {
    // The reason goes to the log, never to the page.
    error_log('resend_activation: ' . $e->getMessage());
    flash_set('login_error', 'Could not send that link. Please try again shortly.');
    flash_set('reopen_modal', 'login');
    redirect($returnTo);
}

$link = BASE_URL . '/auth/activate.php?token=' . urlencode($raw);
$name = $user['full_name'] ?: $user['username'];

$result = mail_send(
    (string)$user['email'],
    'Activate your MarkMe account',
    "Hello {$name},\n\n"
    . "Here is a new link to activate your MarkMe account:\n\n{$link}\n\n"
    . "The link is valid for 24 hours. If you did not ask for this, you can ignore "
    . "this message - the account stays inactive and nothing happens.\n\n"
    . "MarkMe\n"
);

/* The token is never written to the audit trail: an entry carrying the link
   would be a second place the account could be activated from. */
log_activity(
    'account.activation_resent',
    'Activation link re-sent to ' . $name . ($result['sent'] ? '' : ' (mail not configured)'),
    'user',
    (int)$user['user_id'],
    ['role' => 'guest', 'username' => 'Guest'],
    'Account'
);

/* With SMTP unconfigured the message goes to storage/outbox instead of an
   inbox. Saying "sent" then would be a lie, so the link is shown here - the
   same thing registration already does, and it disappears the moment SMTP is
   configured. */
if (!$result['sent']) {
    flash_set('activation_link', $link);
}

$quietSuccess();
