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
--       doctrine-cli.php orm:schema-tool:update --force
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
--    5. queue_runner lease table.
--    6. retirement of the per-mailbox storage-location columns.
--    7. database-atomic open mailbox-task deduplication.
--    8. domain alias-count reconciliation (mailbox self-aliases excluded).
--    9. optimistic domain capacity/counter version.
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
-- An FK needs identical type AND collation. mailbox.username's collation is
-- whatever the schema-tool + server produced: DBAL 4 (unlike DBAL 3) no longer
-- pins COLLATE on the column, so it inherits the server default — e.g.
-- utf8mb3_unicode_ci on the production server but utf8mb3_uca1400_ai_ci on
-- MariaDB 11.4. dovecot_quota is hand-created utf8mb4, so align its username to
-- whatever mailbox.username actually is (derived live) rather than hardcoding a
-- collation that drifts per server/DBAL version, or ADD CONSTRAINT fails errno 150.
SET @mb_cs := ( SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'username' );
SET @mb_co := ( SELECT COLLATION_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'username' );

SET @bad_coll := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA     = DATABASE()
      AND TABLE_NAME       = 'dovecot_quota'
      AND COLUMN_NAME      = 'username'
      AND ( COLLATION_NAME <> @mb_co OR CHARACTER_SET_NAME <> @mb_cs )
);
SET @ddl := IF( @bad_coll > 0 AND @mb_co IS NOT NULL,
    CONCAT('ALTER TABLE `dovecot_quota` MODIFY `username` VARCHAR(255) CHARACTER SET ',
           @mb_cs, ' COLLATE ', @mb_co, ' NOT NULL'),
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
SET @have_last_login_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dovecot_last_login'
);
SET @ddl := IF( @have_last_login_table = 1 AND @have = 0,
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
SET @have_archive_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archive'
);
SET @have := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'archive'
      AND COLUMN_NAME  = 'autoprune'
);
SET @ddl := IF( @have_archive_table = 1 AND @have = 0,
    'ALTER TABLE `archive` ADD `autoprune` TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0 /* archive.autoprune already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 5) queue_runner lease table (runner concurrency cap)
-- ---------------------------------------------------------------------
-- One row per ACTIVE queue runner. A unique numeric slot makes
-- queue.runner.max_concurrent a database-enforced invariant rather than a
-- count-then-insert convention. Created by the entity mapping on a fresh DB;
-- the guarded ALTERs below upgrade an existing lease table.
CREATE TABLE IF NOT EXISTS `queue_runner` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `slot`         INT          NOT NULL,
    `host`         VARCHAR(255) NOT NULL,
    `pid`          INT          NOT NULL,
    `started_at`   DATETIME     NOT NULL,
    `heartbeat_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `queue_runner_slot_uniq` (`slot`),
    INDEX `queue_runner_heartbeat_idx` (`heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @have_runner_slot := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'queue_runner'
      AND COLUMN_NAME  = 'slot'
);
SET @ddl := IF( @have_runner_slot = 0,
    'ALTER TABLE `queue_runner` ADD `slot` INT NULL AFTER `id`',
    'DO 0 /* queue_runner.slot already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

-- Preserve any live pre-upgrade leases. Their slots only need to be unique;
-- stale reaping and normal release will remove them shortly.
SET @runner_slot := 0;
UPDATE `queue_runner`
SET `slot` = ( @runner_slot := @runner_slot + 1 )
WHERE `slot` IS NULL
ORDER BY `id`;

SET @runner_slot_nullable := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'queue_runner'
      AND COLUMN_NAME  = 'slot'
      AND IS_NULLABLE  = 'YES'
);
SET @ddl := IF( @runner_slot_nullable = 1,
    'ALTER TABLE `queue_runner` MODIFY `slot` INT NOT NULL',
    'DO 0 /* queue_runner.slot already non-null */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have_runner_slot_uniq := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'queue_runner'
      AND INDEX_NAME   = 'queue_runner_slot_uniq'
);
SET @ddl := IF( @have_runner_slot_uniq = 0,
    'ALTER TABLE `queue_runner` ADD UNIQUE INDEX `queue_runner_slot_uniq` (`slot`)',
    'DO 0 /* queue_runner slot invariant already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 6) Drop per-mailbox uid / gid / homedir / maildir columns
-- ---------------------------------------------------------------------
-- ViMbAdmin no longer stores mailbox ownership or storage location: Dovecot
-- owns those entirely via its own config (mail_uid / mail_gid / mail_home /
-- mail_path), and the userdb query stops SELECTing these columns. Drop them so
-- the schema matches the entity (guarded per-column; re-runs no-op). Run AFTER
-- the Dovecot userdb query has been updated to not reference them.
SET @col_uid := ( SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'uid' );
SET @d := IF( @col_uid = 1, 'ALTER TABLE `mailbox` DROP COLUMN `uid`', 'DO 0' );
PREPARE _x FROM @d; EXECUTE _x; DEALLOCATE PREPARE _x;

SET @col_gid := ( SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'gid' );
SET @d := IF( @col_gid = 1, 'ALTER TABLE `mailbox` DROP COLUMN `gid`', 'DO 0' );
PREPARE _x FROM @d; EXECUTE _x; DEALLOCATE PREPARE _x;

SET @col_home := ( SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'homedir' );
SET @d := IF( @col_home = 1, 'ALTER TABLE `mailbox` DROP COLUMN `homedir`', 'DO 0' );
PREPARE _x FROM @d; EXECUTE _x; DEALLOCATE PREPARE _x;

SET @col_mail := ( SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'maildir' );
SET @d := IF( @col_mail = 1, 'ALTER TABLE `mailbox` DROP COLUMN `maildir`', 'DO 0' );
PREPARE _x FROM @d; EXECUTE _x; DEALLOCATE PREPARE _x;


-- ---------------------------------------------------------------------
-- 7) Database-atomic mailbox-task deduplication
-- ---------------------------------------------------------------------
-- A nullable generated discriminator lets MariaDB enforce at most one open
-- task per (username, type): PENDING/RUNNING rows carry 1 and conflict, while
-- terminal rows carry NULL and remain as unrestricted history. Adding the
-- unique index must not partially migrate a database that already contains
-- duplicate open tasks. Stop before either DDL statement and leave every row
-- intact for explicit reconciliation.
SET @have_mailbox_task := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox_task'
);
SET @check := IF( @have_mailbox_task = 0,
    'SET @duplicate_open_tasks = 0',
    'SELECT COUNT(*) INTO @duplicate_open_tasks FROM (
       SELECT `username`, `type` FROM `mailbox_task`
       WHERE `status` IN (''PENDING'', ''RUNNING'')
       GROUP BY `username`, `type` HAVING COUNT(*) > 1
     ) AS duplicate_open_task_groups' );
