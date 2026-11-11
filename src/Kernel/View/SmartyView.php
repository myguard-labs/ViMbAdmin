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

        if (array_key_exists('templates', $dirs)) {
            $this->smarty->setTemplateDir(self::directoryValue($dirs['templates'], 'Smarty templates'));
        }
        if (array_key_exists('compiled', $dirs) && $dirs['compiled'] !== '') {
            $compiled = self::directoryValue($dirs['compiled'], 'Smarty compiled');
            $this->ensureDir($compiled);
            $this->smarty->setCompileDir($compiled);
        }
        if (array_key_exists('cache', $dirs) && $dirs['cache'] !== '') {
            $cache = self::directoryValue($dirs['cache'], 'Smarty cache');
            $this->ensureDir($cache);
            $this->smarty->setCacheDir($cache);
        }
        if (array_key_exists('config', $dirs) && $dirs['config'] !== '') {
            $this->smarty->setConfigDir(self::directoryValue($dirs['config'], 'Smarty config'));
        }
        if (array_key_exists('plugins', $dirs) && $dirs['plugins'] !== '') {
            // The OSS template plugins ({genUrl}, {OSS_Message}, {addJSValidator}…).
            $plugins = self::pluginDirectories($dirs['plugins']);
            // Use Smarty 5's existing compatibility extension directly: the
            // deprecated addPluginsDir wrapper only normalizes paths and calls
            // this adapter, but also emits a deprecation during plugin loading.
            foreach ($this->smarty->getExtensions() as $extension) {
                if ($extension instanceof \Smarty\Extension\BCPluginsAdapter) {
                    foreach ((array) $plugins as $plugin) {
                        $path = $this->smarty->_realpath(rtrim($plugin, '/\\') . DIRECTORY_SEPARATOR, true);
                        $extension->loadPluginsFromDir($path);
                    }
                    break;
                }
            }
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
        $resources = self::optionArray($options['resources'] ?? null, 'resources');
        $o = self::optionArray($resources['smarty'] ?? null, 'resources.smarty');

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

        if (array_key_exists('skin', $o)) {
            if (!is_string($o['skin'])) {
                throw new \InvalidArgumentException('resources.smarty.skin must be a string');
            }
            if ($o['skin'] !== '') {
                $view->setSkin($o['skin']);
            }
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
        // Smarty's bulk compiler catches individual failures and returns a count.
        // Compile without rendering so exceptions reach the command's error handler.
        $directories = $this->smarty->getTemplateDir();
        if (!is_array($directories)) {
            throw new \LogicException('Smarty template directories must be an array');
        }
        $count = 0;
        foreach (['.phtml', '.js'] as $ext) {
            $resources = [];
            foreach ($directories as $key => $directory) {
                if (!is_string($directory)) {
                    throw new \LogicException('Smarty template directory must be a string');
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                ));
                foreach ($files as $file) {
                    if (!$file instanceof \SplFileInfo) {
                        throw new \LogicException('Smarty template iterator must yield file information');
                    }
                    $filename = $file->getFilename();
                    if (
                        str_starts_with($filename, '.') || str_contains($filename, '.svn')
                        || !str_ends_with($filename, $ext)
                    ) {
                        continue;
                    }
                    // Keep the same relative resource identity used when rendering.
                    $name = substr($file->getPathname(), strlen($directory));
                    // Qualify shadowed files using Smarty's native directory key.
                    $resource = isset($resources[$name]) ? '[' . $key . ']' . $name : $name;
                    $resources[$name] = true;
                    $template = $this->smarty->createTemplate($resource);
                    $template->caching = \Smarty\Smarty::CACHING_OFF;
                    if ($template->mustCompile()) {
                        $template->compileTemplateSource();
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Resolve a template name to its skin override if one exists under
     * `_skins/<skin>/`, else the default — identical to the ZF1 view.
     */
    public function resolveTemplate(string $name): string
    {
        self::templateName($name);
        $base = $this->templateBase();
        if ($this->skin !== '' && is_readable($base . '/_skins/' . $this->skin . '/' . $name)) {
            $skinned = '_skins/' . $this->skin . '/' . $name;
            if (!$this->templateContained($skinned)) {
                throw new \InvalidArgumentException('Smarty skin template is outside configured template roots');
            }
            return $skinned;
        }
        if ($this->smarty->templateExists($name) && !$this->templateContained($name)) {
            throw new \InvalidArgumentException('Smarty template is outside configured template roots');
        }

        return $name;
    }

    /**
     * Select a skin (a directory under the template dir's `_skins/`). Throws if
     * it does not exist, matching the ZF1 view's contract.
     */
    public function setSkin(string $skin): void
    {
        if (
            $skin === ''
            || str_contains($skin, "\0")
            || str_contains($skin, '/')
            || str_contains($skin, '\\')
            || $skin === '.'
            || $skin === '..'
        ) {
            throw new \InvalidArgumentException('Smarty skin must be a single safe directory name');
        }
        $base = $this->templateBase();
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
            if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create Smarty directory ({$dir})");
            }
        }
    }

    private function templateBase(): string
    {
        $base = $this->smarty->getTemplateDir(0);
        if (is_string($base)) {
            return $base;
        }

        $first = reset($base);
        return is_string($first) ? $first : '';
    }

    private static function directoryValue(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($name . ' must be a non-empty string');
        }
        return $value;
    }

    /** @return string|list<string> */
    private static function pluginDirectories(mixed $value): string|array
    {
        if (is_string($value)) {
            if ($value === '') {
                throw new \InvalidArgumentException('Smarty plugins must contain a non-empty path');
            }
            return $value;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Smarty plugins must be a string or array of strings');
        }
        $plugins = [];
        foreach ($value as $plugin) {
            if (!is_string($plugin) || $plugin === '') {
                throw new \InvalidArgumentException('Smarty plugins must contain non-empty strings');
            }
            $plugins[] = $plugin;
        }
        return $plugins;
    }

    /** @return array<array-key,mixed> */
    private static function optionArray(mixed $value, string $name): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($name . ' must be an array');
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException($name . ' must use string keys');
            }
        }
        return $value;
    }

    private static function templateName(string $name): void
    {
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_contains($name, ':')
            || $name[0] === '/'
        ) {
            throw new \InvalidArgumentException('Smarty template name must be a safe relative path');
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('Smarty template name must be a safe relative path');
            }
        }
    }

    private function templateContained(string $name): bool
    {
        $roots = $this->smarty->getTemplateDir();
        if (!is_array($roots)) {
            $roots = [$roots];
        }
        foreach ($roots as $root) {
            if (!is_string($root)) {
                continue;
            }
            $rootReal = realpath($root);
            $candidateReal = realpath($root . '/' . $name);
            if (
                is_string($rootReal) && is_string($candidateReal)
                && ($candidateReal === $rootReal || str_starts_with($candidateReal, $rootReal . '/'))
            ) {
                return true;
            }
        }
        return false;
    }
}
