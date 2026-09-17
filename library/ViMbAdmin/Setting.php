<?php

/**
 * Tiny key/value settings store.
 *
 * A single `setting(name PK, value, updated_at)` table for small bits of
 * instance state that don't deserve their own entity -- currently the
 * "last time the queue runner started" and "last time a prune ran"
 * timestamps shown on the Maintenance tab, plus the queue runner's atomic
 * autoprune-sweep gate.
 *
 * Raw DBAL (not a Doctrine entity) on purpose: there is nothing relational
 * here, the table is created by ViMbAdmin_Schema::extraSql(), and the helper
 * must work from both the web request and the CLI queue runner without
 * dragging in entity metadata. Informational reads/writes are fail-soft; gate
 * operations deliberately propagate database errors because silently losing
 * a gate can turn every queue drain into a full maintenance sweep.
 */
class ViMbAdmin_Setting
{
    public const LAST_QUEUERUN   = 'last_queuerun_at';
    public const LAST_PRUNE      = 'last_prune_at';
    public const LAST_PRUNE_SWEEP = 'last_prune_sweep_at';   // when the runner last enqueued autoprune tasks

    /**
     * Read a setting value, or $default if absent / on any error.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $name
     * @param mixed  $default
     * @return string|null
     */
    public static function get($em, $name, $default = null)
    {
        if ($default !== null && !is_string($default)) {
            throw new \InvalidArgumentException('Setting default must be a string or null');
        }
        $fallback = $default;
        try {
            $val = $em->getConnection()->fetchOne(
                'SELECT value FROM setting WHERE name = ?',
                [ $name ]
            );
            return ($val === false || $val === null) ? $fallback : (is_string($val) ? $val : $fallback);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Upsert a setting value. Fail-soft (never throws).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $name
     * @param string $value
     * @return void
     */
    public static function set($em, $name, $value)
    {
        try {
            $em->getConnection()->executeStatement(
                'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, NOW())'
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()',
                [ $name, (string) $value ]
            );
        } catch (\Throwable $e) {
            // informational state only -- swallow.
        }
    }

    /**
     * Atomically acquire a timestamp gate when it is absent or older than the
     * supplied cutoff. Unlike informational settings, failures propagate.
     *
     * @param \Doctrine\ORM\EntityManager $em
     */
    public static function claimTimestamp($em, string $name, \DateTimeInterface $cutoff, \DateTimeInterface $now): bool
    {
        $connection = $em->getConnection();
        $affected = $connection->executeStatement(
            'UPDATE setting SET value = ?, updated_at = NOW()'
            . ' WHERE name = ? AND CAST(value AS UNSIGNED) < ?',
            [ (string) $now->getTimestamp(), $name, $cutoff->getTimestamp() ]
        );
        if (!is_int($affected) || $affected < 0 || $affected > 1) {
            throw new \UnexpectedValueException('Setting timestamp gate update returned an invalid affected-row count.');
        }
        if ($affected === 1) {
            return true;
        }

        $inserted = $connection->executeStatement(
            'INSERT IGNORE INTO setting (name, value, updated_at) VALUES (?, ?, NOW())',
            [ $name, (string) $now->getTimestamp() ]
        );
        if (!is_int($inserted) || $inserted < 0 || $inserted > 1) {
            throw new \UnexpectedValueException('Setting timestamp gate insert returned an invalid affected-row count.');
        }
        return $inserted === 1;
    }

    /**
     * Stamp a timestamp setting with "now" (ISO-8601). Convenience for the
     * queue-runner-started / prune-ran markers.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $name
     * @return void
     */
    public static function stampNow($em, $name)
    {
        self::set($em, $name, (new \DateTime())->format('c'));
    }
}
