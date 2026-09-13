#!/usr/bin/env php
<?php

/**
 * Build public/js/min.bundle-v<N>.js and public/css/min.bundle-v<N>.css from an
 * explicit, repo-owned file list.
 *
 * This replaces the direct
 *
 *     php vendor/opensolutions/minify/minify.php --conf "$PWD/bin/minify-options.php" --version 18
 *
 * invocation. The vendor entry point `require_once()`s the config and only THEN
 * expands the `$js_files` / `$css_files` globs, so the config has no post-glob
 * hook: any NNN-prefixed file on disk was shipped whether or not the
 * application still loaded it, and the header .phtml files it regenerates were
 * rebuilt from the same glob. See bin/minify-bundle-files.php for the concrete
 * regression that caused (Chosen and Colorbox, PR #180).
 *
 * `vendor/` is untracked and Composer-pinned (`opensolutions/minify: 1.*`), so
 * it cannot be patched. This driver lives in the repository instead and needs
 * no vendor change: it reproduces minify.php's behaviour step for step and only
 * substitutes an explicit input list for the glob.
 *
 * bin/minify-options.php is still the single source of truth for everything
 * else -- the compiler command lines, the clean-css presence check, the
 * destination directories, the {genUrl} prefixes and the hand-written
 * $mini_*_conditional_* header fragments (including the $skinCss block).
 * It is required here, unmodified,
 * rather than duplicated; only its $js_files / $css_files globs are ignored.
 *
 * Usage:
 *
 *     php bin/minify-bundle.php --version 18
 *     php bin/minify-bundle.php --version 18 --js-only
 *     php bin/minify-bundle.php --version 16 --css-only
 *     php bin/minify-bundle.php --print-inputs
 *
 * --version takes the BARE number ('18'); the 'v' prefix is added for you, as
 * it was by minify.php. Unlike minify.php, --js-only and --css-only are honoured
 * (the old config reset $whatToCompress after argument parsing, so --js-only
 * silently regenerated CSS too).
 *
 * --print-inputs resolves and prints the input lists and exits without
 * minifying anything, so the list can be asserted without Java or clean-css
 * installed. tests/test-minify-bundle-inputs.php uses it.
 *
 * Build prerequisites (per-machine, gitignored, not vendored):
 *
 *     curl -fsSL -o bin/compiler.jar \
 *       https://repo1.maven.org/maven2/com/google/javascript/closure-compiler/v20230802/closure-compiler-v20230802.jar
 *     printf '%s  %s\n' \
 *       230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f \
 *       bin/compiler.jar | sha256sum -c -
 *     npm ci --prefix bin
 */

declare(strict_types=1);

/**
 * Resolve one explicit input list against the directory it names.
 *
 * Deliberately pure and side-effect free: tests/test-minify-bundle-inputs.php
 * calls it directly, without loading bin/minify-options.php, which exit(1)s
 * when clean-css is absent (as it is on CI runners that never build a bundle).
 *
 * Every failure mode is fatal rather than skipped. Silently dropping a missing
 * file would ship a bundle short of a library; silently accepting an unlisted
 * one is the glob behaviour this driver exists to end.
 *
 * @param list<string> $wanted   basenames to bundle, in any order
 * @param list<string> $excluded basenames deliberately kept on disk but not bundled
 * @return list<string> absolute paths, in bundle concatenation order
 * @throws RuntimeException when the tree and the lists disagree
 */
function vimbadminResolveBundleInputs(
    string $directory,
    string $globPattern,
    array $wanted,
    array $excluded
): array {
    $real = realpath($directory);
    if ($real === false || !is_dir($real)) {
        throw new RuntimeException("Asset directory does not exist: {$directory}");
    }

    // The glob is no longer what selects the bundle; it is retained purely as
    // the discovery set for the reconciliation below, so a newly added asset
    // cannot slip into (or out of) production unnoticed.
    $discovered = [];
    foreach ((array) glob($real . '/' . $globPattern) as $path) {
        $discovered[] = basename((string) $path);
    }
    sort($discovered, SORT_STRING);

    $missing = array_diff($wanted, $discovered);
    if ($missing !== []) {
        throw new RuntimeException(
            'Listed bundle inputs are missing from ' . $real . ': '
            . implode(', ', $missing)
        );
    }

    $overlap = array_intersect($wanted, $excluded);
    if ($overlap !== []) {
        throw new RuntimeException(
            'Files are both bundled and excluded: ' . implode(', ', $overlap)
        );
    }

    // Anything on disk that is in neither list is an unreviewed asset. Refuse
    // rather than guess: bundling it would ship unreviewed code to every
    // browser, and skipping it would quietly drop a library someone added.
    $unaccounted = array_diff($discovered, $wanted, $excluded);
    if ($unaccounted !== []) {
        throw new RuntimeException(
            'Assets in ' . $real . ' are in neither the bundled nor the excluded '
            . 'list of bin/minify-bundle-files.php: ' . implode(', ', $unaccounted)
        );
    }

    $stale = array_diff($excluded, $discovered);
    if ($stale !== []) {
        throw new RuntimeException(
            'Excluded assets no longer exist in ' . $real
            . '; drop them from bin/minify-bundle-files.php: ' . implode(', ', $stale)
        );
    }

    // Same ordering minify.php used, so the bundle byte order is unchanged for
    // every file that is still bundled.
    $ordered = array_values($wanted);
    sort($ordered, SORT_STRING);

    return array_map(static fn (string $name): string => $real . '/' . $name, $ordered);
}

