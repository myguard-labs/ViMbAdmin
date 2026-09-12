<?php

/**
 * See bin/minify-bundle-files.php and vimbadminResolveBundleInputs() in
 * bin/minify-bundle.php for the enumeration rationale.
 *
 * This test pins the replacement: bin/minify-bundle-files.php enumerates the
 * inputs, bin/minify-bundle.php resolves them, every live asset is present
 * and correctly ordered, the six deleted paths stay gone, and an asset on
 * disk that is in neither the bundled nor the excluded list still fails
 * loudly instead of being silently shipped or silently dropped.
 *
 * It runs without Java or clean-css, which is why it asserts against
 * vimbadminResolveBundleInputs() and `--print-inputs` rather than against a
 * generated bundle. bin/minify-options.php exit(1)s when clean-css is missing,
 * so it is deliberately never loaded here.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

$check = static function (string $label, bool $ok, mixed $actual = null, mixed $expected = true) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        echo '       actual: ' . var_export(func_num_args() >= 3 ? $actual : $ok, true) . "\n";
        echo '       expected: ' . var_export($expected, true) . "\n";
        $failures++;
    }
};

echo "== minify bundle inputs ==\n";

require_once $root . '/bin/minify-bundle.php';

$check(
    'the driver exposes a resolver that can be loaded without a build toolchain',
    function_exists('vimbadminResolveBundleInputs')
);

/** @var array{js: list<string>, css: list<string>, jsExcluded: list<string>, cssExcluded: list<string>} $lists */
$lists = require $root . '/bin/minify-bundle-files.php';

foreach (['js', 'css', 'jsExcluded', 'cssExcluded'] as $key) {
    $check("the file list declares '{$key}'", isset($lists[$key]) && is_array($lists[$key]));
}

$js = vimbadminResolveBundleInputs(
    $root . '/public/js',
    '[0-9][0-9][0-9]-*.js',
    $lists['js'],
    $lists['jsExcluded']
);
$css = vimbadminResolveBundleInputs(
    $root . '/public/css',
    '[0-9][0-9][0-9]-*.css',
    $lists['css'],
    $lists['cssExcluded']
);

$jsNames = array_map('basename', $js);
$cssNames = array_map('basename', $css);

// The point of the whole change (VIM-A15.56): these six paths matched the
// retired glob or existed only to support the two libraries that did (the
// Chosen sprite images and the Colorbox image directory), and must never come
// back. Chosen and Colorbox are no longer bundled, no longer excluded, and no
// longer on disk at all.
$deletedPaths = [
    'public/js/130-jquery.colorbox.js',
    'public/js/300-chosen.jquery.js',
    'public/css/130-colorbox.css',
    'public/css/300-chosen.css',
    'public/css/chosen-sprite.png',
    'public/css/chosen-sprite@2x.png',
];
foreach ($deletedPaths as $deleted) {
    $check("dead vendor asset no longer exists on disk: {$deleted}", !file_exists($root . '/' . $deleted));
}
$check(
    'the Colorbox image directory no longer exists on disk',
    !is_dir($root . '/public/images/colorbox')
);
foreach (['120-jquery.validate.js', '130-jquery.colorbox.js', '300-chosen.jquery.js', '900-vimbadmin.validate.js'] as $dead) {
    $check("dead JS asset is not a bundle input: {$dead}", !in_array($dead, $jsNames, true));
    $check("dead JS asset no longer exists: {$dead}", !is_file($root . '/public/js/' . $dead));
}
foreach (['130-colorbox.css', '300-chosen.css'] as $dead) {
    $check("dead CSS asset is not a bundle input: {$dead}", !in_array($dead, $cssNames, true));
}
$check('jsExcluded is empty now that Chosen and Colorbox are deleted', $lists['jsExcluded'] === [], $lists['jsExcluded'], []);
$check('cssExcluded is empty now that Chosen and Colorbox are deleted', $lists['cssExcluded'] === [], $lists['cssExcluded'], []);

