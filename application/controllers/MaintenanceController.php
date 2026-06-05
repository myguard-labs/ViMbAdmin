<?php
/**
 * Maintenance — super-admin-only housekeeping actions.
 *
 * Two operations, both POST + CSRF guarded:
 *
 *   1. disable-inactive  — set active=0 on every mailbox and alias whose
 *                          parent domain is itself inactive (active=0).
 *                          Idempotent; only touches currently-active rows.
 *
 *   2. schema-update     — Doctrine ORM schema sync. Two-step and safe:
 *                          the first POST (action only) shows the pending
 *                          ALTER/CREATE/DROP SQL as a dry-run; the operator
 *                          must re-submit with confirm=1 to actually run it.
 *
 * Mirrors AdminController's super-admin gate: preDispatch -> authorise(true).
 */
class MaintenanceController extends ViMbAdmin_Controller_Action
{
    public function preDispatch()
    {
        // The CLI schema migrator runs from vimbtool (container bootstrap), with
        // no web session — skip the super-admin gate for it. Everything else is
        // super-admin-only.
        if( $this->getRequest()->getActionName() === 'cli-schema-update' )
            return;

        $this->authorise( true );
    }

    public function indexAction()
    {
        // NOTE: opening this tab no longer nudges the queue. The queue is
        // drained ONLY by the external cron (queue.cli-run); all in-app
        // trigger-checks were removed so the runner has a single, predictable
        // entry point.
        $this->view->stats = $this->_inactiveStats();
        $this->view->activeMailboxCount = (int) $this->getD2EM()->createQuery(
            'SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1' )->getSingleScalarResult();
        $this->_schemaVersionToView();
        $this->_versionToView();
        $this->_lastRunToView();
    }

    /**
     * Expose deployed vs code DB schema version + pending-statement count.
     */
    private function _schemaVersionToView()
    {
        $schema = new ViMbAdmin_Schema( $this->getD2EM() );
        $this->view->dbVersionApplied = $schema->currentVersion();
        $this->view->dbVersionCode    = $schema->codeVersion();
        $this->view->dbPending        = count( $schema->pendingSql() );
        $this->view->appVersion       = ViMbAdmin_Version::VERSION;
        $this->view->appDbVersionName = defined( 'ViMbAdmin_Version::DBVERSION_NAME' ) ? ViMbAdmin_Version::DBVERSION_NAME : '';
    }

    /**
     * Expose the running release + the git commit the image was built from.
     */
    private function _versionToView()
    {
        $this->view->appVersion = ViMbAdmin_Version::VERSION;
        $this->view->gitCommit  = ViMbAdmin_Version::gitCommit();
        $this->view->gitCommitShort = ViMbAdmin_Version::gitCommitShort();
        $this->view->githubRepo = ViMbAdmin_Version::GITHUB_REPO;
    }

    /**
     * Expose the last-queuerun / last-prune timestamps for the overview.
     */
    private function _lastRunToView()
    {
        $em = $this->getD2EM();
        $this->view->lastQueueRun = ViMbAdmin_Setting::get( $em, ViMbAdmin_Setting::LAST_QUEUERUN );
        $this->view->lastPrune    = ViMbAdmin_Setting::get( $em, ViMbAdmin_Setting::LAST_PRUNE );
    }

    /**
     * Check GitHub for a newer release and/or newer commits. POST + CSRF.
     * `which` = 'release' | 'commit' (the button pressed). Sets a flash
     * message and redirects back; never blocks the page if GitHub is
     * unreachable.
     */
    public function checkUpdateAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $which = $this->getParam( 'which' ) === 'commit' ? 'commit' : 'release';

