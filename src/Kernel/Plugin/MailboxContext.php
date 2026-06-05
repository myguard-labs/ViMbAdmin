<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

use ViMbAdmin\Kernel\Flash\FlashMessages;

/**
 * Native plugin context for a mailbox mutation (Phase 4c of docs/ZF1-REMOVAL.md).
 *
 * Adds the in-scope mailbox to the {@see AbstractContext} surface, satisfying
 * {@see \ViMbAdmin_Plugin_MailboxContext} — the contract the `mailbox_*` hooks
 * (e.g. MailboxAutomaticAliases::mailbox_toggleActive_preToggle) type-hint.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class MailboxContext extends AbstractContext implements \ViMbAdmin_Plugin_MailboxContext
{
    public function __construct(
        object $em,
        object $admin,
        object $domain,
        private readonly object $mailbox,
        array $options,
        FlashMessages $flash,
    ) {
        parent::__construct($em, $admin, $domain, $options, $flash);
    }

    public function getMailbox()
    {
        return $this->mailbox;
    }
}
