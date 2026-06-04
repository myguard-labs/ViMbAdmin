<?php

/**
 * Queue-runner concurrency control + trigger-check.
 *
 * ViMbAdmin has no daemon: the mailbox-task queue is only drained when a runner
 * (`queue.cli-run`) is invoked. A cron MUST do that periodically. On top of the
 * cron, several in-app events "trigger-check": if there is pending work and a
 * runner slot is free, they spawn a runner in the BACKGROUND so the queue
 * starts draining immediately without making the user wait.
 *
 * Concurrency (`queue.runner.max_concurrent`, default 1) is enforced with a DB
 * lease: each active drain holds a row in `queue_runner`, heartbeats it, and
 * deletes it on exit. A new drain only starts if the count of non-stale leases
 * is below the cap. Stale leases (the process died without releasing) are reaped
 * after LEASE_TTL seconds so a slot is never lost forever.
 */
class ViMbAdmin_QueueRunner
{
    /** Seconds after which a lease with no heartbeat is considered dead. */
    const LEASE_TTL = 1800;

    /**
     * Trigger-check: if the queue has pending work and a runner slot is free,
     * spawn `vimbtool.php -a queue.cli-run` in the background (non-blocking) and
     * return true. Best-effort — any failure is swallowed (the cron is the
     * guaranteed path). NEVER throws.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $options  the merged application.ini options
     * @return bool  true if a runner was spawned
     */
    public static function triggerCheck( $em, array $options )
    {
        try
        {
            // Anything to do?
            $pending = (int) $em->createQuery(
                'SELECT COUNT(t.id) FROM \Entities\MailboxTask t WHERE t.status = :s' )
                ->setParameter( 's', \Entities\MailboxTask::STATUS_PENDING )
                ->getSingleScalarResult();
            if( $pending === 0 )
                return false;

            // Slot free?
            if( !self::slotAvailable( $em, $options ) )
                return false;

            return self::spawn();
        }
        catch( \Throwable $e )
        {
            return false;
        }
    }

    /**
     * Are we below queue.runner.max_concurrent active (non-stale) runners?
     * Reaps stale leases first so a crashed runner doesn't pin a slot.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $options
     * @return bool
     */
    public static function slotAvailable( $em, array $options )
    {
        self::reapStale( $em );
        $max    = max( 1, (int) ( $options['queue']['runner']['max_concurrent'] ?? 1 ) );
        $active = (int) $em->createQuery(
            'SELECT COUNT(r.id) FROM \Entities\QueueRunner r' )->getSingleScalarResult();
        return $active < $max;
    }

    /**
     * Acquire a runner lease (call at the start of an actual drain). Returns the
     * lease entity, or null if no slot is free (the caller must then NOT drain).
     * The count + insert race is closed by re-checking after insert and backing
     * out if we overshot the cap — cheap and correct for the small N here.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $options
     * @return \Entities\QueueRunner|null
     */
    public static function acquireLease( $em, array $options )
    {
        if( !self::slotAvailable( $em, $options ) )
            return null;

        $now   = new \DateTime();
        $lease = new \Entities\QueueRunner();
        $lease->setHost( (string) gethostname() )
              ->setPid( function_exists( 'getmypid' ) ? (int) getmypid() : 0 )
              ->setStartedAt( $now )
              ->setHeartbeatAt( $now );
        $em->persist( $lease );
        $em->flush();

        // Race back-off: if our insert pushed the active count over the cap and
        // we are not among the oldest <max> leases, yield our slot.
        $max = max( 1, (int) ( $options['queue']['runner']['max_concurrent'] ?? 1 ) );
        $ids = $em->createQuery(
            'SELECT r.id FROM \Entities\QueueRunner r ORDER BY r.id ASC' )
            ->setMaxResults( $max )->getResult();
        $keep = array_map( function( $r ) { return (int) $r['id']; }, $ids );
        if( !in_array( (int) $lease->getId(), $keep, true ) )
        {
            $em->remove( $lease );
            $em->flush();
            return null;
        }
        return $lease;
    }

    /**
     * Refresh a lease's heartbeat (call periodically during a long drain).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\QueueRunner $lease
     * @return void
     */
    public static function heartbeat( $em, \Entities\QueueRunner $lease )
    {
        $lease->setHeartbeatAt( new \DateTime() );
        $em->flush();
    }

    /**
     * Release a lease (call when the drain finishes, in a finally block).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\QueueRunner $lease
     * @return void
     */
    public static function release( $em, \Entities\QueueRunner $lease )
    {
        try
        {
            $em->remove( $lease );
            $em->flush();
        }
        catch( \Throwable $e )
        {
            // best-effort; a stale row will be reaped by reapStale().
        }
    }

    /**
     * Delete leases whose heartbeat is older than LEASE_TTL (dead runners).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return int  rows reaped
     */
    public static function reapStale( $em )
    {
        $cutoff = ( new \DateTime() )->modify( '-' . self::LEASE_TTL . ' seconds' );
        return (int) $em->createQuery(
            'DELETE FROM \Entities\QueueRunner r WHERE r.heartbeat_at < :cutoff' )
            ->setParameter( 'cutoff', $cutoff )
            ->execute();
    }

    /**
     * Spawn `vimbtool.php -a queue.cli-run` detached from the current process,
     * so the caller (a web request or another CLI) returns immediately. Uses the
     * same PHP binary + the vimbtool next to APPLICATION_PATH. Returns false if
     * the tools needed to spawn aren't available (e.g. exec disabled) — the
     * trigger-check is best-effort and the cron remains the guaranteed path.
     *
     * @return bool
     */
    public static function spawn()
    {
        if( !function_exists( 'proc_open' ) && !function_exists( 'exec' ) )
            return false;
        if( !defined( 'APPLICATION_PATH' ) )
            return false;

        $vimbtool = realpath( APPLICATION_PATH . '/../bin/vimbtool.php' );
        if( !$vimbtool )
            return false;

        // Prefer the running PHP binary; fall back to "php" on PATH.
        $php = defined( 'PHP_BINARY' ) && PHP_BINARY && strpos( PHP_BINARY, 'fpm' ) === false
             ? PHP_BINARY : 'php';

        $env = 'APPLICATION_ENV=' . escapeshellarg( defined( 'APPLICATION_ENV' ) ? APPLICATION_ENV : 'production' );
        $cmd = sprintf(
            '%s %s %s -a queue.cli-run',
            $env,
            escapeshellarg( $php ),
            escapeshellarg( $vimbtool )
        );

        // Detach: redirect IO and background it so we never block.
        if( function_exists( 'proc_open' ) )
        {
            $spec = [ 0 => [ 'file', '/dev/null', 'r' ],
                      1 => [ 'file', '/dev/null', 'a' ],
                      2 => [ 'file', '/dev/null', 'a' ] ];
            $p = @proc_open( '/bin/sh -c ' . escapeshellarg( $cmd . ' &' ), $spec, $pipes );
            if( is_resource( $p ) )
            {
                proc_close( $p );
                return true;
            }
            return false;
        }

        @exec( $cmd . ' >/dev/null 2>&1 &' );
        return true;
    }
}
