<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Http;

use ViMbAdmin\Kernel\Router;
use ViMbAdmin\Kernel\RouteMatch;

/**
 * Framework-free front controller skeleton (Phase 2b, docs/ZF1-REMOVAL.md).
 *
 * Runs ALONGSIDE the ZF1 front controller. {@see handle()} decodes the request
 * path with the {@see Router} and, only for controllers on the router's opt-in
 * "native" allowlist that also have a handler registered here, returns a
 * {@see Response}. For everything else it returns null, and the caller
 * (public/index.php) falls back to the ZF1 front-controller run — so old and
 * new dispatch run side by side and no URL changes behaviour until it is
 * explicitly migrated.
 *
 * This skeleton ships exactly one native route — `kernel-health`, a no-auth,
 * no-database, no-view liveness probe — to prove the whole path end to end
 * (path decode → native dispatch → emit, with ZF1 fallback for all else) without
 * pulling in Doctrine/Smarty. Real controllers are migrated in Phase 3, which is
 * where the container (Doctrine EM, Smarty view, Auth) gets wired in; until then
 * a matched-but-unhandled native controller also falls back to ZF1.
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
     * @var array<string,callable(RouteMatch):Response> handlers keyed by the
     *      dash-form native controller name
     */
    private array $handlers;

    public function __construct(private readonly Router $router)
    {
        $this->handlers = [
            'kernel-health' => static function (RouteMatch $m): Response {
                return Response::text("ok\nphp " . PHP_VERSION . "\nkernel native dispatch\n");
            },
        ];
    }

    /**
     * The dash-form controller names this kernel serves natively — exactly the
     * router's allowlist intersected with the handlers wired here.
     *
     * @return string[]
     */
    public static function nativeControllers(): array
    {
        return ['kernel-health'];
    }

    /**
     * Decode the path and dispatch it natively if a handler exists, else null
     * (→ ZF1 fallback). Pure: it returns a Response and touches no output.
     */
    public function handle(string $path): ?Response
    {
        $match = $this->router->match($path);
        if ($match === null) {
            return null; // not a native controller → ZF1
        }

        $handler = $this->handlers[$match->controller] ?? null;
        if ($handler === null) {
            return null; // native controller with no handler yet → ZF1
        }

        return $handler($match);
    }
}