/**
 * Strip a leading `@charset` at-rule from CSS that is about to be concatenated
 * as a NON-FIRST chunk of a bundle.
 *
 * CSS permits `@charset` only as the very first thing in a stylesheet (no
 * preceding bytes, not even a comment or BOM); a browser silently discards any
 * later occurrence as an invalid at-rule. `bin/minify-bundle.php` concatenates
 * minified CSS inputs in list order, and Google's closure-css/clean-css do not
 * strip a source file's own `@charset` -- so an input file that legitimately
 * opens with `@charset "UTF-8";` (public/css/816-datatables-bootstrap5.css)
 * drags a dead, invalid at-rule into the MIDDLE of the merged bundle whenever
 * it is not the first input (VIM-A15.46).
 *
 * This only ever strips a chunk that is NOT the first in the bundle -- call it
 * with $isFirst = true for the first input and it returns $css unchanged, so a
 * legitimate leading @charset on the bundle's actual first byte survives.
 *
 * Deliberately pure and side-effect free, like vimbadminResolveBundleInputs(),
 * so tests/test-minify-bundle-inputs.php can exercise it without Java or
 * clean-css.
 *
 * @param bool $isFirst whether $css is the first chunk written into the bundle
 */
function vimbadminStripLeadingCharset(string $css, bool $isFirst): string
{
    if ($isFirst) {
        return $css;
    }

    // Matches only a leading @charset rule (optionally preceded by whitespace
    // a minifier left behind), never one that merely appears later in the
    // file -- `preg_replace`'s `^` anchor with no /m flag matches only the
    // start of the whole string.
    $stripped = preg_replace('/^\s*@charset\s+(?:"[^"]*"|\'[^\']*\')\s*;/', '', $css, 1);

    return $stripped ?? $css;
}

/**
 * Load bin/minify-bundle-files.php and prove its shape.
 *
 * The file is plain data, but it is still a `require`, so nothing about its
 * contents is guaranteed at the type level. Validate once here rather than
 * spreading defensive checks (or type suppressions) through the build.
 *
 * @return array{js: list<string>, css: list<string>, jsExcluded: list<string>, cssExcluded: list<string>}
 * @throws RuntimeException when the file does not return the expected shape
 */
function vimbadminLoadBundleFileLists(string $path): array
{
    /** @var mixed $raw */
    $raw = require $path;

    if (!is_array($raw)) {
        throw new RuntimeException("{$path} must return an array.");
    }

    $lists = [];
    foreach (['js', 'css', 'jsExcluded', 'cssExcluded'] as $key) {
        /** @var mixed $value */
        $value = $raw[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException("{$path} must return a '{$key}' array.");
        }

        $names = [];
        /** @var mixed $name */
        foreach ($value as $name) {
            if (!is_string($name) || $name === '' || $name !== basename($name)) {
                throw new RuntimeException(
                    "{$path}: '{$key}' entries must be plain file basenames."
                );
            }
            $names[] = $name;
        }

        $lists[$key] = $names;
    }

    return [
        'js' => $lists['js'],
        'css' => $lists['css'],
        'jsExcluded' => $lists['jsExcluded'],
        'cssExcluded' => $lists['cssExcluded'],
    ];
}

/**
 * Load bin/minify-options.php and return the settings this driver consumes.
 *
 * The config file assigns loose local variables (it predates this driver and is
 * shared with the vendor entry point), so it is loaded inside this function --
 * both to keep those variables out of the driver's scope and to convert them
 * into one validated, typed structure. Its loud clean-css exit(1) fires here,
 * unchanged.
 *
 * @return array{
 *     verbose: bool,
 *     js: array{compiler: string, dest: string, http: string, delMinis: bool, delOld: bool, header: string|false, if: string, else: string, end: string},
 *     css: array{compiler: string, dest: string, http: string, delMinis: bool, delOld: bool, header: string|false, if: string, else: string, end: string}
 * }
 * @throws RuntimeException when a required setting is missing or mistyped
 */
