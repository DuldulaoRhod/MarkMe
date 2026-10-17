<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../icons.php';

$pageTitle = $pageTitle ?? 'MarkMe';
// $fullWidth = true lets a page lay out its own full-bleed sections.
$fullWidth = $fullWidth ?? false;

// Prompt guests to log in once per session, the first time they land on the site.
if (!is_logged_in() && empty($_SESSION['welcomed'])) {
    $_SESSION['welcomed'] = true;
    if (empty($_SESSION['flash']['reopen_modal'])) {
        flash_set('reopen_modal', 'login');
    }
}

$flashSuccess = flash_get('success');
$flashError = flash_get('error');
?>
<!DOCTYPE html>
<html lang="en" data-base="<?= BASE_URL ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
/* When this session goes stale, come back and ask.
   
   The server decides expiry, but it only gets to decide when something asks
   it - so a tab left open sat there looking signed in until the next click.
   This reloads the page a couple of seconds after the session is due to
   expire; the server's own check then runs, destroys the session and
   redirects to the sign-in dialog with the reason attached.
   
   Plain HTML on purpose. The same thing in JavaScript lives at the end of a
   two-thousand-line file, where any unrelated error above it would stop it
   running, and a blocked or failed script would take the behaviour with it.
   A meta refresh has nothing to break.
   
   The page decides nothing: if the session was extended in another tab, the
   reload simply renders the page again with a fresh countdown. */
$sessionLeft = session_seconds_left();
if ($sessionLeft !== null):
?>
    <meta http-equiv="refresh" content="<?= $sessionLeft + 2 ?>">
<?php endif; ?>
    <meta name="theme-color" content="#fbf8f3">
    <meta name="description" content="MarkMe — handmade, personalized bookmarks. Choose a shape and design, add your photo and your words, preview it, and we'll craft it for you.">
    <title><?= e($pageTitle) ?> · MarkMe</title>
<?php require __DIR__ . '/../theme_boot.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php require __DIR__ . '/../styles.php'; ?>
</head>
<body<?php if (($left = session_seconds_left()) !== null): ?> data-session-left="<?= $left ?>"<?php endif; ?>>
<?php require __DIR__ . '/navbar.php'; ?>
<main id="main"<?= $fullWidth ? '' : ' class="container page-shell"' ?>>
    <?php if ($flashSuccess || $flashError): ?>
        <?php if ($fullWidth): ?><div class="container" style="padding-top:var(--s-5)"><?php endif; ?>
        <?php if ($flashSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="status">
                <?= icon('check-circle', 20) ?>
                <span class="flex-grow-1"><?= e($flashSuccess) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
                <?= icon('alert', 20) ?>
                <span class="flex-grow-1"><?= e($flashError) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
            </div>
        <?php endif; ?>
        <?php if ($fullWidth): ?></div><?php endif; ?>
    <?php endif; ?>