// The live assets, enumerated from the real tree, in bundle concatenation
// order. An exact comparison rather than a subset check: a bundle that gained
// an unreviewed file is as much a defect as one that lost a library.
$expectedJs = [
    '120-vimbadmin.validation.js',
    '150-datatables.js',
    '151-datatables.ext.js',
    '152-datatables.bootstrap5.js',
    '800-bootstrap.js',
    '850-vimbadmin.modals.js',
    '910-vimbadmin.functions.js',
    '990-vimbadmin.js',
];
$expectedCss = [
    '800-bootstrap.css',
    '815-bootstrap-icons.css',
    '816-datatables-bootstrap5.css',
    '890-override_container_app.css',
    '895-bootstrap-override.css',
    '920-style.css',
    '930-popup.css',
];

$check('the JS bundle inputs are exactly the live assets, in order', $jsNames === $expectedJs, $jsNames, $expectedJs);
$check('the CSS bundle inputs are exactly the live assets, in order', $cssNames === $expectedCss, $cssNames, $expectedCss);

foreach ($expectedJs as $live) {
    $check("live JS asset resolves to a real file: {$live}", is_file($root . '/public/js/' . $live));
}
$runtimeJs = '';
foreach ($expectedJs as $live) {
    $runtimeJs .= (string) file_get_contents($root . '/public/js/' . $live);
}
$check('runtime JS has no jQuery Validation API references',
    !str_contains($runtimeJs, 'jQuery.validator')
        && !str_contains($runtimeJs, '$.validator')
        && preg_match('/\.validate\s*\(/', $runtimeJs) !== 1);
$check('runtime JS has no Bootbox references',
    stripos($runtimeJs, 'bootbox') === false);
$check('jQuery is not shipped or loaded',
    !is_file($root . '/public/js/100-jquery.js')
        && !str_contains((string) file_get_contents($root . '/application/views/header-js.phtml'), 'jquery'));
// Vendor files retain optional interoperability. First-party code must never
// invoke it; scan source so a forgotten bundle rebuild cannot conceal a regression.
$ownSources = array_merge(
    glob($root . '/public/js/*vimbadmin*.js') ?: [],
    glob($root . '/application/views/*/js/*.js') ?: [],
    [$root . '/public/js/151-datatables.ext.js']
);
foreach ($ownSources as $source) {
    $check('no first-party jQuery runtime reference: ' . basename($source),
        preg_match('/\bjQuery\b|(?:^|[^A-Za-z0-9_$])\$\s*[.(]/m', (string) file_get_contents($source)) === 0);
}
foreach ([
    // Core includes the local fixes documented with its upstream hash in docs/ASSETS.md.
    'public/js/150-datatables.js' => 'ae3173803747ce7ac8867df685a88b048350f874717cbc3b07a3f59c7f56a19c',
    'public/js/152-datatables.bootstrap5.js' => 'cb335f90908b20599ec84d5396940f3ecbb958d43b231e58fb2ddb7fa11b63d3',
    'public/css/816-datatables-bootstrap5.css' => '92a010aa4be02fb5de612cad3aeefc67cdd18ba24529767b9625e60dc70d0c8e',
] as $asset => $hash) {
    $check('DataTables 3.0.3 shipped asset integrity: ' . $asset, hash_file('sha256', $root . '/' . $asset) === $hash);
}
$modalJs = (string) file_get_contents($root . '/public/js/850-vimbadmin.modals.js');
$check('native modal helper has no jQuery runtime dependency',
    !str_contains($modalJs, 'jQuery')
        && preg_match('/(^|[^A-Za-z0-9_$])\$\s*\(/', $modalJs) !== 1);
foreach ($expectedCss as $live) {
    $check("live CSS asset resolves to a real file: {$live}", is_file($root . '/public/css/' . $live));
}

// jQuery Migrate was deleted entirely with the jQuery 4.0.0 upgrade
// (VIM-A15.29): it must not be a bundle input, and it must not exist on disk
// at all any more.
$check(
    'jQuery Migrate is not a bundle input',
    !in_array('jquery-migrate-3.5.2.js', $jsNames, true)
);
$check(
    'jQuery Migrate no longer exists on disk',
    !is_file($root . '/public/js/jquery-migrate-3.5.2.js')
);

