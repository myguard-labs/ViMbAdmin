-- =====================================================================
--  ViMbAdmin fork schema — consolidated migration (DBVERSION 3)
-- =====================================================================
--  The fork tracks ONE schema version above upstream's v2 ("Earth"). On a
--  fresh install Doctrine SchemaTool (orm:schema-tool:update --force) +
--  ViMbAdmin_Schema::extraSql() build the whole thing in one pass, so there is
--  no per-feature migration chain. This single file is the standalone SQL
--  mirror for DBs seeded from older dumps; it folds together what used to be
--  three separate patches (mailbox-username-unique, quota-clone-table,
--  quota-lastlogin-fk-cascade) plus the archive.autoprune column.
--
--  Everything here is IDEMPOTENT (information_schema-guarded / IF NOT EXISTS /
--  INSERT IGNORE), so it is safe to re-run. Equivalent to:
--       doctrine2-cli.php orm:schema-tool:update --force
--  for the Doctrine-managed parts, plus the FK/collation steps that the
--  schema-tool cannot express.
--
--  Apply (MariaDB / MySQL 5.7+):
--       mysql -u<user> -p <database> < 2026-06-fork-schema.sql
--
--  Covers:
--    1. dovecot_quota table (quota-clone live usage) + legacy maildir-scan
--       column retirement (seed then drop maildir_size/homedir_size/size_at,
--       drop interim mirror triggers).
--    2. UNIQUE index on mailbox.username (Postfix/Dovecot lookup + FK target).
--    3. dovecot_quota.username collation alignment + ON DELETE CASCADE FKs
--       dovecot_quota / dovecot_last_login -> mailbox(username).
--    4. archive.autoprune column.
--
--  NB: the dovecot_last_login, mailbox_task, mcp_token and archive tables (and
--  admin.last_login) are created by the entity mappings via the schema-tool on
--  a fresh DB; this file only adds the pieces that need hand-written SQL.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1) dovecot_quota table + legacy maildir-scan retirement
-- ---------------------------------------------------------------------
-- Live-usage table written by Dovecot quota-clone (username PK + bytes +
-- messages). Dedicated table because quota-clone writes INSERT..ON DUPLICATE
-- KEY UPDATE, which can't target mailbox's NOT NULL columns. ViMbAdmin reads
-- it only (Entities\Quota is mapped read-only). Dovecot is the authority.
CREATE TABLE IF NOT EXISTS `dovecot_quota` (
    `username`   VARCHAR(255) NOT NULL,
    `bytes`      BIGINT       NOT NULL DEFAULT 0,
    `messages`   BIGINT       NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Drop the interim mailbox-mirror triggers, if present.
DROP TRIGGER IF EXISTS `trg_dq_ins`;
DROP TRIGGER IF EXISTS `trg_dq_upd`;

-- Seed dovecot_quota from the legacy maildir_size column (only if it still
-- exists and only for usernames not already present) so usage stays visible
-- until Dovecot's first quota-clone write. Message counts start at 0.
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

-- Drop the retired maildir-scan usage columns (guarded; re-runs no-op).
SET @drop := IF( @have_msize = 1,
    'ALTER TABLE `mailbox`
        DROP COLUMN `maildir_size`,
        DROP COLUMN `homedir_size`,
        DROP COLUMN `size_at`',
    'DO 0 /* legacy size columns already dropped */' );
PREPARE _d FROM @drop; EXECUTE _d; DEALLOCATE PREPARE _d;


-- ---------------------------------------------------------------------
-- 2) UNIQUE index on mailbox.username
-- ---------------------------------------------------------------------
-- Postfix (virtual_mailbox_maps) + Dovecot (passdb/userdb) look up by username
-- on every delivery/login; also the FK target for step 3. UNIQUE fails on
-- duplicate usernames — check first:
--   SELECT username, COUNT(*) c FROM mailbox GROUP BY username HAVING c > 1;
SET @have := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox'
      AND INDEX_NAME   = 'IX_Username_mailbox'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `mailbox` ADD UNIQUE INDEX `IX_Username_mailbox` (`username`)',
    'DO 0 /* IX_Username_mailbox already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 3) ON DELETE CASCADE FKs for the Dovecot-owned username tables
-- ---------------------------------------------------------------------
-- dovecot_quota / dovecot_last_login are read-only, username-keyed, Dovecot-
-- written, with NO Doctrine association (Mailbox PK is `id`, not `username`),
-- so the schema-tool can't add these. Cascade-delete the rows with the mailbox.
-- An FK needs identical type AND collation: align dovecot_quota.username
-- (created utf8mb4_uca1400_ai_ci on MariaDB 11.4) to mailbox's
-- utf8mb3_unicode_ci first, or ADD CONSTRAINT fails errno 150.
SET @bad_coll := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'dovecot_quota'
      AND COLUMN_NAME    = 'username'
      AND COLLATION_NAME <> 'utf8mb3_unicode_ci'
);
SET @ddl := IF( @bad_coll > 0,
    'ALTER TABLE `dovecot_quota` MODIFY `username` VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL',
    'DO 0 /* dovecot_quota.username collation already aligned */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dovecot_quota'
      AND CONSTRAINT_NAME = 'FK_dovecot_quota_mailbox'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `dovecot_quota`
       ADD CONSTRAINT `FK_dovecot_quota_mailbox`
       FOREIGN KEY (`username`) REFERENCES `mailbox` (`username`)
       ON DELETE CASCADE ON UPDATE CASCADE',
    'DO 0 /* FK_dovecot_quota_mailbox already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dovecot_last_login'
      AND CONSTRAINT_NAME = 'FK_dovecot_last_login_mailbox'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `dovecot_last_login`
       ADD CONSTRAINT `FK_dovecot_last_login_mailbox`
       FOREIGN KEY (`username`) REFERENCES `mailbox` (`username`)
       ON DELETE CASCADE ON UPDATE CASCADE',
    'DO 0 /* FK_dovecot_last_login_mailbox already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 4) archive.autoprune column
-- ---------------------------------------------------------------------
-- Queue ARCHIVE/DELETE write archive rows; DELETE flags autoprune so the backup
-- is pruned after queue.autoprune.days. Additive boolean (schema-tool also
-- generates this on a fresh DB).
SET @have := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'archive'
      AND COLUMN_NAME  = 'autoprune'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `archive` ADD `autoprune` TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0 /* archive.autoprune already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 5) queue_runner lease table (runner concurrency cap)
-- ---------------------------------------------------------------------
-- One row per ACTIVE queue runner; queue.runner.max_concurrent is enforced by
-- counting the non-stale rows before a new drain starts. Created by the entity
-- mapping on a fresh DB; this is the standalone mirror.
CREATE TABLE IF NOT EXISTS `queue_runner` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `host`         VARCHAR(255) NOT NULL,
    `pid`          INT          NOT NULL,
    `started_at`   DATETIME     NOT NULL,
    `heartbeat_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `queue_runner_heartbeat_idx` (`heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
