<?php
/**
 * First-run two-factor enrolment.
 *
 * Reached from the login flow when an account has MFA switched on but nothing
 * to be challenged with yet - a new registration, or an existing account the
 * migration turned it on for. Without this page those accounts would either be
 * waved through without a second factor or refused for not having set up
 * something they were never asked to set up.
 *
 * It runs on the same half-open session as the code challenge: the password
 * has been accepted and $_SESSION['mfa_pending'] is set, but $_SESSION['user']
 * is not, so require_login() everywhere else still treats this visitor as a
 * guest. Nothing but this page is reachable until the factor is confirmed.
 *
 * Standalone chrome for the same reason auth/mfa.php has it: the storefront
 * header renders an account menu from a session that does not exist yet.
 *
 * The candidate secret lives in the session until a code proves the app really
 * has it. Enabling first and verifying later is how people lock themselves out.
 */
require_once __DIR__ . '/../includes/auth.php';

$pending = mfa_pending();

if (!$pending) {
    flash_set('login_error', 'Your sign-in attempt expired. Please log in again.');
    flash_set('reopen_modal', 'login');
    redirect('/customer/index.php');
}

$userId = (int)$pending['user_id'];
$returnTo = safe_local_path($_GET['return'] ?? $_POST['return_to'] ?? null);
$error = null;

$stmt = db()->prepare('SELECT user_id, username, email, full_name, role FROM users WHERE user_id = :id');
$stmt->execute(['id' => $userId]);
$account = $stmt->fetch();

if (!$account) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_enrol_candidate']);
    flash_set('login_error', 'Please log in again.');
    redirect('/customer/index.php');
}

/* One candidate per enrolment, held for this half-session only. Regenerating
   it on every page load would mean the QR someone just scanned stops matching
   the code they are typing. */
if (empty($_SESSION['mfa_enrol_candidate'])) {
    $_SESSION['mfa_enrol_candidate'] = totp_secret();
}
$candidate = (string)$_SESSION['mfa_enrol_candidate'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

    if (!totp_verify($candidate, $code)) {
        /* Nothing is written. An unverified secret stored against the account
           would mean the next sign-in asks for codes nobody can produce. */
        $error = 'That code did not match. Check the app and try again.';

        log_activity(
            'auth.mfa_failed',
            'Two-factor enrolment code rejected',
            'user',
            $userId,
            $account,
            'Authentication'
        );
    } else {
        /* mfa_method is written here as well as the secret. Leaving it at
           'none' while MFA is on is the sort of half-written state that works
           by accident until something reads the method and believes it. */
        db()->prepare(
            "UPDATE users
                SET mfa_secret = :secret,
                    mfa_enabled = 1,
                    mfa_method = 'totp',
                    mfa_confirmed_at = NOW()
              WHERE user_id = :id"
        )->execute(['secret' => $candidate, 'id' => $userId]);

        unset($_SESSION['mfa_enrol_candidate']);

        log_activity(
            'account.mfa_enabled',
            'Completed two-factor enrolment at sign-in',
            'user',
            $userId,
            $account,
            'Security'
        );

        // The factor exists now, so the half-session becomes a real one.
        $full = db()->prepare('SELECT * FROM users WHERE user_id = :id');
        $full->execute(['id' => $userId]);
        $user = $full->fetch();

        establish_session($user);

        flash_set('success', 'Two-factor authentication is set up. You will be asked for a code each time you sign in.');
        redirect(is_manager() ? panel_base() . '/dashboard.php' : $returnTo);
    }
}

$uri = totp_uri($candidate, (string)$account['email']);
?>
<!DOCTYPE html>
<html lang="en" data-base="<?= BASE_URL ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up two-factor authentication · MarkMe</title>
<?php require __DIR__ . '/../includes/theme_boot.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php require __DIR__ . '/../includes/styles.php'; ?>
</head>
<body class="admin-login-body">
<main class="admin-login-card" style="max-width:560px">
    <span class="brand-mark">MarkMe</span>

    <h1 style="font-size:1.35rem;margin-bottom:var(--s-2)">Set up two-factor authentication</h1>
    <p class="text-muted" style="font-size:.92rem;margin-bottom:var(--s-5)">
        Signing in as <strong><?= e($account['username']) ?></strong>. Your password was
        accepted. Before you continue, MarkMe needs a second way to confirm it is you
        &mdash; a 6-digit code from an authenticator app on your phone.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
            <?= icon('alert', 18) ?><span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <ol class="text-muted" style="font-size:.88rem;padding-left:1.1rem;line-height:1.8;text-align:left">
        <li>Install Google Authenticator, Microsoft Authenticator, or any TOTP app.</li>
        <li>Scan the code below, or type the key in by hand.</li>
        <li>Enter the 6-digit code the app shows.</li>
    </ol>

    <div class="mfa-enrol">
        <div class="mfa-qr">
            <?php /* Drawn in the browser from the otpauth:// URI. That URI carries
                     the shared secret, so it is never handed to an external QR
                     service - the only places it exists are this page, the session
                     and the phone. */ ?>
            <div id="mfaQr" data-otpauth="<?= e($uri) ?>"
                 aria-label="QR code for your authenticator app"></div>
            <noscript>
                <p class="text-muted" style="font-size:.85rem">Enter the key below into your app by hand.</p>
            </noscript>
        </div>

        <div class="mfa-enrol-main">
            <p class="eyebrow eyebrow-muted">Or enter this key by hand</p>
            <p class="mono-num mfa-secret"><?= e(totp_secret_groups($candidate)) ?></p>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                <div class="field-group" style="max-width:220px">
                    <label class="form-label" for="mfaCode">Code from the app</label>
                    <input type="text" id="mfaCode" name="code" class="form-control mono-num"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                           autocomplete="one-time-code" autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Finish setup</button>
            </form>
        </div>
    </div>

    <p class="text-muted" style="font-size:.82rem;margin-top:var(--s-5)">
        You can turn this off later from Settings, after confirming your password.
    </p>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js?v=<?= filemtime(__DIR__ . '/../assets/js/script.js') ?>"></script>
</body>
</html>
