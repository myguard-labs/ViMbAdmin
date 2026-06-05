<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

/**
 * Immutable result of decoding a request path against ViMbAdmin's URL scheme.
 *
 * Phase 2 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). It carries the same
 * three things the ZF1 dispatcher derived from a URL — the controller name, the
 * action name and the `/key/value` parameter tail — plus the inflected PHP class
 * and method names so the framework-free dispatcher (Phase 2b) can target the
 * exact `FooController::barAction()` that ZF1 would have, keeping every URL
 * unchanged.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class RouteMatch
{
    /**
     * @param string               $controller dash-form controller name from the URL (e.g. "two-factor")
     * @param string               $action     dash-form action name from the URL (e.g. "cli-run")
     * @param string               $controllerClass inflected class name (e.g. "TwoFactorController")
     * @param string               $actionMethod    inflected method name (e.g. "cliRunAction")
     * @param array<string,?string> $params    decoded /key/value tail
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly string $controllerClass,
        public readonly string $actionMethod,
        public readonly array $params,
    ) {
    }
}
