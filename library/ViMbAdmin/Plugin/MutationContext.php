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
 * {@see ViMbAdmin_Plugin_AliasContext}) names exactly the surface mutation hooks
 * use, so plugins remain independent of the HTTP controller implementation.
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
