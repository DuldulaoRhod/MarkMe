<?php
require_once __DIR__ . '/functions.php';
// Loaded here so every page that authenticates also has the per-account
// permission resolution available to can(); without it can() silently falls
// back to role defaults and a staff member keeps access an administrator
// thought they had removed.
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/icons.php';

/**
 * Send an expired session back to the public site with its explanation.
 *
 * Without this the guards below treat a timed-out manager as someone without
 * permission and render "Access denied" - which is both wrong (they had
 * permission; the clock ran out) and a dead end. The timeout has already
 * destroyed the session and left the message in a flash; this puts them
 * somewhere that can show it.
 */
function bounce_expired_session(): void
{
    if (empty($_SESSION['flash']['session_expired'])) {
        return;
    }

    // Consumed here, not merely read. It is a flash: it describes one event,
    // and leaving it set made every later guard bounce as well. Because
    // session_regenerate_id() carries $_SESSION across a new sign-in, a stale
    // flag survived logging back in and sent the user straight back out -
    // which looked exactly like the Admin Panel link being broken.
    //
    // The message itself lives in separate flashes (error, login_error) that
    // the destination page renders, so clearing this one loses nothing.
    unset($_SESSION['flash']['session_expired']);

    /* An AJAX caller cannot follow a redirect usefully: it would get a page of
       HTML back where it expected JSON, and the failure would be silent. It
       gets one recognisable shape instead, and the client sends the browser to
       the login page itself. 401 rather than 403 - the request was not
       authenticated, not authenticated-and-refused. */
    if (is_ajax()) {
        json_response([
            'ok' => false,
            'session_expired' => true,
            'login_url' => BASE_URL . '/customer/index.php',
            'message' => 'Your session has expired. Please sign in again.',
        ], 401);
    }

    /* One sign-in dialog for everyone, on the storefront. The message flashes
       (error, login_error) are left set for it to render, and reopen_modal
       tells it to open. */
    flash_set('reopen_modal', 'login');
    redirect('/customer/index.php');
}

/**
 * An expired password is a dead end everywhere except the screen that fixes
 * it. Called from the guards below, so it covers pages and POST handlers
 * alike rather than only the ones that remember to ask.
 */
