<?php

namespace Repositories;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use UnexpectedValueException;

/**
 * MailboxTask repository.
 *
 * Holds the queue-claim logic: a single atomic UPDATE flips a task from
 * PENDING to RUNNING and returns whether *this* runner won the race, so two
 * concurrent runners never process the same task.
 *
 * @extends EntityRepository<\Entities\MailboxTask>
 */
class MailboxTask extends EntityRepository
{
    /** Atomically cancel only a task that is still pending. */
    public function cancelIfPending(\Entities\MailboxTask $task, string $actor): bool
    {
        $message = '[' . gmdate('Y-m-d H:i:s') . '] cancelled by ' . $actor . "\n";
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE mailbox_task SET status = :cancelled, finished_at = CURRENT_TIMESTAMP,'
            . " log = CONCAT(COALESCE(log, ''), :message)"
            . ' WHERE id = :id AND status = :pending',
            [
                'cancelled' => \Entities\MailboxTask::STATUS_CANCELLED,
                'message' => $message,
                'id' => $task->getId(),
                'pending' => \Entities\MailboxTask::STATUS_PENDING,
            ]
        );
        if (!is_int($affected) || $affected < 0 || $affected > 1) {
            throw new UnexpectedValueException('Mailbox task cancel returned an invalid affected-row count.');
        }
        if ($affected === 1) {
            $this->getEntityManager()->refresh($task);
        }
        return $affected === 1;
    }

    /**
     * Mark abandoned RUNNING tasks terminal. The guarded bulk update makes the
     * transition race-safe with a runner completing at the same time. Ownership
     * is decided by the foreign-key-backed lease, never by task age.
     */
    public function reapStaleRunning(): int
    {
        $message = '[' . gmdate('Y-m-d H:i:s') . "] FAILED: runner exited before task completion\n";
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE mailbox_task t LEFT JOIN queue_runner r ON r.id = t.QueueRunner_id'
            . ' SET t.status = :failed, t.abandoned = 1, t.finished_at = CURRENT_TIMESTAMP,'
            . " t.log = CONCAT(COALESCE(t.log, ''), :message)"
            . ' WHERE t.status = :running AND r.id IS NULL',
            [
                'failed'  => \Entities\MailboxTask::STATUS_FAILED,
                'message' => $message,
                'running' => \Entities\MailboxTask::STATUS_RUNNING,
            ]
        );

        if (!is_int($affected) || $affected < 0) {
            throw new UnexpectedValueException('Stale mailbox task reaper returned an invalid affected-row count.');
        }
        return $affected;
    }

    /**
     * Atomically delete a terminal/pending task, or an ownerless RUNNING task.
     * A task whose runner lease still exists cannot pass this database guard.
     */
    public function deleteUnlessActive(\Entities\MailboxTask $task): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE t FROM mailbox_task t LEFT JOIN queue_runner r ON r.id = t.QueueRunner_id'
            . ' WHERE t.id = :id AND (t.status <> :running OR r.id IS NULL)',
            [
                'id'      => $task->getId(),
                'running' => \Entities\MailboxTask::STATUS_RUNNING,
            ]
        );
        if (!is_int($affected) || $affected < 0 || $affected > 1) {
            throw new UnexpectedValueException('Mailbox task delete returned an invalid affected-row count.');
        }
        if ($affected === 1) {
            $this->getEntityManager()->detach($task);
        }
        return $affected === 1;
    }

    /**
     * Publish terminal ORM state only while the task still names this live
     * lease. The task row lock also fences concurrent FK nulling/lease reap.
     *
     * @param callable():void $publish
     */
    public function publishIfOwned(
        \Entities\MailboxTask $task,
        \Entities\QueueRunner $runner,
        callable $publish
    ): bool {
        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $runnerId = $runner->getId();
            if (!is_int($runnerId) || $runnerId < 1) {
                throw new \LogicException('Runner lease must be persisted before terminal publication.');
            }
            $liveOwner = $connection->fetchOne(
                'SELECT id FROM queue_runner WHERE id = ? FOR UPDATE',
                [ $runnerId ]
            );
            if ($liveOwner !== $runnerId && $liveOwner !== (string) $runnerId) {
                $connection->rollBack();
                return false;
            }
            $owner = $connection->fetchOne(
                'SELECT t.QueueRunner_id FROM mailbox_task t'
                . ' WHERE t.id = ? AND t.status = ? FOR UPDATE',
                [ $task->getId(), \Entities\MailboxTask::STATUS_RUNNING ]
            );
            $owned = $owner === $runnerId;
            if (is_string($owner) && preg_match('/^[1-9][0-9]*$/D', $owner) === 1) {
                $owned = filter_var($owner, FILTER_VALIDATE_INT) === $runnerId;
            }
            if (!$owned) {
                $connection->rollBack();
                return false;
            }
            $publish();
            $this->getEntityManager()->flush();
            $connection->commit();
            return true;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,int> */
    private static function requiredStatusCounts(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('Mailbox task status query result must be an array.');
        }

        $counts = [];
        foreach ($rows as $key => $row) {
            if (!is_int($key) || !is_array($row) || count($row) !== 2
                || !isset($row['status']) || !is_string($row['status'])
                || !array_key_exists('cnt', $row)) {
                throw new \UnexpectedValueException('Mailbox task status query row has an invalid shape.');
            }

            $rawCount = $row['cnt'];
            if (is_int($rawCount) && $rawCount >= 0) {
                $count = $rawCount;
            } elseif (is_string($rawCount) && preg_match('/^(0|[1-9][0-9]*)$/D', $rawCount) === 1) {
                $count = filter_var($rawCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count === false) {
                    throw new \UnexpectedValueException('Mailbox task status query row has an invalid count.');
                }
            } else {
                throw new \UnexpectedValueException('Mailbox task status query row has an invalid count.');
            }
            if (array_key_exists($row['status'], $counts)) {
                throw new \UnexpectedValueException('Mailbox task status query returned a duplicate status.');
            }
            $counts[$row['status']] = $count;
        }
        return $counts;
    }

    /**
     * Fetch the oldest PENDING tasks (highest priority first), up to $limit.
     *
     * @param int $limit
     * @return \Entities\MailboxTask[]
     */
    public function pending($limit = 5)
    {
        return \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $this->pendingQuery((int) $limit)->getQuery()->getResult(),
            \Entities\MailboxTask::class,
            'Pending mailbox task query'
        );
    }

    private function pendingQuery(int $limit): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from('\\Entities\\MailboxTask', 't')
            ->where('t.status = :s')
            ->setParameter('s', \Entities\MailboxTask::STATUS_PENDING)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limit);
    }

    /**
     * Atomically claim a task: PENDING -> RUNNING. Returns true only if this
     * call won the row (affected exactly one row). Mirrors ArchiveController's
     * _archiveStateChange guard but at the SQL level so it is race-safe.
     *
     * @param \Entities\MailboxTask $task
     * @return bool
     */
    public function claim(\Entities\MailboxTask $task, \Entities\QueueRunner $runner)
    {
        $conn = $this->getEntityManager()->getConnection();
        $affected = $conn->executeStatement(
            'UPDATE mailbox_task SET status = :running, abandoned = 0,'
            . ' started_at = CURRENT_TIMESTAMP, QueueRunner_id = :runner'
            . ' WHERE id = :id AND status = :pending',
            [
                'running' => \Entities\MailboxTask::STATUS_RUNNING,
                'runner'  => $runner->getId(),
                'id'      => $task->getId(),
                'pending' => \Entities\MailboxTask::STATUS_PENDING,
            ]
        );

        if ($affected === 1) {
            // Refresh the managed entity so in-memory state matches the DB.
            $this->getEntityManager()->refresh($task);
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
            ->select('t.status as status, COUNT(t.id) as cnt')
            ->from('\\Entities\\MailboxTask', 't')
            ->groupBy('t.status')
            ->getQuery()->getArrayResult();

        return self::requiredStatusCounts($rows);
    }
}
