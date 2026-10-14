<?php
/**
 * Per-staff-member permissions.
 *
 * The project already decided what a permission is: a capability string of the
 * form "module.action", checked in exactly one place by can(). This file does
 * not introduce a second idea of access control beside that one - it supplies
 * the set of capabilities a particular staff account holds, which can() then
 * answers from instead of the role defaults.
 *
 * The rules, in order:
 *
 *   administrator  -> everything, always. An administrator's access is not
 *                     editable here, so no permission screen can lock the
 *                     shop's owner out of it.
 *   staff, managed -> exactly the capabilities granted to that account.
 *   staff, not yet -> the role defaults, unchanged. Existing staff keep
 *                     working until an administrator deliberately configures
 *                     them.
 *   anyone else    -> the role defaults.
 *
 * Nothing here reads a role, a user id or a permission from the request. The
 * identity comes from the session and the grants come from the database.
 */
require_once __DIR__ . '/functions.php';

/**
 * Every module and action an administrator can grant, and the capability each
 * one maps to.
 *
 * This list is the catalog the management screen renders and the allowlist the
 * save endpoint validates against, so a module or action that is not named
 * here cannot be stored however the form is tampered with.
 *
 * Only actions the server actually enforces appear. An action listed here
 * without a check behind it would be a promise the code does not keep, which
 * is worse than not offering it.
 */
