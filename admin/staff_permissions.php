<?php
/**
 * Staff permissions — administrators only.
 *
 * Lists every staff account and what each one may do, and opens an editor for
 * one account at a time. Nothing here decides access: it reads and writes the
 * grants that includes/permissions.php resolves and can() enforces, so what
 * this screen shows and what the server allows cannot drift apart.
 *
 * 'permissions.manage' is held by administrators only and is deliberately not
 * in the catalog, so it cannot be granted to a staff account from this page or
 * any other.
 */
require_once __DIR__ . '/../includes/auth.php';
require_can('permissions.manage');

$requireCapability = 'permissions.manage';
$pageTitle = 'Staff Permissions';
require __DIR__ . '/includes/admin_header.php';

$catalog = permission_catalog();

/* ---- the account being edited, if any ------------------------------- */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    // Staff only: an administrator's access is not editable here, which is
    // what stops this screen being used to demote the owner of the shop.
    $stmt = db()->prepare(
        "SELECT user_id, username, full_name, email, is_active
           FROM users WHERE user_id = :id AND role = 'staff'"
    );
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash_set('error', 'That staff account could not be found.');
        redirect('/admin/staff_permissions.php');
    }
}

/* ---- the roster ------------------------------------------------------ */
$search = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = "role = 'staff'";
if ($search !== '') {
    $where .= ' AND (username LIKE :q OR full_name LIKE :q OR email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$stmt = db()->prepare(
    "SELECT user_id, username, full_name, email, is_active, created_at
       FROM users WHERE {$where} ORDER BY full_name, username"
);
$stmt->execute($params);
$staff = $stmt->fetchAll();

/* Their grants, in one query rather than one per row. */
$granted = [];
$configured = [];
if ($staff) {
    $ids = array_column($staff, 'user_id');
    $slots = implode(',', array_fill(0, count($ids), '?'));

    $g = db()->prepare("SELECT user_id, module, action FROM staff_permissions WHERE user_id IN ({$slots})");
    $g->execute($ids);
    foreach ($g->fetchAll() as $row) {
        $granted[(int)$row['user_id']][] = $row['module'] . '.' . $row['action'];
    }

    $c = db()->prepare("SELECT user_id FROM staff_permission_profiles WHERE user_id IN ({$slots})");
    $c->execute($ids);
    foreach ($c->fetchAll() as $row) {
        $configured[(int)$row['user_id']] = true;
    }
}

/** What an account currently holds, for display and for pre-ticking the form. */
$currentFor = static function (int $userId) use ($granted, $configured): array {
    if (isset($configured[$userId])) {
        return $granted[$userId] ?? [];
    }
    // Not configured yet: it is still running on the role defaults, so that is
    // what the editor should open with.
    return array_values(array_filter(
        role_capabilities('staff'),
        static fn($cap) => $cap !== 'profile.self' && $cap !== 'dashboard.view'
    ));
};
?>

<div class="page-head">
    <div>
        <p class="eyebrow eyebrow-muted">People</p>
        <h2 style="margin-top:var(--s-2)">Staff permissions</h2>
        <p>What each staff member may do. Administrators always have full access and are not listed.</p>
    </div>
</div>

