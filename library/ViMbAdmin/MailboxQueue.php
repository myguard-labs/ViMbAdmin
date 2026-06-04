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
 * Helper for enqueueing mailbox-task queue jobs.
 *
 * Lives in the library (autoloaded via the ViMbAdmin_ prefix) rather than on
 * QueueController, because ZF1 does not autoload controller classes by name —
 * calling QueueController::enqueue() from another controller throws
 * "Class QueueController not found". Controllers call this instead.
 */
class ViMbAdmin_MailboxQueue
{
    /**
     * Queue a task for a mailbox. Refuses to stack a second open
     * (PENDING/RUNNING) task of the same type for the same username.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\Mailbox $mailbox
     * @param string $type    One of MailboxTask::TYPE_*
     * @param \Entities\Admin|null $by
     * @param int $priority
     * @return \Entities\MailboxTask|null  null if an open task already exists
     */
    public static function enqueue( $em, \Entities\Mailbox $mailbox, $type, $by = null, $priority = 0 )
    {
        $existing = $em->createQueryBuilder()
            ->select( 't' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->where( 't.username = :u' )
            ->andWhere( 't.type = :t' )
            ->andWhere( 't.status IN (:open)' )
            ->setParameter( 'u', $mailbox->getUsername() )
            ->setParameter( 't', $type )
            ->setParameter( 'open', [ \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING ] )
            ->setMaxResults( 1 )
            ->getQuery()->getOneOrNullResult();

        if( $existing )
            return null;

        $task = new \Entities\MailboxTask();
        $task->setType( $type )
             ->setUsername( $mailbox->getUsername() )
             ->setStatus( \Entities\MailboxTask::STATUS_PENDING )
             ->setPriority( (int) $priority )
             ->setCreatedAt( new \DateTime() )
             ->setDomain( $mailbox->getDomain() )
             ->setRequestedBy( $by );
        $em->persist( $task );
        return $task;
    }
}
