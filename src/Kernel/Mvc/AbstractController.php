<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mvc;

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

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
     * The application session namespace (the ZF1 `getSessionNamespace()`
     * equivalent), for per-session UI state read/written via magic properties.
     */
    protected function session(): object
    {
        return $this->container->session();
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

    /**
     * A 302 redirect to an application path (the native equivalent of the ZF1
     * `_redirect()` / `redirectAndEnsureDie()`). The path is taken relative to
     * the site root, matching ZF1's empty base URL in the container deployment.
     */
    protected function redirect(string $path): Response
    {
        return new Response('', 302, 'text/html; charset=utf-8', [
            'Location' => '/' . ltrim($path, '/'),
        ]);
    }

    /**
     * Render a Smarty page template into an HTML {@see Response} (the native
     * equivalent of ZF1's viewRenderer auto-rendering `{controller}/{action}`).
     *
     * Reuses the SAME `smarty` view resource the ZF1 controllers render through,
     * so the page templates (and the `header.phtml` / `footer.phtml` chrome they
     * `{tmplinclude}`) resolve and render identically — `{genUrl}` and
     * `{OSS_Message}` keep working because they read the front-controller base
     * URL and the session, both live after the shared bootstrap.
     *
     * It seeds exactly the chrome variables those templates consume, mirroring
     * the ZF1 `OSS_Controller_Action_Trait_Smarty` setup plus the ViMbAdmin base
     * controller: `controller`/`action` (nav highlighting), `hasIdentity`/`user`
     * (auth-gated menu + version string), `options` (footer/asset flags), and the
     * pre-computed `skinCss` (skin stylesheet URL). Per-action variables are
     * passed in `$vars`.
     *
     * @param string              $script template path, e.g. "index/about.phtml"
     * @param array<string,mixed> $vars   per-action view variables
     */
    protected function view(string $script, array $vars = [], int $status = 200): Response
    {
        $view  = $this->container->getResource('smarty');
        $admin = $this->admin();

        // Chrome variables header.phtml / footer.phtml expect.
        $view->controller  = $this->route->controller;
        $view->action      = $this->route->action;
        $view->hasIdentity = $admin !== null;
        $view->user        = $admin;
        $view->identity    = $this->container->auth()->identity();
        $view->options     = $this->container->options();
        $view->skinCss     = $this->container->chrome('skinCss') ?? '';
        $view->session     = $this->container->session();

        // The per-session CSRF token guarding state-changing GET links — set only
        // for an authed page, exactly as the ZF1 base controller did, over the
        // same session key (`csrfToken`) so links minted here validate against
        // the ZF1 _assertCsrf() that still serves those actions.
        if ($admin !== null) {
            $view->csrfToken = (new Csrf(new MagicPropertyStorage($this->container->session())))->token();
        }

        foreach ($vars as $key => $value) {
            $view->{$key} = $value;
        }

        return new Response((string) $view->render($script), $status);
    }
}
