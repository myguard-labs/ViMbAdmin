<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

/**
 * Framework-free plugin host (Phase 4c of docs/ZF1-REMOVAL.md) — the native
 * equivalent of the ZF1 `ViMbAdmin_Controller_PluginAction`'s observer plumbing
 * (`registerObservers()` / `loadObservers()` / `notify()`).
 *
 * It scans the plugin directory, instantiates each enabled `ViMbAdminPlugin_*`
 * with a {@see \ViMbAdmin_Plugin_MutationContext} (Phase 4b relaxed the plugin
 * constructor + dispatch chain to accept any object), and fans `notify()` out to
 * every observer's `update()`. A single observer returning false short-circuits
 * the chain and makes `notify()` return false — the veto semantics the ZF1
 * `notify()` had, which the mutation services rely on for their pre-hooks.
 *
 * The plugins themselves still live in `application/plugins/` and are still the
 * legacy ZF1 plugin classes; this host just lets a native controller drive them
 * through a context object instead of a Zend controller.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class PluginHost
{
    /** @var array<int,object> the instantiated observers (ViMbAdminPlugin_*). */
    private array $observers = [];

    /**
     * @param object      $context    the per-action plugin context; its
     *                    `getOptions()` drives the disabled-check and is passed to
     *                    each plugin constructor (plugins only read static config
     *                    there — the entity surface is supplied per hook)
     * @param string|null $pluginsDir the plugin directory; defaults to
     *                    `APPLICATION_PATH/plugins`, the same directory the ZF1
     *                    host scans (overridable for tests)
     */
    public function __construct(object $context, ?string $pluginsDir = null)
    {
        $pluginsDir ??= (defined('APPLICATION_PATH') ? APPLICATION_PATH : '') . '/plugins';

        $options = method_exists($context, 'getOptions') ? (array) $context->getOptions() : [];

        foreach (glob(rtrim($pluginsDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            // Honour the same `vimbadmin_plugins.<name>.disabled` switch the ZF1
            // host reads, so a plugin disabled in application.ini stays disabled.
            if (!empty($options['vimbadmin_plugins'][$name]['disabled'])) {
                continue;
            }

            require_once $file;
            $class = 'ViMbAdminPlugin_' . $name;
            if (class_exists($class)) {
                $this->observers[] = new $class($context);
            }
        }
    }

    /**
     * Fire a `{controller}_{action}_{hook}` notification at every observer, in
     * registration order. Returns false as soon as an observer vetoes (its
     * `update()` returns false), otherwise true — matching the ZF1 contract the
     * mutation services' pre-hooks check.
     *
     * @param array<string,mixed>|null $params optional hook parameters
     */
    public function notify(string $controller, string $action, string $hook, object $context, ?array $params = null): bool
    {
        foreach ($this->observers as $observer) {
            if ($observer->update($controller, $action, $hook, $context, $params) === false) {
                return false;
            }
        }

        return true;
    }

    /** The number of registered (enabled) observers — handy for tests/diagnostics. */
    public function observerCount(): int
    {
        return count($this->observers);
    }
}
