<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mvc;

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mail\Mailer;
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
 * These controllers are the sole HTTP implementation; the legacy controller
 * layer has been removed.
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
     * A request parameter, or $default when absent.
     *
     * Mirrors ZF1's `getParam()` precedence: a decoded `/key/value` route
     * segment wins, then a POST body field, then a query-string field. The
     * AJAX toggles (ossToggle) post their id in the body (e.g. `did`), so the
     * POST fallback is required — without it those handlers saw a null id and
     * returned `ko`.
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->route->params[$key]
            ?? $_POST[$key]
            ?? $_GET[$key]
            ?? $default;
    }

    /**
     * Whether the request is a POST (form submission). The kernel is the HTTP
     * boundary, so reading the superglobal here is acceptable; a Request value
     * object can replace it later.
     */
    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * The submitted form body ($_POST), for binding a {@see \ViMbAdmin\Kernel\Form\Form}.
     *
     * @return array<string,mixed>
     */
    protected function postData(): array
    {
        return $_POST;
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
     * `_redirect()` / `redirectAndEnsureDie()`). The path is application-relative
     * (e.g. `auth/login`); it is prefixed with the front-controller base URL so
     * the redirect honours a reverse-proxy sub-path mount. Without that prefix a
     * `/vimbadmin/` deployment redirects to the proxy root (`/auth/login`) and
     * lands on whatever else lives there (here: Roundcube).
     */
    protected function redirect(string $path, ?callable $afterSend = null): Response
    {
        $base = rtrim((string) \OSS_Runtime::baseUrl(), '/');

        return new Response('', 302, 'text/html; charset=utf-8', [
            'Location' => $base . '/' . ltrim($path, '/'),
        ], $afterSend);
    }

    /**
     * Queue a flash message for the next page (the native `addMessage()`).
     *
     * Writes to the framework-free {@see FlashMessages} queue over the session
     * namespace; the `{OSS_Message}` Smarty renderer drains it and emits the same
     * alert markup as a legacy OSS_Message, so a native action can flash a notice
     * that shows on the next page whether that page is rendered natively or by
     * ZF1. Levels match the OSS_Message classes (success/error/info/warning).
     */
    protected function flash(string $text, string $level = FlashMessages::SUCCESS): void
    {
        (new FlashMessages(new MagicPropertyStorage($this->container->session())))->add($text, $level);
    }

    /**
     * Whether the request carries a valid CSRF token (`?csrf=...`) for the
     * current session — the native equivalent of the ZF1 `_assertCsrf()` check
     * (over the same session token, via the {@see Csrf} service). The caller
     * decides the failure response (flash + redirect), mirroring how the ZF1
     * action aborted to a safe listing.
     */
    protected function csrfValid(): bool
    {
        return (new Csrf(new MagicPropertyStorage($this->container->session())))
            ->isValid((string) $this->param('csrf', ''));
    }

    /**
     * The native mail sender (replaces the ZF1 `getMailer()`), built from the
     * `resources.mail.transport.*` options. Used by the mailer-dependent actions
     * (auth lost-password / reset-password, mailbox email-settings).
     */
    protected function mailer(): Mailer
    {
        return $this->container->mailer();
    }

    /**
     * Render a template to a string through the same `smarty` view, seeding NO
     * page chrome — for standalone fragments: email bodies (`auth/email/`,
     * `mailbox/email/`, the native equivalent of
     * `OSS_Controller_Trait_Auth::resolveTemplate()`) and ajax-loaded partials
     * (e.g. the mailbox email-settings modal). It only assigns the caller's
     * variables; `{genUrl}` still works (it reads the front-controller base URL
     * set up at the entry point).
     *
     * @param string              $script template path, e.g. "auth/email/html/lost-password.phtml"
     * @param array<string,mixed> $vars   template variables
     */
    protected function renderPartial(string $script, array $vars = []): string
    {
        $view = $this->container->getResource('smarty');

        foreach ($vars as $key => $value) {
            $view->{$key} = $value;
        }

        return (string) $view->render($script);
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
