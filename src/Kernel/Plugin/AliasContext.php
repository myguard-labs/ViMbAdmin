<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

use ViMbAdmin\Kernel\Flash\FlashMessages;

/**
 * Native plugin context for an alias mutation (Phase 4c of docs/ZF1-REMOVAL.md).
 *
 * Adds the in-scope alias to the {@see AbstractContext} surface, satisfying
 * {@see \ViMbAdmin_Plugin_AliasContext} — the contract the `alias_*` hooks
 * (e.g. MailboxAutomaticAliases::alias_toggleActive_preToggle) type-hint.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AliasContext extends AbstractContext implements \ViMbAdmin_Plugin_AliasContext
{
    public function __construct(
        object $em,
        object $admin,
        object $domain,
        private readonly object $alias,
        array $options,
        FlashMessages $flash,
    ) {
        parent::__construct($em, $admin, $domain, $options, $flash);
    }

    public function getAlias()
    {
        return $this->alias;
    }
}
