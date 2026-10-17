<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/customer/index.php');
}

require_guest();
csrf_check();

$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';
$returnTo = safe_local_path($_POST['return_to'] ?? null);

/** Send the form back with a message, keeping what was typed. */
$back = static function (string $message) use ($identifier, $returnTo): never {
    flash_set('login_error', $message);
    flash_set('login_old_identifier', $identifier);
    flash_set('reopen_modal', 'login');
    flash_set('login_return_to', $returnTo);
    redirect($returnTo);
};

if ($identifier === '' || $password === '') {
    $back('Please enter your username and password.');
}

/* No CAPTCHA here. Automated guessing is held back by the lockout in
   attempt_login(), which counts failures against the account and refuses it
   server-side once the threshold is passed. Registration keeps its challenge,
   because creating accounts in bulk has no account to lock.

   Everything else this form relies on is untouched: CSRF above, then the
   password, the account's activation state, the lockout and the second
   factor, all inside attempt_login(). */
$result = attempt_login($identifier, $password);

if ($result['status'] === 'ok') {
    $user = $result['user'];
    redirect(is_manager() ? panel_base() . '/dashboard.php' : $returnTo);
}

// Password was right but the account carries a second factor. The session is
// only half open at this point - $_SESSION['user'] is not set - so this is a
// gate rather than a suggestion.
if ($result['status'] === 'mfa_required') {
    redirect('/auth/mfa.php?return=' . urlencode($returnTo));
}

/* Two-factor is on for this account but there is nothing to challenge with
   yet. They finish setting it up before the session opens, rather than being
   refused for not having done something they were never asked to do. */
if ($result['status'] === 'mfa_enrol_required') {
    redirect('/auth/mfa_enrol.php?return=' . urlencode($returnTo));
}

$back($result['message']);
