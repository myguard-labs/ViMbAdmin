<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mvc;

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;

/**
 * Base class for natively-dispatched controllers (Phase 3, docs/ZF1-REMOVAL.md).
 *
 * The framework-free counterpart to `ViMbAdmin_Controller_Action`. A migrated
 * controller extends this, lives in the framework-free `src/` tree, and exposes
 * `{$action}Action(): Response` methods that the {@see Dispatcher} invokes. It
 * is handed the {@see Container} (Doctrine EM, named ZF1 resources, the
 * framework-free auth service) and the decoded {@see RouteMatch}, and returns a
 * {@see Response} value object instead of echoing — so dispatch stays pure and
 * unit-testable, and the entry point is the only place that emits.
 *
 * It deliberately ships only the few helpers the first migrated controllers
 * need — `param()`, `em()`, `admin()`, and response builders. The view-script
 * render helper is added when the first Smarty-rendering controller is migrated;
 * the first native controller (additionalinfo/typeahead) emits JSON and needs no
 * view. Each helper is a thin, intention-revealing wrapper so a migrated
 * action body reads almost exactly like its ZF1 original.
 *
 * The original ZF1 controller stays in place untouched: the native kernel is
 * opt-in (VIMBADMIN_NATIVE_KERNEL), so with the flag off ZF1 still serves the
 * route byte-for-byte. This is a true strangler — the native class is added
 * ALONGSIDE the legacy one, not a rewrite of it.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
abstract class AbstractController
{
    public function __construct(
        protected readonly Container $container,
        protected readonly RouteMatch $route,
    ) {
    }

    /**
     * A decoded `/key/value` route parameter, or $default when absent/dangling.
     *
     * Mirrors ZF1's `getParam()` for the URL-path params the router decodes
     * (query-string params are handled separately as they are migrated).
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->route->params[$key] ?? $default;
    }

    /**
     * The Doctrine entity manager (the ZF1 `getD2EM()` equivalent).
     */
    protected function em(): object
    {
        return $this->container->entityManager();
    }

    /**
     * The logged-in admin entity, or null when unauthenticated (the ZF1
     * `getAdmin()` equivalent, via the framework-free auth service).
     */
    protected function admin(): ?object
    {
        return $this->container->auth()->admin();
    }

    /**
     * Build a JSON response (the native equivalent of the ZF1 idiom
     * `removeHelper('viewRenderer'); echo json_encode(...)`).
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($data),
            $status,
            'application/json; charset=utf-8',
        );
    }
}
