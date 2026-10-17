-- ---------------------------------------------------------------------
-- Two-factor authentication back to opt-in.
--
-- Reverses migration_mfa_default.sql. That migration switched MFA on for
-- every active account, which meant each one had to enrol an authenticator
-- at its next sign-in before it could get in - including the administrator.
-- Enrolment is a thing people choose, not a toll gate on the way to work.
--
-- Only accounts that never completed enrolment are switched off. An account
-- that actually set up an authenticator keeps it: it has a confirmed secret,
-- it works, and turning it off would silently weaken an account whose owner
-- deliberately protected it. Those can still be turned off by their owner
-- from Settings, which asks for the password first.
--
-- Idempotent: re-running changes nothing further.
-- ---------------------------------------------------------------------
USE markme_db;

UPDATE users
   SET mfa_enabled = 0,
       mfa_method = 'none',
       mfa_secret = NULL,
       mfa_confirmed_at = NULL
 WHERE mfa_enabled = 1
   AND (mfa_secret IS NULL OR mfa_secret = '' OR mfa_confirmed_at IS NULL);

SELECT
    SUM(mfa_enabled = 1) AS mfa_on_and_enrolled,
    SUM(mfa_enabled = 0) AS mfa_off
  FROM users;
