<?php
/**
 * Screen: feedback - server-side half.
 *
 * The contact form has been filling feedback_messages since the storefront was
 * built; this is the screen that finally reads it. A message is a small
 * worklist item: it arrives 'new', someone reads it, and it is archived once
 * it has been dealt with.
 *
 * Auth and the POST handler both run before any shell emits HTML, so
 * redirect() still works.
 */
require_once __DIR__ . '/../../auth.php';
require_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['feedback_id'] ?? 0);

    /* Deleting is its own permission: reading a complaint and destroying it
       are different kinds of trust, so an administrator can grant one without
       the other. */
    require_can($action === 'delete' ? 'feedback.delete' : 'feedback.manage');

    $user = current_user();
    /* Back where they came from - the list or the message - but only ever to a
       local path. safe_local_path() falls back to the list rather than letting
       a posted value send anyone off-site. */
    $list = panel_base() . '/feedback.php';
    $back = safe_local_path($_POST['return_to'] ?? null, $list);

    if ($id < 1) {
        flash_set('error', 'No message was selected.');
        redirect($back);
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM feedback_messages WHERE feedback_id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount()) {
            log_activity('feedback.deleted', 'Deleted customer message #' . $id, 'feedback', $id, null, 'Feedback');
            flash_set('success', 'Message deleted.');
        } else {
            flash_set('error', 'That message no longer exists.');
        }
        // The detail page of a deleted message is gone, so always back to the list.
        redirect($list);
    }

    /* Only these three. Anything else posted is ignored rather than written,
       so the enum cannot be reached through the form. */
    $statuses = ['new', 'read', 'archived'];
    $status = in_array($action, $statuses, true) ? $action : '';

    if ($status === '') {
        flash_set('error', 'That is not something we can do to a message.');
        redirect($back);
    }

    /* handled_by and handled_at record who dealt with it. Marking something
       back to 'new' clears them: it has explicitly not been dealt with. */
    $stmt = db()->prepare(
        'UPDATE feedback_messages
            SET status = :s,
                handled_by = :by,
                handled_at = :at
          WHERE feedback_id = :id'
    );
    $stmt->execute([
        's' => $status,
        'by' => $status === 'new' ? null : (int)$user['user_id'],
        'at' => $status === 'new' ? null : date('Y-m-d H:i:s'),
        'id' => $id,
    ]);

    if ($stmt->rowCount()) {
        log_activity(
            'feedback.' . $status,
            'Marked customer message #' . $id . ' as ' . $status,
            'feedback',
            $id,
            null,
            'Feedback'
        );
        flash_set('success', [
            'new' => 'Message put back in the new pile.',
            'read' => 'Message marked as read.',
            'archived' => 'Message archived.',
        ][$status]);
    } else {
        flash_set('error', 'That message no longer exists.');
    }

    redirect($back);
}

$requireCapability = 'feedback.view';
$pageTitle = 'Feedback';
