-- =====================================================================
--  Migration: `dovecot_quota` table for Dovecot quota-clone live usage
-- =====================================================================
--  WHY: Dovecot 2.4's quota-clone plugin mirrors each user's CURRENT quota
--       usage into an SQL dictionary so ViMbAdmin can show live mailbox size
--       (and message count) in the GUI without scanning the maildirs. We point
--       its dict_map at a DEDICATED `dovecot_quota` table keyed by username:
--
--         priv/quota/storage   -> dovecot_quota.bytes     (storage, bytes)
--         priv/quota/messages  -> dovecot_quota.messages  (message count)
--
--       A dedicated table (NOT the `mailbox` table) is required because
--       quota-clone writes with INSERT .. ON DUPLICATE KEY UPDATE, which fails
--       against mailbox's NOT NULL columns (password / quota / local_part have
--       no default). dovecot_quota is clean (username PK + bytes + messages).
--
--       Dovecot is the AUTHORITY for this table -- it REPLACEs the row on every
--       change. ViMbAdmin only ever reads it (Entities\Quota is mapped
--       read-only). The entity mapping declares this table, so FRESH installs
--       created with `doctrine2-cli.php orm:schema-tool:create` already have it
--       -- this migration is for DBs seeded from older SQL dumps.
--
--  Dovecot config: see the "Live quota usage (Dovecot quota-clone)" section in
--  README.md for the dict_server + quota_clone snippet that feeds this table.
--  See also: https://doc.dovecot.org/2.4.4/core/plugins/quota_clone.html
--
--  Apply (MariaDB / MySQL 5.7+):
--       mysql -u<user> -p <database> < 2026-06-quota-clone-table.sql
--  or, equivalently:  doctrine2-cli.php orm:schema-tool:update --force
--
--  This migration also RETIRES the legacy maildir-scan usage columns
--  (`mailbox.maildir_size`, `mailbox.homedir_size`, `mailbox.size_at`) that the
--  old `mailbox.cli-get-sizes` cron populated. It first seeds dovecot_quota from
--  `maildir_size` so usage is not lost (message counts are unknown until Dovecot
--  writes them, so they start at 0), then drops the columns.
--
--  If you previously ran an interim setup where an AFTER INSERT/UPDATE trigger
--  on dovecot_quota mirrored usage back into mailbox.maildir_size, this drops
--  those triggers too -- ViMbAdmin now reads dovecot_quota directly, so the
--  mirror is redundant (and would target dropped columns).
--
--  Idempotent: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, DROP TRIGGER IF
--  EXISTS, and column drops guarded by information_schema -- safe to re-run.
-- =====================================================================

-- 1) The live-usage table written by Dovecot quota-clone.
CREATE TABLE IF NOT EXISTS `dovecot_quota` (
    `username`   VARCHAR(255) NOT NULL,
    `bytes`      BIGINT       NOT NULL DEFAULT 0,
    `messages`   BIGINT       NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Drop the interim mailbox-mirror triggers, if present (ViMbAdmin now reads
--    dovecot_quota directly).
DROP TRIGGER IF EXISTS `trg_dq_ins`;
DROP TRIGGER IF EXISTS `trg_dq_upd`;

-- 3) Seed from the legacy maildir_size column (only if it still exists and only
--    for usernames not already present). Keeps the usage figure visible until
--    Dovecot's first quota-clone write replaces it.
SET @have_msize := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox'
      AND COLUMN_NAME  = 'maildir_size'
);

SET @seed := IF( @have_msize = 1,
    'INSERT IGNORE INTO `dovecot_quota` (`username`, `bytes`, `messages`)
        SELECT `username`, COALESCE(`maildir_size`, 0), 0 FROM `mailbox`',
    'DO 0 /* maildir_size already gone, nothing to seed */' );
PREPARE _s FROM @seed; EXECUTE _s; DEALLOCATE PREPARE _s;

-- 4) Drop the retired columns (guarded so re-runs are no-ops).
SET @drop := IF( @have_msize = 1,
    'ALTER TABLE `mailbox`
        DROP COLUMN `maildir_size`,
        DROP COLUMN `homedir_size`,
        DROP COLUMN `size_at`',
    'DO 0 /* legacy size columns already dropped */' );
PREPARE _d FROM @drop; EXECUTE _d; DEALLOCATE PREPARE _d;
