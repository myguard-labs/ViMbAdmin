<?php

namespace Repositories;

use Doctrine\ORM\EntityRepository;

/**
 * MailboxTask repository.
 *
 * Holds the queue-claim logic: a single atomic UPDATE flips a task from
 * PENDING to RUNNING and returns whether *this* runner won the race, so two
 * concurrent runners never process the same task.
 */
class MailboxTask extends EntityRepository
{
    /**
     * Fetch the oldest PENDING tasks (highest priority first), up to $limit.
     *
     * @param int $limit
     * @return \Entities\MailboxTask[]
     */
    public function pending( $limit = 5 )
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select( 't' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->where( 't.status = :s' )
            ->setParameter( 's', \Entities\MailboxTask::STATUS_PENDING )
            ->orderBy( 't.priority', 'DESC' )
            ->addOrderBy( 't.id', 'ASC' )
            ->setMaxResults( (int) $limit )
            ->getQuery()->getResult();
    }

    /**
     * Atomically claim a task: PENDING -> RUNNING. Returns true only if this
     * call won the row (affected exactly one row). Mirrors ArchiveController's
     * _archiveStateChange guard but at the SQL level so it is race-safe.
     *
     * @param \Entities\MailboxTask $task
     * @return bool
     */
    public function claim( \Entities\MailboxTask $task )
    {
        $conn = $this->getEntityManager()->getConnection();
        $affected = $conn->executeStatement(
            'UPDATE mailbox_task SET status = :running, started_at = :now'
            . ' WHERE id = :id AND status = :pending',
            [
                'running' => \Entities\MailboxTask::STATUS_RUNNING,
                'now'     => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
                'id'      => $task->getId(),
                'pending' => \Entities\MailboxTask::STATUS_PENDING,
            ]
        );

        if( $affected === 1 )
        {
            // Refresh the managed entity so in-memory state matches the DB.
            $this->getEntityManager()->refresh( $task );
            return true;
        }
        return false;
    }

    /**
     * Counts of tasks grouped by status (for the queue tab summary).
     *
     * @return array<string,int>
     */
    public function statusCounts()
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select( 't.status as status, COUNT(t.id) as cnt' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->groupBy( 't.status' )
            ->getQuery()->getArrayResult();

        $out = [];
        foreach( $rows as $r )
            $out[ $r['status'] ] = (int) $r['cnt'];
        return $out;
    }
}
