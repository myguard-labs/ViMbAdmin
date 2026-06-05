<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * Mailbox mutation-context contract (Phase 4b of docs/ZF1-REMOVAL.md) — a
 * {@see ViMbAdmin_Plugin_MutationContext} that also exposes the mailbox in scope,
 * the surface the `mailbox_*` plugin hooks use.
 *
 * @package ViMbAdmin
 * @subpackage Plugin
 */
interface ViMbAdmin_Plugin_MailboxContext extends ViMbAdmin_Plugin_MutationContext
{
    /** @return \Entities\Mailbox the mailbox in scope for the current action. */
    public function getMailbox();
}
