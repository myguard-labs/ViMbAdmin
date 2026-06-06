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
     * Create a new mailbox.
     *
     * Mirrors the create path of the legacy `MailboxController::addAction`: the
     * caller has already resolved `$domain`, set the username, name, quota and
     * the PLAINTEXT password on `$mailbox`, and run its own form validation /
     * uniqueness / quota-clamp checks. This service owns the rest of the add:
     * it derives the storage fields from the application options (homedir / uid /
     * gid + the formatted home/maildir paths), hashes the plaintext password,
     * marks the mailbox active and not delete-pending, stamps `created`, persists
     * it, optionally creates the matching auto mailbox-alias (address -> address,
     * skipped if one already exists), bumps the domain's mailbox count, logs the
     * add and flushes — firing the supplied pre/post-flush plugin hooks around
     * the flush (the native equivalent of the ZF1 `addPreflush`/`addPostflush`
     * notify). Like {@see toggleActive}/{@see purge} the hooks are optional
     * callables so either dispatch path can thread its plugin notify in.
     *
     * @param array $options the merged application options (needs
     *        `defaults.mailbox.{homedir,maildir,uid,gid,password_scheme[,password_salt]}`
     *        and the top-level `mailboxAliases` switch)
     * @param callable():void|null $preFlush  fires after the log, before flush
     * @param callable():void|null $postFlush fires after flush
     */
    public function create(
        \Entities\Mailbox $mailbox,
        \Entities\Domain $domain,
        \Entities\Admin $actor,
        array $options,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): \Entities\Mailbox {
        $mb = $options['defaults']['mailbox'];

        $mailbox->setDomain($domain);
        $mailbox->setHomedir($mb['homedir']);
        $mailbox->setUid($mb['uid']);
        $mailbox->setGid($mb['gid']);
        $mailbox->formatHomedir($mb['homedir']);
        $mailbox->formatMaildir($mb['maildir']);
        $mailbox->setActive(1);
        $mailbox->setDeletePending(false);
        $mailbox->setCreated(new \DateTime());

        $mailbox->setPassword(\OSS_Auth_Password::hash($mailbox->getPassword(), [
            'pwhash'   => $mb['password_scheme'],
            'pwsalt'   => $mb['password_salt'] ?? null,
            'username' => $mailbox->getUsername(),
        ]));

        $this->em->persist($mailbox);

        // Auto mailbox-alias (address -> address). Skip if an alias with that
        // address already exists (e.g. an orphan from an earlier failed attempt)
        // — inserting a duplicate violates the unique key and rolls the create
        // back.
        if (!empty($options['mailboxAliases']) && (int) $options['mailboxAliases'] === 1
            && $this->em->getRepository('\\Entities\\Alias')->findOneBy(['address' => $mailbox->getUsername()]) === null
        ) {
            $alias = new \Entities\Alias();
            $alias->setAddress($mailbox->getUsername());
            $alias->setGoto($mailbox->getUsername());
            $alias->setDomain($domain);
            $alias->setActive(1);
            $alias->setCreated(new \DateTime());
            $this->em->persist($alias);
        }

        $domain->setMailboxCount($domain->getMailboxCount() + 1);

        $this->log(
            $actor,
            \Entities\Log::ACTION_MAILBOX_ADD,
            "{$actor->getFormattedName()} added mailbox {$mailbox->getUsername()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return $mailbox;
    }

    /**
     * Persist an edit to an existing mailbox.
     *
     * Mirrors the edit path of the legacy `MailboxController::addAction` (reached
     * via `editAction`'s `forward('add')`): the caller has already applied the
     * editable fields (name, quota, alt_email, the plugin writebacks) onto
     * `$mailbox`. This service stamps `modified`, logs the edit and flushes,
     * firing the supplied pre/post-flush plugin hooks around the flush (the
     * native equivalent of the ZF1 `addPreflush`/`addPostflush` notify). The
     * lighter sibling of {@see create} — no derivation, no auto-alias, no count
     * bump, since an edit changes none of those.
     *
     * @param callable():void|null $preFlush  fires after the log, before flush
     * @param callable():void|null $postFlush fires after flush
     */
    public function update(
        \Entities\Mailbox $mailbox,
        \Entities\Admin $actor,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): \Entities\Mailbox {
        $mailbox->setModified(new \DateTime());

        $this->log(
            $actor,
            \Entities\Log::ACTION_MAILBOX_EDIT,
            "{$actor->getFormattedName()} edited mailbox {$mailbox->getUsername()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return $mailbox;
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