        if( $which === 'release' )
        {
            $res = ViMbAdmin_Version::releaseUpdateAvailable();
            if( $res === null )
                $this->addMessage( _( 'Could not reach GitHub right now — please try again in a moment.' ), OSS_Message::WARNING );
            elseif( $res === false )
                $this->addMessage( sprintf( _( 'You are on the latest release (%s).' ), ViMbAdmin_Version::VERSION ), OSS_Message::SUCCESS );
            else
                $this->addMessage( sprintf( _( 'A newer release is available: %1$s (you have %2$s).' ), $res, ViMbAdmin_Version::VERSION ), OSS_Message::INFO );
        }
        else
        {
            $res = ViMbAdmin_Version::commitUpdateAvailable();
            if( $res === null )
                $this->addMessage( _( 'Could not reach GitHub right now — please try again in a moment.' ), OSS_Message::WARNING );
            elseif( $res === false )
                $this->addMessage( _( 'This image is built from the latest commit.' ), OSS_Message::SUCCESS );
            else
                $this->addMessage( sprintf( _( 'Newer commits exist on GitHub (latest %1$s, this build %2$s). Rebuild the image to update.' ), $res, ViMbAdmin_Version::gitCommitShort() ?: '?' ), OSS_Message::INFO );
        }

        $this->redirect( 'maintenance/index' );
    }

    /**
     * Enqueue a REPAIR (force-resync + index + purge + quota recalc) task for
     * every active mailbox. HEAVY: this can queue thousands of doveadm jobs.
     * Two-step: the first POST shows a disclaimer, confirm=1 actually enqueues.
     * The queue-runner then drains them throttled (queue.runner.max_per_run).
     */
    public function repairAllAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        // Dry-run: show the disclaimer + count, require explicit confirmation.
        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->stats              = $this->_inactiveStats();
            $this->view->activeMailboxCount = (int) $this->getD2EM()->createQuery(
                'SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1' )->getSingleScalarResult();
            $this->view->confirmRepairAll   = true;
            $this->_schemaVersionToView();
            return $this->render( 'index' );
        }

        $em       = $this->getD2EM();
        $mailboxes = $em->getRepository( '\\Entities\\Mailbox' )->findBy( [ 'active' => 1 ] );

        $queued = 0;
        foreach( $mailboxes as $mailbox )
        {
            $task = ViMbAdmin_MailboxQueue::enqueue( $em, $mailbox, \Entities\MailboxTask::TYPE_REPAIR, $this->getAdmin() );
            if( $task )
                $queued++;
        }
        $em->flush();

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} queued repair/optimize for all active mailboxes ({$queued} task(s))" );

        $this->addMessage(
            sprintf( _( 'Queued repair/optimize for %d mailbox(es). The runner will process them in the background.' ), $queued ),
            OSS_Message::SUCCESS );
        $this->redirect( 'queue/index' );
    }

    /**
     * Autoprune EXPIRED backups: every autoprune-on archive older than
     * queue.autoprune.days. Removes the /backups maildir (doveadm fs delete)
     * and the archive row. Two-step: confirm=1 to actually run.
     */
    public function pruneExpiredAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $days = max( 0, (int) ( $this->_options['queue']['autoprune']['days'] ?? 90 ) );
        // days=0 means instant-delete (no backups are ever kept), so nothing to
        // expire by age — treat the cutoff as "now" (prunes anything already there).
        $cutoff = ( new \DateTime() )->modify( '-' . $days . ' days' );

        $candidates = $this->getD2EM()->getRepository( '\\Entities\\Archive' )->findAutoprune( $cutoff );

        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->confirmPruneExpired = true;
            $this->view->pruneExpiredCount   = count( $candidates );
            $this->view->pruneExpiredDays    = $days;
            $this->indexAction();
            return $this->render( 'index' );
        }

        $n = $this->_prune( $candidates );
        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} autopruned {$n} expired archive backup(s) (> {$days} days)" );
        $this->addMessage(
            sprintf( _( 'Pruned %d expired archive backup(s).' ), $n ), OSS_Message::SUCCESS );
        $this->redirect( 'archive/list' );
    }

    /**
     * Delete ALL autoprune-on backups regardless of age. Removes the /backups
     * maildir + archive row for every archive flagged autoprune. confirm=1.
     */
    public function pruneAllAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $candidates = $this->getD2EM()->getRepository( '\\Entities\\Archive' )->findAutoprune( null );

        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->confirmPruneAll = true;
            $this->view->pruneAllCount   = count( $candidates );
            $this->indexAction();
            return $this->render( 'index' );
        }

        $n = $this->_prune( $candidates );
        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} deleted ALL {$n} autoprune-on archive backup(s)" );
        $this->addMessage(
            sprintf( _( 'Deleted %d autoprune-on archive backup(s).' ), $n ), OSS_Message::SUCCESS );
        $this->redirect( 'archive/list' );
    }

    /**
     * Remove each archive's backup maildir (doveadm fs delete -R) and its row.
     * A doveadm failure on one archive is logged and skipped (the row is kept
     * so it retries next run), so a single bad path can't abort the whole prune.
     *
     * @param \Entities\Archive[] $archives
     * @return int  number fully pruned
     */
    private function _prune( array $archives )
    {
        // Record that a prune ran (even if it pruned nothing) for the overview.
        ViMbAdmin_Setting::stampNow( $this->getD2EM(), ViMbAdmin_Setting::LAST_PRUNE );

        if( !$archives )
            return 0;

        $em      = $this->getD2EM();
        $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->_options );
        $pruned  = 0;

        foreach( $archives as $archive )
        {
            $dest = $archive->getMaildirFile();   // e.g. maildir:/backups/dom/user
            try
            {
                if( $dest )
                    $doveadm->fsDelete( $dest );
                $em->remove( $archive );
                $pruned++;
            }
            catch( \Throwable $e )
            {
                $this->getLogger()->err( "MaintenanceController::_prune {$archive->getUsername()}: " . $e->getMessage() );
                // keep the row; it will be retried on the next prune run.
            }
        }
        $em->flush();
        return $pruned;
    }

    /**
     * Count active mailboxes/aliases that sit under an inactive domain.
     *
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    private function _inactiveStats()
    {
        $em = $this->getD2EM();
        return [
            'domains'   => (int) $em->createQuery(
                'SELECT COUNT(d.id) FROM \Entities\Domain d WHERE d.active = 0' )->getSingleScalarResult(),
            'mailboxes' => (int) $em->createQuery(
                'SELECT COUNT(m.id) FROM \Entities\Mailbox m JOIN m.Domain d WHERE d.active = 0 AND m.active = 1' )->getSingleScalarResult(),
            'aliases'   => (int) $em->createQuery(
                'SELECT COUNT(a.id) FROM \Entities\Alias a JOIN a.Domain d WHERE d.active = 0 AND a.active = 1' )->getSingleScalarResult(),
        ];
    }

    /**
     * Disable all mailboxes and aliases belonging to inactive domains.
     */
    public function disableInactiveAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $em = $this->getD2EM();
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try
        {
            $mb = $conn->executeStatement(
                'UPDATE mailbox m JOIN domain d ON d.id = m.Domain_id'
                . ' SET m.active = 0 WHERE d.active = 0 AND m.active = 1' );
            $al = $conn->executeStatement(
                'UPDATE alias a JOIN domain d ON d.id = a.Domain_id'
                . ' SET a.active = 0 WHERE d.active = 0 AND a.active = 1' );
            $conn->commit();
        }
        catch( \Throwable $e )
        {
            $conn->rollBack();
            $this->getLogger()->err( 'Maintenance disable-inactive: ' . $e->getMessage() );
            $this->addMessage( _( 'Failed to disable inactive-domain accounts: ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        $this->log(
            \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} disabled accounts of inactive domains ({$mb} mailboxes, {$al} aliases)"
        );
        $em->flush();

        $this->addMessage(
            sprintf( _( 'Disabled %d mailbox(es) and %d alias(es) belonging to inactive domains.' ), $mb, $al ),
            OSS_Message::SUCCESS
        );
        $this->redirect( 'maintenance/index' );
    }

    /**
     * Enqueue a QUOTA_RECALC task for every active mailbox so the
     * dovecot_quota usage table is refreshed instance-wide. Lighter than
     * repair-all (quota recalc only). Two-step: first POST shows a confirm,
     * confirm=1 enqueues; the runner drains throttled.
     */
    public function recalcAllAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->stats              = $this->_inactiveStats();
            $this->view->activeMailboxCount = (int) $this->getD2EM()->createQuery(
                'SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1' )->getSingleScalarResult();
            $this->view->confirmRecalcAll   = true;
            $this->_schemaVersionToView();
            return $this->render( 'index' );
        }

        $em        = $this->getD2EM();
        $mailboxes = $em->getRepository( '\\Entities\\Mailbox' )->findBy( [ 'active' => 1 ] );

        $queued = 0;
        foreach( $mailboxes as $mailbox )
        {
            $task = ViMbAdmin_MailboxQueue::enqueue( $em, $mailbox, \Entities\MailboxTask::TYPE_QUOTA_RECALC, $this->getAdmin() );
            if( $task )
                $queued++;
        }
        $em->flush();

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} queued quota recalc for all active mailboxes ({$queued} task(s))" );

        $this->addMessage(
            sprintf( _( 'Queued quota recalc for %d mailbox(es). The runner will process them in the background.' ), $queued ),
            OSS_Message::SUCCESS );
        $this->redirect( 'queue/index' );
    }

    /**
     * Flush Dovecot's auth cache (whole cache). Useful after bulk
     * password/active changes so the next login re-reads the userdb/passdb.
     */
    public function flushAuthCacheAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        try
        {
            ViMbAdmin_Doveadm::fromOptions( $this->_options )->authCacheFlush();
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'Maintenance flush-auth-cache: ' . $e->getMessage() );
            $this->addMessage( _( 'Failed to flush Dovecot auth cache: ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} flushed the Dovecot auth cache" );

        $this->addMessage( _( 'Dovecot authentication cache flushed.' ), OSS_Message::SUCCESS );
        $this->redirect( 'maintenance/index' );
    }

    /**
     * Doctrine schema sync. Dry-run by default; applies only with confirm=1.
     */
    public function schemaUpdateAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $schema = new ViMbAdmin_Schema( $this->getD2EM() );
        $sql    = $schema->pendingSql();

        if( count( $sql ) === 0 )
        {
            // Still record the version so the UI reflects a clean deploy.
            $schema->recordVersion();
            $this->addMessage( _( 'Database schema is already up to date — nothing to do.' ), OSS_Message::SUCCESS );
            $this->redirect( 'maintenance/index' );
        }

        // Dry-run: show the pending statements and ask for explicit confirmation.
        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->stats     = $this->_inactiveStats();
            $this->view->schemaSql = $sql;
            $this->view->activeMailboxCount = (int) $this->getD2EM()->createQuery(
                'SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1' )->getSingleScalarResult();
            $this->_schemaVersionToView();
            return $this->render( 'index' );
        }

        // Confirmed: apply. DDL auto-commits in MySQL/MariaDB, so the helper
        // runs each statement on its own (NO surrounding transaction — wrapping
        // it threw "no active transaction" on commit) and records the version.
        try
        {
            $applied = $schema->apply( $sql );
            $schema->recordVersion();
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'Maintenance schema-update: ' . $e->getMessage() );
            $this->addMessage( _( 'Schema update failed: ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        $this->log(
            \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} applied schema update ({$applied} statement(s))"
        );

        $this->addMessage(
            sprintf( _( 'Schema updated successfully — %d statement(s) executed.' ), $applied ),
            OSS_Message::SUCCESS
        );
        $this->redirect( 'maintenance/index' );
    }

    /**
     * CLI schema auto-migrator — run from the container bootstrap on every
     * start (vimbtool.php -a maintenance.cli-schema-update). Applies pending
     * additive schema SQL and records the version. Idempotent + non-interactive.
     * Safe to run on every boot: no pending SQL == no-op.
     */
    public function cliSchemaUpdateAction()
    {
        $verbose = $this->getParam( 'verbose' );
        $schema  = new ViMbAdmin_Schema( $this->getD2EM() );

        try
        {
            $res = $schema->migrate();
        }
        catch( \Throwable $e )
        {
            echo 'ERROR: schema update failed: ' . $e->getMessage() . "\n";
            return;
        }

        if( $verbose )
        {
            if( $res['applied'] )
            {
                echo "Applied {$res['applied']} schema statement(s):\n";
                foreach( $res['statements'] as $s )
                    echo '  ' . $s . "\n";
            }
            else
            {
                echo "Schema already up to date.\n";
            }
            echo 'DB version: ' . ( $res['version'] ?? '?' ) . "\n";
        }
    }

    /**
     * The mail-home root that holds the per-user maildirs (dovecot
     * `mail_home = <root>/%{user}`). Configurable via
     * doveadm.maildir_root in application.ini.
     *
     * @return string
     */
    private function _maildirRoot()
    {
        $root = isset( $this->_options['doveadm']['maildir_root'] )
            ? trim( (string) $this->_options['doveadm']['maildir_root'] ) : '';
        return $root !== '' ? rtrim( $root, '/' ) : '/opt/myguard/dovecot/maildir';
    }

    /**
     * Scan the mail-home root for ORPHAN maildirs: per-user directories on disk
     * that have NO ViMbAdmin mailbox row (account deleted in the panel but the
     * mail left behind, or mail that pre-dates the panel). REST-only — lists
     * the dirs via `doveadm fs iter-dirs`, then subtracts the known mailbox
     * usernames. Only counts dirs that look like maildirs (contain `cur`).
     *
     * @return string[]  orphan usernames (the directory names)
     */
    private function _scanOrphans()
    {
        $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->_options );
        $root    = $this->_maildirRoot();

        $dirs = $doveadm->fsListDirs( $root );          // user dir names
        if( !$dirs )
            return [];

        // Known mailbox usernames (lower-cased set).
        $known = [];
        foreach( $this->getD2EM()->createQuery(
            'SELECT m.username FROM \Entities\Mailbox m' )->getArrayResult() as $r )
            $known[ strtolower( $r['username'] ) ] = true;

        $orphans = [];
        foreach( $dirs as $name )
        {
            if( isset( $known[ strtolower( $name ) ] ) )
                continue;
            // Looks like a maildir? (has a cur/ subdir). Skip stray dirs.
            $sub = $doveadm->fsListDirs( $root . '/' . $name );
            if( in_array( 'cur', $sub, true ) )
                $orphans[] = $name;
        }
        sort( $orphans );
        return $orphans;
    }

    /**
     * Maintenance action: scan for orphan maildirs and show them (count +
     * names) on the tab. No side effects.
     */
    public function scanOrphansAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        try
        {
            $this->view->orphans = $this->_scanOrphans();
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'Maintenance scan-orphans: ' . $e->getMessage() );
            $this->addMessage( _( 'Orphan scan failed: ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        $this->indexAction();
        return $this->render( 'index' );
    }

    /**
     * Maintenance action: enqueue a low-priority BACKUP_ORPHAN task for every
     * orphan maildir found by the scan. The queue runner does the actual
     * temp-account backup + repair in the background. Two-step (confirm=1).
     */
    public function backupOrphansAction()
    {
        $this->_assertCsrf();
        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        try
        {
            $orphans = $this->_scanOrphans();
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'Maintenance backup-orphans scan: ' . $e->getMessage() );
            $this->addMessage( _( 'Orphan scan failed: ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->orphans            = $orphans;
            $this->view->confirmBackupOrphans = true;
            $this->indexAction();
            return $this->render( 'index' );
        }

        $em = $this->getD2EM();
        $queued = 0;
        foreach( $orphans as $user )
        {
            // Dedup: skip if a BACKUP_ORPHAN is already open for this user.
            $open = (int) $em->createQuery(
                'SELECT COUNT(t.id) FROM \Entities\MailboxTask t
                  WHERE t.username = :u AND t.type = :t AND t.status IN (:open)' )
                ->setParameter( 'u', $user )
                ->setParameter( 't', \Entities\MailboxTask::TYPE_BACKUP_ORPHAN )
                ->setParameter( 'open', [ \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING ] )
                ->getSingleScalarResult();
            if( $open > 0 )
                continue;

            $mt = new \Entities\MailboxTask();
            $mt->setType( \Entities\MailboxTask::TYPE_BACKUP_ORPHAN )
               ->setUsername( $user )
               ->setStatus( \Entities\MailboxTask::STATUS_PENDING )
               ->setPriority( -15 )                  // below normal, around MEASURE_SIZE
               ->setCreatedAt( new \DateTime() )
               ->setRequestedBy( $this->getAdmin() );
            $em->persist( $mt );
            $queued++;
        }
        $em->flush();

        $this->log( \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} queued orphan-maildir backup for {$queued} unmanaged maildir(s)" );
        $this->addMessage(
            sprintf( _( 'Queued backup of %d unmanaged maildir(s). The runner will process them in the background.' ), $queued ),
            OSS_Message::SUCCESS );
        $this->redirect( 'queue/index' );
    }
}
