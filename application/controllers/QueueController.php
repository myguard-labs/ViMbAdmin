<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Mailbox-task queue.
 *
 * Tasks (repair/optimize/archive/delete) are queued in the mailbox_task table
 * and executed serially against the Dovecot doveadm HTTP API by a single
 * runner, so a bulk action cannot fire hundreds of doveadm calls at once.
 *
 * Entry points to the runner:
 *   - index            super-admin web UI: queue tab (list + "run now" button)
 *   - run-now          super-admin web POST: drain synchronously (CSRF guarded)
 *   - cli-run          vimbtool / local cron: drain up to queue.runner.max_per_run
 *   - trigger          remote cron web endpoint: key + IP gated, then drains
 *   - cancel           super-admin web POST: cancel a PENDING task
 *
 * Enqueueing is done by MailboxController / MaintenanceController via the static
 * QueueController::enqueue() helper.
 */
class QueueController extends ViMbAdmin_Controller_Action
{
    public function preDispatch()
    {
        $action = $this->getRequest()->getActionName();

        // The remote trigger and the CLI runner are NOT session-authenticated.
        if( in_array( $action, [ 'trigger', 'cli-run' ], true ) )
        {
            $this->_helper->viewRenderer->setNoRender( true );
            try { $this->_helper->layout()->disableLayout(); } catch( \Exception $e ) {}
            return;
        }

        // Everything else is a super-admin-only UI action.
        $this->authorise( true );
    }

    // =====================================================================
    //  Enqueue helper — delegates to ViMbAdmin_MailboxQueue (the loadable lib;
    //  ZF1 does not autoload controller classes by name, so other controllers
    //  must call the library, not this).
    // =====================================================================

    public static function enqueue( $em, \Entities\Mailbox $mailbox, $type, $by = null, $priority = 0 )
    {
        return ViMbAdmin_MailboxQueue::enqueue( $em, $mailbox, $type, $by, $priority );
    }

    // =====================================================================
    //  UI: queue tab
    // =====================================================================

    public function indexAction()
    {
        $repo = $this->getD2EM()->getRepository( '\\Entities\\MailboxTask' );

        $this->view->counts = $repo->statusCounts();
        $this->view->tasks  = $this->getD2EM()->createQueryBuilder()
            ->select( 't' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->orderBy( 't.id', 'DESC' )
            ->setMaxResults( 200 )
            ->getQuery()->getResult();
        $this->view->cancellable = \Entities\MailboxTask::STATUS_PENDING;
    }

    /**
     * Drain the queue synchronously from the UI ("Run queue now").
     */
    public function runNowAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $n = $this->_drain( (int) ( $this->_options['queue']['runner']['max_per_run'] ?? 5 ) );

        if( $n < 0 )
        {
            $this->addMessage( _( 'A queue runner is already active (max_concurrent reached) — it will pick up the work.' ), OSS_Message::INFO );
            $this->redirect( 'queue/index' );
        }

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} ran the mailbox-task queue ({$n} task(s))" );

