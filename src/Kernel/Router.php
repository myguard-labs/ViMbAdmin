<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

/**
 * Framework-free router for ViMbAdmin's URL scheme.
 *
 * It decodes the established request-path format —
 * `/{controller}/{action}/{k1}/{v1}/{k2}/{v2}...`, with controller and action
 * defaulting to "index" — and inflects controller/action names to their native
 * PHP class and method names.
 *
 * It is deliberately dependency-free (no nikic/fast-route): ViMbAdmin has a
 * single generic pattern with a variable-length key/value tail, which fast-route
 * cannot express natively, and a small hand parser is both clearer and unit
 * testable without a framework. fast-route is reserved for any explicit literal
 * routes a later phase may register.
 *
 * {@see self::match()} returns a RouteMatch only for controllers on the native
 * allowlist. Unknown controllers return null and the HTTP entry point rejects
 * them without booting application resources.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Router
{
    /**
     * Lower-cased dash-form controller names that are served natively.
     *
     * @var array<string,true>
     */
    private array $native = [];

    /**
     * @param string[] $nativeControllers dash-form controller names (e.g. ["domain", "two-factor"])
     *                                     to serve through the native dispatcher
     */
    public function __construct(array $nativeControllers = [])
    {
        foreach ($nativeControllers as $name) {
            $this->native[strtolower($name)] = true;
        }
    }

    /**
     * Whether a (dash-form) controller name is served natively.
     */
    public function isNative(string $controller): bool
    {
        return isset($this->native[strtolower($controller)]);
    }

    /**
     * Decode a request path into a RouteMatch IF its controller is served
     * natively, otherwise null (→ ZF1 fallback).
     *
     * The path must already have any mount base path (e.g. "/vimbadmin")
     * stripped by the caller — the same point at which ZF1 stripped its baseUrl.
     */
    public function match(string $path): ?RouteMatch
    {
        $decoded = self::parse($path);

        if (!$this->isNative($decoded['controller'])) {
            return null;
        }

        return new RouteMatch(
            $decoded['controller'],
            $decoded['action'],
            self::controllerClass($decoded['controller']),
            self::actionMethod($decoded['action']),
            $decoded['params'],
        );
    }

    /**
     * Pure decode of a path into controller / action / params, with no regard to
     * the native allowlist. Mirrors the ZF1 default router:
     *
     *   ""                              -> index / index / []
     *   "/domain"                       -> domain / index / []
     *   "/domain/list"                  -> domain / list  / []
     *   "/mailbox/edit/id/5"            -> mailbox / edit / [id => "5"]
     *   "/mailbox/add/did/3/x/y"        -> mailbox / add  / [did => "3", x => "y"]
     *   "/domain/admins/did"            -> domain / admins / [did => null]  (dangling key)
     *
     * Empty segments (leading/trailing/double slashes) are ignored and each
     * segment is urldecoded, as ZF1 did.
     *
     * @return array{controller:string,action:string,params:array<string,?string>}
     */
    public static function parse(string $path): array
    {
        $segments = array_values(array_filter(
            array_map('rawurldecode', explode('/', $path)),
            static fn (string $s): bool => $s !== '',
        ));

        // ZF1 treated the controller/action tokens case-insensitively and
        // normalised them to lower-case dash form; param keys/values are left
        // untouched.
        $controller = strtolower($segments[0] ?? 'index');
        $action     = strtolower($segments[1] ?? 'index');

        $params = [];
        $tail   = array_slice($segments, 2);
        for ($i = 0, $n = count($tail); $i < $n; $i += 2) {
            $key = $tail[$i];
            $params[$key] = $tail[$i + 1] ?? null; // dangling key → null, like ZF1
        }

        return [
            'controller' => $controller,
            'action'     => $action,
            'params'     => $params,
        ];
    }

    /**
     * Inflect a dash-form controller name to its ZF1 class name.
     * "two-factor" -> "TwoFactorController", "index" -> "IndexController".
     */
    public static function controllerClass(string $controller): string
    {
        return self::camel($controller, true) . 'Controller';
    }

    /**
     * Inflect a dash-form action name to its ZF1 method name.
     * "cli-run" -> "cliRunAction", "index" -> "indexAction".
     */
    public static function actionMethod(string $action): string
    {
        return self::camel($action, false) . 'Action';
    }

    /**
     * ZF1-style camelCase of a name whose words are separated by "-" or ".".
     * $upperFirst controls StudlyCaps (controllers) vs lowerCamel (actions).
     */
    private static function camel(string $name, bool $upperFirst): string
    {
        $parts = preg_split('/[-.]/', $name) ?: [$name];
        $parts = array_map(static fn (string $p): string => ucfirst(strtolower($p)), $parts);
        $out   = implode('', $parts);

        return $upperFirst ? $out : lcfirst($out);
    }
}
