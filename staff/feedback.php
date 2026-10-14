<?php
/**
 * Feedback - staff panel.
 *
 * The screen itself lives in includes/backoffice/screens (logic) and
 * includes/backoffice/views (markup), shared with the other panel. This file is
 * only the wrapper that decides which shell the screen renders inside.
 */
require __DIR__ . '/../includes/backoffice/screens/feedback.php';
require __DIR__ . '/includes/staff_header.php';
require __DIR__ . '/../includes/backoffice/views/feedback.php';
require __DIR__ . '/includes/staff_footer.php';
