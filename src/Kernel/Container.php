<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

use ViMbAdmin\Kernel\Mail\Mailer;
use ViMbAdmin\Kernel\Security\Auth;

/**
 * Service container for the framework-free kernel (Phase 3, docs/ZF1-REMOVAL.md).
 *
 * Thin typed facade over the native application resources. It is handed an
 * object exposing `getResource()` and `getOptions()`, plus the wired
 * framework-free {@see Auth} service.
 *
 * Keeping the bootstrap behind a `getResource()`-shaped `object` (not a
 * framework type) is what lets this class live in the framework-free `src/`
 * tree and be unit-tested with a tiny fake — the same technique the {@see Auth}
 * service uses for its admin-loader callable. The single framework-specific
 * wiring happens once in {@see Bootstrap} and is injected here ready-made.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Container
{
    /**
     * @param object               $bootstrap anything exposing
     *                             `getResource(string): mixed` and `getOptions(): array`
     *                             (the native resource holder in production)
     * @param array<string,mixed>  $chrome  view variables a native controller's
     *                             page templates need (currently `skinCss`)
     */
    public function __construct(
        private readonly object $bootstrap,
        private readonly Auth $auth,
        private readonly array $chrome = [],
    ) {
    }

    private ?Mailer $mailer = null;

    /**
     * The native mail sender, built from the `resources.mail.transport.*`
     * options. Replaces the ZF1 mailer (`OSS_Resource_Mailer`) path for
     * native controllers (lost-password / reset / email-settings). Lazily built
     * and cached.
     */
    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer(
            $this->options()['resources']['mail']['transport'] ?? [],
            \ViMbAdmin_Demo::enabled($this->options())
        );
    }

    /**
     * The Doctrine entity manager.
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
     * native auth storage.
     */
    public function auth(): Auth
    {
        return $this->auth;
    }

    /**
     * The merged application options from `application.ini` that page
     * templates read as `$options` (e.g. `$options.footer.hide`,
     * `$options.resources.smarty.skin`).
     *
     * @return array<string,mixed>
     */
    public function options(): array
    {
        return $this->bootstrap->getOptions();
    }

    /**
     * The application session namespace where per-session UI state lives.
     */
    public function session(): object
    {
        return $this->getResource('namespace');
    }

    /**
     * A pre-computed chrome view variable (e.g. `skinCss`), or null if absent.
     */
    public function chrome(string $key): mixed
    {
        return $this->chrome[$key] ?? null;
    }

    /**
     * Fetch any named native resource. The escape hatch for resources without a dedicated
     * typed accessor yet; prefer adding one as each is needed natively.
     */
    public function getResource(string $name): mixed
    {
        return $this->bootstrap->getResource($name);
    }
}
