-- ---------------------------------------------------------------------
-- Email one-time codes, as a second factor beside the authenticator app.
--
-- Separate from email_verification_tokens on purpose: an activation link is
-- a long single-use URL that lives for a day, and a sign-in code is six
-- digits that lives for five minutes and is allowed only a few guesses. The
-- two have different lifetimes, different attempt rules and different
-- consequences for being wrong, so they are different tables rather than one
-- table with a type column and two sets of half-applicable rules.
--
-- Only a hash of the code is stored, for the same reason the activation token
-- is hashed: a copy of this table must not let anyone sign in.
--
-- Additive and idempotent. Nothing existing is modified.
-- ---------------------------------------------------------------------
USE markme_db;

CREATE TABLE IF NOT EXISTS email_otps (
    otp_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  CHAR(64)     NOT NULL,
    -- Guesses used. The row is spent once this passes the ceiling, so a code
    -- cannot be brute forced by asking repeatedly.
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME     NOT NULL,
    used_at    TIMESTAMP    NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_otps_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    -- The lookup every verification makes: the newest live code for a user.
    KEY idx_email_otps_user (user_id, used_at, expires_at)
) ENGINE=InnoDB;

-- Which second factor an account uses. 'none' and 'totp' describe every
-- existing account, so no row changes meaning when this is added.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS mfa_method ENUM('none','totp','email')
        NOT NULL DEFAULT 'none' AFTER mfa_enabled;

-- Accounts already enrolled in the authenticator app keep working: they are
-- marked as using it rather than being reset to no second factor.
UPDATE users SET mfa_method = 'totp' WHERE mfa_enabled = 1 AND mfa_method = 'none';
