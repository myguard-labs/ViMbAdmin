<?php

/**
 * Domain application service — framework-free business logic for DomainController.
 *
 * Phase 1 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md): the genuine business
 * operations of DomainController (toggle active, assign/remove a domain admin,
 * purge) live here as plain methods that take the Doctrine entity manager plus
 * already-resolved entities and return data or throw ViMbAdmin_Service_Exception.
 * There is no $this->view, no action helper, no redirect/addMessage and no ZF1
 * reference, so each method is unit-testable without booting the framework.
 *
 * The controller keeps only HTTP plumbing: resolve params/entities, authorise,
 * fire plugin notify() hooks, call a method here, then turn the result (or a
 * caught exception) into a view assignment / OSS_Message / redirect.
 *
 * Logging is replicated here (rather than calling the controller's protected
 * log()) so the service owns its full side effect: it writes the same
 * \Entities\Log row the controller used to write, and flushes once.
 *
 * @package ViMbAdmin
 * @subpackage Service
 */
class ViMbAdmin_Service_Domain
{
    /**
     * The Doctrine entity manager, typed as the minimal ObjectManager port the
     * service actually uses (persist / flush / getRepository). The production
     * EntityManager implements ObjectManager, and narrowing the dependency lets
     * the unit test pass a lightweight fake without a database.
     *
     * @var \Doctrine\Persistence\ObjectManager
     */
    private $em;

    public function __construct(\Doctrine\Persistence\ObjectManager $em)
    {
        $this->em = $em;
    }

    /**
     * Flip a domain's active flag, stamp it modified, log the action against the
     * acting admin, and flush. Returns the new active state.
     *
     * Mirrors DomainController::ajaxToggleActiveAction().
     *
     * @return bool the domain's active state after toggling
     */
    public function toggleActive(\Entities\Domain $domain, \Entities\Admin $actor): bool
    {
        $domain->setActive( !$domain->getActive() );
        $domain->setModified( new \DateTime() );

        $active = (bool) $domain->getActive();

        $this->log(
            $actor,
            $domain,
            $active ? \Entities\Log::ACTION_DOMAIN_ACTIVATE : \Entities\Log::ACTION_DOMAIN_DEACTIVATE,
            "{$actor->getFormattedName()} " . ( $active ? 'activated' : 'deactivated' ) . " domain {$domain->getDomain()}"
        );

        $this->em->flush();

        return $active;
    }

    /**
     * Assign a domain admin. Throws if the admin is already assigned to the
     * domain (the controller used to surface this as an OSS_Message::ERROR).
     *
     * Mirrors DomainController::assignAdminAction(): the "already assigned"
     * check reads the inverse side ($domain->getAdmins()) while the mutation is
     * applied on the owning side ($target->addDomain($domain)) — preserved
     * exactly so Doctrine persists the join identically.
     *
     * @throws ViMbAdmin_Service_Exception if $target is already assigned
     */
    public function assignAdmin(\Entities\Domain $domain, \Entities\Admin $target, \Entities\Admin $actor): void
    {
        if( $domain->getAdmins()->contains( $target ) )
            throw new ViMbAdmin_Service_Exception( 'This admin is already assigned to the domain.' );

        $target->addDomain( $domain );

        $this->log(
            $actor,
            $domain,
            \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD,
            "{$actor->getFormattedName()} added admin {$target->getFormattedName()} to domain {$domain->getDomain()}"
        );

        $this->em->flush();
    }

    /**
     * Remove a domain admin and log it.
     *
     * Mirrors DomainController::removeAdminAction().
     */
    public function removeAdmin(\Entities\Domain $domain, \Entities\Admin $target, \Entities\Admin $actor): void
    {
        $target->removeDomain( $domain );

        $this->log(
            $actor,
            $domain,
            \Entities\Log::ACTION_ADMIN_TO_DOMAIN_REMOVE,
            "{$actor->getFormattedName()} removed admin {$target->getFormattedName()} from domain {$domain->getDomain()}"
        );

        $this->em->flush();
    }

    /**
     * Purge a domain and everything hanging off it via the repository.
     *
     * Mirrors DomainController::purgeAction() (minus the plugin notify() hooks,
     * which remain controller-side glue).
     */
    public function purge(\Entities\Domain $domain): void
    {
        $this->em->getRepository( '\\Entities\\Domain' )->purge( $domain );
    }

    /**
     * Write a Log row against the acting admin and domain, and persist it (no
     * flush — the caller flushes once). Same shape as the controller's
     * protected log(), kept framework-free here.
     */
    private function log(\Entities\Admin $actor, \Entities\Domain $domain, string $action, string $message): void
    {
        $log = new \Entities\Log();
        $log->setAction( $action );
        $log->setData( $message );
        $log->setAdmin( $actor );
        $log->setDomain( $domain );
        $log->setTimestamp( new \DateTime() );

        $this->em->persist( $log );
    }
}
