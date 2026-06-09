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
    foreach (['src', 'library', 'application/Entities', 'application/Repositories', 'application/Proxies', 'application/plugins', 'var/templates_c'] as $dir) {
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

    // opcache emits an E_WARNING ("Can't preload unlinked class …") for every
    // file whose parent/interface/trait is not yet loaded at preload time, or
    // for dev-only classes (PHPUnit constraints, DI compiler passes) that will
    // never be reachable in production. These are benign — preload is
    // best-effort and the worker compiles such a file lazily on first use — but
    // they flood the FPM log on every master start.
    //
    // Crucially these warnings are NOT raised by opcache_compile_file(): opcache
    // defers class *linking* to a final pass that runs after this preload script
    // returns. Suppressing error_reporting only around the compile loop (and
    // restoring it afterwards) therefore leaves the link pass running at the
    // original level — which is exactly why the warnings still flooded the log.
    //
    // So we lower error_reporting and DO NOT restore it. This runs in the
    // FPM *master* process at startup; workers are forked later and read their
    // error_reporting fresh from php.ini, so leaving the master's level at 0 has
    // no effect on request handling — it only silences opcache's own link pass.
    error_reporting(0);
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
