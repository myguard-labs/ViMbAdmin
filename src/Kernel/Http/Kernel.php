<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Http;

use ViMbAdmin\Kernel\Controller\AdditionalInfoController;
use ViMbAdmin\Kernel\Controller\AdminController;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\Controller\AliasController;
use ViMbAdmin\Kernel\Controller\ArchiveController;
use ViMbAdmin\Kernel\Controller\DomainController;
use ViMbAdmin\Kernel\Controller\IndexController;
use ViMbAdmin\Kernel\Controller\LogController;
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\Controller\MaintenanceController;
use ViMbAdmin\Kernel\Controller\QueueController;
use ViMbAdmin\Kernel\Mvc\Dispatcher;
use ViMbAdmin\Kernel\Router;
use ViMbAdmin\Kernel\RouteMatch;

/**
 * Framework-free front controller (Phase 2b skeleton + Phase 3 dispatch,
 * docs/ZF1-REMOVAL.md).
 *
 * {@see handle()} decodes the request path with the {@see Router} and returns a
 * {@see Response} for the built-in health probe or a controller in
 * {@see NATIVE_CONTROLLERS}. Unknown routes return null for the entry point to
 * emit a 404.
 *
 * `kernel-health` is a no-auth, no-database, no-view liveness probe kept from
 * the Phase 2b skeleton. Phase 3 adds the {@see Dispatcher}: real controllers
 * are ported to {@see \ViMbAdmin\Kernel\Mvc\AbstractController} subclasses under
 * `src/Kernel/Controller/`, listed in {@see NATIVE_CONTROLLERS}, and reach the
 * Doctrine EM / auth service through the {@see \ViMbAdmin\Kernel\Container}. A
 * matched controller with no native action is treated as not found.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Kernel
{
    /**
     * Dash-form controller name → native controller class. The single source of
     * truth for both the {@see Dispatcher} map and {@see nativeControllers()};
     * the entry point builds the dispatcher from this so the router allowlist and
     * the dispatch table can never drift apart.
     *
     * @var array<string,class-string<\ViMbAdmin\Kernel\Mvc\AbstractController>>
     */
    public const NATIVE_CONTROLLERS = [
        'additionalinfo' => AdditionalInfoController::class,
        'auth'           => AuthController::class,
        'index'          => IndexController::class,
        'log'            => LogController::class,
        'admin'          => AdminController::class,
        'domain'         => DomainController::class,
        'alias'          => AliasController::class,
        'mailbox'        => MailboxController::class,
        'archive'        => ArchiveController::class,
        'queue'          => QueueController::class,
        'maintenance'    => MaintenanceController::class,
        'mcp'            => \McpController::class,
    ];

    /**
     * @var array<string,callable(RouteMatch):Response> built-in handlers keyed
     *      by the dash-form native controller name (routes that need no container)
     */
    private array $handlers;

    public function __construct(
        private readonly Router $router,
        private readonly ?Dispatcher $dispatcher = null,
    ) {
        $this->handlers = [
            'kernel-health' => static function (RouteMatch $m): Response {
                return Response::text("ok\nphp " . PHP_VERSION . "\nkernel native dispatch\n");
            },
        ];
    }

    /**
     * The dash-form controller names this kernel can serve natively — the
     * built-in handlers plus the container-backed {@see NATIVE_CONTROLLERS}. The
     * entry point feeds this to the {@see Router} as its native allowlist.
     *
     * @return string[]
     */
    public static function nativeControllers(): array
    {
        return array_merge(['kernel-health'], array_keys(self::NATIVE_CONTROLLERS));
    }

    /**
     * Whether the kernel can serve this path natively, decided WITHOUT building
     * any resource (no container, no dispatcher) — purely from the route, the
     * built-in handler keys and `method_exists` on the mapped controller class.
     *
     * The entry point uses this to reject unknown routes before bootstrapping.
     * A controller whose specific action method does not exist is NOT
     * servable here; native controllers must therefore never punt a route they
     * own by returning null at runtime — they self-handle and redirect instead.
     */
    public function canHandle(string $path): bool
    {
        $match = $this->router->match($path);
        if ($match === null) {
            return false;
        }
        if (isset($this->handlers[$match->controller])) {
            return true; // a built-in (e.g. kernel-health)
        }
        $class = self::NATIVE_CONTROLLERS[$match->controller] ?? null;

        return $class !== null && method_exists($class, $match->actionMethod);
    }

    /**
     * Decode the path and dispatch it if possible, else null.
     */
    public function handle(string $path): ?Response
    {
        $match = $this->router->match($path);
        if ($match === null) {
            return null; // unknown controller
        }

        // Built-in, container-free handlers (e.g. the health probe) first.
        $handler = $this->handlers[$match->controller] ?? null;
        if ($handler !== null) {
            return $handler($match);
        }

        // Container-backed controllers via the dispatcher; null if the
        // dispatcher is absent or cannot serve this controller/action.
        return $this->dispatcher?->dispatch($match);
    }
}
