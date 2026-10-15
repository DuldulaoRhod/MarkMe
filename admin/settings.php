<?php
/**
 * Admin settings — store-wide configuration, separate from Admin Profile.
 *
 * Everything on this page is persisted in the `settings` table and read back by
 * the storefront, so nothing here is a control that does nothing. The theme
 * choice is the exception, and it is stored per-device in localStorage.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$errors = [];

// Only these keys may be written from this form.
$editable = [
    'store_name' => 'Store name',
    'store_tagline' => 'Tagline',
    'store_email' => 'Contact email',
    'store_phone' => 'Contact phone',
    'store_address' => 'Store address',
    'low_stock_default' => 'Default low-stock threshold',
];

/* Asks PayMongo whether it accepts the key that is configured. Nothing is
   created and no money can move - see paymongo_ping(). The result is a flash
   message rather than stored state, because it is only true of this moment. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_paymongo') {
    csrf_check();
    $ping = paymongo_ping();
    flash_set($ping['ok'] ? 'success' : 'error', $ping['message']);
    redirect('/admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    csrf_check();

    $values = [];
    foreach ($editable as $key => $label) {
        $values[$key] = trim($_POST[$key] ?? '');
    }

    if ($values['store_name'] === '') {
        $errors[] = 'Store name is required.';
    }
    if ($values['store_email'] !== '' && !filter_var($values['store_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email must be a valid email address.';
    }
    if ($values['low_stock_default'] !== '' && !ctype_digit($values['low_stock_default'])) {
        $errors[] = 'Default low-stock threshold must be a whole number.';
    }

    if (!$errors) {
        foreach ($values as $key => $value) {
            setting_put($key, $value);
        }
        flash_set('success', 'Store settings saved.');
        redirect('/admin/settings.php');
    }
}

// Applying the default threshold to every product is an explicit, separate
// action — saving the settings form alone must not rewrite catalog data.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_threshold') {
    csrf_check();
    $default = (int)setting('low_stock_default', '5');
    $stmt = db()->prepare('UPDATE products SET low_stock_threshold = :t');
    $stmt->execute(['t' => max(0, $default)]);

    flash_set('success', 'Applied a threshold of ' . max(0, $default) . ' to all products.');
    redirect('/admin/settings.php');
}

$allowRegistration = setting('allow_registration', '1') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_registration') {
    csrf_check();
    setting_put('allow_registration', $allowRegistration ? '0' : '1');
    flash_set('success', $allowRegistration
        ? 'New customer registration is now closed.'
        : 'New customer registration is now open.');
    redirect('/admin/settings.php');
}

$requireCapability = 'settings.manage';
$pageTitle = 'Settings';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow eyebrow-muted">System</p>
        <h2 style="margin-top:var(--s-2)">Settings</h2>
        <p>Store-wide configuration. Your own account details are in
            <a href="<?= BASE_URL ?>/admin/profile.php">Profile</a>.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert"
         style="margin-bottom:var(--s-5)">
        <?= icon('alert', 20) ?>
        <ul style="margin:0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="d-flex flex-column" style="gap:var(--s-6)">
    <!-- ------------------------ store information ------------------------ -->
    <section class="card card-flush">
        <div class="panel-head">
            <h2 class="d-flex align-items-center gap-2"><?= icon('store', 18) ?> Store information</h2>
        </div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <div class="form-grid">
                    <div>
                        <label class="form-label" for="store_name">Store name</label>
                        <input type="text" id="store_name" name="store_name" class="form-control"
                               value="<?= e(setting('store_name', 'MarkMe')) ?>" maxlength="100" required>
                    </div>
                    <div>
                        <label class="form-label" for="store_tagline">Tagline</label>
                        <input type="text" id="store_tagline" name="store_tagline" class="form-control"
                               value="<?= e(setting('store_tagline')) ?>" maxlength="150">
                    </div>
                    <div>
                        <label class="form-label" for="store_email">Contact email</label>
                        <input type="email" id="store_email" name="store_email" class="form-control"
                               value="<?= e(setting('store_email')) ?>" maxlength="150">
                    </div>
                    <div>
                        <label class="form-label" for="store_phone">Contact phone</label>
                        <input type="tel" id="store_phone" name="store_phone" class="form-control"
                               value="<?= e(setting('store_phone')) ?>" maxlength="30">
                    </div>
                    <div class="span-2">
                        <label class="form-label" for="store_address">Store address</label>
                        <input type="text" id="store_address" name="store_address" class="form-control"
                               value="<?= e(setting('store_address')) ?>" maxlength="255">
                    </div>
                    <div>
                        <label class="form-label" for="low_stock_default">Default low-stock threshold</label>
                        <input type="number" id="low_stock_default" name="low_stock_default"
                               class="form-control" value="<?= e(setting('low_stock_default', '5')) ?>"
                               min="0" max="10000" step="1">
                        <p class="form-text">Used for new products. Existing products keep their own
                            threshold, editable in <a href="<?= BASE_URL ?>/admin/inventory.php">Inventory</a>.</p>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:var(--s-5)">
                    <?= icon('check', 16) ?> Save settings
                </button>
            </form>
        </div>
    </section>

    <!-- --------------------------- appearance ---------------------------- -->
    <?php require __DIR__ . '/../includes/backoffice/appearance_panel.php'; ?>

    <!-- --------------------------- payments ------------------------------ -->
    <?php
    /* Status only. The keys themselves live in config/config.php, which is
       gitignored and never read into this page - a secret key rendered into
       HTML is a secret key in the browser's cache, in a screenshot, and in
       whatever the page is printed to. */
    $pm = paymongo_status();
    ?>
    <section class="card card-flush">
        <div class="panel-head">
            <h2 class="d-flex align-items-center gap-2"><?= icon('wallet', 18) ?> Online card payments</h2>
            <span class="badge bg-<?= !$pm['configured'] ? 'secondary' : ($pm['mode'] === 'test' ? 'warning' : 'success') ?>">
                <?= !$pm['configured'] ? 'Not configured' : ($pm['mode'] === 'test' ? 'Test mode' : 'LIVE mode') ?>
            </span>
        </div>
        <div class="panel-body">
            <?php if (!$pm['configured']): ?>
                <p style="margin-top:0">
                    PayMongo is built in but dormant: with no keys the card option does not
                    appear at checkout, and customers see Cash on Delivery and Bank Transfer
                    only. Nothing is broken &mdash; it is waiting for credentials.
                </p>
                <p class="form-text">
                    Keys are issued to your own PayMongo account and cannot be supplied from
                    here. To switch it on:
                </p>
                <ol class="form-text" style="padding-left:1.2rem;line-height:1.7">
                    <li>Create a free account at
                        <a href="https://dashboard.paymongo.com" target="_blank" rel="noopener">dashboard.paymongo.com</a>
                        &mdash; test keys need no business verification.</li>
                    <li>Open <strong>Developers &rarr; API keys</strong> and copy the
                        <strong>test</strong> pair (<code>pk_test_&hellip;</code> and <code>sk_test_&hellip;</code>).</li>
                    <li>Paste them into <code>config/config.php</code> as
                        <code>PAYMONGO_PUBLIC_KEY</code> and <code>PAYMONGO_SECRET_KEY</code>.</li>
                    <li>Reload this page and press <strong>Test connection</strong>.</li>
                </ol>
            <?php else: ?>
                <p style="margin-top:0">
                    <?php if ($pm['mode'] === 'test'): ?>
                        Running against PayMongo's test environment. Cards are simulated and no
                        real money moves.
                    <?php else: ?>
                        <strong>Live keys are configured. Real cards will be charged.</strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <dl class="payment-facts" style="margin-bottom:var(--s-4)">
                <dt>Secret key</dt>
                <dd><?= $pm['secret_set'] ? e($pm['prefix']) . '&hellip; set' : 'not set' ?></dd>
                <dt>Public key</dt>
                <dd><?= $pm['public_set'] ? 'set' : 'not set' ?></dd>
                <dt>Webhook secret</dt>
                <dd><?= $pm['webhook_set'] ? 'set' : 'not set &mdash; webhooks will be refused' ?></dd>
                <dt>Webhook URL</dt>
                <dd><?= e($pm['webhook_url']) ?></dd>
            </dl>

            <?php if ($pm['configured'] && !$pm['webhook_set']): ?>
                <p class="form-text" style="margin-bottom:var(--s-4)">
                    Without a webhook secret every delivery is refused, so an order will only
                    settle if the customer returns to the site. Add the endpoint above under
                    <strong>Developers &rarr; Webhooks</strong>, subscribe it to
                    <code>checkout_session.payment.paid</code>, and put the signing secret in
                    <code>PAYMONGO_WEBHOOK_SECRET</code>.
                </p>
            <?php endif; ?>

            <?php if (str_contains($pm['webhook_url'], 'localhost') || str_contains($pm['webhook_url'], '127.0.0.1')): ?>
                <p class="form-text" style="margin-bottom:var(--s-4)">
                    <?= icon('alert', 14) ?>
                    PayMongo cannot reach <code>localhost</code>. For webhooks to arrive while
                    developing, expose this machine with a tunnel (ngrok or similar) and
                    register that address instead.
                </p>
            <?php endif; ?>

            <div class="setting-row">
                <div>
                    <p class="setting-title">Test connection</p>
                    <p class="form-text" style="margin:0">
                        Asks PayMongo whether it accepts the configured key. Nothing is
                        created and no payment can be taken.
                    </p>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_paymongo">
                    <button type="submit" class="btn btn-secondary btn-sm"
                            <?= $pm['configured'] ? '' : 'disabled' ?>>
                        <?= icon('refresh', 15) ?> Test connection
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- ------------------------ system preferences ----------------------- -->
    <section class="card card-flush">
        <div class="panel-head">
            <h2 class="d-flex align-items-center gap-2"><?= icon('settings', 18) ?> System preferences</h2>
        </div>
        <div class="panel-body">
            <div class="setting-row">
                <div>
                    <p class="setting-title">Customer registration</p>
                    <p class="form-text" style="margin:0">
                        <?= $allowRegistration
                            ? 'Anyone can create a customer account from the storefront.'
                            : 'The register form is closed. Existing customers can still sign in.' ?>
                    </p>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_registration">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <?= $allowRegistration ? 'Close registration' : 'Open registration' ?>
                    </button>
                </form>
            </div>

            <div class="setting-row">
                <div>
                    <p class="setting-title">Apply default threshold to all products</p>
                    <p class="form-text" style="margin:0">Overwrites every product's low-stock threshold with
                        the default above. This cannot be undone.</p>
                </div>
                <form method="post"
                      data-confirm
                  data-confirm-title="Apply to every product?"
                  data-confirm-body="This overwrites the low-stock threshold on all products."
                  data-confirm-action="Apply to all">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_threshold">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <?= icon('refresh', 15) ?> Apply to all
                    </button>
                </form>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/backoffice/shell_footer.php'; ?>
