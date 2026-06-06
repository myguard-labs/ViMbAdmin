<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2024 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Framework-free service for Archive mutations (docs/ZF1-REMOVAL.md, Phase 4).
 *
 * Carves the autoprune toggle out of `ArchiveController::toggleAutopruneAction`
 * so it can run from either dispatch path. Unlike the mailbox/alias toggles this
 * one fires no plugin hooks, so it needs no callback threading — it owns the
 * entity flip, the timestamp bookkeeping, the Log write and the single flush.
 *
 * Depends only on `Doctrine\Persistence\ObjectManager` and the entities, so it is
 * unit-testable with no framework and no DB.
 *
 * @package ViMbAdmin
 * @subpackage Services
 */
class ViMbAdmin_Service_Archive
{
    /**
     * @var \Doctrine\Persistence\ObjectManager
     */
    private $em;

    public function __construct(\Doctrine\Persistence\ObjectManager $em)
    {
        $this->em = $em;
    }

    /**
     * Toggle an archive's autoprune flag, mirroring the legacy
     * `ArchiveController::toggleAutopruneAction`.
     *
     * ON → OFF: clears autoprune and stamps `statusChangedAt` (the backup stops
     * expiring). OFF → ON: sets autoprune, and stamps BOTH `archivedAt` and
     * `statusChangedAt` to now so the prune window restarts from now. Logs an
     * `ARCHIVE_REQUEST` either way and flushes once. Returns the new autoprune
     * state so the caller can pick the right flash message.
     *
     * @return bool the new autoprune state (true = enabled)
     */
    public function toggleAutoprune(\Entities\Archive $archive, \Entities\Admin $actor): bool
    {
        $now = new \DateTime();

        if ($archive->getAutoprune()) {
            // ON -> OFF
            $archive->setAutoprune(false)
                    ->setStatusChangedAt($now);

            $this->log(
                $actor,
                \Entities\Log::ACTION_ARCHIVE_REQUEST,
                "{$actor->getFormattedName()} disabled autoprune for archive {$archive->getUsername()}"
            );
        } else {
            // OFF -> ON (restart the prune window)
            $archive->setAutoprune(true)
                    ->setArchivedAt($now)
                    ->setStatusChangedAt($now);

            $this->log(
                $actor,
                \Entities\Log::ACTION_ARCHIVE_REQUEST,
                "{$actor->getFormattedName()} enabled autoprune for archive {$archive->getUsername()} (window reset to now)"
            );
        }

        $this->em->flush();

        return (bool) $archive->getAutoprune();
    }

    /**
     * Delete an archive row (the DB side of `ArchiveController::deleteAction`).
     *
     * The caller has already removed the backup files via doveadm (an I/O concern
     * that must succeed first, so a failure aborts before the row is dropped). This
     * just removes the archive, logs the deletion and flushes.
     */
    public function delete(\Entities\Archive $archive, \Entities\Admin $actor): void
    {
        $user = $archive->getUsername();

        $this->em->remove($archive);

        $this->log(
            $actor,
            \Entities\Log::ACTION_ARCHIVE_REQUEST,
            "{$actor->getFormattedName()} deleted archive backup for {$user}"
        );

        $this->em->flush();
    }

    /**
     * Write a Log row for an action (persist only; the flush above commits it).
     */
    private function log(\Entities\Admin $actor, string $action, string $message): void
    {
        $log = new \Entities\Log();
        $log->setAction($action);
        $log->setData($message);
        $log->setAdmin($actor);
        $log->setTimestamp(new \DateTime());

        $this->em->persist($log);
    }
}
