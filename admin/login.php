<?php
/**
 * The separate back-office sign-in page is gone.
 *
 * Administrators and staff now sign in through the same storefront dialog as
 * customers. There was never a reason for two: both forms posted the same
 * credentials to the same attempt_login(), with the same CSRF check, the same
 * lockout and the same second factor behind them. Two copies of one form only
 * meant two places for a fix to be applied to one of.
 *
 * customer/login.php already sends a manager on to their panel after a
 * successful sign-in, so nothing is lost by arriving through the storefront.
 *
 * This file stays as a redirect rather than being deleted: bookmarks, muscle
 * memory and anything still linking here should land somewhere useful instead
 * of a 404.
 */
require_once __DIR__ . '/../includes/auth.php';

// Already signed in as a manager: straight to the panel, not back out to the
// shop to sign in again.
if (is_manager()) {
    redirect(panel_base() . '/dashboard.php');
}

/* Whatever brought them here - an expired session, a bookmark - the storefront
   needs to know to open the sign-in dialog. The message flashes set by
   bounce_expired_session() are left untouched so the reason survives the hop. */
flash_set('reopen_modal', 'login');

redirect('/customer/index.php');