function vimbadminLoadMinifyOptions(string $path): array
{
    // The config file assigns loose local variables. `get_defined_vars()`
    // captures them as one array rather than leaving them as scope-injected
    // locals no static analyser can see, and every value is then validated
    // below instead of being trusted.
    require $path;
    /** @var array<string, mixed> $defined */
    $defined = get_defined_vars();
    unset($defined['path']);

    $string = static function (string $name) use ($defined, $path): string {
        $value = $defined[$name] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("{$path} must define \${$name} as a string.");
        }

        return $value;
    };
    $header = static function (string $name) use ($defined, $path): string|false {
        $value = $defined[$name] ?? null;
        if ($value === false) {
            return false;
        }
        if (!is_string($value)) {
            throw new RuntimeException("{$path} must define \${$name} as a string or false.");
        }

        return $value;
    };
    $flag = static fn (string $name): bool => (bool) ($defined[$name] ?? false);

    return [
        'verbose' => $flag('verbose'),
        'js' => [
            'compiler' => $string('js_compiler'),
            'dest' => $string('js_dest'),
            'http' => $string('http_js'),
            'delMinis' => $flag('del_mini_js'),
            'delOld' => $flag('del_old_js_bundles'),
            'header' => $header('js_header_file'),
            'if' => $string('mini_js_conditional_if'),
            'else' => $string('mini_js_conditional_else'),
            'end' => $string('mini_js_conditional_end'),
        ],
        'css' => [
            'compiler' => $string('css_compiler'),
            'dest' => $string('css_dest'),
            'http' => $string('http_css'),
            'delMinis' => $flag('del_mini_css'),
            'delOld' => $flag('del_old_css_bundles'),
            'header' => $header('css_header_file'),
            'if' => $string('mini_css_conditional_if'),
            'else' => $string('mini_css_conditional_else'),
            'end' => $string('mini_css_conditional_end'),
        ],
    ];
}

/**
 * Minify each input to min.<basename>, concatenate in list order, delete the
 * per-file temporaries and old bundles, write min.bundle-v<N>.<ext>, then
 * regenerate the header .phtml from the config's hand-written fragments.
 *
 * This is `minify.php`'s sequence step for step, with the glob replaced by
 * $inputs and with each compiler invocation checked: minify.php ignored
 * exec()'s status, so a compiler failure produced a silently truncated bundle.
 *
 * @param 'js'|'css' $kind
 * @param list<string> $inputs absolute paths, in concatenation order
 * @param array{compiler: string, dest: string, http: string, delMinis: bool, delOld: bool, header: string|false, if: string, else: string, end: string} $config
 * @param callable(string):void $say
 */
function vimbadminBuildBundle(
    string $kind,
    array $inputs,
    string $outputFlag,
    string $rowFormat,
    array $config,
    string $version,
    callable $say
): void {
    $say("\nMinifying " . strtoupper($kind) . ":\n\n");

    $header = '';
    $count = 0;
    foreach ($inputs as $input) {
        $count++;
        $base = basename($input);
        $say("    [{$count}] {$base} => min.{$base}\n");

        $minified = $config['dest'] . '/min.' . $base;
        // Same argument shape minify.php used: `$js_compiler --js X
        // --js_output_file Y` and `$css_compiler -o Y X`. Every path is escaped
        // here, which it was not there.
        $command = $kind === 'js'
            ? $config['compiler'] . ' --js ' . escapeshellarg($input)
                . ' ' . $outputFlag . ' ' . escapeshellarg($minified)
            : $config['compiler'] . ' ' . $outputFlag . ' ' . escapeshellarg($minified)
                . ' ' . escapeshellarg($input);

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0 || !is_file($minified)) {
            fwrite(STDERR, "FATAL: minifying {$base} failed (status {$status}):\n"
                . implode("\n", $output) . "\n");
            exit(1);
        }

        $header .= sprintf($rowFormat, $config['http'], $base);
    }

    $say("\n    Combining...");
    $merged = '';
    $first = true;
    foreach ($inputs as $input) {
        $minified = $config['dest'] . '/min.' . basename($input);
        $contents = file_get_contents($minified);
        if ($contents === false) {
            fwrite(STDERR, "FATAL: could not read {$minified}\n");
            exit(1);
        }

        // Only a NON-first CSS chunk's own @charset is dead weight in the
        // middle of the bundle; the bundle's first byte may legitimately open
        // with one (VIM-A15.46). JS has no @charset concept, so this is a
        // no-op there.
        if ($kind === 'css') {
            $contents = vimbadminStripLeadingCharset($contents, $first);
        }

        $merged .= $contents;
        $first = false;

        if ($config['delMinis']) {
            unlink($minified);
        }
    }

    if ($config['delOld']) {
        foreach ((array) glob($config['dest'] . '/min.bundle*' . $kind) as $old) {
            if (is_string($old)) {
                unlink($old);
            }
        }
    }

    // Written loudly: a silent write failure (read-only tree, full disk, bad
    // permissions on the .phtml) would print " done", exit 0, and leave a
    // missing or stale bundle behind a green build.
    $bundle = "min.bundle-v{$version}.{$kind}";
    $bundlePath = $config['dest'] . '/' . $bundle;
    if (file_put_contents($bundlePath, $merged) === false) {
        fwrite(STDERR, "FATAL: could not write {$bundlePath}\n");
        exit(1);
    }

    if ($config['header'] !== false && $config['header'] !== '') {
        $bundleRow = sprintf($rowFormat, $config['http'], $bundle);
        $written = file_put_contents(
            $config['header'],
            $config['if'] . "\n" . $bundleRow . $config['else'] . "\n" . $header . $config['end'] . "\n"
        );
        if ($written === false) {
            fwrite(STDERR, "FATAL: could not write {$config['header']}\n");
            exit(1);
        }
    }

    $say(" done\n\n");
}

