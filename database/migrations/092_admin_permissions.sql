-- 092 The super admin assigns admin-panel access per role and per account.
--
-- Auth::PERMISSIONS is a PHP constant, so "what may a moderator do" could only
-- change by editing code and redeploying. The sidebar already hides items with
-- Auth::can() and every /admin route carries a permission, so that one map was
-- the single thing standing between a role and the parts of the console it may
-- reach.
--
-- Both tables hold the DIFFERENCE from the built-in default, not a full copy:
--
--   granted = 1  the permission is added beyond the default
--   granted = 0  the permission is taken away from the default
--   (no row)     the default applies untouched
--
-- Storing a delta keeps a fresh install behaving exactly as it does today, and
-- a permission added to Auth::PERMISSIONS later still reaches every role
-- nobody has customised.
--
-- Three permissions can never leave the super admin (mail_admin, manage_roles,
-- system_ops) and manage_admins can never go below the admin role, because it
-- mints full admin accounts. Auth::mayHold() enforces both when the rows are
-- READ, so a hand-edited row cannot grant them either.

CREATE TABLE IF NOT EXISTS admin_role_permissions (
    role       VARCHAR(32)  NOT NULL,
    permission VARCHAR(64)  NOT NULL,
    granted    TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_user_permissions (
    user_id    INT UNSIGNED NOT NULL,
    permission VARCHAR(64)  NOT NULL,
    granted    TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission),
    CONSTRAINT fk_admin_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
