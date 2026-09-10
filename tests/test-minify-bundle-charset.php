<?php

declare(strict_types=1);

/**
 * The shipped JS bundle must carry real UTF-8 bytes, not Closure Compiler's
 * default US-ASCII output.
 *
 * bin/minify-options.php builds $js_compiler with `java -jar compiler.jar
 * --compilation_level WHITESPACE_ONLY ...`. Closure Compiler's own default
 * (undocumented until `--help`) is "accept UTF-8 input, emit US-ASCII output"
 * -- so any vendored source carrying a legitimate non-ASCII byte (the "ö" in
 * 120-jquery.validate.js's licence header, the "©" in
 * 150-jquery.datatables.js's) came out mangled into `?` once concatenated
 * through vimbadminBuildBundle()'s exec() pipeline (VIM-A15.60). The fix adds
 * `--charset UTF-8` to $js_compiler so both directions are UTF-8.
 *
 * This test asserts the SHIPPED bundle at the byte level, not by grepping for
 * a spelling: a mojibake bundle (UTF-8 bytes misread as Latin-1, then
 * re-emitted) contains a *different*, also non-ASCII-but-wrong byte sequence
 * ("JÃ¶rn", "Â©") that a naive `str_contains($bundle, 'Jörn')` check cannot
 * distinguish from the correct one if the test file itself is saved in the
 * wrong encoding. Comparing raw byte sequences constructed from explicit code
 * points removes that ambiguity entirely.
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
        'bundle contains the correct "©2008-2024 SpryMedia" UTF-8 byte sequence',
        str_contains($bundle, "{$correctCopyright}2008-2024 SpryMedia")
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
        !str_contains($bundle, 'J?rn') && !str_contains($bundle, '?2008-2024')
    );
} else {
    $failures++;
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