// Loading this file for its function only (a test, or a custom runner) must not
// run the build.
if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$root = dirname(__DIR__);
$version = null;
$whatToCompress = 'all';
$printInputs = false;
$quiet = false;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--version':
            $i++;
            $version = $argv[$i] ?? null;
            break;
        case '--js-only':
            $whatToCompress = 'js';
            break;
        case '--css-only':
            $whatToCompress = 'css';
            break;
        case '--print-inputs':
            $printInputs = true;
            break;
        case '--quiet':
            $quiet = true;
            break;
        case '--help':
        case '-h':
            fwrite(
                STDOUT,
                "Usage: php bin/minify-bundle.php --version <num> [--js-only|--css-only] [--quiet]\n"
                . "       php bin/minify-bundle.php --print-inputs\n\n"
                . "--version takes the bare number; the 'v' prefix is added for you.\n"
            );
            exit(0);
        default:
            fwrite(STDERR, "Unknown parameter {$argv[$i]}\n");
            exit(2);
    }
}

try {
    $lists = vimbadminLoadBundleFileLists(__DIR__ . '/minify-bundle-files.php');
    $jsInputs = vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        $lists['js'],
        $lists['jsExcluded']
    );
    $cssInputs = vimbadminResolveBundleInputs(
        $root . '/public/css',
        '[0-9][0-9][0-9]-*.css',
        $lists['css'],
        $lists['cssExcluded']
    );
} catch (RuntimeException $error) {
    fwrite(STDERR, 'FATAL: ' . $error->getMessage() . "\n");
    exit(1);
}

if ($printInputs) {
    foreach ($jsInputs as $path) {
        echo 'js  ' . basename($path) . "\n";
    }
    foreach ($cssInputs as $path) {
        echo 'css ' . basename($path) . "\n";
    }
    exit(0);
}

if ($version === null || preg_match('/^[0-9]+$/', $version) !== 1) {
    fwrite(STDERR, "FATAL: --version <num> is required and takes the bare number, e.g. --version 18.\n");
    exit(2);
}

// bin/minify-options.php supplies the compilers, destinations, URL prefixes and
// the hand-written header fragments. It also performs the loud clean-css check
// and exit(1)s when it is absent -- that behaviour is deliberately inherited,
// not re-implemented. It defines APPLICATION_PATH and SCRIPTDIR itself when
// they are not already defined.
try {
    define('VIMBADMIN_MINIFY_LANE', $whatToCompress);
    $options = vimbadminLoadMinifyOptions(__DIR__ . '/minify-options.php');
} catch (RuntimeException $error) {
    fwrite(STDERR, 'FATAL: ' . $error->getMessage() . "\n");
    exit(1);
}

$verbose = $options['verbose'] && !$quiet;
$say = static function (string $message) use ($verbose): void {
    if ($verbose) {
        echo $message;
    }
};

$jsRow = "    <script type=\"text/javascript\" src=\"%s/%s\"></script>\n";
$cssRow = "    <link rel=\"stylesheet\" type=\"text/css\" href=\"%s/%s\" />\n";

// minify-options.php resets $whatToCompress unconditionally, which is why the
// vendor entry point's --js-only never worked: it parsed the command line
// BEFORE loading the config. Here the command line is applied after.
if ($whatToCompress === 'all' || $whatToCompress === 'js') {
    vimbadminBuildBundle('js', $jsInputs, '--js_output_file', $jsRow, $options['js'], $version, $say);
}

if ($whatToCompress === 'all' || $whatToCompress === 'css') {
    vimbadminBuildBundle('css', $cssInputs, '-o', $cssRow, $options['css'], $version, $say);
}
