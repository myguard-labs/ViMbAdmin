<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Doctrine schema sync helper.
 *
 * Used by both the Maintenance web UI and the CLI auto-migrator (run from the
 * container bootstrap on every start). Centralises:
 *
 *   - computing the pending additive schema SQL,
 *   - applying it DDL-safely (NO surrounding transaction — MySQL/MariaDB
 *     implicitly commits on every DDL statement, so wrapping the loop in
 *     begin/commit throws "There is no active transaction" on the commit),
 *   - recording the applied DBVERSION in the database_version table so the
 *     deployed schema version is visible.
 */
class ViMbAdmin_Schema
{
    private static function countValue(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $normalized = ltrim($value, '0');
            $value = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException('Schema introspection count is malformed');
        }
        return $value;
    }
    /** @var \Doctrine\ORM\EntityManagerInterface */
    private $_em;

    public function __construct(\Doctrine\ORM\EntityManagerInterface $em)
    {
        $this->_em = $em;
    }

    /**
     * Pending additive schema statements (no DROP TABLE for tables Doctrine
     * doesn't manage).
     *
     * @return string[]
     */
    public function pendingSql()
    {
        $tool = new \Doctrine\ORM\Tools\SchemaTool($this->_em);
        $meta = $this->_em->getMetadataFactory()->getAllMetadata();
        $sql  = $tool->getUpdateSchemaSql($meta);

        // Strip table drops. Doctrine ORM 3 removed the $saveMode argument from
        // getUpdateSchemaSql() (ORM 2 accepted a second `true` to emit only the
        // safe, drop-free subset); it now always returns the FULL diff. That
        // diff includes DROP TABLE for any table without an entity mapping --
        // notably the hand-managed `setting` KV store created in extraSql()
        // below -- surfacing a destructive "DROP TABLE `setting`;" as a phantom
        // pending statement in the Maintenance tab. This is an ADDITIVE
        // migrator: it must never drop a table, so filter every table drop
        // (mirrors the old saveMode=true behaviour).
        $sql = array_values(array_filter($sql, function ($stmt) {
            return !preg_match('/^\s*DROP\s+TABLE\b/i', $stmt);
        }));

        // Drop no-op ALTERs against Dovecot-owned tables. These are read-only
        // entities (dovecot_quota / dovecot_last_login); their timestamp column
        // uses a `column-definition` override which Doctrine's schema comparator
        // can never reconcile with what MariaDB introspects (current_timestamp()
        // vs CURRENT_TIMESTAMP normalisation), so it emits an identical CHANGE
        // statement on every run -- a perpetual phantom "1 pending statement".
        // The ALTER is a no-op (column already in that exact shape), so filter
        // these tables out: ViMbAdmin must never rewrite tables Dovecot owns.
        $sql = array_values(array_filter($sql, function ($stmt) {
            return !preg_match('/\bALTER\s+TABLE\s+`?dovecot_(quota|last_login)`?\b/i', $stmt);
        }));

        // Append migrations the Doctrine schema-tool can't express, because the
        // Dovecot-owned tables are mapped read-only with NO association (the
        // Mailbox PK is `id`, not `username`, so an owning assoc would collide
        // with quota-clone's writes). We still want the rows to die with their
        // mailbox, so add ON DELETE CASCADE FKs at the DB layer. Each statement
        // is introspection-guarded — it appears here only while actually
        // missing, so it counts as "pending" exactly once and is a no-op after.
        foreach ($this->extraSql() as $stmt) {
            $sql[] = $stmt;
        }

        return $sql;
    }

    /**
     * Stop before any DDL when an existing queue cannot accept the new unique
     * open-task index. Operators must reconcile duplicates explicitly; an
     * automatic status change could cancel work that is already running.
     *
     * @param string[] $sql
     */
    private function assertMailboxTaskOpenUniquenessCanMigrate(array $sql): void
    {
        $needsIndex = false;
        foreach ($sql as $stmt) {
            if (stripos($stmt, 'mailbox_task_open_unique') !== false) {
                $needsIndex = true;
                break;
            }
        }
        if (!$needsIndex) {
            return;
        }

        $conn = $this->_em->getConnection();
        $db = $conn->getDatabase();
        if ($db === null || $db === '') {
            throw new \RuntimeException(
                'Cannot verify mailbox_task open-task uniqueness: no database is selected.'
            );
        }

        $haveTable = self::countValue($conn->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [ $db, 'mailbox_task' ]
        ));
        if ($haveTable === 0) {
            return;
        }

        $duplicates = self::countValue($conn->fetchOne(
            'SELECT COUNT(*) FROM ('
            . ' SELECT username, type FROM mailbox_task'
            . ' WHERE status IN (?, ?) GROUP BY username, type HAVING COUNT(*) > 1'
            . ' ) duplicate_open_tasks',
            \Entities\MailboxTask::OPEN_STATUSES
        ));
        if ($duplicates !== 0) {
            throw new \RuntimeException(sprintf(
                'Cannot add mailbox_task_open_unique: found %d duplicate open username/type group(s);'
                . ' resolve the duplicate PENDING/RUNNING tasks before updating the schema.',
                $duplicates
            ));
        }
    }

    /**
     * Ownership cannot be inferred for a task claimed by pre-upgrade code.
     * Require those runners to finish before adding the fencing columns.
     *
     * @param string[] $sql
     */
    private function needsMailboxTaskOwnershipMigration(array $sql): bool
    {
        foreach ($sql as $stmt) {
            if (preg_match('/\bADD\s+(?:COLUMN\s+)?`?(?:QueueRunner_id|abandoned)`?/i', $stmt) === 1) {
                return true;
            }
        }
        return false;
    }

    private function assertMailboxTaskOwnershipCanMigrate(): void
    {
        $conn = $this->_em->getConnection();
        $db = $conn->getDatabase();
        if ($db === null || $db === '') {
            throw new \RuntimeException(
                'Cannot verify mailbox-task ownership migration: no database is selected.'
            );
        }
        $haveTable = self::countValue($conn->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [ $db, 'mailbox_task' ]
        ));
        if ($haveTable === 0) {
            return;
        }
        $leases = self::countValue($conn->fetchOne(
            'SELECT COUNT(*) FROM queue_runner'
        ));
        $running = self::countValue($conn->fetchOne(
            'SELECT COUNT(*) FROM mailbox_task WHERE status = ?',
            [ \Entities\MailboxTask::STATUS_RUNNING ]
        ));
        if ($leases !== 0 || $running !== 0) {
            throw new \RuntimeException(sprintf(
                'Cannot add mailbox-task runner ownership while %d runner lease(s) or %d RUNNING task(s) exist;'
                . ' quiesce queue runners and let or explicitly reconcile every RUNNING task before updating the schema.',
                $leases,
                $running
            ));
        }
    }

    /**
     * Hand-written migration statements that Doctrine's schema-tool cannot
     * generate (FKs on read-only/unassociated tables, collation alignment).
     * Returned only when not yet applied, so they integrate with the normal
     * pending-count / apply flow. Mirror of the FK/collation steps in
     * contrib/migrations/2026-06-fork-schema.sql.
     *
     * @return string[]
     */
    public function extraSql()
    {
        $conn = $this->_em->getConnection();
        $db   = $conn->getDatabase();
        $out  = [];

        // The two Dovecot-owned tables that should cascade-delete with mailbox.
        $fks = [
            'dovecot_quota'      => 'FK_dovecot_quota_mailbox',
            'dovecot_last_login' => 'FK_dovecot_last_login_mailbox',
        ];

        try {
            // 0) Tiny key/value settings store (ViMbAdmin_Setting): last-queuerun
            //    / last-prune timestamps for the Maintenance tab. Introspection-
            //    GUARDED: emit the CREATE only while the table is actually
            //    missing, so it counts as "pending" exactly once and never shows
            //    a phantom pending statement after it exists. (A bare
            //    `CREATE TABLE IF NOT EXISTS` would be a no-op at apply time but
            //    would still appear in pendingSql() forever -> perpetual "1
            //    pending".)
            $haveSetting = self::countValue($conn->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [ $db, 'setting' ]
            ));
            if ($haveSetting === 0) {
                $out[] = 'CREATE TABLE `setting` ('
                       . ' `name` VARCHAR(64) NOT NULL,'
                       . ' `value` VARCHAR(255) NULL DEFAULT NULL,'
                       . ' `updated_at` DATETIME NULL DEFAULT NULL,'
                       . ' PRIMARY KEY (`name`)'
                       . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci';
            }

            // 1) dovecot_quota.username collation must match mailbox.username
            //    (utf8mb3_unicode_ci) or the FK fails with errno 150.
            $coll = $conn->fetchOne(
                'SELECT COLLATION_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [ $db, 'dovecot_quota', 'username' ]
            );
            if ($coll !== false && $coll !== null && $coll !== 'utf8mb3_unicode_ci') {
                $out[] = 'ALTER TABLE `dovecot_quota` MODIFY `username` '
                       . 'VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL';
            }

            // 2) the cascade FKs themselves (only if absent).
            foreach ($fks as $table => $name) {
                $have = self::countValue($conn->fetchOne(
                    'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                    [ $db, $table, $name ]
                ));
                if ($have === 0) {
                    $out[] = sprintf(
                        'ALTER TABLE `%s` ADD CONSTRAINT `%s` '
                        . 'FOREIGN KEY (`username`) REFERENCES `mailbox` (`username`) '
                        . 'ON DELETE CASCADE ON UPDATE CASCADE',
                        $table,
                        $name
                    );
                }
            }
        } catch (\Throwable $e) {
            // Introspection failed (e.g. a base table not present yet on a
            // brand-new install before schema-tool created it). Skip — the next
            // run picks up the setting table + FKs once introspection works.
            return [];
        }

        return $out;
    }

    /**
     * Apply the given statements one by one. DDL auto-commits, so we do NOT
     * wrap this in a transaction; instead each statement runs on its own and
     * the first failure aborts with context.
     *
     * @param string[] $sql
     * @return int number of statements executed
     * @throws \Throwable on the first failing statement
     */
    public function apply(array $sql)
    {
        $conn = $this->_em->getConnection();
        $ownershipMigration = $this->needsMailboxTaskOwnershipMigration($sql);
        $locked = false;
        try {
            if ($ownershipMigration) {
                $result = $conn->fetchOne(
                    'SELECT GET_LOCK(?, ?)',
                    [ ViMbAdmin_QueueRunner::ACQUIRE_LOCK_NAME, ViMbAdmin_QueueRunner::ACQUIRE_LOCK_TIMEOUT ]
                );
                if ($result !== 1 && $result !== '1') {
                    throw new \RuntimeException('Could not acquire queue-runner mutex for ownership migration.');
                }
                $locked = true;
                $this->assertMailboxTaskOwnershipCanMigrate();
            }
            $this->assertMailboxTaskOpenUniquenessCanMigrate($sql);

            $done = 0;
            foreach ($sql as $stmt) {
                try {
                    $conn->executeStatement($stmt);
                    $done++;
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        sprintf(
                            'schema statement %d/%d failed: %s | SQL: %s',
                            $done + 1,
                            count($sql),
                            $e->getMessage(),
                            $stmt
                        ),
                        0,
                        $e
                    );
                }
            }
            return $done;
        } finally {
            if ($locked) {
                $released = $conn->fetchOne(
                    'SELECT RELEASE_LOCK(?)',
                    [ ViMbAdmin_QueueRunner::ACQUIRE_LOCK_NAME ]
                );
                if ($released !== 1 && $released !== '1') {
                    $conn->close();
                }
            }
        }
    }

    /**
     * Record the current code DBVERSION in database_version (idempotent: only
     * inserts a row when the highest recorded version is behind the code).
     *
     * @return bool true if a new version row was written
     */
    public function recordVersion()
    {
        if (!class_exists('ViMbAdmin_Version') || !defined('ViMbAdmin_Version::DBVERSION')) {
            return false;
        }

        $current = $this->currentVersion();
        if ($current !== null && (int) $current >= (int) ViMbAdmin_Version::DBVERSION) {
            return false;
        }

        $row = new \Entities\DatabaseVersion();
        $row->setVersion(ViMbAdmin_Version::DBVERSION);
        $row->setName(defined('ViMbAdmin_Version::DBVERSION_NAME') ? ViMbAdmin_Version::DBVERSION_NAME : '');
        $row->setAppliedOn(new \DateTime());
        $this->_em->persist($row);
        $this->_em->flush();
        return true;
    }

    /**
     * Highest version recorded in database_version, or null if none/table absent.
     *
     * @return int|null
     */
    public function currentVersion()
    {
        try {
            $v = $this->_em->createQuery(
                'SELECT MAX(d.version) FROM \Entities\DatabaseVersion d'
            )->getSingleScalarResult();
            return $v === null ? null : (int) $v;
        } catch (\Throwable $e) {
            return null; // table may not exist yet on a fresh install
        }
    }

    /**
     * The DBVERSION the running code expects.
     *
     * @return int|null
     */
    public function codeVersion()
    {
        return (class_exists('ViMbAdmin_Version') && defined('ViMbAdmin_Version::DBVERSION'))
            ? (int) ViMbAdmin_Version::DBVERSION : null;
    }

    /**
     * Bring the schema up to date and record the version. Returns a summary.
     *
     * @return array{applied:int,version:int|null,statements:string[]}
     */
    public function migrate()
    {
        $sql = $this->pendingSql();
        $applied = count($sql) ? $this->apply($sql) : 0;
        $this->recordVersion();
        return [
            'applied'    => $applied,
            'version'    => $this->codeVersion(),
            'statements' => $sql,
        ];
    }
}
