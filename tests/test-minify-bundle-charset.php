<?php

declare(strict_types=1);

/**
 * The shipped JS bundle must carry real UTF-8 bytes, not Closure Compiler's
 * default US-ASCII output.
 *
 * Closure Compiler defaults to "accept UTF-8 input, emit US-ASCII output", which
 * mangled the "ö" in 120-jquery.validate.js's licence header and the "©" in
 * 150-jquery.datatables.js's into `?`; bin/minify-options.php now passes
 * `--charset UTF-8` so both directions are UTF-8 (VIM-A15.60).
 *
 * Assertions compare raw byte sequences built from explicit code points, so
 * they also distinguish a correct bundle from a mojibake one (UTF-8 read as
 * Latin-1 and re-encoded: "JÃ¶rn", "Â©"), which is non-ASCII but still wrong.
 *
 * Requires the JS bundle to have been (re)built via
 * `php bin/minify-bundle.php --version <N> --js-only` against a compiler.jar
 * present at bin/compiler.jar (gitignored build prerequisite, not vendored).
 */

require __DIR__ . '/support/resolve-bundle-v.php';

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

echo "== JS bundle charset (VIM-A15.60) ==\n";

$root = dirname(__DIR__);

try {
    $bundleFile = resolveBundleV();
    $bundlePath = $root . '/public/js/' . $bundleFile;
    $bundle = file_get_contents($bundlePath);
} catch (RuntimeException $e) {
    $bundle = null;
}

$check('production JS bundle is present and readable', is_string($bundle));

// Correct UTF-8 byte sequences for the two known non-ASCII licence bytes.
$correctOumlaut = "\xC3\xB6";   // U+00F6 LATIN SMALL LETTER O WITH DIAERESIS
$correctCopyright = "\xC2\xA9"; // U+00A9 COPYRIGHT SIGN

// The two ways a "UTF-8 out" flag alone (without also reading input as UTF-8)
// fails: input bytes get read as Latin-1/CP1252 and then genuinely
// re-encoded to UTF-8, producing a DIFFERENT, wrong non-ASCII byte sequence
// -- not the original ASCII '?' mangling and not the correct one.
$mojibakeOumlaut = "J\xC3\x83\xC2\xB6rn";      // "JÃ¶rn"
$mojibakeCopyright = "\xC3\x82\xC2\xA9";        // "Â©"

if (is_string($bundle)) {
    $check(
        'bundle contains at least one non-ASCII (>= 0x80) byte',
        (bool) preg_match('/[\x80-\xFF]/', $bundle)
    );
    $check(
        'bundle contains the correct "Jörn Zaefferer" UTF-8 byte sequence',
        str_contains($bundle, "J{$correctOumlaut}rn Zaefferer")
    );
    $check(
        'bundle contains the correct "© SpryMedia Ltd" UTF-8 byte sequence',
        str_contains($bundle, "{$correctCopyright} SpryMedia Ltd")
    );
    $check(
        'bundle does NOT contain the "read-as-Latin-1" mojibake spelling of Jörn',
        !str_contains($bundle, $mojibakeOumlaut)
    );
    $check(
        'bundle does NOT contain the "read-as-Latin-1" mojibake spelling of ©',
        !str_contains($bundle, $mojibakeCopyright)
    );
    $check(
        'bundle does NOT contain the ASCII "?" mangling of either licence byte',
        !str_contains($bundle, 'J?rn') && !str_contains($bundle, '? SpryMedia')
    );
} else {
    // The readability check above has already counted this as a failure; do not
    // count it twice. Say plainly that the six byte assertions never ran, so a
    // missing bundle cannot be mistaken for a passing charset check.
    echo "  ---- bundle unreadable; the six byte-level assertions did not run\n";
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
