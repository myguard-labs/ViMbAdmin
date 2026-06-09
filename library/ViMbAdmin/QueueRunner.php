<?php

/**
 * Queue-runner concurrency control.
 *
 * ViMbAdmin has no daemon and never forks a runner from a web request. The
 * mailbox-task queue is drained by two triggers, both running the same engine
 * synchronously: (1) the `queue.cli-run` CLI invoked out-of-band by the
 * container cron / s6 service, and (2) the bearer-key + IP-gated remote
 * `POST /queue/trigger` endpoint (drains in-request). The Maintenance tab's
 * "Run now" button drains in-request too. This class only arbitrates how many
 * of those drains may run at once.
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

        // Record when the runner last actually started a drain (the lease row
        // is deleted on exit, so the Maintenance overview reads this marker
        // rather than the live queue_runner table).
        ViMbAdmin_Setting::stampNow( $em, ViMbAdmin_Setting::LAST_QUEUERUN );

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
}
