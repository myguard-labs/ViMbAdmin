<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2024 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Framework-free mailbox-task queue RUNNER (docs/ZF1-REMOVAL.md, Phase 4).
 *
 * The execution engine carved out of the ZF1 `QueueController` (`_execute` +
 * `_drain` + their helpers) so BOTH dispatch paths can drive the queue:
 *   - the ZF1 `cli-run` (cron) / `run-now` / `run-task` controller actions, and
 *   - the native kernel `QueueController::runNow` / `runTask`.
 *
 * It depends only on `Doctrine\Persistence\ObjectManager`, the merged options
 * array, the entities, and the (already framework-free) `ViMbAdmin_Doveadm` /
 * `ViMbAdmin_QueueRunner` / `ViMbAdmin_Setting` helpers — no framework. Internal
 * failures that ZF1 sent to its logger are written with `error_log()`.
 *
 *   drain($max)   — lease-gated batch: claim + run up to $max PENDING tasks,
 *                   marking each DONE/FAILED; returns the count, or -1 throttled.
 *   runOne($task) — run a single (already-claimed) task; throws on failure so the
 *                   caller records DONE/FAILED itself (the run-task path).
 *
 * @package ViMbAdmin
 * @subpackage Services
 */
class ViMbAdmin_Service_QueueRunner
{
    /** @var \Doctrine\Persistence\ObjectManager */
    private $em;

    /** @var array */
    private $options;

    public function __construct(\Doctrine\Persistence\ObjectManager $em, array $options)
    {
        $this->em      = $em;
        $this->options = $options;
    }

    /**
     * Lease-gated batch drain: process up to $max PENDING tasks.
     *
     * Returns the number processed, or -1 if every runner slot
     * (queue.runner.max_concurrent) was busy (throttled, not "no work").
     */
    public function drain($max, $verbose = false)
    {
        $max  = max(1, (int) $max);
        $em   = $this->em;
        $repo = $em->getRepository('\\Entities\\MailboxTask');

        $lease = ViMbAdmin_QueueRunner::acquireLease($em, $this->options);
        if ($lease === null) {
            if ($verbose) {
                echo "All runner slots busy (queue.runner.max_concurrent) — skipping.\n";
            }
            return -1;
        }

        // Periodic autoprune sweep (gated to once / 8h): enqueue a PRUNE task for
        // each expired autoprune backup.
        $this->autopruneSweep();

        $processed = 0;
        try {
            foreach ($repo->pending($max) as $task) {
                // Atomic PENDING -> RUNNING; skip if another runner won the row.
                if (!$repo->claim($task)) {
                    continue;
                }

                try {
                    $doveadm = ViMbAdmin_Doveadm::fromOptions($this->options);
                    $this->execute($task, $doveadm);
                    $task->setStatus(\Entities\MailboxTask::STATUS_DONE);
                    $task->appendLog('done');
                } catch (\Throwable $e) {
                    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED);
                    $task->appendLog('FAILED: ' . $e->getMessage());
                    error_log("QueueRunner task {$task->getId()} ({$task->getType()} {$task->getUsername()}): " . $e->getMessage());
                }

                $task->setFinishedAt(new \DateTime());
                $em->flush();

                ViMbAdmin_QueueRunner::heartbeat($em, $lease);

                if ($verbose) {
                    echo " - #{$task->getId()} {$task->getType()} {$task->getUsername()}: {$task->getStatus()}\n";
                }

                $processed++;
            }
        } finally {
            ViMbAdmin_QueueRunner::release($em, $lease);
        }

