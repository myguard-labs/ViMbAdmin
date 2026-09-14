<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mvc;

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mail\Mailer;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\ContentSecurityPolicy;
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
        return self::stringMap($_POST, 'POST data');
    }

    /**
     * Configured server-side search floor for a DataTable list. A list-specific
     * value overrides the shared pagination value.
     */
    protected function dataTableMinimumSearchLength(?string $list = null): int
    {
        $options = $this->container->options();
        $defaults = array_key_exists('defaults', $options)
            ? self::stringMap($options['defaults'], 'defaults')
            : [];
        $serverSide = array_key_exists('server_side', $defaults)
            ? self::stringMap($defaults['server_side'], 'defaults.server_side')
            : [];
        $pagination = array_key_exists('pagination', $serverSide)
            ? self::stringMap($serverSide['pagination'], 'defaults.server_side.pagination')
            : [];

        $value = $pagination['min_search_str'] ?? 3;
        if ($list !== null && array_key_exists($list, $pagination)) {
            $listOptions = self::stringMap(
                $pagination[$list],
                'defaults.server_side.pagination.' . $list,
            );
            $value = $listOptions['min_search_str'] ?? $value;
        }

        return self::nonNegativeInt($value, 'min_search_str');
    }

    /**
     * The Doctrine entity manager (the ZF1 `getD2EM()` equivalent).
     */
    protected function em(): object
    {
        return $this->container->entityManager();
    }

    /** Resolve a submitted target domain and enforce the administrator's scope. */
    protected function resolveAuthorizedTargetDomain(
        object $admin,
        ?int $domainId,
        \Repositories\Domain $repository,
    ): ?\Entities\Domain {
        if (!$admin instanceof \Entities\Admin) {
            throw new \LogicException('Authenticated administrator has an invalid type');
        }
        if ($domainId === null) {
            return null;
        }

        $domain = $repository->find($domainId);
        if (!$domain instanceof \Entities\Domain) {
            return null;
        }

        return $admin->isSuper() || $admin->canManageDomain($domain) ? $domain : null;
    }

    /**
     * The logged-in admin entity, or null when unauthenticated (the ZF1
     * `getAdmin()` equivalent, via the framework-free auth service).
     */
    protected function admin(): ?object
    {
        $auth = $this->container->auth();

        // Idle-timeout enforcement: an authenticated session untouched for longer
        // than resources.session.idle_timeout is dropped (identity + session
        // wiped) before the action sees an admin. `timeOfLastAction` was written
        // at login but never enforced, so sessions previously lived to
        // gc_maxlifetime. 0/unset disables. Cheap + idempotent per request.
        if ($auth->isAuthenticated()) {
            $options = $this->container->options();
            $resources = array_key_exists('resources', $options)
                ? self::stringMap($options['resources'], 'resources')
                : [];
            $sessionOptions = array_key_exists('session', $resources)
                ? self::stringMap($resources['session'], 'resources.session')
                : [];
            $idle = array_key_exists('idle_timeout', $sessionOptions)
                ? self::nonNegativeInt($sessionOptions['idle_timeout'], 'idle_timeout')
                : 0;
            if ($idle > 0) {
                $session = new MagicPropertyStorage($this->container->session());
                $lastValue = $session->get('timeOfLastAction');
                $last = $lastValue === null
                    ? 0
                    : self::nonNegativeInt($lastValue, 'timeOfLastAction');
                if ($last > 0 && (time() - $last) > $idle) {
                    $auth->clear();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $_SESSION = [];
                        session_regenerate_id(true);
                        session_destroy();
                    }
                    return null;
                }
                $session->set('timeOfLastAction', time());
            }
        }

        return $auth->admin();
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

    /** Consistent response for an expired DataTables session. */
    protected function dataTableAuthenticationRequired(): Response
    {
        return $this->json(['error' => 'Authentication required'], 401);
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
        $token = $this->param('csrf', '');
        return is_string($token)
            && (new Csrf(new MagicPropertyStorage($this->container->session())))->isValid($token);
    }

    /**
     * Whether this is a POST carrying a valid CSRF token in its body.
     *
     * Unlike {@see csrfValid()}, this deliberately does not accept route or
     * query-string input. Use it for mutations whose token must travel with the
     * POST body rather than for legacy CSRF-guarded confirmation links.
     */
    protected function postBodyCsrfValid(): bool
    {
        $token = $_POST['csrf'] ?? null;

        return $this->isPost()
            && is_string($token)
            && (new Csrf(new MagicPropertyStorage($this->container->session())))->isValid($token);
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
        $view = $this->viewResource();

        foreach ($vars as $key => $value) {
            $this->assignView($view, $key, $value);
        }

        return $this->renderView($view, $script);
    }

    /**
     * Render a Smarty page template into an HTML {@see Response} (the native
     * equivalent of ZF1's viewRenderer auto-rendering `{controller}/{action}`).
     *
     * Reuses the native container's shared `smarty` view resource,
     * so the page templates (and the `header.phtml` / `footer.phtml` chrome they
     * `{tmplinclude}`) resolve and render identically — `{genUrl}` and
     * `{OSS_Message}` keep working because they read the front-controller base
     * URL and session supplied by the native runtime.
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
        $view  = $this->viewResource();
        $admin = $this->admin();

        // Chrome variables header.phtml / footer.phtml expect.
        $this->assignView($view, 'controller', $this->route->controller);
        $this->assignView($view, 'action', $this->route->action);
        $this->assignView($view, 'hasIdentity', $admin !== null);
        $this->assignView($view, 'user', $admin);
        $this->assignView($view, 'identity', $this->container->auth()->identity());
        $this->assignView($view, 'options', $this->container->options());
        $this->assignView($view, 'skinCss', $this->container->chrome('skinCss') ?? '');
        $this->assignView($view, 'session', $this->container->session());

        // The per-session CSRF token guarding state-changing GET links — set only
        // for an authenticated page, over the shared `csrfToken` session key so
        // links minted here validate in the native action handlers.
        if ($admin !== null) {
            $this->assignView(
                $view,
                'csrfToken',
                (new Csrf(new MagicPropertyStorage($this->container->session())))->token()
            );
        }

        foreach ($vars as $key => $value) {
            $this->assignView($view, $key, $value);
        }

        // Fresh per-response CSP nonce. The views stamp it on every inline
        // <script> so the policy below can drop 'unsafe-inline' from script-src;
        // it must be minted here (not in the web server) because only the
        // application can hand the value to the templates. Assigned last so an
        // action variable can never shadow it and desync view from header.
        $csp = new ContentSecurityPolicy();
        $this->assignView($view, 'cspNonce', $csp->nonce());

        return new Response($this->renderView($view, $script), $status, headers: $csp->headers());
    }

    /** @return array<string,mixed> */
    private static function stringMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \TypeError($name . ' must be an array');
        }
        foreach ($value as $key => $_value) {
            if (!is_string($key)) {
                throw new \TypeError($name . ' must use string keys');
            }
        }

        return $value;
    }

    private static function nonNegativeInt(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false && $parsed >= 0) {
                return $parsed;
            }
        }

        throw new \TypeError($name . ' must be a non-negative integer');
    }

    private function viewResource(): object
    {
        $view = $this->container->getResource('smarty');
        if (!is_object($view) || !is_callable([$view, 'render'])) {
            throw new \TypeError('smarty resource must expose render()');
        }

        return $view;
    }

    private function assignView(object $view, string $key, mixed $value): void
    {
        $setter = [$view, '__set'];
        if (!is_callable($setter)) {
            throw new \TypeError('smarty resource must expose __set()');
        }
        $setter($key, $value);
    }

    private function renderView(object $view, string $script): string
    {
        $renderer = [$view, 'render'];
        if (!is_callable($renderer)) {
            throw new \TypeError('smarty resource must expose render()');
        }
        $rendered = $renderer($script);
        if (!is_string($rendered)) {
            throw new \TypeError('smarty render() must return a string');
        }

        return $rendered;
    }
}
