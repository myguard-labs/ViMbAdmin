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
        // Every maintenance action requires a super admin.
        $this->authorise( true );
    }

    public function indexAction()
    {
        $this->view->stats = $this->_inactiveStats();
        $this->view->activeMailboxCount = (int) $this->getD2EM()->createQuery(
            'SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1' )->getSingleScalarResult();
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
            return $this->render( 'index' );
        }

        $em       = $this->getD2EM();
        $mailboxes = $em->getRepository( '\\Entities\\Mailbox' )->findBy( [ 'active' => 1 ] );

        $queued = 0;
        foreach( $mailboxes as $mailbox )
        {
            $task = QueueController::enqueue( $em, $mailbox, \Entities\MailboxTask::TYPE_REPAIR, $this->getAdmin() );
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
     * Doctrine schema sync. Dry-run by default; applies only with confirm=1.
     */
    public function schemaUpdateAction()
    {
        $this->_assertCsrf();

        if( !$this->getRequest()->isPost() )
            $this->redirect( 'maintenance/index' );

        $em   = $this->getD2EM();
        $tool = new \Doctrine\ORM\Tools\SchemaTool( $em );
        $meta = $em->getMetadataFactory()->getAllMetadata();

        // saveMode=true -> additive/altering SQL only (no DROP TABLE for tables
        // Doctrine doesn't know about). Still emits DROP COLUMN / DROP INDEX
        // where mappings changed, so we always show the SQL before running it.
        $sql = $tool->getUpdateSchemaSql( $meta, true );

        if( count( $sql ) === 0 )
        {
            $this->addMessage( _( 'Database schema is already up to date — nothing to do.' ), OSS_Message::SUCCESS );
            $this->redirect( 'maintenance/index' );
        }

        // Dry-run: show the pending statements and ask for explicit confirmation.
        if( (int) $this->getParam( 'confirm', 0 ) !== 1 )
        {
            $this->view->stats     = $this->_inactiveStats();
            $this->view->schemaSql = $sql;
            return $this->render( 'index' );
        }

        // Confirmed: execute, transactionally.
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try
        {
            foreach( $sql as $stmt )
                $conn->executeStatement( $stmt );
            $conn->commit();
        }
        catch( \Throwable $e )
        {
            $conn->rollBack();
            $this->getLogger()->err( 'Maintenance schema-update: ' . $e->getMessage() );
            $this->addMessage( _( 'Schema update failed (rolled back): ' ) . $e->getMessage(), OSS_Message::ERROR );
            $this->redirect( 'maintenance/index' );
        }

        $this->log(
            \Entities\Log::ACTION_MAINTENANCE,
            "{$this->getAdmin()->getFormattedName()} applied schema update (" . count( $sql ) . ' statement(s))'
        );
        $em->flush();

        $this->addMessage(
            sprintf( _( 'Schema updated successfully — %d statement(s) executed.' ), count( $sql ) ),
            OSS_Message::SUCCESS
        );
        $this->redirect( 'maintenance/index' );
    }
}
