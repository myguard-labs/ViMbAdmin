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
        if( in_array( $action, [ 'trigger', 'cli-run', 'cli-set-size', 'cli-list-unsized' ], true ) )
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

    /**
     * CLI: record the REAL on-disk (compressed) size of an archive backup.
     *
     * The compressed footprint can only be read with `du` on the Dovecot
     * filesystem (the doveadm HTTP API exposes no recursive-size command). The
     * host cron (contrib/cron/vimbadmin-archive-size.sh) does the `du` inside
     * the dovecot container, then calls this to persist it — vimbadmin owns the
     * DB, dovecot owns the filesystem, neither needs the other's access.
     *
     *   vimbtool.php -a queue.cli-set-size --id=<archiveId> --bytes=<n>
     *
     * @return void
     */
    public function cliSetSizeAction()
    {
        $id    = (int) $this->getParam( 'id', 0 );
        $bytes = $this->getParam( 'bytes', null );

        if( $id <= 0 || $bytes === null || !ctype_digit( (string) $bytes ) )
        {
            echo "ERROR: --id=<archiveId> and --bytes=<n> are required.\n";
            return;
        }

        $archive = $this->getD2EM()->getRepository( '\\Entities\\Archive' )->find( $id );
        if( !$archive )
        {
            echo "ERROR: archive {$id} not found.\n";
            return;
        }

        $archive->setMaildirSize( (int) $bytes );
        $this->getD2EM()->flush();

        if( $this->getParam( 'verbose' ) )
            echo "archive {$id}: maildir_size = {$bytes}\n";
    }

    /**
     * CLI: list archives whose real on-disk size hasn't been measured yet, as
     * `<id><TAB><maildir_file>` lines, for the host size-cron to `du` + persist.
     * "Not measured" = maildir_size is NULL or still equals the logical
     * maildir_orig_size fallback. Session-exempt (CLI only).
     *
     *   vimbtool.php -a queue.cli-list-unsized
     *
     * @return void
     */
    public function cliListUnsizedAction()
    {
        $rows = $this->getD2EM()->getConnection()->fetchAllAssociative(
            'SELECT id, maildir_file FROM archive
              WHERE maildir_file IS NOT NULL AND maildir_file <> \'\'
                AND ( maildir_size IS NULL OR maildir_size = maildir_orig_size )' );
        foreach( $rows as $r )
            echo $r['id'] . "\t" . $r['maildir_file'] . "\n";
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

        // Concurrency cap: take a runner lease. If every slot
        // (queue.runner.max_concurrent) is busy, do nothing and report -1 so the
        // caller knows it was throttled rather than "no work".
        $lease = ViMbAdmin_QueueRunner::acquireLease( $em, $this->_options );
        if( $lease === null )
        {
            if( $this->getParam( 'verbose' ) )
                echo "All runner slots busy (queue.runner.max_concurrent) — skipping.\n";
            return -1;
        }

        $processed = 0;
        try
        {
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

                // Keep the lease fresh through a long run so it isn't reaped.
                ViMbAdmin_QueueRunner::heartbeat( $em, $lease );

                if( $this->getParam( 'verbose' ) )
                    echo " - #{$task->getId()} {$task->getType()} {$task->getUsername()}: {$task->getStatus()}\n";

                $processed++;
            }
        }
        finally
        {
            ViMbAdmin_QueueRunner::release( $em, $lease );
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

            case \Entities\MailboxTask::TYPE_QUOTA_RECALC:
                $task->appendLog( 'quota recalc' );   $doveadm->quotaRecalc( $user );
                break;

            case \Entities\MailboxTask::TYPE_ARCHIVE:
                // backup first; only empty the store if the backup succeeded.
                // An explicit archive is kept indefinitely (autoprune off) — the
                // admin asked to archive it, not to let it expire.
                $dest = $this->_backupDest( $task );
                $task->appendLog( "backup -> {$dest}" );
                $doveadm->backup( $user, $dest );
                $task->appendLog( 'recording archive row' );
                $this->_recordArchive( $task, $dest, false );
                $task->appendLog( 'mailbox delete (empty store, keep account)' );
                $doveadm->mailboxDelete( $user );
                // Account row is intentionally KEPT.
                $this->_logAudit( $task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "archived {$user} (backup {$dest}, store emptied, account kept)" );
                break;

            case \Entities\MailboxTask::TYPE_DELETE:
                // queue.autoprune.days == 0 => "instant": no backup is taken,
                // the store is emptied and the account removed immediately.
                if( $this->_autopruneDays() === 0 )
                {
                    $task->appendLog( 'autoprune.days=0 — instant delete, no backup' );
                    $doveadm->mailboxDelete( $user );
                    $task->appendLog( 'removing ViMbAdmin mailbox row' );
                    $this->_removeMailboxRow( $user );
                    $this->_logAudit( $task, \Entities\Log::ACTION_MAILBOX_PURGE,
                        "deleted {$user} (instant, autoprune.days=0 — no backup)" );
                    break;
                }

                // Otherwise: backup (safety) -> record an autoprune-on archive
                // row (so it expires after queue.autoprune.days) -> empty store
                // -> remove the ViMbAdmin row.
                $dest = $this->_backupDest( $task );
                $task->appendLog( "backup -> {$dest}" );
                $doveadm->backup( $user, $dest );
                $task->appendLog( 'recording archive row (autoprune on)' );
                $this->_recordArchive( $task, $dest, true );
                $task->appendLog( 'mailbox delete (empty store)' );
                $doveadm->mailboxDelete( $user );
                $task->appendLog( 'removing ViMbAdmin mailbox row' );
                $this->_removeMailboxRow( $user );
                $this->_logAudit( $task, \Entities\Log::ACTION_MAILBOX_PURGE,
                    "deleted {$user} (backup {$dest}, autoprune on — prunes after queue.autoprune.days)" );
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
     * Auto-prune window in days, from application.ini queue.autoprune.days.
     * 0  = "instant": delete tasks take no backup and remove immediately.
     * >0 = keep the backup this many days past archived_at, then prune.
     * Default 90 when unset.
     *
     * @return int
     */
    private function _autopruneDays()
    {
        $v = $this->_options['queue']['autoprune']['days'] ?? 90;
        return max( 0, (int) $v );
    }

    /**
     * Upsert the `archive` row that backs a queue ARCHIVE / DELETE backup, so
     * the backup shows on the Archives page. username is UNIQUE, so a repeat
     * archive of the same address updates the existing row rather than failing.
     *
     * @param \Entities\MailboxTask $task
     * @param string $dest      the doveadm backup destination (maildir:/...)
     * @param bool   $autoprune whether this backup expires automatically
     * @return void
     */
    /**
     * Write an audit row to the Log table from the queue runner. The runner is
     * CLI (no web session), so we attribute the action to the admin who queued
     * the task (task.requestedBy), which may be null for system/MCP-initiated
     * work. Best-effort: a logging failure must never abort the task.
     *
     * @param \Entities\MailboxTask $task
     * @param string $action  a \Entities\Log::ACTION_* constant
     * @param string $message
     * @return void
     */
    private function _logAudit( \Entities\MailboxTask $task, $action, $message )
    {
        try
        {
            $em  = $this->getD2EM();
            $log = new \Entities\Log();
            $log->setAction( $action )
                ->setData( $message )
                ->setTimestamp( new \DateTime() );
            if( method_exists( $task, 'getRequestedBy' ) && $task->getRequestedBy() )
                $log->setAdmin( $task->getRequestedBy() );
            if( method_exists( $task, 'getDomain' ) && $task->getDomain() )
                $log->setDomain( $task->getDomain() );
            $em->persist( $log );
            $em->flush();
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'QueueController::_logAudit: ' . $e->getMessage() );
        }
    }

    private function _recordArchive( \Entities\MailboxTask $task, $dest, $autoprune )
    {
        $em   = $this->getD2EM();
        $user = $task->getUsername();
        $now  = new \DateTime();

        $archive = $em->getRepository( '\\Entities\\Archive' )->findOneBy( [ 'username' => $user ] );
        if( !$archive )
        {
            $archive = new \Entities\Archive();
            $archive->setUsername( $user );
        }

        $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->_options );

        // LOGICAL mailbox size (uncompressed) from the Dovecot quota-clone,
        // refreshed so it's current — kept as maildir_orig_size for reference.
        $origSize = null;
        try
        {
            $doveadm->quotaRecalc( $user );
            $bytes = $em->getConnection()->fetchOne(
                'SELECT bytes FROM dovecot_quota WHERE username = ?', [ $user ] );
            if( $bytes !== false && $bytes !== null )
                $origSize = (int) $bytes;
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( "QueueController::_recordArchive quota {$user}: " . $e->getMessage() );
        }

        // maildir_size is seeded with the logical size now (instant), then the
        // host size-cron (contrib/cron/vimbadmin-archive-size.sh) replaces it
        // with the REAL on-disk COMPRESSED footprint via `du` on the Dovecot
        // box — the doveadm HTTP API has no fast recursive-size command, so we
        // don't block the archive task on it. Until the cron runs, the column
        // shows the logical size (a safe over-estimate).
        $size = $origSize;

        // Capture the full mailbox attributes (incl the password HASH) while the
        // row still exists, so a later restore of a DELETE'd account can recreate
        // it losslessly. ARCHIVE keeps the account, so this is belt-and-braces
        // there; for DELETE it's the only record of the mailbox.
        $mbData = null;
        $mb = $em->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $user ] );
        if( $mb )
            $mbData = [
                'username'   => $mb->getUsername(),
                'local_part' => $mb->getLocalPart(),
                'name'       => $mb->getName(),
                'password'   => $mb->getPassword(),
                'quota'      => $mb->getQuota(),
                'homedir'    => $mb->getHomedir(),
                'maildir'    => $mb->getMaildir(),
                'uid'        => $mb->getUid(),
                'gid'        => $mb->getGid(),
                'active'     => $mb->getActive(),
            ];

        $archive->setStatus( \Entities\Archive::STATUS_ARCHIVED )
                ->setArchivedAt( $now )
                ->setStatusChangedAt( $now )
                ->setArchivedBy( $task->getRequestedBy() )
                ->setDomain( $task->getDomain() )
                ->setMaildirServer( (string) ( $this->_options['doveadm']['http']['url'] ?? '' ) )
                ->setMaildirFile( $dest )
                ->setMaildirOrigSize( $origSize )
                ->setMaildirSize( $size )
                ->setAutoprune( $autoprune )
                ->setData( json_encode( [
                    'username' => $user,
                    'type'     => $task->getType(),
                    'task_id'  => $task->getId(),
                    'dest'     => $dest,
                    'mailbox'  => $mbData,
                ] ) );

        $em->persist( $archive );
        // flushed by _drain() after _execute returns.
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

        // Drop the live-usage row written by Dovecot's quota-clone plugin.
        // `dovecot_quota` is a read-only, dedicated table with NO Doctrine
        // association to Mailbox (the username join collides — see the Quota
        // entity), so purgeMailbox() never touches it; a deleted user would
        // otherwise leave an orphan row. Remove it by username via native SQL.
        try
        {
            $conn = $em->getConnection();
            $conn->executeStatement(
                'DELETE FROM dovecot_quota WHERE username = ?',
                [ $username ]
            );
        }
        catch( \Exception $e )
        {
            // Non-fatal: the mailbox is already gone; a leftover quota row is
            // cosmetic. Log and continue rather than failing the delete task.
            error_log( 'vimbadmin: dovecot_quota cleanup failed for '
                . $username . ': ' . $e->getMessage() );
        }
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
