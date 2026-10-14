<?php
/**
 * Screen: feedback_view - markup.
 *
 * The message as the customer wrote it, with the ways of answering or filing
 * it beside. Loading and the read-marking happen in
 * includes/backoffice/screens/feedback_view.php, before any output.
 */
$statusBadge = [
    'new' => 'warning',
    'read' => 'info',
    'archived' => 'secondary',
];
$id = (int)$message['feedback_id'];
$replySubject = rawurlencode('Re: your message to MarkMe');
?>

<nav class="breadcrumbs" aria-label="Breadcrumb">
    <a href="<?= panel_url('feedback.php') ?>">Feedback</a>
    <span class="sep" aria-hidden="true"><?= icon('chevron-right', 13) ?></span>
    <span aria-current="page"><?= e($message['name']) ?></span>
</nav>

<div class="page-head">
    <div>
        <p class="eyebrow eyebrow-muted">Message</p>
        <h2 style="margin-top:var(--s-2)"><?= e($message['name']) ?></h2>
        <p>
            Received <?= e(date('F j, Y \a\t g:i A', strtotime($message['created_at']))) ?>
        </p>
    </div>
    <span class="badge bg-<?= $statusBadge[$message['status']] ?? 'secondary' ?>"
          style="font-size:.85rem;padding:.5em 1em">
        <?= e(ucfirst((string)$message['status'])) ?>
    </span>
</div>

<div class="cart-grid">
    <section class="data-card">
        <div class="panel-head"><h3>What they wrote</h3></div>
        <div class="panel-body">
            <?php /* white-space:pre-wrap keeps their paragraphs; e() means the
                     message is text on this page and never markup. */ ?>
            <p style="white-space:pre-wrap;margin:0"><?= e($message['message']) ?></p>
        </div>
    </section>

    <aside class="summary-card" aria-label="About this message">
        <h3>Sender</h3>
        <div class="summary-body">
            <p style="margin-top:0">
                <strong><?= e($message['name']) ?></strong><br>
                <a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a>
            </p>

            <?php if ($message['handled_by_name']): ?>
                <p class="text-muted" style="font-size:.85rem">
                    Handled by <?= e($message['handled_by_name']) ?>
                    <?php if ($message['handled_at']): ?>
                        on <?= e(date('M j, Y', strtotime($message['handled_at']))) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <a class="btn btn-primary btn-block"
               href="mailto:<?= e($message['email']) ?>?subject=<?= $replySubject ?>">
                <?= icon('mail', 16) ?> Reply by email
            </a>

            <?php if (can('feedback.manage')): ?>
                <?php
                /* Both the useful moves, rather than one next step: from a
                   message you have just read, archiving and reopening are
                   equally reasonable. */
                $moves = $message['status'] === 'archived'
                    ? [['new', 'Reopen this message']]
                    : [['archived', 'Archive this message'], ['new', 'Mark as unread']];
                ?>
                <?php foreach ($moves as [$action, $label]): ?>
                    <form method="post" action="<?= panel_url('feedback.php') ?>" style="margin-top:var(--s-3)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feedback_id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="<?= e($action) ?>">
                        <input type="hidden" name="return_to"
                               value="<?= e(panel_base() . '/feedback_view.php?id=' . $id) ?>">
                        <button type="submit" class="btn btn-secondary btn-block"><?= e($label) ?></button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (can('feedback.delete')): ?>
                <form method="post" action="<?= panel_url('feedback.php') ?>" style="margin-top:var(--s-3)"
                      data-confirm
                      data-confirm-title="Delete this message?"
                      data-confirm-body="The message from <?= e($message['name']) ?> will be removed."
                      data-confirm-note="This action cannot be undone."
                      data-confirm-action="Delete message"
                      data-confirm-tone="danger">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feedback_id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-outline-danger btn-block">
                        <?= icon('trash', 15) ?> Delete message
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="summary-foot">
            <a href="<?= panel_url('feedback.php') ?>" class="btn btn-quiet btn-block">
                <?= icon('arrow-left', 15) ?> Back to all messages
            </a>
        </div>
    </aside>
</div>
