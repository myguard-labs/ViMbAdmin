<?php

/**
 * Admin application service — framework-free business logic for AdminController.
 *
 * Phase 1 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). The genuine business
 * operations of AdminController — toggle active, toggle super, assign/remove a
 * domain, and purge — live here as plain methods taking the Doctrine
 * ObjectManager plus resolved entities, returning data or throwing
 * ViMbAdmin_Service_Exception. No $this->view, no action helper, no
 * redirect/addMessage, no ZF1 reference: each method is unit-testable without
 * the framework.
 *
 * Self-guards that drive HTTP responses (you cannot toggle/purge yourself, which
 * the controller answers with 'ko' or an OSS_Message + redirect) stay in the
 * controller. The "already assigned" rule is a business rule and is enforced
 * here as a thrown exception.
 *
 * Logging mirrors the controller's protected log(): a \Entities\Log row bound to
 * the acting admin, and — only for the per-domain operations — to the domain in
 * context (the admin-level toggles/purge bind no domain, matching the old
 * `if( $this->getDomain() )` guard). The caller flushes once via the service.
 *
 * @package ViMbAdmin
 * @subpackage Service
 */
class ViMbAdmin_Service_Admin
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
     * Flip the target admin's active flag, stamp modified, log, flush.
     * Mirrors AdminController::ajaxToggleActiveAction() (minus the self-guard).
     *
     * @return bool the active state after toggling
     */
    public function toggleActive(\Entities\Admin $target, \Entities\Admin $actor): bool
    {
        $target->setActive( !$target->getActive() );
        $target->setModified( new \DateTime() );

        $active = (bool) $target->getActive();

        $this->log(
            $actor,
            $active ? \Entities\Log::ACTION_ADMIN_ACTIVATE : \Entities\Log::ACTION_ADMIN_DEACTIVATE,
            "{$actor->getFormattedName()} " . ( $active ? 'activated' : 'deactivated' ) . " admin {$target->getFormattedName()}"
        );

        $this->em->flush();

        return $active;
    }

    /**
     * Flip the target admin's super flag, stamp modified, log, flush.
     * Mirrors AdminController::ajaxToggleSuperAction() (minus the self-guard).
     *
     * @return bool the super state after toggling
     */
    public function toggleSuper(\Entities\Admin $target, \Entities\Admin $actor): bool
    {
        $target->setSuper( !$target->getSuper() );
        $target->setModified( new \DateTime() );

        $super = (bool) $target->getSuper();

        $this->log(
            $actor,
            $super ? \Entities\Log::ACTION_ADMIN_SUPER : \Entities\Log::ACTION_ADMIN_NORMAL,
            "{$actor->getFormattedName()} set admin {$target->getFormattedName()} as " . ( $super ? 'super' : 'normal' )
        );

        $this->em->flush();

        return $super;
    }

    /**
     * Assign a domain to an admin. Throws if already assigned.
     * Mirrors AdminController::assignDomainAction().
     *
     * @throws ViMbAdmin_Service_Exception if $domain is already assigned to $target
     */
    public function assignDomain(\Entities\Admin $target, \Entities\Domain $domain, \Entities\Admin $actor): void
    {
        if( $target->getDomains()->contains( $domain ) )
            throw new ViMbAdmin_Service_Exception( 'This domain is already assigned to the admin.' );

        $target->addDomain( $domain );

        $this->log(
            $actor,
            \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD,
            "{$actor->getFormattedName()} added admin {$target->getFormattedName()} to domain {$domain->getDomain()}",
            $domain
        );

        $this->em->flush();
    }

    /**
     * Remove a domain from an admin and log it.
     * Mirrors AdminController::removeDomainAction().
     */
    public function removeDomain(\Entities\Admin $target, \Entities\Domain $domain, \Entities\Admin $actor): void
    {
        $target->removeDomain( $domain );

        $this->log(
            $actor,
            \Entities\Log::ACTION_ADMIN_TO_DOMAIN_REMOVE,
            "{$actor->getFormattedName()} removed admin {$target->getFormattedName()} from domain {$domain->getDomain()}",
            $domain
        );

        $this->em->flush();
    }

    /**
     * Purge an admin: drop its preferences, logs and remember-me tokens, detach
     * it from every domain it administers, remove the admin, log the purge
     * against the actor, and flush. Mirrors AdminController::purgeAction()
     * (minus the self / not-found guards, which stay controller-side).
     */
    public function purge(\Entities\Admin $target, \Entities\Admin $actor): void
    {
        foreach( $target->getPreferences() as $pref )
            $this->em->remove( $pref );

        foreach( $target->getLogs() as $log )
            $this->em->remove( $log );

        foreach( $target->getRememberMes() as $rememberMe )
            $this->em->remove( $rememberMe );

        foreach( $target->getDomains() as $domain )
            $domain->removeAdmin( $target );

        $this->em->remove( $target );

        $this->log(
            $actor,
            \Entities\Log::ACTION_ADMIN_PURGE,
            "{$actor->getFormattedName()} purged admin {$target->getFormattedName()}"
        );

        $this->em->flush();
    }

    /**
     * Write a Log row against the acting admin (and optionally a domain) and
     * persist it; the caller flushes. Same shape as the controller's protected
     * log(), which only binds a domain when one is in context.
     */
    private function log(\Entities\Admin $actor, string $action, string $message, ?\Entities\Domain $domain = null): void
    {
        $log = new \Entities\Log();
        $log->setAction( $action );
        $log->setData( $message );
        $log->setAdmin( $actor );

        if( $domain !== null )
            $log->setDomain( $domain );

        $log->setTimestamp( new \DateTime() );

        $this->em->persist( $log );
    }
}
