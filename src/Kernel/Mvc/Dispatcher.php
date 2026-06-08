<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mvc;

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;

/**
 * Dispatches a decoded {@see RouteMatch} to a native controller (Phase 3,
 * docs/ZF1-REMOVAL.md).
 *
 * Holds a map of dash-form controller name → native controller class, each a
 * {@see AbstractController}. {@see dispatch()} instantiates the mapped class with
 * the {@see Container} and the route, invokes the route's action method, and
 * returns its {@see Response}. Anything it cannot serve — a controller not in
 * the map, an action the class does not implement, or an action that does not
 * return a Response — yields null so the entry point can emit a 404.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Dispatcher
{
    /**
     * @param array<string,class-string<AbstractController>> $controllers
     *        dash-form controller name → native controller class
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $controllers,
    ) {
    }

    /**
     * Dispatch natively, or null if this route is not served.
     */
    public function dispatch(RouteMatch $match): ?Response
    {
        $class = $this->controllers[$match->controller] ?? null;
        if ($class === null) {
            return null; // controller not migrated
        }

        $controller = new $class($this->container, $match);

        $method = $match->actionMethod;
        if (!method_exists($controller, $method)) {
            return null; // action not migrated on this controller
        }

        $result = $controller->{$method}();

        return $result instanceof Response ? $result : null;
    }
}
