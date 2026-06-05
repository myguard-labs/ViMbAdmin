<?php

/**
 * Tiny key/value settings store.
 *
 * A single `setting(name PK, value, updated_at)` table for small bits of
 * instance state that don't deserve their own entity -- currently the
 * "last time the queue runner started" and "last time a prune ran"
 * timestamps shown on the Maintenance tab.
 *
 * Raw DBAL (not a Doctrine entity) on purpose: there is nothing relational
 * here, the table is created by ViMbAdmin_Schema::extraSql(), and the helper
 * must work from both the web request and the CLI queue runner without
 * dragging in entity metadata. All methods are fail-soft: a missing table or
 * a DB error never throws into the caller (the timestamps are informational).
 */
class ViMbAdmin_Setting
{
    const LAST_QUEUERUN   = 'last_queuerun_at';
    const LAST_PRUNE      = 'last_prune_at';
    const LAST_PRUNE_SWEEP = 'last_prune_sweep_at';   // when the runner last enqueued autoprune tasks

    /**
     * Read a setting value, or $default if absent / on any error.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $name
     * @param mixed  $default
     * @return string|null
     */
    public static function get( $em, $name, $default = null )
    {
        try
        {
            $val = $em->getConnection()->fetchOne(
                'SELECT value FROM setting WHERE name = ?', [ $name ] );
            return ( $val === false || $val === null ) ? $default : $val;
        }
        catch( \Throwable $e )
        {
            return $default;
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
    public static function set( $em, $name, $value )
    {
        try
        {
            $em->getConnection()->executeStatement(
                'INSERT INTO setting (name, value, updated_at) VALUES (?, ?, NOW())'
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()',
                [ $name, (string) $value ] );
        }
        catch( \Throwable $e )
        {
            // informational state only -- swallow.
        }
    }

    /**
     * Stamp a timestamp setting with "now" (ISO-8601). Convenience for the
     * queue-runner-started / prune-ran markers.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $name
     * @return void
     */
    public static function stampNow( $em, $name )
    {
        self::set( $em, $name, ( new \DateTime() )->format( 'c' ) );
    }
}
