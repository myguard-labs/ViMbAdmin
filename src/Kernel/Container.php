<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

use ViMbAdmin\Kernel\Security\Auth;

/**
 * Service container for the framework-free kernel (Phase 3, docs/ZF1-REMOVAL.md).
 *
 * Rather than re-implement `application.ini` (Doctrine EM, Smarty view, cache,
 * mailer, …), Phase 3 REUSES the resources the existing ZF1 application
 * bootstrap already builds. The container is a thin, typed
 * facade over that bootstrap: it is handed the bootstrap object (anything with a
 * `getResource(string): mixed` method — the ZF1 bootstrap, or a fake in tests)
 * plus the already-wired framework-free {@see Auth} service, and hands the
 * native dispatcher/controllers the entity manager, named ZF1 resources and the
 * auth service without any of them naming the framework.
 *
 * Keeping the bootstrap behind a `getResource()`-shaped `object` (not a
 * framework type) is what lets this class live in the framework-free `src/`
 * tree and be unit-tested with a tiny fake — the same technique the {@see Auth}
 * service uses for its admin-loader callable. The single framework-specific
 * wiring (building the identity bridge for {@see Auth}) happens once at the
 * entry point (`public/index.php`, a ZF1 zone) and is injected here ready-made.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Container
{
    /**
     * @param object $bootstrap anything exposing `getResource(string): mixed`
     *                          (the ZF1 application bootstrap in production)
     */
    public function __construct(
        private readonly object $bootstrap,
        private readonly Auth $auth,
    ) {
    }

    /**
     * The Doctrine entity manager the ZF1 `doctrine2` resource built.
     *
     * Typed as `object` because the kernel must not name the Doctrine classes
     * here; the same purity rule that keeps the tree framework-free keeps it
     * dependency-light and unit-testable. Callers use it via its public API
     * (`getRepository()`, …).
     */
    public function entityManager(): object
    {
        return $this->getResource('doctrine2');
    }

    /**
     * The framework-free auth/authorisation service, identity-bridged onto the
     * live ZF1 auth storage at the entry point.
     */
    public function auth(): Auth
    {
        return $this->auth;
    }

    /**
     * Fetch any named resource the ZF1 bootstrap exposes (`smarty`, `mailer`,
     * `doctrine2cache`, …). The escape hatch for resources without a dedicated
     * typed accessor yet; prefer adding one as each is needed natively.
     */
    public function getResource(string $name): mixed
    {
        return $this->bootstrap->getResource($name);
    }
}
