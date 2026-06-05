<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Http;

use ViMbAdmin\Kernel\Controller\AdditionalInfoController;
use ViMbAdmin\Kernel\Controller\IndexController;
use ViMbAdmin\Kernel\Controller\LogController;
use ViMbAdmin\Kernel\Mvc\Dispatcher;
use ViMbAdmin\Kernel\Router;
use ViMbAdmin\Kernel\RouteMatch;

/**
 * Framework-free front controller (Phase 2b skeleton + Phase 3 dispatch,
 * docs/ZF1-REMOVAL.md).
 *
 * Runs ALONGSIDE the ZF1 front controller. {@see handle()} decodes the request
 * path with the {@see Router} and returns a {@see Response} only when the route
 * is served natively — either the built-in `kernel-health` probe or a controller
 * in {@see NATIVE_CONTROLLERS} dispatched through the {@see Dispatcher}. For
 * everything else it returns null and the caller (public/index.php) falls back
 * to the ZF1 front-controller run, so old and new dispatch run side by side and
 * no URL changes behaviour until it is explicitly migrated.
 *
 * `kernel-health` is a no-auth, no-database, no-view liveness probe kept from
 * the Phase 2b skeleton. Phase 3 adds the {@see Dispatcher}: real controllers
 * are ported to {@see \ViMbAdmin\Kernel\Mvc\AbstractController} subclasses under
 * `src/Kernel/Controller/`, listed in {@see NATIVE_CONTROLLERS}, and reach the
 * Doctrine EM / auth service through the {@see \ViMbAdmin\Kernel\Container}. A
 * matched controller with no native action still falls back to ZF1.
 *
 * The whole native path is opt-in at the entry point via the
 * VIMBADMIN_NATIVE_KERNEL env flag (default off = the historical ZF1 path,
 * byte for byte), so enabling it is a deliberate, reversible step.
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
        'index'          => IndexController::class,
        'log'            => LogController::class,
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
     * Decode the path and dispatch it natively if possible, else null
     * (→ ZF1 fallback). Pure: it returns a Response and touches no output.
     */
    public function handle(string $path): ?Response
    {
        $match = $this->router->match($path);
        if ($match === null) {
            return null; // not a native controller → ZF1
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