        $this->addMessage( sprintf( _( 'Queue run complete — %d task(s) processed.' ), $n ),
            $n > 0 ? OSS_Message::SUCCESS : OSS_Message::INFO );
        $this->redirect( 'queue/index' );
    }

    /**
     * Run a single PENDING task now (synchronously). CSRF + POST guarded.
     */
    public function runTaskAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $em   = $this->getD2EM();
        $repo = $em->getRepository( '\\Entities\\MailboxTask' );
        $task = $repo->find( (int) $this->getParam( 'id', 0 ) );

        if( !$task || $task->getStatus() !== \Entities\MailboxTask::STATUS_PENDING )
        {
            $this->addMessage( _( 'Task not found or not pending.' ), OSS_Message::ERROR );
            $this->redirect( 'queue/index' );
        }

        // Atomic PENDING -> RUNNING; bail if a background runner grabbed it.
        if( !$repo->claim( $task ) )
        {
            $this->addMessage( _( 'Task is already being processed.' ), OSS_Message::INFO );
            $this->redirect( 'queue/index' );
        }

        try
        {
            $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->_options );
            $this->_execute( $task, $doveadm );
            $task->setStatus( \Entities\MailboxTask::STATUS_DONE );
            $task->appendLog( 'done (run-now by ' . $this->getAdmin()->getFormattedName() . ')' );
            $this->addMessage( sprintf( _( 'Task #%d completed.' ), $task->getId() ), OSS_Message::SUCCESS );
        }
        catch( \Throwable $e )
        {
            $task->setStatus( \Entities\MailboxTask::STATUS_FAILED );
            $task->appendLog( 'FAILED: ' . $e->getMessage() );
            $this->getLogger()->err( "QueueController run-now task {$task->getId()}: " . $e->getMessage() );
            $this->addMessage( sprintf( _( 'Task #%d failed: %s' ), $task->getId(), $e->getMessage() ), OSS_Message::ERROR );
        }

        $task->setFinishedAt( new \DateTime() );
        $em->flush();
        $this->redirect( 'queue/index' );
    }

    /**
     * Retry a FAILED task: reset it to PENDING so the runner picks it up again.
     * CSRF + POST guarded.
     */
    public function retryAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $task = $this->getD2EM()->getRepository( '\\Entities\\MailboxTask' )
            ->find( (int) $this->getParam( 'id', 0 ) );

        if( $task && $task->getStatus() === \Entities\MailboxTask::STATUS_FAILED )
        {
            $task->setStatus( \Entities\MailboxTask::STATUS_PENDING )
                 ->setFinishedAt( null )
                 ->appendLog( 'retry queued by ' . $this->getAdmin()->getFormattedName() );
            $this->getD2EM()->flush();
            $this->addMessage( _( 'Task re-queued.' ), OSS_Message::SUCCESS );
        }
        else
        {
            $this->addMessage( _( 'Task not found or not in a failed state.' ), OSS_Message::ERROR );
        }
        $this->redirect( 'queue/index' );
    }

    /**
     * Cancel a PENDING task.
     */
    public function cancelAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $task = $this->getD2EM()->getRepository( '\\Entities\\MailboxTask' )
            ->find( (int) $this->getParam( 'id', 0 ) );

        if( $task && $task->getStatus() === \Entities\MailboxTask::STATUS_PENDING )
        {
            $task->setStatus( \Entities\MailboxTask::STATUS_CANCELLED )
                 ->setFinishedAt( new \DateTime() )
                 ->appendLog( 'cancelled by ' . $this->getAdmin()->getFormattedName() );
            $this->getD2EM()->flush();
            $this->addMessage( _( 'Task cancelled.' ), OSS_Message::SUCCESS );
        }
        else
        {
            $this->addMessage( _( 'Task not found or not cancellable.' ), OSS_Message::ERROR );
        }
        $this->redirect( 'queue/index' );
    }

    /**
     * Delete a single task row (any status except RUNNING — never remove a task
     * mid-execution). For cleaning up DONE/FAILED/CANCELLED/PENDING entries.
     */
    public function deleteAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $task = $this->getD2EM()->getRepository( '\\Entities\\MailboxTask' )
            ->find( (int) $this->getParam( 'id', 0 ) );

        if( $task && $task->getStatus() !== \Entities\MailboxTask::STATUS_RUNNING )
        {
            $this->getD2EM()->remove( $task );
            $this->getD2EM()->flush();
            $this->addMessage( _( 'Task deleted.' ), OSS_Message::SUCCESS );
        }
        else
        {
            $this->addMessage( _( 'Task not found, or it is currently running.' ), OSS_Message::ERROR );
        }
        $this->redirect( 'queue/index' );
    }

    /**
     * Bulk-delete all finished tasks (DONE / FAILED / CANCELLED). Leaves
     * PENDING and RUNNING untouched.
     */
    public function clearAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'queue/index' );

        $n = (int) $this->getD2EM()->createQuery(
            'DELETE FROM \Entities\MailboxTask t WHERE t.status IN (:done)' )
            ->setParameter( 'done', [
                \Entities\MailboxTask::STATUS_DONE,
                \Entities\MailboxTask::STATUS_FAILED,
                \Entities\MailboxTask::STATUS_CANCELLED,
            ] )
            ->execute();

        $this->addMessage( sprintf( _( 'Cleared %d finished task(s).' ), $n ), OSS_Message::SUCCESS );
        $this->redirect( 'queue/index' );
    }

    // =====================================================================
    //  CLI runner (vimbtool / local cron)
    // =====================================================================

    public function cliRunAction()
    {
        $max = (int) ( $this->_options['queue']['runner']['max_per_run'] ?? 5 );
        $n   = $this->_drain( $max );
        if( $this->getParam( 'verbose' ) && $n >= 0 )
            echo "Processed {$n} task(s).\n";
    }

    // =====================================================================
    //  Remote trigger (off-box cron) — key + IP gated
    // =====================================================================

    public function triggerAction()
    {
        $key = (string) ( $this->_options['queue']['runner']['key'] ?? '' );
        if( $key === '' )
            return $this->_json( 404, [ 'error' => 'queue trigger disabled' ] );

        // Bearer key (compared by SHA-256, constant-time).
        $auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if( !preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) )
            return $this->_json( 401, [ 'error' => 'missing bearer' ] );
        if( !hash_equals( hash( 'sha256', $key ), hash( 'sha256', trim( $m[1] ) ) ) )
            return $this->_json( 403, [ 'error' => 'bad key' ] );

        // IP allowlist (reuse the same proxy-aware resolver + CIDR check the MCP
        // endpoint uses).
        $proxy = $this->_options['trustedproxy'] ?? [];
        $ip    = ViMbAdmin_Net::clientIp( $_SERVER,
            isset( $proxy['mode'] ) ? $proxy['mode'] : 'auto',
            isset( $proxy['proxies'] ) ? (array) $proxy['proxies'] : [] );

        if( !$this->_ipAllowed( $ip ) )
            return $this->_json( 403, [ 'error' => "source IP {$ip} not allowed" ] );

        // Non-blocking: spawn a background runner if there is work and a slot is
        // free, then return immediately (don't make the cron/curl wait).
        $spawned = ViMbAdmin_QueueRunner::triggerCheck( $this->getD2EM(), $this->_options );
        return $this->_json( 200, [ 'triggered' => $spawned ] );
    }

    /**
     * Is $ip within queue.runner.allowed_ips (comma/space separated CIDRs)?
     *
     * @param string $ip
     * @return bool
     */
    private function _ipAllowed( $ip )
    {
        // empty list = deny all (ipInList returns false on empty, matching).
        $raw = (string) ( $this->_options['queue']['runner']['allowed_ips'] ?? '' );
        return ViMbAdmin_Net::ipInList( $ip, $raw );
    }

    // =====================================================================
    //  Worker core — delegates to the framework-free ViMbAdmin_Service_QueueRunner
    //  (the engine is shared with the native kernel QueueController; see
    //  docs/ZF1-REMOVAL.md). The per-task execution + batch drain + their helpers
    //  now live in library/ViMbAdmin/Service/QueueRunner.php.
    // =====================================================================

    /**
     * Lease-gated batch drain (run-now / cli-run): process up to $max PENDING
     * tasks. Returns the count processed, or -1 if throttled.
     *
     * @param int $max
     * @return int
     */
    private function _drain( $max )
    {
        return ( new ViMbAdmin_Service_QueueRunner( $this->getD2EM(), $this->_options ) )
            ->drain( (int) $max, (bool) $this->getParam( 'verbose' ) );
    }

    /**
     * Execute one already-claimed task (run-task). Throws on failure; the caller
     * records DONE/FAILED. The $doveadm argument is retained for signature
     * compatibility but the service builds its own from the options.
     */
    private function _execute( \Entities\MailboxTask $task, ViMbAdmin_Doveadm $doveadm )
    {
        ( new ViMbAdmin_Service_QueueRunner( $this->getD2EM(), $this->_options ) )->runOne( $task );
    }

    // =====================================================================
    //  helpers
    // =====================================================================

    /**
     * Emit a JSON response with an HTTP status and stop.
     *
     * @param int   $code
     * @param array $payload
     */
    private function _json( $code, array $payload )
    {
        $this->getResponse()
            ->setHttpResponseCode( $code )
            ->setHeader( 'Content-Type', 'application/json', true )
            ->setBody( json_encode( $payload ) );
        return;
    }
}
