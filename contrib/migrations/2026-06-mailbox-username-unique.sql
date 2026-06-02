-- =====================================================================
--  Migration: UNIQUE index on mailbox.username
-- =====================================================================
--  WHY: Postfix (virtual_mailbox_maps) and Dovecot (passdb/userdb) query
--       `... FROM mailbox WHERE username = '...'` on EVERY mail delivery and
--       EVERY IMAP/POP/SMTP-AUTH login. The entity mapping declares this index
--       (IX_Username_mailbox), so FRESH installs created with
--       `doctrine2-cli.php orm:schema-tool:create` already have it -- but DBs
--       seeded from the older SQL dumps do NOT, so those daemons full-scan the
--       mailbox table. This adds the missing UNIQUE index (also enforces the
--       one-mailbox-per-address integrity the app checks in code).
--
--  Apply (MariaDB / MySQL 5.7+):
--       mysql -u<user> -p <database> < 2026-06-mailbox-username-unique.sql
--  or, equivalently:  doctrine2-cli.php orm:schema-tool:update --force
--
--  IMPORTANT: it's UNIQUE -- if duplicate usernames somehow exist the ALTER
--  fails. Check first and clean up before running:
--       SELECT username, COUNT(*) c FROM mailbox GROUP BY username HAVING c > 1;
--
--  Idempotent: skips if IX_Username_mailbox already exists.
-- =====================================================================

SET @have := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'mailbox'
      AND INDEX_NAME   = 'IX_Username_mailbox'
);

SET @ddl := IF( @have = 0,
    'ALTER TABLE `mailbox` ADD UNIQUE INDEX `IX_Username_mailbox` (`username`)',
    'DO 0 /* IX_Username_mailbox already present */' );

PREPARE _m FROM @ddl;
EXECUTE _m;
DEALLOCATE PREPARE _m;
