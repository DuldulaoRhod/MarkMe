<?php
/**
 * Save one staff account's permissions — administrators only.
 *
 * The capability is required here in its own right, not inherited from the
 * page that drew the form: this endpoint is reachable directly, and a hidden
 * link has never stopped anybody.
 *
 * What is written is decided entirely here. The request supplies a list of
 * capability strings; everything about who may change what, and which strings
 * are even real, is settled against the catalog and the session. A staff
 * account cannot reach this file at all, so it cannot grant anything to itself
 * or to anyone else.
 */
require_once __DIR__ . '/../includes/auth.php';
require_can('permissions.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/staff_permissions.php');
}
csrf_check();

$userId = (int)($_POST['user_id'] ?? 0);
$actor = current_user();
$actorId = (int)$actor['user_id'];

/* The target must be a staff account. Administrators are not editable here,
   so this screen can never be used to reduce an administrator - including the
   one using it - and a customer id cannot be turned into a managed account. */
$stmt = db()->prepare(
    "SELECT user_id, username, full_name, role FROM users WHERE user_id = :id AND role = 'staff'"
);
$stmt->execute(['id' => $userId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('error', 'Permissions can only be set for staff accounts.');
    redirect('/admin/staff_permissions.php');
}

/* Belt and braces: an administrator editing themselves would mean the role
   check above had already failed, but saying so costs nothing. */
if ($userId === $actorId) {
    flash_set('error', 'You cannot change your own permissions.');
    redirect('/admin/staff_permissions.php');
}

/* Only strings from the catalog survive. staff_permissions_replace() filters
   again on the way to the database, so a posted value that is not a real
   module and action is dropped rather than stored. */
$posted = $_POST['permissions'] ?? [];
$posted = is_array($posted) ? $posted : [];

$wanted = [];
foreach ($posted as $cap) {
    $cap = (string)$cap;
    [$module, $action] = array_pad(explode('.', $cap, 2), 2, '');
    if ($module !== '' && $action !== '' && permission_is_known($module, $action)
        && !in_array($cap, permission_reserved(), true)) {
        $wanted[] = $cap;
    }
}
$wanted = array_values(array_unique($wanted));

try {
    $change = staff_permissions_replace($userId, $wanted, $actorId);
} catch (RuntimeException $e) {
    // The transaction rolled back, so nothing changed and nothing is logged:
    // an audit entry here would describe a change that did not happen.
    flash_set('error', $e->getMessage());
    redirect('/admin/staff_permissions.php?edit=' . $userId);
}

/* Logged only now, after the commit. Both directions are recorded, with the
   administrator who made the change, the account it applies to, and what the
   permission was before and after - log_activity() adds the time, the IP and
   the user agent the same way it does for every other event. */
$who = $target['full_name'] ?: $target['username'];

foreach ($change['added'] as $cap) {
    log_activity(
        'permission.granted',
        'Granted "' . $cap . '" to ' . $who . ' (was: not granted)',
        'user',
        $userId,
        null,
        'Security'
    );
}
foreach ($change['removed'] as $cap) {
    log_activity(
        'permission.revoked',
        'Revoked "' . $cap . '" from ' . $who . ' (was: granted)',
        'user',
        $userId,
        null,
        'Security'
    );
}
if (!$change['added'] && !$change['removed']) {
    log_activity(
        'permission.reviewed',
        'Reviewed permissions for ' . $who . ' — no change',
        'user',
        $userId,
        null,
        'Security'
    );
}

$summary = [];
if ($change['added']) {
    $summary[] = count($change['added']) . ' granted';
}
if ($change['removed']) {
    $summary[] = count($change['removed']) . ' revoked';
}

flash_set(
    'success',
    'Permissions updated for ' . $who . ($summary ? ' — ' . implode(', ', $summary) . '.' : ' — no change.')
);
redirect('/admin/staff_permissions.php');
