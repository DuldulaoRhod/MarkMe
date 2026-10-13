-- ---------------------------------------------------------------------
-- Per-staff-member permissions.
--
-- The project already had role-based access: role_capabilities() returns a
-- fixed allowlist per role and can() is the single gate every screen and
-- endpoint passes through. This adds a per-account layer on top of that,
-- reusing the same capability strings rather than inventing a parallel idea
-- of permission - a capability is "module.action", so the module and action
-- columns below compose exactly into the strings can() already understands.
--
-- Two tables rather than one, because "this staff member has no permissions"
-- and "this staff member has never been configured" are different facts and a
-- row count cannot tell them apart. staff_permission_profiles records that an
-- administrator has taken charge of an account; without a profile row the
-- account keeps the role defaults, so existing staff are unaffected by this
-- migration until someone deliberately changes them.
--
-- Additive and idempotent. No existing row is modified or removed.
-- ---------------------------------------------------------------------
USE markme_db;

-- Which accounts are explicitly managed. Presence of a row means "use the
-- grants in staff_permissions and nothing else"; absence means "fall back to
-- the role defaults".
CREATE TABLE IF NOT EXISTS staff_permission_profiles (
    user_id       INT UNSIGNED NOT NULL,
    configured_by INT UNSIGNED NULL,
    configured_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_spp_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_spp_admin FOREIGN KEY (configured_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- One row per granted capability. A revoked permission is an absent row, not
-- a row with a false flag: that way the table only ever states what is
-- allowed, and a question about access is a lookup rather than a comparison.
CREATE TABLE IF NOT EXISTS staff_permissions (
    permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    module        VARCHAR(40)  NOT NULL,
    action        VARCHAR(40)  NOT NULL,
    granted_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    -- The protection against granting the same thing twice.
    UNIQUE KEY uq_staff_permission (user_id, module, action),
    -- The lookup can() makes on every protected request.
    KEY idx_staff_permissions_user (user_id),
    CONSTRAINT fk_sp_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sp_admin FOREIGN KEY (granted_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