// The header {else} branches are what the driver regenerates from these lists,
// so they must already agree -- otherwise the first regeneration silently
// changes what development loads.
$headerJs = (string) file_get_contents($root . '/application/views/header-js.phtml');
$headerCss = (string) file_get_contents($root . '/application/views/header-css.phtml');

foreach ($expectedJs as $live) {
    $check("header-js.phtml lists the bundled asset: {$live}", str_contains($headerJs, '/js/' . $live . '"'));
}
foreach (['130-jquery.colorbox.js', '300-chosen.jquery.js'] as $dead) {
    $check("header-js.phtml does not list the dead asset: {$dead}", !str_contains($headerJs, $dead));
}
foreach ($expectedCss as $live) {
    $check("header-css.phtml lists the bundled asset: {$live}", str_contains($headerCss, '/css/' . $live . '"'));
}
foreach (['130-colorbox.css', '300-chosen.css'] as $dead) {
    $check("header-css.phtml does not list the dead asset: {$dead}", !str_contains($headerCss, $dead));
}

// Reconciliation guards. An asset that is in neither list is unreviewed, and
// bundling or skipping it silently are both wrong.
$rejects = static function (callable $call): bool {
    try {
        $call();
    } catch (RuntimeException) {
        return true;
    }

    return false;
};

// Stricter than $rejects: confirms the RuntimeException is the SPECIFIC one
// expected, identified by a substring unique to that failure branch, not
// merely that some RuntimeException was thrown by some other branch first.
$rejectsWith = static function (callable $call, string $expectedSubstring): bool {
    try {
        $call();
    } catch (RuntimeException $error) {
        return str_contains($error->getMessage(), $expectedSubstring);
    }

    return false;
};

// The "unaccounted asset" and "bundled-and-excluded" cases used to be
// exercised against public/js with Chosen/Colorbox playing the disagreeing
// file; now that both are deleted, real public/js has no unlisted or
// double-listed file left to borrow, so a tiny throwaway fixture directory
// stands in for the tree instead.
$fixtureDir = sys_get_temp_dir() . '/vimbadmin-minify-bundle-fixture-' . bin2hex(random_bytes(6));
if (!mkdir($fixtureDir, 0700) && !is_dir($fixtureDir)) {
    throw new RuntimeException("Could not create fixture directory: {$fixtureDir}");
}
try {
    if (file_put_contents($fixtureDir . '/100-listed.js', '// stub') === false) {
        throw new RuntimeException("Could not write fixture file: {$fixtureDir}/100-listed.js");
    }
    if (file_put_contents($fixtureDir . '/200-unlisted.js', '// stub') === false) {
        throw new RuntimeException("Could not write fixture file: {$fixtureDir}/200-unlisted.js");
    }

    $check(
        'an asset in neither list is rejected rather than silently skipped',
        $rejectsWith(static fn () => vimbadminResolveBundleInputs(
            $fixtureDir,
            '[0-9][0-9][0-9]-*.js',
            ['100-listed.js'],
            [] // '200-unlisted.js' is on disk but in neither list.
        ), 'are in neither the bundled nor the excluded')
    );
    $check(
        'a listed input that is missing from disk is rejected',
        $rejects(static fn () => vimbadminResolveBundleInputs(
            $root . '/public/js',
            '[0-9][0-9][0-9]-*.js',
            array_merge($lists['js'], ['999-not-on-disk.js']),
            $lists['jsExcluded']
        ))
    );
    $check(
        'a file that is both bundled and excluded is rejected',
        $rejects(static fn () => vimbadminResolveBundleInputs(
            $fixtureDir,
            '[0-9][0-9][0-9]-*.js',
            ['100-listed.js', '200-unlisted.js'],
            ['200-unlisted.js']
        ))
    );
} finally {
    @unlink($fixtureDir . '/100-listed.js');
    @unlink($fixtureDir . '/200-unlisted.js');
    @rmdir($fixtureDir);
}
$check(
    'a stale exclusion for a file no longer on disk is rejected',
    $rejects(static fn () => vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        $lists['js'],
        array_merge($lists['jsExcluded'], ['777-deleted-long-ago.js'])
    ))
);