PREPARE _m FROM @check; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @ddl := IF( @duplicate_open_tasks = 0,
    'DO 0 /* mailbox_task open-task identities are unique */',
    'SELECT * FROM `ERROR_duplicate_open_mailbox_tasks_resolve_before_schema_update`' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox_task'
      AND COLUMN_NAME  = 'open_task'
);
SET @ddl := IF( @have_mailbox_task = 0,
    'DO 0 /* mailbox_task is created by the entity schema */',
    IF( @have = 0,
        -- STORED generated columns may rebuild the table; apply during a
        -- maintenance window when mailbox_task has accumulated large history.
        'ALTER TABLE `mailbox_task` ADD `open_task` TINYINT(1)
           GENERATED ALWAYS AS (IF(`status` IN (''PENDING'', ''RUNNING''), 1, NULL)) STORED',
        'DO 0 /* mailbox_task.open_task already present */' ) );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox_task'
      AND INDEX_NAME   = 'mailbox_task_open_unique'
);
SET @ddl := IF( @have_mailbox_task = 0,
    'DO 0 /* mailbox_task is created by the entity schema */',
    IF( @have = 0,
        'ALTER TABLE `mailbox_task` ADD UNIQUE INDEX `mailbox_task_open_unique`
           (`username`, `type`, `open_task`)',
        'DO 0 /* mailbox_task_open_unique already present */' ) );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

SET @have := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox_task'
      AND INDEX_NAME   = 'mailbox_task_username_type_status_idx'
);
SET @ddl := IF( @have_mailbox_task = 0,
    'DO 0 /* mailbox_task is created by the entity schema */',
    IF( @have = 0,
        'ALTER TABLE `mailbox_task` ADD INDEX `mailbox_task_username_type_status_idx`
           (`username`, `type`, `status`)',
        'DO 0 /* mailbox_task_username_type_status_idx already present */' ) );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;


-- ---------------------------------------------------------------------
-- 8) Reconcile persisted domain alias counts
-- ---------------------------------------------------------------------
-- Historical MCP writes counted mailbox self-aliases (address == goto), while
-- the shared alias service and capacity checks exclude them. Recompute from
-- the persisted source rows on every upgrade so stale counters cannot keep a
-- domain artificially at capacity. This UPDATE is idempotent and also repairs
-- undercounts left by interrupted or older writes.
-- Install the version before reconciliation so existing ORM readers are
-- invalidated by the counter repair as well as ordinary application writes.
SET @have_capacity_version := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domain'
      AND COLUMN_NAME = 'capacity_version'
);
SET @ddl := IF( @have_capacity_version = 0,
    'ALTER TABLE `domain` ADD `capacity_version` BIGINT NOT NULL DEFAULT 1',
    'DO 0 /* capacity_version already present */' );
PREPARE _v FROM @ddl; EXECUTE _v; DEALLOCATE PREPARE _v;

UPDATE `domain` AS d
SET `alias_count` = (
    SELECT COUNT(*)
    FROM `alias` AS a
    WHERE a.`Domain_id` = d.`id`
      AND CAST(a.`address` AS BINARY) != CAST(a.`goto` AS BINARY)
), `capacity_version` = `capacity_version` + 1;

-- 9) Generation-fenced mailbox-task transitions (additive/idempotent).
-- Apply before running upgraded queue workers; normal ORM schema updates also
-- add this column. Existing task history starts at generation 1.
SET @have_task_version := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox_task'
      AND COLUMN_NAME = 'task_version'
);
SET @ddl := IF( @have_mailbox_task = 0,
    'DO 0 /* mailbox_task is created by the entity schema */',
    IF( @have_task_version = 0,
        'ALTER TABLE `mailbox_task` ADD `task_version` BIGINT NOT NULL DEFAULT 1',
        'DO 0 /* mailbox_task.task_version already present */' ) );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;
