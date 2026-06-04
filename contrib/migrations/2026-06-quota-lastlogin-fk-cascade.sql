-- =====================================================================
--  Migration: ON DELETE CASCADE FKs for the Dovecot-owned username tables
-- =====================================================================
--  WHY: `dovecot_quota` (quota-clone live usage) and `dovecot_last_login`
--       are dedicated, READ-ONLY-from-ViMbAdmin tables keyed by `username`
--       (the full email address). Dovecot writes them; ViMbAdmin only reads.
--       They have NO Doctrine association to Mailbox -- and correctly so: the
--       Mailbox PK is `id` (bigint), `username` is just a unique field, so a
--       Doctrine owning assoc would have to key on a non-PK column and would
--       collide with quota-clone's INSERT .. ON DUPLICATE KEY writes (the
--       read-only entities exist precisely to keep Doctrine from writing them).
--       See memory lesson feedback-vimbadmin-quota-clone-table.
--
--       Result: deleting a mailbox left ORPHAN rows in these two tables. The
--       queue runner now also DELETEs them in PHP, but the authoritative fix
--       is a DB-level FK with ON DELETE CASCADE so the rows die with the
--       mailbox no matter who deletes it (panel, CLI, raw SQL).
--
--  PREREQ: UNIQUE index on mailbox.username (IX_Username_mailbox) -- added by
--          2026-06-mailbox-username-unique.sql. An FK needs a unique key on
--          the referenced column.
--
--  COLLATION: an FK requires the child and parent columns to share an
--          identical type AND collation. mailbox.username is
--          utf8mb3_unicode_ci. dovecot_last_login.username already matches;
--          dovecot_quota.username was created utf8mb4_uca1400_ai_ci (MariaDB
--          11.4 default) and MUST be converted first or the ADD CONSTRAINT
--          fails with errno 150.
--
--  Apply (MariaDB / MySQL 5.7+):
--       mysql -u<user> -p <database> < 2026-06-quota-lastlogin-fk-cascade.sql
--
--  IMPORTANT: orphan rows in either table make ADD CONSTRAINT fail. Check &
--             clean first:
--    SELECT q.username FROM dovecot_quota q
--      LEFT JOIN mailbox m ON m.username=q.username WHERE m.username IS NULL;
--    SELECT l.username FROM dovecot_last_login l
--      LEFT JOIN mailbox m ON m.username=l.username WHERE m.username IS NULL;
--
--  Idempotent: each step skips if already applied.
-- =====================================================================

-- ---- 1) align dovecot_quota.username collation with mailbox.username -------
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

-- ---- 2) FK: dovecot_quota.username -> mailbox.username  (CASCADE) ----------
SET @have := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'dovecot_quota'
      AND CONSTRAINT_NAME = 'FK_dovecot_quota_mailbox'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `dovecot_quota`
       ADD CONSTRAINT `FK_dovecot_quota_mailbox`
       FOREIGN KEY (`username`) REFERENCES `mailbox` (`username`)
       ON DELETE CASCADE ON UPDATE CASCADE',
    'DO 0 /* FK_dovecot_quota_mailbox already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;

-- ---- 3) FK: dovecot_last_login.username -> mailbox.username  (CASCADE) -----
SET @have := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'dovecot_last_login'
      AND CONSTRAINT_NAME = 'FK_dovecot_last_login_mailbox'
);
SET @ddl := IF( @have = 0,
    'ALTER TABLE `dovecot_last_login`
       ADD CONSTRAINT `FK_dovecot_last_login_mailbox`
       FOREIGN KEY (`username`) REFERENCES `mailbox` (`username`)
       ON DELETE CASCADE ON UPDATE CASCADE',
    'DO 0 /* FK_dovecot_last_login_mailbox already present */' );
PREPARE _m FROM @ddl; EXECUTE _m; DEALLOCATE PREPARE _m;
