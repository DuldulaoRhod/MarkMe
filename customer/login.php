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

/* The CAPTCHA is checked before the password.
   
   Checked first because the point of it here is to make automated guessing
   expensive: a script that has to solve a challenge per attempt cannot work
   through a password list, whatever the lockout threshold is. Checked on the
   server because the control in the page is only a picture - the answer lives
   in the session and captcha_check() consumes it, so one solved challenge buys
   exactly one attempt.

   Passing it proves nothing about the credentials. The password, the account's
   activation state, its lockout and its second factor are all still ahead of
   the caller; this gate only decides whether they are worth evaluating. */
if (captcha_active() && !captcha_check($_POST)) {
    log_activity(
        'auth.captcha_failed',
        'Sign-in blocked: CAPTCHA not solved' . ($identifier !== '' ? ' for "' . $identifier . '"' : ''),
        'user',
        null,
        null,
        'Authentication'
    );
    $back(captcha_mode() === 'local'
        ? 'The characters did not match. A new code has been loaded - please try again.'
        : 'Please complete the "I am not a robot" check and try again.');
}

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

$back($result['message']);
