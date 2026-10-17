-- ---------------------------------------------------------------------
-- Two-factor authentication on by default.
--
-- Switches MFA on for every existing account that can sign in, and repairs
-- rows left in a half-written state by the older enrolment code, which set
-- mfa_enabled without ever setting mfa_method.
--
-- Nobody is locked out by this. Turning MFA on does not require a secret:
-- attempt_login() treats "enabled with nothing to challenge with" as
-- not-yet-enrolled and sends that account to auth/mfa_enrol.php at its next
-- sign-in, where it sets up an authenticator before the session opens. An
-- account that already has a confirmed secret is left exactly as it is and
-- keeps being challenged as before.
--
-- Additive and idempotent: re-running changes nothing further.
-- ---------------------------------------------------------------------
USE markme_db;

-- 1. Accounts already carrying a confirmed TOTP secret but no method recorded.
--    These were enrolled by the older code path. They work today only because
--    the challenge page treats an unknown method as TOTP; naming it makes the
--    row say what is actually true.
UPDATE users
   SET mfa_method = 'totp'
 WHERE mfa_enabled = 1
   AND mfa_secret IS NOT NULL
   AND mfa_secret <> ''
   AND mfa_method <> 'totp';

-- 2. Accounts marked enabled with nothing behind it - no secret and no usable
--    method. They were being waved straight through with no second factor at
--    all. Marked as pending TOTP enrolment, which the login flow now acts on.
UPDATE users
   SET mfa_method = 'totp'
 WHERE mfa_enabled = 1
   AND (mfa_secret IS NULL OR mfa_secret = '')
   AND mfa_method = 'none';

-- 3. Everyone else: switch it on, pending enrolment at next sign-in.
--    Only accounts that can actually sign in - a deactivated account gains
--    nothing from a second factor and would only have to enrol if it were
--    ever reactivated, which the same rule then covers.
UPDATE users
   SET mfa_enabled = 1,
       mfa_method = 'totp'
 WHERE mfa_enabled = 0
   AND is_active = 1;

-- What the result should look like: every active account enabled, those with
-- a secret already enrolled, the rest pending enrolment.
SELECT
    SUM(mfa_enabled = 1)                                               AS mfa_on,
    SUM(mfa_enabled = 1 AND mfa_secret IS NOT NULL AND mfa_secret<>'') AS already_enrolled,
    SUM(mfa_enabled = 1 AND (mfa_secret IS NULL OR mfa_secret = ''))   AS pending_enrolment,
    SUM(mfa_enabled = 0)                                               AS mfa_off
  FROM users;