// The driver's own --print-inputs path, which is what a human runs, must agree
// with the resolver the assertions above used.
$printed = [];
$status = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/minify-bundle.php') . ' --print-inputs 2>&1',
    $printed,
    $status
);
$check('--print-inputs succeeds without a build toolchain', $status === 0, $status, 0);
$expectedPrinted = array_merge(
    array_map(static fn (string $n): string => 'js  ' . $n, $expectedJs),
    array_map(static fn (string $n): string => 'css ' . $n, $expectedCss)
);
$check('--print-inputs reports the same list the resolver returns', $printed === $expectedPrinted, $printed, $expectedPrinted);

// The retired vendor invocation must not be advertised anywhere: running it
// would reintroduce the glob and revert PR #180 again.
foreach (['bin/minify-options.php', 'bin/minify-bundle.php'] as $documented) {
    $source = (string) file_get_contents($root . '/' . $documented);
    $check(
        "{$documented} does not advertise a runnable vendor minify.php invocation",
        preg_match('/^[^\n*]*\bphp\s+\S*vendor\S*minify\.php\b/m', $source) !== 1
    );
}
$optionsSource = (string) file_get_contents($root . '/bin/minify-options.php');
$check(
    'bin/minify-options.php points at the repo-owned driver',
    str_contains($optionsSource, 'bin/minify-bundle.php')
);
$toolPackage = json_decode((string) file_get_contents($root . '/bin/package.json'), true);
$toolLock = json_decode((string) file_get_contents($root . '/bin/package-lock.json'), true);
$assetsDoc = (string) file_get_contents($root . '/docs/ASSETS.md');
$toolDependencies = is_array($toolPackage) && isset($toolPackage['dependencies'])
    && is_array($toolPackage['dependencies']) ? $toolPackage['dependencies'] : [];
$toolPackages = is_array($toolLock) && isset($toolLock['packages'])
    && is_array($toolLock['packages']) ? $toolLock['packages'] : [];
$cleanCssCliLock = isset($toolPackages['node_modules/clean-css-cli'])
    && is_array($toolPackages['node_modules/clean-css-cli'])
    ? $toolPackages['node_modules/clean-css-cli'] : [];
$cleanCssLock = isset($toolPackages['node_modules/clean-css'])
    && is_array($toolPackages['node_modules/clean-css'])
    ? $toolPackages['node_modules/clean-css'] : [];
$check(
    'Closure Compiler digest is enforced by the build configuration',
    str_contains($optionsSource, "230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f")
        && str_contains($optionsSource, "hash_file( 'sha256', \$compiler_jar )")
);
$check(
    'clean-css CLI is an exact direct dependency',
    ($toolDependencies['clean-css-cli'] ?? null) === '5.6.3'
);
$check(
    'clean-css dependency graph is locked',
    is_array($toolLock)
        && ($toolLock['lockfileVersion'] ?? null) === 3
        && ($cleanCssCliLock['version'] ?? null) === '5.6.3'
        && ($cleanCssLock['version'] ?? null) === '5.3.3'
);
$check(
    'asset documentation describes enforced toolchain verification',
    str_contains($assetsDoc, 'npm ci --prefix bin')
        && str_contains($assetsDoc, 'verifies the compiler SHA-256')
        && str_contains($assetsDoc, 'clean-css dependency')
);