        return $processed;
    }

    /**
     * Execute a single task (already claimed / RUNNING). Throws on failure; the
     * caller records DONE/FAILED + finishedAt + flush.
     */
    public function runOne(\Entities\MailboxTask $task)
    {
        $this->execute($task, ViMbAdmin_Doveadm::fromOptions($this->options));
    }

    // ---------------------------------------------------------------------
    //  Engine (moved verbatim from the ZF1 QueueController)
    // ---------------------------------------------------------------------

    private function execute(\Entities\MailboxTask $task, ViMbAdmin_Doveadm $doveadm)
    {
        $user = $task->getUsername();

        switch ($task->getType()) {
            case \Entities\MailboxTask::TYPE_REPAIR:
            case \Entities\MailboxTask::TYPE_OPTIMIZE:
                $task->appendLog('force-resync');  $doveadm->forceResync($user);
                $task->appendLog('index');          $doveadm->index($user);
                $task->appendLog('purge');          $doveadm->purge($user);
                $task->appendLog('quota recalc');   $doveadm->quotaRecalc($user);
                break;

            case \Entities\MailboxTask::TYPE_QUOTA_RECALC:
                $task->appendLog('quota recalc');   $doveadm->quotaRecalc($user);
                break;

            case \Entities\MailboxTask::TYPE_MEASURE_SIZE:
                $archive = $this->em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
                if (!$archive || !$archive->getMaildirFile()) {
                    $task->appendLog('measure-size: no archive/dest — nothing to do');
                    break;
                }
                $task->appendLog('measure-size: fs-walk ' . $archive->getMaildirFile());
                $bytes = $doveadm->fsDirSize($archive->getMaildirFile());
                if ($bytes !== null && $bytes > 0) {
                    $archive->setMaildirSize((int) $bytes);
                    $this->em->persist($archive);
                    $task->appendLog('measure-size: ' . $bytes . ' bytes');
                } else {
                    $task->appendLog('measure-size: walk returned no size (kept logical)');
                }
                break;

            case \Entities\MailboxTask::TYPE_PRUNE:
                $archive = $this->em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
                if (!$archive) {
                    $task->appendLog('prune: archive already gone — nothing to do');
                    break;
                }
                if (!$archive->getAutoprune()) {
                    $task->appendLog('prune: autoprune turned off — skipping');
                    break;
                }
                $dest = $archive->getMaildirFile();
                if ($dest) {
                    $task->appendLog('prune: fs delete ' . $dest);
                    $doveadm->fsDelete($dest);
                }
                $this->em->remove($archive);
                ViMbAdmin_Setting::stampNow($this->em, ViMbAdmin_Setting::LAST_PRUNE);
                $task->appendLog('prune: archive removed');
                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "autopruned expired archive backup for {$user}");
                break;

            case \Entities\MailboxTask::TYPE_BACKUP_ORPHAN:
                $this->backupOrphan($task, $doveadm);
                break;

            case \Entities\MailboxTask::TYPE_ARCHIVE:
                $dest = $this->backupDest($task);
                $task->appendLog("backup -> {$dest}");
                $doveadm->backup($user, $dest);
                $task->appendLog('recording archive row');
                $this->recordArchive($task, $dest, false);
                $task->appendLog('mailbox delete (empty store, keep account)');
                $doveadm->mailboxDelete($user);
                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "archived {$user} (backup {$dest}, store emptied, account kept)");
                break;

            case \Entities\MailboxTask::TYPE_DELETE:
                if ($this->autopruneDays() === 0) {
                    $task->appendLog('autoprune.days=0 — instant delete, no backup');
                    $doveadm->mailboxDelete($user);
                    $this->removeMaildirHome($task, $doveadm, $user);
                    $task->appendLog('removing ViMbAdmin mailbox row');
                    $this->removeMailboxRow($user);
                    $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                        "deleted {$user} (instant, autoprune.days=0 — no backup)");
                    break;
                }

                $dest = $this->backupDest($task);
                $task->appendLog("backup -> {$dest}");
                $doveadm->backup($user, $dest);
                $task->appendLog('recording archive row (autoprune on)');
                $this->recordArchive($task, $dest, true);
                $task->appendLog('mailbox delete (empty store)');
                $doveadm->mailboxDelete($user);
                $this->removeMaildirHome($task, $doveadm, $user);
                $task->appendLog('removing ViMbAdmin mailbox row');
                $this->removeMailboxRow($user);
                $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                    "deleted {$user} (backup {$dest}, autoprune on — prunes after queue.autoprune.days)");
                break;

            default:
                throw new ViMbAdmin_Exception('unknown task type: ' . $task->getType());
        }
    }

    private function backupDest(\Entities\MailboxTask $task)
    {
        $tpl  = (string) ($this->options['doveadm']['backup']['dest'] ?? 'maildir:/backups/%d/%u');
        $user = self::assertPathSafe($task->getUsername());
        $dom  = $task->getDomain() ? $task->getDomain()->getDomain() : (strstr($user, '@') ? substr(strrchr($user, '@'), 1) : '');
        $dom  = self::assertPathSafe((string) $dom);
        return str_replace(['%d', '%u'], [$dom, $user], $tpl);
    }

    /**
     * Defence in depth against a maildir/backup path escaping its jail. A
     * username/domain is substituted into filesystem paths ('%d/%u', maildir
     * home) that doveadm then reads/writes/recursively-deletes. Creation is
     * validated (web form + MCP), but a legacy or externally-inserted row could
     * still hold a traversal-shaped value — reject any path separator or
     * parent-dir reference here rather than trusting the input.
     *
     * @throws ViMbAdmin_Exception
     */
    private static function assertPathSafe($value)
    {
        $s = (string) $value;
        if ($s === '' || strpos($s, '/') !== false || strpos($s, "\0") !== false
            || $s === '..' || strpos($s, '..') !== false) {
            throw new ViMbAdmin_Exception('refusing unsafe path component in mailbox task: ' . $s);
        }
        return $s;
    }

    private function recordArchive(\Entities\MailboxTask $task, $dest, $autoprune)
    {
        $em   = $this->em;
        $user = $task->getUsername();
        $now  = new \DateTime();

        $archive = $em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
        if (!$archive) {
            $archive = new \Entities\Archive();
            $archive->setUsername($user);
        }

        $origSize = null;
        try {
            ViMbAdmin_Doveadm::fromOptions($this->options)->quotaRecalc($user);
            $bytes = $em->getConnection()->fetchOne('SELECT bytes FROM dovecot_quota WHERE username = ?', [$user]);
            if ($bytes !== false && $bytes !== null) {
                $origSize = (int) $bytes;
            }
        } catch (\Throwable $e) {
            error_log("QueueRunner::recordArchive quota {$user}: " . $e->getMessage());
        }

        $size = $origSize;

        $mbData = null;
        $mb = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $user]);
        if ($mb) {
            $mbData = [
                'username'   => $mb->getUsername(),
                'local_part' => $mb->getLocalPart(),
                'name'       => $mb->getName(),
                'password'   => $mb->getPassword(),
                'quota'      => $mb->getQuota(),
                'active'     => $mb->getActive(),
            ];
        }

        $archive->setStatus(\Entities\Archive::STATUS_ARCHIVED)
                ->setArchivedAt($now)
                ->setStatusChangedAt($now)
                ->setArchivedBy($task->getRequestedBy())
                ->setDomain($task->getDomain())
                ->setMaildirServer((string) ($this->options['doveadm']['http']['url'] ?? ''))
                ->setMaildirFile($dest)
                ->setMaildirOrigSize($origSize)
                ->setMaildirSize($size)
                ->setAutoprune($autoprune)
                ->setData(json_encode([
                    'username' => $user,
                    'type'     => $task->getType(),
                    'task_id'  => $task->getId(),
                    'dest'     => $dest,
                    'mailbox'  => $mbData,
                ]));

        $em->persist($archive);

        $open = $em->createQuery(
            'SELECT COUNT(t.id) FROM \Entities\MailboxTask t
              WHERE t.username = :u AND t.type = :t AND t.status IN (:open)')
            ->setParameter('u', $user)
            ->setParameter('t', \Entities\MailboxTask::TYPE_MEASURE_SIZE)
            ->setParameter('open', [\Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING])
            ->getSingleScalarResult();
        if ((int) $open === 0) {
            $mt = new \Entities\MailboxTask();
            $mt->setType(\Entities\MailboxTask::TYPE_MEASURE_SIZE)
               ->setUsername($user)
               ->setStatus(\Entities\MailboxTask::STATUS_PENDING)
               ->setPriority(-10)
               ->setCreatedAt(new \DateTime())
               ->setDomain($task->getDomain())
               ->setRequestedBy($task->getRequestedBy())
               ->setData(json_encode(['dest' => $dest]));
            $em->persist($mt);
        }
    }

    private function removeMaildirHome(\Entities\MailboxTask $task, $doveadm, $user)
    {
        $root = isset($this->options['doveadm']['maildir_root'])
            ? rtrim((string) $this->options['doveadm']['maildir_root'], '/')
            : '/opt/myguard/dovecot/maildir';
        $home = $root . '/' . self::assertPathSafe($user);

        try {
            if ($doveadm->maildirHasMail($home)) {
                $task->appendLog('KEEP maildir home — still contains mail (empty/backup step failed?): ' . $home);
                return;
            }
        } catch (\Throwable $e) {
            $task->appendLog('KEEP maildir home — could not verify it is empty: ' . $e->getMessage());
            return;
        }

        try {
            $task->appendLog('remove empty maildir home ' . $home);
            $doveadm->fsDelete($home);
        } catch (\Throwable $e) {
            $task->appendLog('remove maildir home warning: ' . $e->getMessage());
        }
    }

    private function removeMailboxRow($username)
    {
        $em      = $this->em;
        $mailbox = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $username]);
        if (!$mailbox) {
            return;
        }

        $em->getRepository('\\Entities\\Mailbox')->purgeMailbox($mailbox, null, true);

        try {
            $em->getConnection()->executeStatement('DELETE FROM dovecot_quota WHERE username = ?', [$username]);
        } catch (\Exception $e) {
            error_log('vimbadmin: dovecot_quota cleanup failed for ' . $username . ': ' . $e->getMessage());
        }
    }

    private function logAudit(\Entities\MailboxTask $task, $action, $message)
    {
        try {
            $log = new \Entities\Log();
            $log->setAction($action)
                ->setData($message)
                ->setTimestamp(new \DateTime());
            if (method_exists($task, 'getRequestedBy') && $task->getRequestedBy()) {
                $log->setAdmin($task->getRequestedBy());
            }
            if (method_exists($task, 'getDomain') && $task->getDomain()) {
                $log->setDomain($task->getDomain());
            }
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $e) {
            error_log('QueueRunner::logAudit: ' . $e->getMessage());
        }
    }

    private function autopruneDays()
    {
        $v = $this->options['queue']['autoprune']['days'] ?? 90;
        return max(0, (int) $v);
    }

    private function autopruneSweep()
    {
        $em = $this->em;

        $last = ViMbAdmin_Setting::get($em, ViMbAdmin_Setting::LAST_PRUNE_SWEEP);
        if ($last !== null) {
            $lastTs = strtotime((string) $last);
            if ($lastTs !== false && (time() - $lastTs) < 8 * 3600) {
                return;
            }
        }

        ViMbAdmin_Setting::set($em, ViMbAdmin_Setting::LAST_PRUNE_SWEEP, (new \DateTime())->format('c'));

        try {
            $days   = max(0, (int) ($this->options['queue']['autoprune']['days'] ?? 90));
            $cutoff = (new \DateTime())->modify('-' . $days . ' days');
            $expired = $em->getRepository('\\Entities\\Archive')->findAutoprune($cutoff);

            foreach ($expired as $archive) {
                $user = $archive->getUsername();

                $open = (int) $em->createQuery(
                    'SELECT COUNT(t.id) FROM \Entities\MailboxTask t
                      WHERE t.username = :u AND t.type = :t AND t.status IN (:open)')
                    ->setParameter('u', $user)
                    ->setParameter('t', \Entities\MailboxTask::TYPE_PRUNE)
                    ->setParameter('open', [\Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING])
                    ->getSingleScalarResult();
                if ($open > 0) {
                    continue;
                }

                $mt = new \Entities\MailboxTask();
                $mt->setType(\Entities\MailboxTask::TYPE_PRUNE)
                   ->setUsername($user)
                   ->setStatus(\Entities\MailboxTask::STATUS_PENDING)
                   ->setPriority(-20)
                   ->setCreatedAt(new \DateTime())
                   ->setDomain($archive->getDomain())
                   ->setData(json_encode(['dest' => $archive->getMaildirFile()]));
                $em->persist($mt);
            }
            $em->flush();
        } catch (\Throwable $e) {
            error_log('QueueRunner::autopruneSweep: ' . $e->getMessage());
        }
    }

    private function backupOrphan(\Entities\MailboxTask $task, $doveadm)
    {
        $em   = $this->em;
        $user = $task->getUsername();
        $conn = $em->getConnection();

        $exists = (int) $em->createQuery('SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.username = :u')
            ->setParameter('u', $user)->getSingleScalarResult();
        if ($exists > 0) {
            $task->appendLog('backup-orphan: a real mailbox now exists — skipping');
            return;
        }

        $domainPart = strstr($user, '@') ? substr(strrchr($user, '@'), 1) : null;
        if (!$domainPart) {
            $task->appendLog("backup-orphan: '{$user}' has no domain part — cannot create temp user");
            return;
        }
        $domain = $em->getRepository('\\Entities\\Domain')->findOneBy(['domain' => $domainPart]);

        // If the orphan's domain isn't in ViMbAdmin we still want to back it up
        // and reap it. The temp mailbox row below needs a NOT NULL Domain_id, so
        // create a transient INACTIVE domain row (active=0 -> postfix/dovecot SQL
        // auth filter on active=1, so no mail/routing effect). It is removed
        // again in the finally block UNLESS an Archive row ends up referencing
        // it: Archive.Domain_id has no onDelete (-> RESTRICT) and the archive is
        // EM-persisted then flushed AFTER this method, so deleting the domain
        // here would make that later flush fail with an FK error. Hence the
        // has-mail path keeps the (inactive) domain; only the empty-skeleton
        // path (no archive) drops it again.
        $tempDomainId = null;
        if (!$domain) {
            $conn->insert('domain', [
                'domain'      => $domainPart,
                'description' => 'auto-created for orphan backup',
                'active'      => 0,
                'created'     => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $tempDomainId = (int) $conn->lastInsertId();
            $domain = $em->getRepository('\\Entities\\Domain')->findOneBy(['domain' => $domainPart]);
            $task->appendLog("backup-orphan: domain '{$domainPart}' not in ViMbAdmin — created transient inactive domain row #{$tempDomainId}");
        }

        $root      = isset($this->options['doveadm']['maildir_root'])
            ? rtrim((string) $this->options['doveadm']['maildir_root'], '/')
            : '/opt/myguard/dovecot/maildir';
        $home      = $root . '/' . $user;
        $localPart = strstr($user, '@', true) ?: $user;
        $tempId    = null;
        $keepDomain = false;   // set true once an Archive row references $domain

        try {
            $conn->insert('mailbox', [
                'username'   => $user,
                'password'   => '{PLAIN}!orphan-backup-no-login!',
                'local_part' => $localPart,
                'quota'      => 0,
                'active'     => 0,
                'created'    => (new \DateTime())->format('Y-m-d H:i:s'),
                'Domain_id'  => $domain->getId(),
            ]);
            $tempId = (int) $conn->lastInsertId();
            $task->appendLog("backup-orphan: temp user row #{$tempId} created");

            $task->setDomain($domain);

            $doveadm->authCacheFlush();

            $task->appendLog('orphan: repair (force-resync/index/purge)');
            try {
                $doveadm->forceResync($user);
                $doveadm->index($user);
                $doveadm->purge($user);
            } catch (\Throwable $e) {
                $task->appendLog('orphan: repair warning: ' . $e->getMessage());
            }

            if ($doveadm->maildirHasMail($home)) {
                $dest = $this->backupDest($task);
                $task->appendLog("orphan: has mail — backup -> {$dest}");
                $doveadm->backup($user, $dest);

                $task->appendLog('orphan: recording archive row');
                $this->recordArchive($task, $dest, false);
                // Archive row now references $domain (FK RESTRICT, flushed after
                // this method) -> a transient domain must NOT be deleted.
                $keepDomain = true;

                $task->appendLog('orphan: empty store + remove maildir home');
                try {
                    $doveadm->mailboxDelete($user);
                } catch (\Throwable $e) {
                    $task->appendLog('orphan: mailboxDelete warning: ' . $e->getMessage());
                }
                $this->removeMaildirHome($task, $doveadm, $user);

                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "imported ORPHAN maildir for {$user}: backed up + removed (had mail)");
            } else {
                $task->appendLog('orphan: empty skeleton — removing maildir home (no backup)');
                $this->removeMaildirHome($task, $doveadm, $user);

                $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                    "removed empty ORPHAN maildir skeleton for {$user} (no mail)");
            }
        } finally {
            if ($tempId !== null) {
                try {
                    $conn->delete('mailbox', ['id' => $tempId]);
                } catch (\Throwable $e) {
                    error_log("backup-orphan temp-row cleanup {$user}: " . $e->getMessage());
                }
                $task->appendLog('backup-orphan: temp user row removed');
            }
            // Remove the transient domain (created above) AFTER the temp mailbox
            // row that FK-references it. Keep it when an archive references it.
            if ($tempDomainId !== null) {
                if ($keepDomain) {
                    $task->appendLog("backup-orphan: transient domain '{$domainPart}' kept (referenced by archive), left inactive");
                } else {
                    // Drop the in-memory reference first: the task FK is
                    // onDelete SET NULL at the DB, but the EM still holds the
                    // transient Domain entity and would re-write Domain_id on the
                    // task's later flush (-> FK error against the deleted row).
                    $task->setDomain(null);
                    try { $em->detach($domain); } catch (\Throwable $e) {}
                    try {
                        $conn->delete('domain', ['id' => $tempDomainId]);
                        $task->appendLog('backup-orphan: transient domain row removed');
                    } catch (\Throwable $e) {
                        error_log("backup-orphan temp-domain cleanup {$domainPart}: " . $e->getMessage());
                        $task->appendLog('backup-orphan: transient domain kept (cleanup failed: ' . $e->getMessage() . ')');
                    }
                }
            }
            try {
                $doveadm->authCacheFlush();
            } catch (\Throwable $e) {
            }
        }
    }
}
