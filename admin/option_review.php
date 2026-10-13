<?php
/**
 * Review queue for uploaded shapes and designs — administrator only.
 *
 * Nothing uploaded through the catalog screens reaches a customer until it is
 * approved here. The queue shows what was uploaded, by whom, when, and whether
 * a malware scanner actually ran on it — stated plainly, because "scanned" and
 * "no scanner was configured" are different facts and the second one must not
 * read like the first.
 *
 * Approve and reject are POSTs with a CSRF token, and the capability is checked
 * on this page and again in option_review_action.php, so the buttons being
 * hidden is never what keeps anyone out.
 */
require_once __DIR__ . '/../includes/auth.php';
require_can('options.view');

$requireCapability = 'options.view';
$pageTitle = 'Review Uploads';
require __DIR__ . '/includes/admin_header.php';

$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}

/**
 * Both catalogs answer the same questions, so they are read with the same
 * query shape and merged into one queue ordered by age.
 */
/* The two catalogs are read as one set with UNION ALL rather than fetched
   separately and merged in PHP. The difference matters once there is paging:
   merging in PHP means reading every shape and every design to show ten of
   them, and "the newest ten overall" cannot be worked out from the newest ten
   of each. The database orders and limits the combined set instead.
   
   The table names are literals written here, not request values. The status is
   bound - twice, once per branch of the union, because a placeholder cannot be
   reused across both halves in every driver configuration. */
$whereShape = $filter === 'all' ? '' : ' WHERE o.status = :status_s';
$whereDesign = $filter === 'all' ? '' : ' WHERE o.status = :status_d';

$columns = "SELECT o.%s AS id, o.name, o.category, o.image_path, o.status, o.created_at,
                   o.reviewed_at, o.review_note, o.virus_scanned,
                   up.username AS uploader, rv.username AS reviewer,
                   '%s' AS kind, '%s' AS folder
              FROM %s o
              LEFT JOIN users up ON up.user_id = o.uploaded_by
              LEFT JOIN users rv ON rv.user_id = o.reviewed_by%s";

$unionSql = sprintf($columns, 'shape_id', 'shape', 'shapes', 'shapes', $whereShape)
    . ' UNION ALL '
    . sprintf($columns, 'design_id', 'design', 'designs', 'designs', $whereDesign);

$countSql = "SELECT (SELECT COUNT(*) FROM shapes o{$whereShape})
                  + (SELECT COUNT(*) FROM designs o{$whereDesign})";

$params = $filter === 'all' ? [] : ['status_s' => $filter, 'status_d' => $filter];

/* id is part of the order as well as created_at: the two catalogs number
   independently, so without it two rows created in the same second could swap
   between pages. */
$pager = paginate($countSql, $unionSql, $params, 'ORDER BY created_at DESC, kind ASC, id DESC');
$rows = $pager['rows'];

/** Counts for the filter pills, so the queue says how much is waiting. */
$tally = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach (['shapes', 'designs'] as $table) {
    $stmt = db()->query("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status");
    foreach ($stmt->fetchAll() as $r) {
        if (isset($tally[$r['status']])) {
            $tally[$r['status']] += (int)$r['n'];
        }
    }
}
$tally['all'] = array_sum($tally);

$pills = [
    'pending' => ['Pending review', $tally['pending']],
    'approved' => ['Approved', $tally['approved']],
    'rejected' => ['Rejected', $tally['rejected']],
    'all' => ['All', $tally['all']],
];
?>

<div class="page-head">
    <div>
        <p class="eyebrow eyebrow-muted">Catalog</p>
        <h2 style="margin-top:var(--s-2)">Review uploads</h2>
        <p>Shapes and designs stay out of the design studio until they are approved here.</p>
    </div>
</div>

