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
