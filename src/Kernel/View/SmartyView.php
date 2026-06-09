<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\View;

/**
 * Framework-free Smarty view for the native kernel (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * The native controllers render through `Container::getResource('smarty')`,
 * which until now returned the ZF1 `OSS_View_Smarty` — a Smarty-5 wrapper that
 * only extends the ZF1 view base for the framework's view-renderer integration,
 * which native rendering never uses. This class reproduces exactly the part the
 * kernel relies on (the Smarty engine setup, the magic-property var assignment,
 * `render()`, skin resolution) WITHOUT the framework base, so the native
 * bootstrap can build a view with no ZF1 application present.
 *
 * It is a faithful subset of `OSS_View_Smarty`:
 *   - the same `\Smarty\Smarty` engine with `setEscapeHtml(true)` (auto
 *     HTML-escape; templates mark intentional raw HTML `nofilter`);
 *   - the same bare-function modifiers registered so the existing templates
 *     compile under Smarty 5 (`strlen`/`count`/`in_array`/`is_array`);
 *   - template/compile/cache/config dirs and the OSS plugin dir from options
 *     (the `{genUrl}` / `{OSS_Message}` / … template plugins live there);
 *   - `__set($k,$v)` → `assign`, the exact shape `AbstractController::view()`
 *     uses to seed chrome + page vars;
 *   - `render($name)` → `fetch(resolveTemplate($name))` with the same skin
 *     lookup (a skin copy under `_skins/<skin>/` wins over the default).
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class SmartyView
{
    private \Smarty\Smarty $smarty;

    private string $skin = '';

    /**
     * @param array<string,mixed> $dirs `templates` (required), plus optional
     *                            `compiled`, `cache`, `config`, `plugins`
     */
    public function __construct(array $dirs)
    {
        $this->smarty = new \Smarty\Smarty();
        $this->smarty->setEscapeHtml(true);

        // Smarty 5 forbids bare PHP functions in template expressions; the
        // templates use a few, so register them as modifiers (substr/strpos/…
        // ship as modifier.*.php plugins; only the rest are registered here).
        foreach (['strlen', 'count', 'in_array', 'is_array'] as $fn) {
            if (function_exists($fn) && !$this->smarty->getRegisteredPlugin('modifier', $fn)) {
                $this->smarty->registerPlugin('modifier', $fn, $fn, true);
            }
        }

        if (isset($dirs['templates'])) {
            $this->smarty->setTemplateDir((string) $dirs['templates']);
        }
        if (!empty($dirs['compiled'])) {
            $this->ensureDir((string) $dirs['compiled']);
            $this->smarty->setCompileDir((string) $dirs['compiled']);
        }
        if (!empty($dirs['cache'])) {
            $this->ensureDir((string) $dirs['cache']);
            $this->smarty->setCacheDir((string) $dirs['cache']);
        }
        if (!empty($dirs['config'])) {
            $this->smarty->setConfigDir((string) $dirs['config']);
        }
        if (!empty($dirs['plugins'])) {
            // The OSS template plugins ({genUrl}, {OSS_Message}, {addJSValidator}…).
            @$this->smarty->addPluginsDir($dirs['plugins']);
        }
    }

    /**
     * Build a view from the merged application options, mirroring how the ZF1
     * `smarty` resource read `resources.smarty.*` (templates/compiled/cache/
     * config/plugins/skin/enabled).
     *
     * @param array<string,mixed> $options the full options array
     */
    public static function fromOptions(array $options): self
    {
        $o = $options['resources']['smarty'] ?? [];

        // Sensible defaults derived from APPLICATION_PATH so a lean
        // application.ini need not spell out the standard layout. Any
        // resources.smarty.* key still overrides its default.
        $app = defined('APPLICATION_PATH') ? APPLICATION_PATH : '.';

        $view = new self([
            'templates' => $o['templates'] ?? $app . '/views',
            'compiled'  => $o['compiled']  ?? $app . '/../var/templates_c',
            'cache'     => $o['cache']     ?? $app . '/../var/cache',
            'config'    => $o['config']    ?? $app . '/configs/smarty',
            'plugins'   => $o['plugins']   ?? [
                $app . '/../library/ViMbAdmin/Smarty/functions',
                $app . '/../library/OSS/Smarty/functions',
            ],
        ]);

        if (isset($o['skin']) && (string) $o['skin'] !== '') {
            $view->setSkin((string) $o['skin']);
        }

        return $view;
    }

    /** Assign a template variable — the shape AbstractController::view() uses. */
    public function __set(string $key, mixed $value): void
    {
        $this->smarty->assign($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->smarty->getTemplateVars($key) !== null;
    }

    public function __unset(string $key): void
    {
        $this->smarty->clearAssign($key);
    }

    public function assign(string $key, mixed $value): void
    {
        $this->smarty->assign($key, $value);
    }

    /** Render a template (with skin resolution) to a string. */
    public function render(string $name): string
    {
        return (string) $this->smarty->fetch($this->resolveTemplate($name));
    }

    /**
     * Pre-compile every template in the template dir to the compile dir, so the
     * first request never pays the per-template Smarty compile. Covers both the
     * page templates (`.phtml`) and the JS templates pulled via `{tmplinclude}`
     * (`.js`). Returns the number of files compiled. Run at deploy/boot; the
     * compiled output lives in the persistent `var/templates_c`.
     */
    public function compileAll(): int
    {
        // force_compile = false: compile only what is missing or whose source
        // changed (mtime), so a re-run on a warm templates_c is cheap.
        $count = 0;
        foreach (['.phtml', '.js'] as $ext) {
            $count += (int) $this->smarty->compileAllTemplates($ext, false);
        }

        return $count;
    }

    /**
     * Resolve a template name to its skin override if one exists under
     * `_skins/<skin>/`, else the default — identical to the ZF1 view.
     */
    public function resolveTemplate(string $name): string
    {
        $base = (string) $this->smarty->getTemplateDir(0);
        if ($this->skin !== '' && is_readable($base . '/_skins/' . $this->skin . '/' . $name)) {
            return '_skins/' . $this->skin . '/' . $name;
        }

        return $name;
    }

    /**
     * Select a skin (a directory under the template dir's `_skins/`). Throws if
     * it does not exist, matching the ZF1 view's contract.
     */
    public function setSkin(string $skin): void
    {
        $base = (string) $this->smarty->getTemplateDir(0);
        if (!is_readable($base . '/_skins/' . $skin)) {
            throw new \RuntimeException("Skin directory does not exist or is not readable ({$base}/_skins/{$skin})");
        }
        $this->skin = $skin;
    }

    public function getSkin(): string
    {
        return $this->skin;
    }

    /** The underlying engine, for the few callers that register a class etc. */
    public function getEngine(): \Smarty\Smarty
    {
        return $this->smarty;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }
}
