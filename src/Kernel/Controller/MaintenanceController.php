<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native port of the super-admin Maintenance dashboard (docs/ZF1-REMOVAL.md).
 *
 * `index` renders the maintenance dashboard (DB schema version + pending count,
 * app/git version, last queue-run / prune markers, inactive-domain stats). The
 * bulk actions reproduce the ZF1 ones: `repair-all` / `recalc-all` bulk-enqueue a
 * REPAIR / QUOTA_RECALC task for every active mailbox (two-step: a bare POST shows
 * the confirm disclaimer, `confirm=1` enqueues); `disable-inactive` disables the
 * accounts of inactive domains; `flush-auth-cache` flushes Dovecot's auth cache
 * via the doveadm HTTP API; `check-update` polls GitHub for a newer release/commit.
 *
 * All super-gated (the ZF1 `preDispatch` `authorise(true)`); the action POSTs
 * carry the CSRF token as a hidden field (read from the body via {@see postCsrfValid}).
 * `prune-expired` / `prune-all` remove autoprune archive backups via the doveadm
 * HTTP API; `scan-orphans` / `backup-orphans` find on-disk maildirs with no
 * mailbox row and enqueue their import; `schema-update` applies pending Doctrine
 * DDL (dry-run by default). Only `cli-schema-update` (CLI) stays on ZF1.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class MaintenanceController extends AbstractController
{
    /**
     * GET /maintenance — the maintenance dashboard.
     */
    public function indexAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        return $this->renderDashboard();
    }

    /**
     * POST /maintenance/repair-all — enqueue REPAIR for every active mailbox.
     */
    public function repairAllAction(): Response
    {
        return $this->bulkEnqueue(\Entities\MailboxTask::TYPE_REPAIR, 'confirmRepairAll', 'repair/optimize');
    }

    /**
     * POST /maintenance/recalc-all — enqueue QUOTA_RECALC for every active mailbox.
     */
    public function recalcAllAction(): Response
    {
        return $this->bulkEnqueue(\Entities\MailboxTask::TYPE_QUOTA_RECALC, 'confirmRecalcAll', 'quota recalc');
    }

    /**
     * POST /maintenance/disable-inactive — disable accounts of inactive domains.
     */
    public function disableInactiveAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        $conn = $this->em()->getConnection();
        $conn->beginTransaction();
        try {
            $mb = $conn->executeStatement(
                'UPDATE mailbox m JOIN domain d ON d.id = m.Domain_id SET m.active = 0 WHERE d.active = 0 AND m.active = 1');
            $al = $conn->executeStatement(
                'UPDATE alias a JOIN domain d ON d.id = a.Domain_id SET a.active = 0 WHERE d.active = 0 AND a.active = 1');
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->flash('Failed to disable inactive-domain accounts: ' . $e->getMessage(), FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }

        $this->logMaintenance($guard, "disabled accounts of inactive domains ({$mb} mailboxes, {$al} aliases)");
        $this->flash(sprintf('Disabled %d mailbox(es) and %d alias(es) belonging to inactive domains.', $mb, $al));
        return $this->redirect('maintenance/index');
    }

    /**
     * POST /maintenance/flush-auth-cache — flush Dovecot's auth cache.
     */
    public function flushAuthCacheAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            \ViMbAdmin_Doveadm::fromOptions($this->container->options())->authCacheFlush();
        } catch (\Throwable $e) {
            $this->flash('Could not flush the Dovecot auth cache: ' . $e->getMessage(), FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }

        $this->flash('Dovecot authentication cache flushed.');
        return $this->redirect('maintenance/index');
    }

    /**
     * POST /maintenance/check-update — check GitHub for a newer release / commit.
     */
    public function checkUpdateAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        $which = (($this->postData()['which'] ?? '') === 'commit') ? 'commit' : 'release';

        if ($which === 'release') {
            $res = \ViMbAdmin_Version::releaseUpdateAvailable();
            if ($res === null) {
                $this->flash('Could not reach GitHub right now — please try again in a moment.', FlashMessages::WARNING);
            } elseif ($res === false) {
                $this->flash(sprintf('You are on the latest release (%s).', \ViMbAdmin_Version::VERSION));
            } else {
                $this->flash(sprintf('A newer release is available: %1$s (you have %2$s).', $res, \ViMbAdmin_Version::VERSION), FlashMessages::INFO);
            }
        } else {
            $res = \ViMbAdmin_Version::commitUpdateAvailable();
            if ($res === null) {
                $this->flash('Could not reach GitHub right now — please try again in a moment.', FlashMessages::WARNING);
            } elseif ($res === false) {
                $this->flash('This image is built from the latest commit.');
            } else {
                $this->flash(sprintf('Newer commits exist on GitHub (latest %1$s, this build %2$s). Rebuild the image to update.', $res, \ViMbAdmin_Version::gitCommitShort() ?: '?'), FlashMessages::INFO);
            }
        }

        return $this->redirect('maintenance/index');
    }

    /**
     * POST /maintenance/prune-expired — remove autoprune backups older than
     * queue.autoprune.days (two-step confirm).
     */
    public function pruneExpiredAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        $days   = max(0, (int) ($this->container->options()['queue']['autoprune']['days'] ?? 90));
        $cutoff = (new \DateTime())->modify('-' . $days . ' days');
        $candidates = $this->em()->getRepository('\\Entities\\Archive')->findAutoprune($cutoff);

        if ((int) ($this->postData()['confirm'] ?? 0) !== 1) {
            return $this->renderDashboard([
                'confirmPruneExpired' => true,
                'pruneExpiredCount'   => count($candidates),
                'pruneExpiredDays'    => $days,
            ]);
        }

        $n = $this->prune($candidates);
        $this->logMaintenance($guard, "autopruned {$n} expired archive backup(s) (> {$days} days)");
        $this->flash(sprintf('Pruned %d expired archive backup(s).', $n));
        return $this->redirect('archive/list');
    }

    /**
     * POST /maintenance/prune-all — remove ALL autoprune-on backups regardless of
     * age (two-step confirm).
     */
    public function pruneAllAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        $candidates = $this->em()->getRepository('\\Entities\\Archive')->findAutoprune(null);

        if ((int) ($this->postData()['confirm'] ?? 0) !== 1) {
            return $this->renderDashboard([
                'confirmPruneAll' => true,
                'pruneAllCount'   => count($candidates),
            ]);
        }

        $n = $this->prune($candidates);
        $this->logMaintenance($guard, "deleted ALL {$n} autoprune-on archive backup(s)");
        $this->flash(sprintf('Deleted %d autoprune-on archive backup(s).', $n));
        return $this->redirect('archive/list');
    }

    /**
     * Remove each archive's backup maildir via the doveadm HTTP API and drop its
     * row. Stamps the last-prune marker; a doveadm failure on one archive is logged
     * and the row kept (retried next run). Returns the number pruned.
     *
     * @param \Entities\Archive[] $archives
     */
    private function prune(array $archives): int
    {
        \ViMbAdmin_Setting::stampNow($this->em(), \ViMbAdmin_Setting::LAST_PRUNE);

        if (!$archives) {
            return 0;
        }

        $doveadm = \ViMbAdmin_Doveadm::fromOptions($this->container->options());
        $pruned  = 0;

        foreach ($archives as $archive) {
            $dest = $archive->getMaildirFile();
            try {
                if ($dest) {
                    $doveadm->fsDelete($dest);
                }
                $this->em()->remove($archive);
                $pruned++;
            } catch (\Throwable $e) {
                error_log("MaintenanceController::prune {$archive->getUsername()}: " . $e->getMessage());
            }
        }
        $this->em()->flush();

        return $pruned;
    }

    /**
     * POST /maintenance/schema-update — apply pending Doctrine schema DDL.
     *
     * Dry-run by default: nothing pending → record the version + "up to date";
     * otherwise (confirm!=1) re-render the dashboard listing the pending
     * statements; confirm=1 applies them (DDL auto-commits) + records the version.
     */
    public function schemaUpdateAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        $schema = new \ViMbAdmin_Schema($this->em());
        $sql    = $schema->pendingSql();

        if (count($sql) === 0) {
            $schema->recordVersion();
            $this->flash('Database schema is already up to date — nothing to do.');
            return $this->redirect('maintenance/index');
        }

        if ((int) ($this->postData()['confirm'] ?? 0) !== 1) {
            return $this->renderDashboard(['schemaSql' => $sql]);
        }

        try {
            $applied = $schema->apply($sql);
            $schema->recordVersion();
        } catch (\Throwable $e) {
            $this->flash('Schema update failed: ' . $e->getMessage(), FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }

        $this->logMaintenance($guard, "applied schema update ({$applied} statement(s))");
        $this->flash(sprintf('Schema updated successfully — %d statement(s) executed.', $applied));
        return $this->redirect('maintenance/index');
    }

    /**
     * POST /maintenance/scan-orphans — list on-disk maildirs with no mailbox row.
     */
    public function scanOrphansAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $orphans = $this->scanOrphans();
        } catch (\Throwable $e) {
            $this->flash('Orphan scan failed: ' . $e->getMessage(), FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }

        return $this->renderDashboard(['orphans' => $orphans]);
    }

    /**
     * POST /maintenance/backup-orphans — enqueue a BACKUP_ORPHAN import for one
     * (`?username=`) or, with `confirm=1`, every unmanaged maildir. The runner
     * (Service_QueueRunner) backs up any with mail and removes empty leftovers.
     */
    public function backupOrphansAction(): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $orphans = $this->scanOrphans();
        } catch (\Throwable $e) {
            $this->flash('Orphan scan failed: ' . $e->getMessage(), FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }

        $one = (string) ($this->postData()['username'] ?? '');
        if ($one !== '') {
            if (!in_array($one, $orphans, true)) {
                $this->flash('That maildir is no longer an unmanaged orphan.', FlashMessages::INFO);
                return $this->redirect('maintenance/index');
            }
            $orphans = [$one];
        } elseif ((int) ($this->postData()['confirm'] ?? 0) !== 1) {
            $this->flash('Re-scan and use "Import all" to import every unmanaged maildir.', FlashMessages::INFO);
            return $this->redirect('maintenance/index');
        }

        $em     = $this->em();
        $queued = 0;
        foreach ($orphans as $user) {
            $open = (int) $em->createQuery(
                'SELECT COUNT(t.id) FROM \Entities\MailboxTask t WHERE t.username = :u AND t.type = :t AND t.status IN (:open)')
                ->setParameter('u', $user)
                ->setParameter('t', \Entities\MailboxTask::TYPE_BACKUP_ORPHAN)
                ->setParameter('open', [\Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING])
                ->getSingleScalarResult();
            if ($open > 0) {
                continue;
            }

            $mt = new \Entities\MailboxTask();
            $mt->setType(\Entities\MailboxTask::TYPE_BACKUP_ORPHAN)
               ->setUsername($user)
               ->setStatus(\Entities\MailboxTask::STATUS_PENDING)
               ->setPriority(-15)
               ->setCreatedAt(new \DateTime())
               ->setRequestedBy($guard);
            $em->persist($mt);
            $queued++;
        }
        $em->flush();

        $this->logMaintenance($guard, "queued orphan-maildir import for {$queued} unmanaged maildir(s)");
        $this->flash(sprintf('Queued import of %d unmanaged maildir(s). The runner backs up any with mail and removes empty ones, in the background.', $queued));
        return $this->redirect('queue/index');
    }

    /**
     * On-disk maildirs (under the configured maildir root) that have no mailbox
     * row — the native equivalent of the ZF1 `_scanOrphans`. A dir counts only if
     * it looks like a maildir (has a `cur/` subdir).
     *
     * @return string[]
     */
    private function scanOrphans(): array
    {
        $doveadm = \ViMbAdmin_Doveadm::fromOptions($this->container->options());
        $root    = $this->maildirRoot();

        $dirs = $doveadm->fsListDirs($root);
        if (!$dirs) {
            return [];
        }

        $known = [];
        foreach ($this->em()->createQuery('SELECT m.username FROM \Entities\Mailbox m')->getArrayResult() as $r) {
            $known[strtolower($r['username'])] = true;
        }

        $orphans = [];
        foreach ($dirs as $name) {
            if (isset($known[strtolower($name)])) {
                continue;
            }
            if (in_array('cur', $doveadm->fsListDirs($root . '/' . $name), true)) {
                $orphans[] = $name;
            }
        }
        sort($orphans);

        return $orphans;
    }

    private function maildirRoot(): string
    {
        $root = isset($this->container->options()['doveadm']['maildir_root'])
            ? trim((string) $this->container->options()['doveadm']['maildir_root']) : '';
        return $root !== '' ? rtrim($root, '/') : '/opt/myguard/dovecot/maildir';
    }

    /**
     * The two-step bulk enqueue shared by repair-all / recalc-all: a bare POST
     * re-renders the dashboard with the confirm disclaimer; `confirm=1` enqueues
     * the given task type for every active mailbox and redirects to the queue.
     */
    private function bulkEnqueue(string $type, string $confirmFlag, string $label): Response
    {
        $guard = $this->guardSuperPost();
        if ($guard instanceof Response) {
            return $guard;
        }

        if ((int) ($this->postData()['confirm'] ?? 0) !== 1) {
            return $this->renderDashboard([$confirmFlag => true]);
        }

        $em        = $this->em();
        $mailboxes = $em->getRepository('\\Entities\\Mailbox')->findBy(['active' => 1]);

        $queued = 0;
        foreach ($mailboxes as $mailbox) {
            if (\ViMbAdmin_MailboxQueue::enqueue($em, $mailbox, $type, $guard)) {
                $queued++;
            }
        }
        $em->flush();

        $this->logMaintenance($guard, "queued {$label} for all active mailboxes ({$queued} task(s))");
        $this->flash(sprintf('Queued %s for %d mailbox(es). The runner will process them in the background.', $label, $queued));
        return $this->redirect('queue/index');
    }

    /**
     * Render maintenance/index.phtml with the dashboard variables (+ any extra
     * flags, e.g. a two-step confirm marker).
     *
     * @param array<string,mixed> $extra
     */
    private function renderDashboard(array $extra = []): Response
    {
        $em     = $this->em();
        $schema = new \ViMbAdmin_Schema($em);

        $vars = [
            'stats'             => [
                'domains'   => (int) $em->createQuery('SELECT COUNT(d.id) FROM \Entities\Domain d WHERE d.active = 0')->getSingleScalarResult(),
                'mailboxes' => (int) $em->createQuery('SELECT COUNT(m.id) FROM \Entities\Mailbox m JOIN m.Domain d WHERE d.active = 0 AND m.active = 1')->getSingleScalarResult(),
                'aliases'   => (int) $em->createQuery('SELECT COUNT(a.id) FROM \Entities\Alias a JOIN a.Domain d WHERE d.active = 0 AND a.active = 1')->getSingleScalarResult(),
            ],
            'activeMailboxCount' => (int) $em->createQuery('SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.active = 1')->getSingleScalarResult(),
            'dbVersionApplied'   => $schema->currentVersion(),
            'dbVersionCode'      => $schema->codeVersion(),
            'dbPending'          => count($schema->pendingSql()),
            'appVersion'         => \ViMbAdmin_Version::VERSION,
            'appDbVersionName'   => defined('ViMbAdmin_Version::DBVERSION_NAME') ? \ViMbAdmin_Version::DBVERSION_NAME : '',
            'gitCommit'          => \ViMbAdmin_Version::gitCommit(),
            'gitCommitShort'     => \ViMbAdmin_Version::gitCommitShort(),
            'githubRepo'         => \ViMbAdmin_Version::GITHUB_REPO,
            'lastQueueRun'       => \ViMbAdmin_Setting::get($em, \ViMbAdmin_Setting::LAST_QUEUERUN),
            'lastPrune'          => \ViMbAdmin_Setting::get($em, \ViMbAdmin_Setting::LAST_PRUNE),
        ];

        return $this->view('maintenance/index.phtml', $extra + $vars);
    }

    /**
     * Super + POST + CSRF guard (the maintenance forms carry the token in the POST
     * body). Returns the admin on success, or the Response to return on failure.
     */
    private function guardSuperPost(): object
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }
        if (!$this->isPost()) {
            return $this->redirect('maintenance/index');
        }
        if (!$this->postCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the maintenance page.', FlashMessages::ERROR);
            return $this->redirect('maintenance/index');
        }
        return $admin;
    }

    private function postCsrfValid(): bool
    {
        return (new Csrf(new MagicPropertyStorage($this->container->session())))
            ->isValid((string) ($this->postData()['csrf'] ?? ''));
    }

    private function logMaintenance(object $admin, string $message): void
    {
        $log = new \Entities\Log();
        $log->setAction(\Entities\Log::ACTION_MAINTENANCE)
            ->setData("{$admin->getFormattedName()} {$message}")
            ->setAdmin($admin)
            ->setTimestamp(new \DateTime());
        $this->em()->persist($log);
        $this->em()->flush();
    }
}
