<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * Alias mutation-context contract (Phase 4b of docs/ZF1-REMOVAL.md) — a
 * {@see ViMbAdmin_Plugin_MutationContext} that also exposes the alias in scope,
 * the surface the `alias_*` plugin hooks use.
 *
 * @package ViMbAdmin
 * @subpackage Plugin
 */
interface ViMbAdmin_Plugin_AliasContext extends ViMbAdmin_Plugin_MutationContext
{
    /** @return \Entities\Alias the alias in scope for the current action. */
    public function getAlias();
}
