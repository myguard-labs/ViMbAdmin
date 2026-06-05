<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * Plugin mutation-context contract (Phase 4b of docs/ZF1-REMOVAL.md).
 *
 * Historically a plugin hook received the concrete ZF1 controller and called a
 * handful of accessors on it (`getDomain()`, `getMailbox()`, `getD2EM()`, …).
 * That hard-wired the plugin system to the Zend MVC controllers and was the wall
 * blocking a native (framework-free) controller from firing the same hooks.
 *
 * This interface (with {@see ViMbAdmin_Plugin_MailboxContext} /
 * {@see ViMbAdmin_Plugin_AliasContext}) names exactly the surface the *mutation*
 * hooks (toggle/purge/delete/add-postflush in MailboxAutomaticAliases) use, so a
 * hook can type-hint a contract instead of a concrete controller. Both the ZF1
 * controllers (which already expose every method) and a native context adapter
 * can satisfy it, letting the identical plugin code run from either MVC.
 *
 * The form-build hooks (AccessPermissions / AdditionalInfo / DirectoryEntry)
 * still need the ZF1 form accessors and are NOT covered here — they belong to
 * the separate forms axis.
 *
 * @package ViMbAdmin
 * @subpackage Plugin
 */
interface ViMbAdmin_Plugin_MutationContext
{
    /** @return array the merged application options (`getOptions()` on a controller). */
    public function getOptions();

    /** @return \Doctrine\ORM\EntityManagerInterface the Doctrine entity manager. */
    public function getD2EM();

    /** @return \Entities\Admin the acting administrator. */
    public function getAdmin();

    /** @return \Entities\Domain the domain in scope for the current action. */
    public function getDomain();

    /** Queue a user-facing message (same contract as the controller's addMessage). */
    public function addMessage( $message, $class = null, $type = null );
}
