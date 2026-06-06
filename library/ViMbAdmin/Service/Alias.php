<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2024 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Framework-free service for Alias mutations (docs/ZF1-REMOVAL.md, Phase 4).
 *
 * The alias counterpart to {@see ViMbAdmin_Service_Mailbox}: the active-toggle is
 * carved out of the controller, with the plugin `notify()` hooks (which run
 * between the mutation and the flush) supplied as optional callables so the
 * service stays framework-free while preserving the exact hook ordering.
 *
 * Depends only on `Doctrine\Persistence\ObjectManager` and the entities, so it is
 * reusable from either dispatch path and unit-testable with no framework.
 *
 * @package ViMbAdmin
 * @subpackage Services
 */
class ViMbAdmin_Service_Alias
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
     * Toggle an alias's active flag, threading the plugin hooks.
     *
     * Order matches the legacy `AliasController::ajaxToggleActiveAction`:
     *   1. `$preToggle` fires FIRST, with the alias still in its current state;
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
        \Entities\Alias $alias,
        \Entities\Admin $actor,
        ?callable $preToggle = null,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): ?bool {
        if ($preToggle !== null && $preToggle() === false) {
            return null;
        }

        $alias->setActive(!$alias->getActive());
        $alias->setModified(new \DateTime());

        $active = (bool) $alias->getActive();

        $this->log(
            $actor,
            $active ? \Entities\Log::ACTION_ALIAS_ACTIVATE : \Entities\Log::ACTION_ALIAS_DEACTIVATE,
            "{$actor->getFormattedName()} " . ($active ? 'activated' : 'deactivated') . " alias {$alias->getAddress()}"
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
     * Create a new alias.
     *
     * Mirrors the create path of the legacy `AliasController::addAction`: the
     * caller has already resolved `$domain`, parsed the goto list and set the
     * `address` + `goto` on `$alias` (the form/validation concerns), and run its
     * own uniqueness / allowance checks. This service sets the domain, marks the
     * alias active, stamps `created`, persists it, bumps the domain's alias count
     * (only when the alias is not a plain mailbox self-alias, i.e. address != goto,
     * matching ZF1), logs the add and flushes — firing the pre/post-flush plugin
     * hooks around the flush (the native equivalent of the ZF1 `addPreflush`/
     * `addPostflush` notify).
     *
     * @param callable():void|null $preFlush  fires after the log, before flush
     * @param callable():void|null $postFlush fires after flush
     */
    public function create(
        \Entities\Alias $alias,
        \Entities\Domain $domain,
        \Entities\Admin $actor,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): \Entities\Alias {
        $alias->setDomain($domain);
        $alias->setActive(1);
        $alias->setCreated(new \DateTime());

        $this->em->persist($alias);

        // A mailbox self-alias (address == goto) does not count against the
        // domain's alias allowance (ZF1 parity).
        if ($alias->getAddress() != $alias->getGoto()) {
            $domain->setAliasCount($domain->getAliasCount() + 1);
        }

        $this->log(
            $actor,
            \Entities\Log::ACTION_ALIAS_ADD,
            "{$actor->getFormattedName()} added alias {$alias->getAddress()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return $alias;
    }

    /**
     * Update an existing alias (the edit path).
     *
     * The lighter sibling of {@see create()}: the caller has already re-parsed the
     * goto list onto `$alias` (the form concern); this service stamps `modified`,
     * logs the edit and flushes — firing the pre/post-flush plugin hooks around the
     * flush (the native equivalent of the ZF1 edit `addPreflush`/`addPostflush`
     * notify). The address, domain and alias count are not touched on an edit
     * (matching the ZF1 `addAction` edit branch, which only re-sets `goto`).
     *
     * @param callable():void|null $preFlush  fires after the log, before flush
     * @param callable():void|null $postFlush fires after flush
     */
    public function update(
        \Entities\Alias $alias,
        \Entities\Admin $actor,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): \Entities\Alias {
        $alias->setModified(new \DateTime());

        $this->log(
            $actor,
            \Entities\Log::ACTION_ALIAS_EDIT,
            "{$actor->getFormattedName()} edited alias {$alias->getAddress()}"
        );

        if ($preFlush !== null) {
            $preFlush();
        }

        $this->em->flush();

        if ($postFlush !== null) {
            $postFlush();
        }

        return $alias;
    }

    /**
     * Delete an alias.
     *
     * Mirrors the legacy `AliasController::deleteAction`: it removes the alias's
     * preferences, then — unless a `$preRemove` plugin veto returns false —
     * removes the alias, decrements the domain's alias count (only when
     * address != goto, ZF1 parity), logs the delete and flushes, firing the
     * pre/post-flush hooks around the flush. Returns true when the alias was
     * removed, false when a plugin vetoed (nothing is flushed on a veto).
     *
     * @param callable():bool|null $preRemove veto hook (false aborts)
     * @param callable():void|null $preFlush  fires after the log, before flush
     * @param callable():void|null $postFlush fires after flush
     */
    public function delete(
        \Entities\Alias $alias,
        \Entities\Admin $actor,
        ?callable $preRemove = null,
        ?callable $preFlush = null,
        ?callable $postFlush = null
    ): bool {
        foreach ($alias->getPreferences() as $pref) {
            $this->em->remove($pref);
        }

        if ($preRemove !== null && $preRemove() === false) {
            return false;
        }

        $this->em->remove($alias);

        if ($alias->getAddress() != $alias->getGoto()) {
            $alias->getDomain()->setAliasCount($alias->getDomain()->getAliasCount() - 1);
        }

        $this->log(
            $actor,
            \Entities\Log::ACTION_ALIAS_DELETE,
            "{$actor->getFormattedName()} removed alias {$alias->getAddress()}"
        );

        if ($preFlush !== null) {
            $preFlush();
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