function enforce_password_expiry(): void
{
    if (empty($_SESSION['password_expired'])) {
        return;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = ['/customer/settings.php', '/admin/profile.php', '/staff/profile.php',
                '/customer/logout.php', '/admin/logout.php', '/auth/mfa_setup.php'];

    foreach ($allowed as $path) {
        if (str_ends_with($script, $path)) {
            return;
        }
    }

    flash_set('error', 'Your password has expired. Please create a new password to continue.');
    redirect(is_manager() ? panel_base() . '/profile.php#password' : '/customer/settings.php#password');
}

function require_login(): void
{
    bounce_expired_session();

    if (!is_logged_in()) {
        /* An AJAX caller cannot follow a redirect usefully - it would get the
           storefront's HTML back where it expected JSON. It gets the same
           session-expired shape the back office uses instead. */
        if (is_ajax()) {
            json_response([
                'ok' => false,
                'session_expired' => true,
                'login_url' => BASE_URL . '/customer/index.php',
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        // A session that just timed out has already explained itself. Saying
        // "Please log in to continue" over the top of that would replace the
        // reason with a blank instruction.
        if (empty($_SESSION['flash']['login_error'])) {
            flash_set('login_error', 'Please log in to continue.');
        }
        flash_set('reopen_modal', 'login');
        flash_set('login_return_to', safe_local_path(current_path()));
        redirect('/customer/index.php');
    }

    enforce_password_expiry();
}

/** Administrator only. Staff hitting this get the same refusal as a guest. */
function require_admin(): void
{
    bounce_expired_session();

    if (!is_admin()) {
        deny_access('Administrator access required.');
    }
}

/** Lets both administrators and staff into the back office. */
function require_manager(): void
{
    bounce_expired_session();

    if (!is_manager()) {
        deny_access('Management access required.');
    }

    enforce_password_expiry();
}

/**
 * Enforce one capability from the matrix in functions.php.
 *
 * This is the real boundary. Hiding a button or a sidebar link is a courtesy;
 * every restricted page and every POST endpoint calls this, so typing the URL
 * or replaying a form gets the same 403.
 */
function require_can(string $capability): void
{
    bounce_expired_session();

    if (!can($capability)) {
        deny_access('Your account does not have permission to do that.');
    }
}

/**
 * A readable 403 rather than a bare die(), so a staff member who follows a
 * stale link understands what happened instead of seeing a blank page.
 */
function deny_access(string $message): never
{
    /* Not signed in is a different answer from not allowed.
    
       An expired back-office session used to land here and be shown "Access
       denied" - a 403 that reads like a permissions problem when the real
       answer is "your session ended, sign in again". Worse, there was no way
       back: the page offered no login. Only a caller who IS signed in and
       still lacks the capability gets the refusal page below. */
    if (!is_logged_in()) {
        $expired = !empty($_SESSION['flash']['session_expired'])
            || !empty($_SESSION['flash']['login_error']);

        if (is_ajax()) {
            /* One shape for every expired AJAX call, so the client has a
               single thing to recognise. 401, not 403: the request was not
               authenticated, rather than authenticated and refused. */
            json_response([
                'ok' => false,
                'session_expired' => true,
                'login_url' => BASE_URL . '/customer/index.php',
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        if (!$expired) {
            flash_set('login_error', 'Please sign in to continue.');
        }
        flash_set('reopen_modal', 'login');
        flash_set('login_return_to', safe_local_path(current_path()));

        // One sign-in dialog for everyone, on the storefront.
        redirect('/customer/index.php');
    }

    http_response_code(403);

    if (is_ajax()) {
        json_response(['ok' => false, 'message' => $message], 403);
    }

    $signedIn = is_logged_in();
    $home = BASE_URL . ($signedIn && is_manager() ? panel_base() . '/dashboard.php' : '/customer/index.php');
    ?>
    <!DOCTYPE html>
    <html lang="en" data-base="<?= BASE_URL ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Access denied · MarkMe</title>
        <?php require __DIR__ . '/theme_boot.php'; ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <?php require __DIR__ . '/styles.php'; ?>
    </head>
    <body class="admin-login-body">
        <main class="admin-login-card" style="text-align:center">
            <span class="gate-icon" aria-hidden="true"><?= icon('shield', 26) ?></span>
            <h1 style="font-size:1.35rem;margin-bottom:var(--s-2)">Access denied</h1>
            <p class="text-muted" style="font-size:.94rem;margin-bottom:var(--s-6)"><?= e($message) ?></p>
            <a href="<?= e($home) ?>" class="btn btn-primary btn-block">
                <?= $signedIn && is_manager() ? 'Back to dashboard' : 'Back to MarkMe' ?>
            </a>
            <?php if ($signedIn): ?>
                <p style="text-align:center;margin-top:var(--s-5);font-size:.86rem">
                    Signed in as <strong><?= e(current_user()['username']) ?></strong>
                    (<?= e(strtolower(role_label())) ?>) &middot;
                    <a href="<?= BASE_URL ?>/admin/logout.php">Log out</a>
                </p>
            <?php endif; ?>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function require_guest(): void
{
    if (is_logged_in()) {
        redirect(is_manager() ? panel_base() . '/dashboard.php' : '/customer/index.php');
    }
}

/**
 * Authenticate a user.
 *
 * This is the only path into a MarkMe session - both the storefront modal and
 * the admin login post through here - so every gate lives in one place:
 *
 *   locked account  ->  refused before the password is even checked
 *   wrong password  ->  failed attempt recorded, account locked at the limit
 *   not activated   ->  refused with a resend option
 *   MFA enrolled    ->  half-open session only, completed by the TOTP step
 *   expired password->  signed in but forced to change it
 *
 * Returns a status array rather than a bare user row, because "correct
 * password" is no longer the same thing as "signed in".
 *
 * @return array{status: string, message: string, user: ?array}
 *   status: ok | invalid | locked | inactive | mfa_required
 */
function attempt_login(string $identifier, string $password): array
{
    $fail = static fn(string $status, string $message): array =>
        ['status' => $status, 'message' => $message, 'user' => null];

    // Deliberately identical wording for "no such user" and "wrong password":
    // telling them apart would turn the login form into an account-enumeration
    // oracle.
    $generic = 'Invalid username/email or password.';

    /* Shown whenever the account is barred, whether it just locked or was
       already locked. It names no threshold, no count and no account. */
    $lockedMessage = 'Unable to sign in at this time. Please try again later.';

    $stmt = db()->prepare(
        'SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1'
    );
    $stmt->execute(['username' => $identifier, 'email' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        // Logged, but no account_locks row: an attacker must not be able to
        // create lock rows for usernames that were never real.
        log_activity(
            'auth.failed',
            'Failed sign-in for unknown account "' . mb_substr($identifier, 0, 40) . '"',
            'user',
            null,
            ['role' => 'guest', 'username' => 'Guest'],
            'Authentication'
        );
        return $fail('invalid', $generic);
    }

    $userId = (int)$user['user_id'];

    // ---- locked? refuse before touching the password --------------------
    if (account_is_locked($userId)) {
        log_activity(
            'auth.blocked',
            'Sign-in refused: account is locked',
            'user',
            $userId,
            $user,
            'Authentication'
        );
        /* Deliberately vague. Naming the threshold, the count or even the
           fact that this particular account is locked tells an attacker how
           close they are and confirms the account exists. The audit entry
           above carries the detail for whoever is allowed to see it. */
        return $fail('locked', $lockedMessage);
    }

    // ---- password -------------------------------------------------------
    if (!password_verify($password, $user['password_hash'])) {
        $result = register_failed_attempt($user);

        log_activity(
            'auth.failed',
            'Failed sign-in (attempt ' . $result['attempts'] . ' of ' . sec_max_attempts() . ')',
            'user',
            $userId,
            $user,
            'Authentication'
        );

        if ($result['locked']) {
            log_activity(
                'account.locked',
                'Account locked after ' . $result['attempts'] . ' failed sign-in attempts',
                'user',
                $userId,
                $user,
                'Authentication'
            );
            return $fail('locked', $lockedMessage);
        }

        /* The count that used to be appended here - "2 attempts remaining" -
           told an attacker exactly how many guesses were left and how many
           had already been spent against this account. The lockout still
           runs; it just no longer narrates itself. */
        return $fail('invalid', $generic);
    }

    // ---- activated? -----------------------------------------------------
    if ((int)$user['is_active'] !== 1) {
        log_activity(
            'auth.blocked',
            'Sign-in refused: account not activated',
            'user',
            $userId,
            $user,
            'Authentication'
        );
        return $fail(
            'inactive',
            'This account has not been activated yet. Check your email for the activation link.'
        );
    }

    // The password was right, so the counter resets whatever happens next.
    clear_failed_attempts($userId);

    // ---- MFA ------------------------------------------------------------
    /* A second factor is required when the account has one enrolled. Which
       kind decides what the challenge asks for, not whether there is one: the
       condition used to require a TOTP secret, so an account using emailed
       codes - which has no secret - would have walked straight past this gate
       into a full session. */
    $mfaMethod = $user['mfa_method'] ?? 'totp';
    $mfaOn = (int)$user['mfa_enabled'] === 1;

    /* Enabled is not the same as usable. A factor can only challenge someone
       if the means to deliver it exists: a TOTP account needs a confirmed
       secret, and an email account needs mail to be configured. An account
       switched on without either is not a locked account - it is one that has
       not finished enrolling, and it gets sent to do exactly that. */
    $hasFactor = $mfaOn && ($mfaMethod === 'email'
        ? mail_configured()
        : !empty($user['mfa_secret']));

    $needsEnrolment = $mfaOn && !$hasFactor;

    if ($hasFactor || $needsEnrolment) {
        /* A half-open session: enough to finish the second-factor step and
           nothing else. $_SESSION['user'] is deliberately NOT set, so every
           require_login()/require_can() in the app still treats this as a
           guest until the code checks out. Enrolment runs on the same
           half-session, so an account that must set up a factor cannot reach
           any other page until it has. */
        session_regenerate_id(true);
        $_SESSION['mfa_pending'] = [
            'user_id' => $userId,
            'username' => $user['username'],
            'role' => $user['role'],
            'started' => time(),
        ];

        log_activity(
            'auth.mfa_challenge',
            $needsEnrolment
                ? 'Password accepted, awaiting two-factor enrolment'
                : 'Password accepted, awaiting authenticator code',
            'user',
            $userId,
            $user,
            'Authentication'
        );

        return [
            'status' => $needsEnrolment ? 'mfa_enrol_required' : 'mfa_required',
            'message' => '',
            'user' => $user,
        ];
    }

    establish_session($user);

    return ['status' => 'ok', 'message' => '', 'user' => $user];
}

/**
 * Turn a verified identity into a signed-in session.
 *
 * Shared by the direct path and by the MFA step, so the session is built the
 * same way regardless of which door was used - including the id rotation that
 * closes session fixation.
 */
function establish_session(array $user): array
{
    session_regenerate_id(true);
    unset($user['password_hash'], $user['mfa_secret']);

    $_SESSION['user'] = $user;
    $_SESSION['started_at'] = time();
    $_SESSION['last_activity'] = time();
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_code_sent']);

    // A successful sign-in answers any earlier expiry, so nothing about the
    // previous session should outlive it. Belt and braces alongside the
    // consume in bounce_expired_session().
    unset($_SESSION['flash']['session_expired']);

    // Expired passwords still sign in, but land on the change-password screen
    // and cannot go anywhere else until it is done.
    if (password_is_expired($user)) {
        $_SESSION['password_expired'] = true;
    }

    log_activity(
        'auth.login',
        role_label($user['role']) . ' signed in',
        'user',
        (int)$user['user_id'],
        $user,
        'Authentication'
    );

    return $user;
}

/** The half-finished login waiting on a TOTP code, if there is one. */
function mfa_pending(): ?array
{
    $pending = $_SESSION['mfa_pending'] ?? null;
    if (!$pending) {
        return null;
    }

    // A challenge left open forever would be a second, weaker way in.
    if (time() - (int)$pending['started'] > 300) {
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_code_sent']);
        return null;
    }

    return $pending;
}

function logout_user(): void
{
    // Logged before the session goes, or there would be no actor left to name.
    if ($user = current_user()) {
        log_activity('auth.logout', role_label($user['role']) . ' signed out', 'user', (int)$user['user_id'], $user);
    }

    $_SESSION = [];
    session_destroy();
}