<div class="filter-pills" style="margin-bottom:var(--s-5)" role="group" aria-label="Filter by review status">
    <?php foreach ($pills as $key => [$label, $count]): ?>
        <a class="pill <?= $filter === $key ? 'active' : '' ?>"
           href="<?= BASE_URL ?>/admin/option_review.php?status=<?= e($key) ?>"
           <?= $filter === $key ? 'aria-current="true"' : '' ?>>
            <?= e($label) ?> <span class="count"><?= (int)$count ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <div class="card empty-state">
        <span class="empty-icon"><?= icon('check-circle', 26) ?></span>
        <h3>Nothing <?= $filter === 'all' ? 'in the catalog' : e($filter) ?></h3>
        <p><?= $filter === 'pending'
            ? 'Every uploaded shape and design has been reviewed.'
            : 'No items with this status.' ?></p>
    </div>
<?php else: ?>
    <div class="review-grid" data-reveal-grid>
        <?php foreach ($rows as $row): ?>
            <article class="card card-flush review-card">
                <div class="review-preview">
                    <img src="<?= upload_url($row['folder'], $row['image_path']) ?>"
                         alt="<?= e($row['name']) ?>" loading="lazy" decoding="async">
                </div>
                <div class="panel-body">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <h3 class="review-name"><?= e($row['name']) ?></h3>
                        <span class="badge badge-status s-<?= e($row['status']) ?>">
                            <?= e(ucfirst($row['status'])) ?>
                        </span>
                    </div>

                    <dl class="review-facts">
                        <div><dt>Kind</dt><dd><?= e(ucfirst($row['kind'])) ?></dd></div>
                        <div><dt>Type</dt><dd><?= e($row['category'] ?: 'Uncategorized') ?></dd></div>
                        <div><dt>Uploaded by</dt><dd><?= e($row['uploader'] ?? 'Seeded with the project') ?></dd></div>
                        <div><dt>Uploaded</dt>
                            <dd><?= e(date('M j, Y g:i A', strtotime((string)$row['created_at']))) ?></dd></div>
                        <div><dt>Malware scan</dt>
                            <dd><?= $row['virus_scanned']
                                ? 'Scanned, no threat found'
                                : '<span class="text-muted">No scanner ran</span>' ?></dd></div>
                        <?php if ($row['reviewed_at']): ?>
                            <div><dt>Reviewed</dt>
                                <dd><?= e(date('M j, Y', strtotime((string)$row['reviewed_at']))) ?>
                                    by <?= e($row['reviewer'] ?? 'unknown') ?></dd></div>
                        <?php endif; ?>
                        <?php if ($row['review_note']): ?>
                            <div class="span-2"><dt>Reason</dt><dd><?= e($row['review_note']) ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <?php if ($row['status'] !== 'approved'): ?>
                        <form method="post" action="<?= BASE_URL ?>/admin/option_review_action.php"
                              class="review-actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= e($row['kind']) ?>">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" name="decision" value="approve" class="btn btn-primary btn-sm">
                                <?= icon('check', 15) ?> Approve
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($row['status'] !== 'rejected'): ?>
                        <form method="post" action="<?= BASE_URL ?>/admin/option_review_action.php"
                              class="review-actions"
                              data-confirm
                              data-confirm-title="Reject this <?= e($row['kind']) ?>?"
                              data-confirm-body="&quot;<?= e($row['name']) ?>&quot; will stay out of the design studio."
                              data-confirm-note="The file is kept, so you can approve it later."
                              data-confirm-action="Reject"
                              data-confirm-tone="danger">
                            <?= csrf_field() ?>
                            <input type="hidden" name="kind" value="<?= e($row['kind']) ?>">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <label class="visually-hidden" for="note<?= e($row['kind']) . (int)$row['id'] ?>">
                                Reason for rejecting <?= e($row['name']) ?>
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="note<?= e($row['kind']) . (int)$row['id'] ?>"
                                   name="note" maxlength="255" placeholder="Reason (optional)">
                            <button type="submit" name="decision" value="reject" class="btn btn-secondary btn-sm">
                                <?= icon('close', 15) ?> Reject
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php $pagerLabel = 'submission';
          require __DIR__ . '/../includes/backoffice/pager.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/backoffice/shell_footer.php'; ?>
