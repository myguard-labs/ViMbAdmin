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

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} ran the mailbox-task queue ({$n} task(s))" );

        $this->addMessage( sprintf( _( 'Queue run complete — %d task(s) processed.' ), $n ),
            $n > 0 ? OSS_Message::SUCCESS : OSS_Message::INFO );
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

    // =====================================================================
    //  CLI runner (vimbtool / local cron)
    // =====================================================================

    public function cliRunAction()
    {
        $max = (int) ( $this->_options['queue']['runner']['max_per_run'] ?? 5 );
        $n   = $this->_drain( $max );
        if( $this->getParam( 'verbose' ) )
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

        $max = (int) ( $this->_options['queue']['runner']['max_per_run'] ?? 5 );
        $n   = $this->_drain( $max );
        return $this->_json( 200, [ 'processed' => $n ] );
    }

    /**
     * Is $ip within queue.runner.allowed_ips (comma/space separated CIDRs)?
     *
     * @param string $ip
     * @return bool
     */
    private function _ipAllowed( $ip )
    {
        $raw = (string) ( $this->_options['queue']['runner']['allowed_ips'] ?? '' );
        $list = preg_split( '/[\s,]+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
        if( !count( $list ) )
            return false; // empty = deny all
        foreach( $list as $cidr )
            if( ViMbAdmin_Net::ipInCidr( $ip, $cidr ) )
                return true;
        return false;
    }

    // =====================================================================
    //  Worker core
    // =====================================================================

    /**
     * Claim and execute up to $max PENDING tasks. Returns the count processed.
     *
     * @param int $max
     * @return int
     */
    private function _drain( $max )
    {
        $max  = max( 1, (int) $max );
        $em   = $this->getD2EM();
        $repo = $em->getRepository( '\\Entities\\MailboxTask' );

        $processed = 0;
        foreach( $repo->pending( $max ) as $task )
        {
            // Atomic PENDING -> RUNNING; skip if another runner won the row.
            if( !$repo->claim( $task ) )
                continue;

            try
            {
                $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->_options );
                $this->_execute( $task, $doveadm );
                $task->setStatus( \Entities\MailboxTask::STATUS_DONE );
                $task->appendLog( 'done' );
            }
            catch( \Throwable $e )
            {
                $task->setStatus( \Entities\MailboxTask::STATUS_FAILED );
                $task->appendLog( 'FAILED: ' . $e->getMessage() );
                $this->getLogger()->err( "QueueController task {$task->getId()} ({$task->getType()} {$task->getUsername()}): " . $e->getMessage() );
            }

            $task->setFinishedAt( new \DateTime() );
            $em->flush();

            if( $this->getParam( 'verbose' ) )
                echo " - #{$task->getId()} {$task->getType()} {$task->getUsername()}: {$task->getStatus()}\n";

            $processed++;
        }
        return $processed;
    }

    /**
     * Execute one task against doveadm. Throws on failure (caught by _drain).
     *
     * @param \Entities\MailboxTask $task
     * @param ViMbAdmin_Doveadm $doveadm
     */
    private function _execute( \Entities\MailboxTask $task, ViMbAdmin_Doveadm $doveadm )
    {
        $user = $task->getUsername();

        switch( $task->getType() )
        {
            case \Entities\MailboxTask::TYPE_REPAIR:
            case \Entities\MailboxTask::TYPE_OPTIMIZE:
                $task->appendLog( 'force-resync' );  $doveadm->forceResync( $user );
                $task->appendLog( 'index' );          $doveadm->index( $user );
                $task->appendLog( 'purge' );          $doveadm->purge( $user );
                $task->appendLog( 'quota recalc' );   $doveadm->quotaRecalc( $user );
                break;

            case \Entities\MailboxTask::TYPE_ARCHIVE:
                // backup first; only empty the store if the backup succeeded.
                $dest = $this->_backupDest( $task );
                $task->appendLog( "backup -> {$dest}" );
                $doveadm->backup( $user, $dest );
                $task->appendLog( 'mailbox delete (empty store, keep account)' );
                $doveadm->mailboxDelete( $user );
                // Account row is intentionally KEPT.
                break;

            case \Entities\MailboxTask::TYPE_DELETE:
                // backup (safety) -> empty store -> remove the ViMbAdmin row.
                $dest = $this->_backupDest( $task );
                $task->appendLog( "backup -> {$dest}" );
                $doveadm->backup( $user, $dest );
                $task->appendLog( 'mailbox delete (empty store)' );
                $doveadm->mailboxDelete( $user );
                $task->appendLog( 'removing ViMbAdmin mailbox row' );
                $this->_removeMailboxRow( $user );
                break;

            default:
                throw new ViMbAdmin_Exception( 'unknown task type: ' . $task->getType() );
        }
    }

    /**
     * Expand the backup destination template (%d domain, %u username).
     *
     * @param \Entities\MailboxTask $task
     * @return string
     */
    private function _backupDest( \Entities\MailboxTask $task )
    {
        $tpl  = (string) ( $this->_options['doveadm']['backup']['dest'] ?? 'maildir:/srv/vmail-backup/%d/%u' );
        $user = $task->getUsername();
        $dom  = $task->getDomain() ? $task->getDomain()->getDomain() : ( strstr( $user, '@' ) ? substr( strrchr( $user, '@' ), 1 ) : '' );
        return str_replace( [ '%d', '%u' ], [ $dom, $user ], $tpl );
    }

    /**
     * Remove the ViMbAdmin mailbox row + its aliases/prefs (full teardown).
     * Reuses Repositories\Mailbox::purgeMailbox so alias/preference cleanup and
     * the domain mailbox-count are handled consistently with the panel.
     *
     * @param string $username
     */
    private function _removeMailboxRow( $username )
    {
        $em      = $this->getD2EM();
        $mailbox = $em->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $username ] );
        if( !$mailbox )
            return; // already gone — nothing to do

        // removeMailbox=true; admin=null means skip the per-admin ownership
        // check (the runner is trusted system context). purgeMailbox() already
        // removes aliases/preferences and decrements the domain mailbox count,
        // so we must NOT decrement it again here.
        $em->getRepository( '\\Entities\\Mailbox' )->purgeMailbox( $mailbox, null, true );
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
