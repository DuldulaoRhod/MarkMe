<?php
/**
 * Screen: feedback_view - server-side half.
 *
 * One message, in full. The list truncates; this does not.
 *
 * Opening a new message marks it read, which is the one place that happens
 * without a button: having read it is exactly what opening it means. It needs
 * feedback.manage all the same, so someone granted read-only access leaves the
 * worklist as they found it.
 */
require_once __DIR__ . '/../../auth.php';
require_manager();
require_can('feedback.view');

$feedbackId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT f.*, u.full_name AS handled_by_name
       FROM feedback_messages f
       LEFT JOIN users u ON u.user_id = f.handled_by
      WHERE f.feedback_id = :id'
);
$stmt->execute(['id' => $feedbackId]);
$message = $stmt->fetch();

if (!$message) {
    flash_set('error', 'That message could not be found.');
    redirect(panel_base() . '/feedback.php');
}

if ($message['status'] === 'new' && can('feedback.manage')) {
    $user = current_user();
    db()->prepare(
        "UPDATE feedback_messages
            SET status = 'read', handled_by = :by, handled_at = NOW()
          WHERE feedback_id = :id AND status = 'new'"
    )->execute(['by' => (int)$user['user_id'], 'id' => $feedbackId]);

    // Reflect it on this render rather than making the reader reload.
    $message['status'] = 'read';
    $message['handled_by_name'] = $user['full_name'];
    $message['handled_at'] = date('Y-m-d H:i:s');
}

$requireCapability = 'feedback.view';
$pageTitle = 'Message from ' . $message['name'];
