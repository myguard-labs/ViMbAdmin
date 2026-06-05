<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2024 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Framework-free service for Mailbox mutations (docs/ZF1-REMOVAL.md, Phase 4).
 *
 * The counterpart to {@see ViMbAdmin_Service_Admin} / {@see ViMbAdmin_Service_Domain}
 * for the mailbox entity. Phase 1 deferred the mailbox/alias services because
 * their controller actions interleave plugin `notify()` hooks between the
 * mutation and the flush. This service resolves that by taking the hooks as
 * optional callables, so the caller (the ZF1 controller today, a native
 * controller once the plugin contract is framework-free) supplies the
 * notify dispatch while the service owns the entity change, the log write and
 * the single flush.
 *
 * It depends only on `Doctrine\Persistence\ObjectManager` and the entities, so
 * it is reusable from either dispatch path and unit-testable with no framework.
 *
 * @package ViMbAdmin
 * @subpackage Services
 */
class ViMbAdmin_Service_Mailbox
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
     * Toggle a mailbox's active flag, threading the plugin hooks.
     *
     * Order matches the legacy `MailboxController::ajaxToggleActiveAction`:
     *   1. `$preToggle` fires FIRST, with the mailbox still in its current state;
     *      if it returns false (a plugin veto) nothing changes and null is
     *      returned (the caller prints "ko").
     *   2. the active flag flips, `modified` is bumped and the change is logged;
     *   3. `$preFlush` fires, then the single flush, then `$postFlush`.
     *
     * @param callable():bool|null $preToggle veto hook (false aborts)
     * @param callable():void|null $preFlush  fires after mutation, before flush
     * @param callable():void|null $postFlush fires after flush
     * @return bool|null the new active state, or null if a plugin vetoed
     */
    public function toggleActive(
        \Entities\Mailbox $mailbox,
        \Entities\Admin $actor,
        ?callable $preToggle = null,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): ?bool {
        if ($preToggle !== null && $preToggle() === false) {
            return null;
        }

        $mailbox->setActive(!$mailbox->getActive());
        $mailbox->setModified(new \DateTime());

        $active = (bool) $mailbox->getActive();

        $this->log(
            $actor,
            $active ? \Entities\Log::ACTION_MAILBOX_ACTIVATE : \Entities\Log::ACTION_MAILBOX_DEACTIVATE,
            "{$actor->getFormattedName()} " . ($active ? 'activated' : 'deactivated') . " mailbox {$mailbox->getUsername()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return $active;
    }

    /**
     * Purge a mailbox (and its dependent aliases), threading the plugin hooks.
     *
     * Order matches the legacy `MailboxController::purgeAction` POST branch:
     *   1. `$preRemove` fires FIRST; if it returns false (a plugin veto) nothing
     *      changes and false is returned (the caller skips its success notice);
     *   2. the repository `purgeMailbox()` clears the mailbox's preferences and
     *      dependent aliases, and the purge is logged;
     *   3. `$preFlush` fires; then, depending on `$deleteFiles`, the mailbox is
     *      either marked delete-pending + inactive (so the maildir is reaped
     *      out-of-band) or removed outright; then the single flush, then
     *      `$postFlush`.
     *
     * `purgeMailbox()`'s third argument is `$removeMailbox = !$deleteFiles`,
     * mirroring the ZF1 call exactly.
     *
     * @param callable():bool|null $preRemove veto hook (false aborts the purge)
     * @param callable():void|null $preFlush  fires after the purge, before flush
     * @param callable():void|null $postFlush fires after flush
     * @return bool true when purged, false when a plugin vetoed
     */
    public function purge(
        \Entities\Mailbox $mailbox,
        \Entities\Admin $actor,
        bool $deleteFiles,
        ?callable $preRemove = null,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): bool {
        if ($preRemove !== null && $preRemove() === false) {
            return false;
        }

        $this->em->getRepository('\\Entities\\Mailbox')->purgeMailbox($mailbox, $actor, !$deleteFiles);

        $this->log(
            $actor,
            \Entities\Log::ACTION_MAILBOX_PURGE,
            "{$actor->getFormattedName()} purged mailbox {$mailbox->getUsername()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        if ($deleteFiles) {
            $mailbox->setDeletePending(true);
            $mailbox->setActive(false);
        } else {
            $this->em->remove($mailbox);
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return true;
    }

    /**
     * Write a Log row for an action (persist only; the caller's flush commits it).
     */
    private function log(\Entities\Admin $actor, string $action, string $message, ?\Entities\Domain $domain = null): void
    {
        $log = new \Entities\Log();
        $log->setAction($action);
        $log->setData($message);
        $log->setAdmin($actor);

        if ($domain !== null) {
            $log->setDomain($domain);
        }

        $log->setTimestamp(new \DateTime());

        $this->em->persist($log);
    }
}