function permission_catalog(): array
{
    return [
        'products' => [
            'label' => 'Products',
            'blurb' => 'The product catalog.',
            'actions' => [
                'view' => 'View products',
                'create' => 'Add new products',
                'edit' => 'Edit existing products',
                'delete' => 'Delete products',
                'restock' => 'Restock from the inventory screen',
            ],
        ],
        'categories' => [
            'label' => 'Categories',
            'blurb' => 'How the catalog is grouped.',
            'actions' => [
                'view' => 'View categories',
                'create' => 'Add new categories',
                'edit' => 'Edit categories',
                'delete' => 'Delete categories',
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'blurb' => 'Stock levels and low-stock warnings.',
            'actions' => [
                'view' => 'View stock levels',
                'adjust' => 'Adjust stock',
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'blurb' => 'Customer accounts. Never their passwords.',
            'actions' => [
                'view' => 'View customers',
                'manage' => 'Activate or deactivate accounts',
            ],
        ],
        'orders' => [
            'label' => 'Orders',
            'blurb' => 'Orders placed by customers.',
            'actions' => [
                'view' => 'View orders',
                'status' => 'Update order status',
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'blurb' => 'Sales and stock figures.',
            'actions' => [
                'view' => 'View and generate reports',
            ],
        ],
        'audit' => [
            'label' => 'Audit Trail',
            'blurb' => 'Who did what, and when. Read only for everyone.',
            'actions' => [
                'view' => 'View audit records',
            ],
        ],
        'options' => [
            'label' => 'Review Uploads',
            'blurb' => 'Shapes and designs waiting for review.',
            'actions' => [
                'view' => 'View the review queue',
                'approve' => 'Approve submissions',
                'reject' => 'Reject submissions',
            ],
        ],
        'feedback' => [
            'label' => 'Feedback',
            'blurb' => 'Messages customers send through the contact form.',
            'actions' => [
                'view' => 'Read customer messages',
                'manage' => 'Mark messages read or archived',
                'delete' => 'Delete messages',
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'blurb' => 'Store configuration stays with administrators; '
                . 'only personal preferences can be granted.',
            'actions' => [
                'appearance' => 'Change their own theme',
            ],
        ],
    ];
}

/** The capability string for a module and action, e.g. "products.edit". */
function permission_capability(string $module, string $action): string
{
    return $module . '.' . $action;
}

/** Is this module/action pair one we actually offer? */
function permission_is_known(string $module, string $action): bool
{
    $catalog = permission_catalog();

    return isset($catalog[$module]['actions'][$action]);
}

/**
 * Capabilities that are never grantable through this screen.
 *
 * Administrator-only ground: managing staff, managing other administrators,
 * the security screen, store configuration, and permission management itself.
 * Keeping them out of the catalog is what stops a staff account being handed
 * the ability to edit its own access.
 */
function permission_reserved(): array
{
    return [
        'staff.manage',
        'admins.manage',
        'security.manage',
        'settings.manage',
        'permissions.manage',
        'products.manage',
        'categories.manage',
        'inventory.manage',
        'options.manage',
        'customers.manage_admin',
    ];
}

/**
 * Has an administrator taken charge of this account's permissions?
 *
 * Without a profile row the account is still on the role defaults, which is
 * what keeps this migration from changing anything for staff who existed
 * before it ran.
 */
function staff_permissions_configured(int $userId): bool
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM staff_permission_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return $cache[$userId] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // The table is missing (migration not run): behave as before rather
        // than locking the back office.
        error_log('staff_permissions_configured: ' . $e->getMessage());
        return $cache[$userId] = false;
    }
}

/**
 * The capabilities granted to one staff account, as capability strings.
 *
 * Read once per request: can() is called many times while a page renders and
 * every one of those would otherwise be a query.
 */
function staff_granted_capabilities(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    try {
        $stmt = db()->prepare(
            'SELECT module, action FROM staff_permissions WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $caps = [];
        foreach ($stmt->fetchAll() as $row) {
            $caps[] = permission_capability((string)$row['module'], (string)$row['action']);
        }
    } catch (Throwable $e) {
        error_log('staff_granted_capabilities: ' . $e->getMessage());
        $caps = [];
    }

    /* Always theirs, never granted or revoked: their own profile, and the
       dashboard they land on after signing in. A staff account that could not
       reach either would be an account that cannot be used at all. */
    $caps[] = 'profile.self';
    $caps[] = 'dashboard.view';

    return $cache[$userId] = array_values(array_unique($caps));
}

/**
 * The effective capability list for a user, whatever their role.
 *
 * This is what can() asks. Keeping the decision here rather than in can()
 * means there is still exactly one place that answers "what may this account
 * do", which is the property that made the sidebar and the endpoints agree in
 * the first place.
 */
function effective_capabilities(array $user): array
{
    $role = (string)($user['role'] ?? '');

    if ($role === 'admin') {
        return ['*'];
    }

    if ($role === 'staff' && !empty($user['user_id'])
        && staff_permissions_configured((int)$user['user_id'])) {
        return staff_granted_capabilities((int)$user['user_id']);
    }

    return role_capabilities($role);
}

/**
 * Does a specific user hold a capability?
 *
 * The centralised check named in the brief. can() is the usual way in because
 * it answers for the signed-in session; this exists for the cases that ask
 * about somebody else, such as previewing what a staff member would see.
 */
function user_has_permission(int $userId, string $module, string $action): bool
{
    $stmt = db()->prepare('SELECT user_id, role FROM users WHERE user_id = :id AND is_active = 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $caps = effective_capabilities($user);
    $wanted = permission_capability($module, $action);

    return in_array('*', $caps, true) || in_array($wanted, $caps, true);
}

/**
 * Replace one staff account's permissions, and say what changed.
 *
 * The whole set is written in a transaction: a half-applied permission change
 * is a security state nobody chose. Returns the grants added and removed so
 * the caller can record them, and records nothing itself - the audit entry is
 * written after the commit, by the endpoint, so a rolled-back change can never
 * leave a log saying it succeeded.
 *
 * @param string[] $capabilities  "module.action" strings, already validated
 * @return array{added: string[], removed: string[]}
 */
function staff_permissions_replace(int $userId, array $capabilities, int $actorId): array
{
    $pdo = db();

    // Only pairs from the catalog are ever written, whatever was posted.
    $wanted = [];
    foreach ($capabilities as $cap) {
        [$module, $action] = array_pad(explode('.', (string)$cap, 2), 2, '');
        if ($module !== '' && $action !== '' && permission_is_known($module, $action)
            && !in_array($cap, permission_reserved(), true)) {
            $wanted[permission_capability($module, $action)] = [$module, $action];
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT module, action FROM staff_permissions WHERE user_id = :id FOR UPDATE');
        $stmt->execute(['id' => $userId]);
        $existing = [];
        foreach ($stmt->fetchAll() as $row) {
            $existing[permission_capability((string)$row['module'], (string)$row['action'])] = true;
        }

        $added = array_values(array_diff(array_keys($wanted), array_keys($existing)));
        $removed = array_values(array_diff(array_keys($existing), array_keys($wanted)));

        foreach ($removed as $cap) {
            [$module, $action] = explode('.', $cap, 2);
            $pdo->prepare(
                'DELETE FROM staff_permissions
                  WHERE user_id = :id AND module = :m AND action = :a'
            )->execute(['id' => $userId, 'm' => $module, 'a' => $action]);
        }

        foreach ($added as $cap) {
            [$module, $action] = $wanted[$cap];
            // INSERT IGNORE against the unique key, so a double submit cannot
            // produce a duplicate grant.
            $pdo->prepare(
                'INSERT IGNORE INTO staff_permissions (user_id, module, action, granted_by)
                 VALUES (:id, :m, :a, :by)'
            )->execute(['id' => $userId, 'm' => $module, 'a' => $action, 'by' => $actorId]);
        }

        // Mark the account as explicitly managed from now on.
        $pdo->prepare(
            'INSERT INTO staff_permission_profiles (user_id, configured_by)
             VALUES (:id, :by)
             ON DUPLICATE KEY UPDATE configured_by = VALUES(configured_by),
                                     configured_at = CURRENT_TIMESTAMP'
        )->execute(['id' => $userId, 'by' => $actorId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('staff_permissions_replace: ' . $e->getMessage());
        throw new RuntimeException('Could not save those permissions.');
    }

    return ['added' => $added, 'removed' => $removed];
}