<?php if ($editing): ?>
    <?php
    $current = $currentFor((int)$editing['user_id']);
    $isConfigured = isset($configured[(int)$editing['user_id']]);
    ?>
    <section class="card card-flush">
        <div class="panel-head">
            <h2 class="d-flex align-items-center gap-2">
                <?= icon('lock', 18) ?>
                <?= e($editing['full_name'] ?: $editing['username']) ?>
            </h2>
            <span class="text-muted" style="font-size:.85rem"><?= e($editing['email']) ?></span>
        </div>

        <div class="panel-body">
            <?php if (!$isConfigured): ?>
                <p class="step-note">
                    <?= icon('info', 15) ?>
                    <span>This account has never been configured, so it is running on the
                        default staff permissions shown below. Saving takes charge of it.</span>
                </p>
            <?php endif; ?>

            <form method="post" action="<?= BASE_URL ?>/admin/staff_permissions_save.php">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$editing['user_id'] ?>">

                <div class="perm-grid">
                    <?php foreach ($catalog as $module => $meta): ?>
                        <fieldset class="perm-module">
                            <legend>
                                <?= e($meta['label']) ?>
                                <span class="perm-blurb"><?= e($meta['blurb']) ?></span>
                            </legend>
                            <?php foreach ($meta['actions'] as $action => $label): ?>
                                <?php
                                $cap = $module . '.' . $action;
                                $id = 'perm_' . $module . '_' . $action;
                                ?>
                                <label class="perm-check" for="<?= e($id) ?>">
                                    <input type="checkbox" id="<?= e($id) ?>" name="permissions[]"
                                           value="<?= e($cap) ?>"
                                        <?= in_array($cap, $current, true) ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <p class="form-text" style="margin-top:var(--s-5)">
                    <?= icon('info', 15) ?>
                    Their own profile and the dashboard are always available and are not listed.
                    Store settings, staff and administrator management, and the security screen
                    stay with administrators and cannot be granted here.
                </p>

                <div class="d-flex gap-2" style="margin-top:var(--s-5)">
                    <button type="submit" class="btn btn-primary">
                        <?= icon('save', 16) ?> Save changes
                    </button>
                    <a class="btn btn-quiet" href="<?= BASE_URL ?>/admin/staff_permissions.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php else: ?>
    <form method="get" class="audit-filters"
          style="border-bottom:0;padding-bottom:0;margin-bottom:var(--s-5)">
        <div class="af-field">
            <label class="form-label" for="spq">Search staff</label>
            <input type="search" id="spq" name="q" class="form-control form-control-sm"
                   value="<?= e($search) ?>" placeholder="Name, username or email">
        </div>
        <div class="af-actions">
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('search', 15) ?> Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-quiet btn-sm" href="<?= BASE_URL ?>/admin/staff_permissions.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="card card-flush">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Staff member</th>
                        <th scope="col">Email</th>
                        <th scope="col">Status</th>
                        <th scope="col">Permissions</th>
                        <th scope="col" class="text-end">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $member): ?>
                        <?php
                        $uid = (int)$member['user_id'];
                        $caps = $currentFor($uid);
                        $byModule = [];
                        foreach ($caps as $cap) {
                            [$m, $a] = array_pad(explode('.', $cap, 2), 2, '');
                            if (isset($catalog[$m]['actions'][$a])) {
                                $byModule[$m][] = $a;
                            }
                        }
                        ksort($byModule);
                        ?>
                        <tr>
                            <th scope="row" style="font-weight:600">
                                <?= e($member['full_name'] ?: $member['username']) ?><br>
                                <span class="text-muted" style="font-weight:400;font-size:.8rem">
                                    <?= e($member['username']) ?>
                                </span>
                            </th>
                            <td style="font-size:.85rem"><?= e($member['email']) ?></td>
                            <td>
                                <span class="badge badge-status <?= $member['is_active'] ? 's-approved' : 's-rejected' ?>">
                                    <?= $member['is_active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!$byModule): ?>
                                    <span class="text-muted" style="font-size:.84rem">No module access</span>
                                <?php else: ?>
                                    <div class="perm-tags">
                                        <?php foreach ($byModule as $m => $actions): ?>
                                            <span class="perm-tag">
                                                <strong><?= e($catalog[$m]['label']) ?></strong>
                                                <?= e(implode(', ', $actions)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!isset($configured[$uid])): ?>
                                    <span class="text-muted" style="font-size:.76rem">default for staff</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= BASE_URL ?>/admin/staff_permissions.php?edit=<?= $uid ?>">
                                    <?= icon('edit', 14) ?> Manage
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$staff): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <span class="empty-icon"><?= icon('shield', 24) ?></span>
                                    <h3><?= $search !== '' ? 'No staff match that search' : 'No staff accounts yet' ?></h3>
                                    <p><?= $search !== ''
                                        ? 'Try a different name or email.'
                                        : 'Staff accounts are created on the Staff screen.' ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/backoffice/shell_footer.php'; ?>