// VIM-A15.46: public/css/816-datatables-bootstrap5.css opens with a real
// `@charset "UTF-8";`, which is only valid as the very first byte of a
// stylesheet. bin/minify-bundle.php concatenates minified CSS inputs in list
// order, so a non-first input's @charset must be stripped before merging or a
// browser discards it as an invalid at-rule anyway -- but silently, in the
// middle of the shipped bundle. vimbadminStripLeadingCharset() is the pure
// helper the merge loop calls; exercise it directly here since building an
// actual bundle needs Java and clean-css.
$check(
    'the driver exposes the @charset-stripping helper',
    function_exists('vimbadminStripLeadingCharset')
);
$charsetChunk = "@charset \"UTF-8\";\ntable{color:red}";
$check(
    'a non-first chunk loses its leading @charset',
    vimbadminStripLeadingCharset($charsetChunk, false) === "\ntable{color:red}"
);
$check(
    'the first chunk keeps its leading @charset unchanged',
    vimbadminStripLeadingCharset($charsetChunk, true) === $charsetChunk
);
$check(
    'a chunk with no @charset is unaffected either way',
    vimbadminStripLeadingCharset('table{color:red}', false) === 'table{color:red}'
    && vimbadminStripLeadingCharset('table{color:red}', true) === 'table{color:red}'
);
$check(
    'only a LEADING @charset is stripped, not one appearing mid-file',
    vimbadminStripLeadingCharset('table{color:red}@charset "UTF-8";', false)
        === 'table{color:red}@charset "UTF-8";'
);

// End-to-end: prove the concatenation the merge loop performs (helper applied
// to every non-first chunk, in the same order vimbadminResolveBundleInputs()
// returns) contains at most one @charset, and only at offset 0.
//
// This used to pin public/css/816-datatables-bootstrap5.css as the non-first
// chunk carrying the @charset. DataTables 2.x ships its Bootstrap 5 stylesheet
// without one (1.13.11 had it), so pinning that file by name asserted a fact
// about a vendored third-party artifact rather than about the merge. Instead
// discover which inputs actually open with @charset: the first chunk must be
// allowed to keep one, and at least one NON-first chunk must carry one, or the
// stripping path below is never exercised and the end-to-end check is vacuous.
$charsetOpeners = [];
foreach ($expectedCss as $index => $cssName) {
    $chunk = (string) file_get_contents($root . '/public/css/' . $cssName);
    if (str_starts_with($chunk, '@charset')) {
        $charsetOpeners[$index] = $cssName;
    }
}
$nonFirstCharsetOpeners = array_filter(
    $charsetOpeners,
    static fn (int $index): bool => $index > 0,
    ARRAY_FILTER_USE_KEY
);
// Whether any non-first chunk currently carries @charset is a property of the
// vendored inputs, not of the merge, so it is REPORTED rather than asserted --
// the direct vimbadminStripLeadingCharset() checks above already pin the
// stripping behaviour itself. The end-to-end invariant that must always hold,
// asserted below, is that the merged bundle carries at most one @charset and
// only at offset 0.
if ($nonFirstCharsetOpeners === []) {
    echo "  note no non-first CSS input currently opens with @charset; the merge\n"
       . "       loop's strip path is unexercised end-to-end (unit-checked above)\n";
} else {
    echo '  note non-first CSS inputs opening with @charset: '
       . implode(', ', $nonFirstCharsetOpeners) . "\n";
}

$simulatedMerge = '';
$isFirstChunk = true;
foreach ($css as $cssPath) {
    $chunk = (string) file_get_contents($cssPath);
    $simulatedMerge .= vimbadminStripLeadingCharset($chunk, $isFirstChunk);
    $isFirstChunk = false;
}
$charsetOccurrences = substr_count($simulatedMerge, '@charset');
$check(
    'the simulated bundle contains at most one @charset',
    $charsetOccurrences <= 1,
    $charsetOccurrences,
    'at most 1'
);
if ($charsetOccurrences === 1) {
    $check(
        'the single remaining @charset sits at offset 0',
        str_starts_with($simulatedMerge, '@charset'),
        strpos($simulatedMerge, '@charset'),
        0
    );
} elseif ($charsetOccurrences === 0) {
    echo "  ok   no @charset survived concatenation (no chunk opened with one)\n";
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
