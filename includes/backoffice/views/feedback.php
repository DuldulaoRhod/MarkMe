<?php
/**
 * Screen: feedback - markup.
 *
 * Rendered inside whichever panel shell required it. Form handling lives in
 * includes/backoffice/screens/feedback.php, before any output.
 *
 * A worklist rather than an archive: new messages first by default, with the
 * message itself on screen so most can be dealt with without opening anything.
 */
?>
<?php
$statusTabs = [
    'new' => 'New',
    'read' => 'Read',
    'archived' => 'Archived',
    '' => 'All',
];
$activeTab = isset($_GET['status']) && isset($statusTabs[$_GET['status']]) ? $_GET['status'] : 'new';
$search = trim($_GET['q'] ?? '');

$from = ' FROM feedback_messages f LEFT JOIN users u ON u.user_id = f.handled_by WHERE 1 = 1';
$params = [];

if ($activeTab !== '') {
    $from .= ' AND f.status = :status';
    $params['status'] = $activeTab;
}
if ($search !== '') {
    $from .= ' AND (f.name LIKE :s_name OR f.email LIKE :s_mail OR f.message LIKE :s_msg)';
    $like = '%' . $search . '%';
    /* One placeholder per use: a named parameter can be bound only once with
       PDO::ATTR_EMULATE_PREPARES => false. */
    $params['s_name'] = $like;
    $params['s_mail'] = $like;
    $params['s_msg'] = $like;
}

$pager = paginate(
    'SELECT COUNT(*)' . $from,
    'SELECT f.*, u.full_name AS handled_by_name' . $from,
    $params,
    'ORDER BY f.created_at DESC, f.feedback_id DESC'
);
$messages = $pager['rows'];

// Counts for the tabs, over every message rather than the current page.
$tabCounts = ['' => 0];
foreach (db()->query('SELECT status, COUNT(*) AS n FROM feedback_messages GROUP BY status') as $row) {
    $tabCounts[$row['status']] = (int)$row['n'];
    $tabCounts[''] += (int)$row['n'];
}

$statusBadge = [
    'new' => 'warning',
    'read' => 'info',
    'archived' => 'secondary',
];

// Where an action should return to, so marking a message read keeps the tab,
// the search and the page the user was looking at.
$here = panel_base() . '/feedback.php'
    . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
?>

<div class="page-head">
    <div>
        <p class="eyebrow eyebrow-muted">People</p>
        <h2 style="margin-top:var(--s-2)">Feedback</h2>
        <p>What customers have sent through the contact form.</p>
    </div>
</div>

<div class="filter-pills" style="margin-top:0" role="group" aria-label="Filter messages by status">
    <?php foreach ($statusTabs as $key => $label): ?>
        <a class="pill <?= $activeTab === $key ? 'active' : '' ?>"
           href="<?= panel_url('feedback.php') ?>?status=<?= urlencode($key) ?>"
           <?= $activeTab === $key ? 'aria-current="true"' : '' ?>>
            <?= e($label) ?> <span class="count"><?= (int)($tabCounts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" class="admin-toolbar" role="search" style="margin-bottom:var(--s-5)">
    <input type="hidden" name="status" value="<?= e($activeTab) ?>">
    <div class="input-icon">
        <label class="visually-hidden" for="feedbackSearch">Search messages</label>
        <?= icon('search', 18) ?>
        <input type="search" class="form-control" id="feedbackSearch" name="q" value="<?= e($search) ?>"
               placeholder="Search by name, email or message">
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search !== ''): ?>
        <a href="<?= panel_url('feedback.php') ?>?status=<?= urlencode($activeTab) ?>"
           class="btn btn-quiet btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="data-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <caption class="visually-hidden">Customer messages</caption>
            <thead>
                <tr>
                    <th scope="col">From</th>
                    <th scope="col">Message</th>
                    <th scope="col">Received</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $m): ?>
                    <?php
                    $id = (int)$m['feedback_id'];
                    /* Trimmed here rather than in SQL, so a search still matches
                       words further into the message than the excerpt shows. */
                    $excerpt = trim(preg_replace('/\s+/', ' ', (string)$m['message']));
                    $long = mb_strlen($excerpt) > 150;
                    ?>
                    <tr>
                        <th scope="row">
                            <span style="display:block;font-weight:600"><?= e($m['name']) ?></span>
                            <a class="text-muted" style="font-size:.8rem;font-weight:400"
                               href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>
                        </th>
                        <td style="max-width:46ch">
                            <?= e($long ? mb_substr($excerpt, 0, 150) . '…' : $excerpt) ?>
                        </td>
                        <td class="text-muted" style="font-size:.85rem;white-space:nowrap">
                            <?= e(date('M j, Y', strtotime($m['created_at']))) ?>
                            <span class="d-block"><?= e(date('g:i A', strtotime($m['created_at']))) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $statusBadge[$m['status']] ?? 'secondary' ?>">
                                <?= e(ucfirst((string)$m['status'])) ?>
                            </span>
                            <?php if ($m['handled_by_name']): ?>
                                <span class="text-muted d-block" style="font-size:.75rem">
                                    by <?= e($m['handled_by_name']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="<?= panel_url('feedback_view.php') ?>?id=<?= $id ?>"
                                   class="btn btn-secondary btn-sm"
                                   aria-label="Read the message from <?= e($m['name']) ?>">
                                    <?= icon('eye', 14) ?> Read
                                </a>

                                <?php if (can('feedback.manage')): ?>
                                    <?php
                                    /* One button, whichever move is the useful one from
                                       here: new messages get read, read ones archived,
                                       archived ones reopened. */
                                    [$next, $nextLabel] = match ($m['status']) {
                                        'new' => ['read', 'Mark read'],
                                        'read' => ['archived', 'Archive'],
                                        default => ['new', 'Reopen'],
                                    };
                                    ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="feedback_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="<?= e($next) ?>">
                                        <input type="hidden" name="return_to" value="<?= e($here) ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <?= e($nextLabel) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (can('feedback.delete')): ?>
                                    <form method="post"
                                          data-confirm
                                          data-confirm-title="Delete this message?"
                                          data-confirm-body="The message from <?= e($m['name']) ?> will be removed."
                                          data-confirm-note="This action cannot be undone."
                                          data-confirm-action="Delete message"
                                          data-confirm-tone="danger">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="feedback_id" value="<?= $id ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                aria-label="Delete the message from <?= e($m['name']) ?>">
                                            <?= icon('trash', 14) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span class="empty-icon"><?= icon('mail', 24) ?></span>
                                <h3>Nothing here</h3>
                                <p>
                                    <?= $search !== ''
                                        ? 'No message matches that search.'
                                        : ($activeTab === 'new'
                                            ? 'No new messages. Anything sent through the contact form arrives here.'
                                            : 'No messages with this status.') ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $pagerLabel = 'message';
          require __DIR__ . '/../pager.php'; ?>
</div>
