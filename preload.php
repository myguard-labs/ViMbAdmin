<?php

/**
 * opcache preload script (set as `opcache.preload` in the FPM pool).
 *
 * Runs once in the PHP-FPM master at startup and compiles the whole app +
 * vendor tree into the shared opcache, so worker processes never compile on the
 * first request (the cold-start cost a CLI warmup cannot touch — opcache is
 * per-SAPI and only an in-process compile fills FPM's copy).
 *
 * Uses opcache_compile_file (not require): it caches the compiled opcodes
 * without executing the file, which tolerates classes whose parents/interfaces
 * are not yet loaded at preload time. Everything is guarded + best-effort so a
 * single unpreloadable file can never stop FPM from starting.
 *
 * Source of the file list:
 *   - the Composer optimized classmap (all vendor + mapped classes), and
 *   - the app's own non-Composer trees (the framework-free kernel under src/,
 *     the OSS/ViMbAdmin libraries, and the Doctrine Entities/Repositories/
 *     Proxies under application/, which load via dedicated autoloaders).
 */

(static function (): void {
    $root = __DIR__;

    if (!function_exists('opcache_compile_file')) {
        return; // opcache not available — nothing to preload
    }

    $files = [];

    $classmap = $root . '/vendor/composer/autoload_classmap.php';
    if (is_file($classmap)) {
        /** @var array<string,string> $map */
        $map   = require $classmap;
        $files = array_values($map);
    }

    // var/templates_c holds the Smarty-compiled templates (the bootstrap
    // precompiles them before FPM starts), so they are opcached at master start
    // too — the first render then compiles nothing.
    foreach (['src', 'library', 'application/Entities', 'application/Repositories', 'application/Proxies', 'var/templates_c'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        /** @var SplFileInfo $f */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }

    foreach (array_unique($files) as $file) {
        if (is_string($file) && is_file($file)) {
            try {
                @opcache_compile_file($file);
            } catch (\Throwable $e) {
                // A file that cannot be preloaded (missing optional dependency,
                // conditional class def, …) is skipped — never fatal.
            }
        }
    }
})();
