<?php

declare(strict_types=1);

/**
 * The shipped JS bundle must carry real UTF-8 bytes, not Closure Compiler's
 * default US-ASCII output.
 *
 * Closure Compiler defaults to "accept UTF-8 input, emit US-ASCII output", which
 * mangled the "©" in 150-datatables.js's licence header into `?`;
 * bin/minify-options.php now passes
 * `--charset UTF-8` so both directions are UTF-8 (VIM-A15.60).
 *
 * Assertions compare raw byte sequences built from explicit code points, so
 * they also distinguish a correct bundle from a mojibake one (UTF-8 read as
 * Latin-1 and re-encoded: "Â©"), which is non-ASCII but still wrong.
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

// Correct UTF-8 bytes for the known non-ASCII licence character.
$correctCopyright = "\xC2\xA9"; // U+00A9 COPYRIGHT SIGN

// The two ways a "UTF-8 out" flag alone (without also reading input as UTF-8)
// fails: input bytes get read as Latin-1/CP1252 and then genuinely
// re-encoded to UTF-8, producing a DIFFERENT, wrong non-ASCII byte sequence
// -- not the original ASCII '?' mangling and not the correct one.
$mojibakeCopyright = "\xC3\x82\xC2\xA9"; // "Â©"

// ASCII-mangled attribution as produced by Closure's default US-ASCII output:
// "Jörn Zaefferer" -> "J?rn", "©2008-2024" -> "?2008-". Both forms are only
// looked for inside block comments (licence headers): outside them "J?rn" could
// be legitimate string data and "?2000-" a ternary. Only /* ... */ comments are
// scanned; the shipped bundle keeps its licences as /*! ... */ blocks.
$mangledAttribution = static function (string $text): bool {
    if (preg_match_all('~/\*.*?\*/~s', $text, $comments) < 1) {
        return false;
    }
    foreach ($comments[0] as $comment) {
        if (str_contains($comment, 'J?rn')
            || preg_match('/(?:^|[\s*(])\?\s?(?:19|20)\d\d-\d{2,4}/', $comment) === 1
        ) {
            return true;
        }
    }
    return false;
};

// Negative control: the guard predicate must fire on the known-bad spellings
// and stay quiet on the correct ones, else the bundle checks below are vacuous.
$check('guard flags "J?rn" attribution', $mangledAttribution('/* J?rn Zaefferer */'));
$check('guard ignores "J?rn" in a string literal outside a comment', !$mangledAttribution('var a="J?rn";'));
$check('guard flags "?2008-" year range', $mangledAttribution('/*! ?2008-2024 SpryMedia Ltd */'));
$check('guard flags "? 2008-" year range', $mangledAttribution('/*! ? 2008-2024 SpryMedia Ltd */'));
$check(
    'guard ignores a "?200x-" ternary in code',
    !$mangledAttribution('var a=b ?2000-1:3;')
);
$check(
    'guard accepts correct UTF-8 attribution',
    !$mangledAttribution("/* J\xC3\xB6rn Zaefferer \xC2\xA92008-2024 */")
);

// Assert on the PHP string tokens, not raw source text, so reformatting,
// renaming the variable or editing comments cannot flip the result.
$minifyOptions = file_get_contents($root . '/bin/minify-options.php');
$passesCharset = false;
if (is_string($minifyOptions)) {
    foreach (token_get_all($minifyOptions) as $token) {
        if (is_array($token)
            && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && str_contains($token[1], '--charset UTF-8')
        ) {
            $passesCharset = true;
            break;
        }
    }
}
$check('minify-options.php passes --charset UTF-8 to Closure Compiler', $passesCharset);

if (is_string($bundle)) {
    $check('bundle is valid UTF-8', preg_match('//u', $bundle) === 1);
    $check(
        'bundle carries no "J?rn" or "?200x-" mangled attribution',
        !$mangledAttribution($bundle)
    );
    $check(
        'bundle contains at least one non-ASCII (>= 0x80) byte',
        (bool) preg_match('/[\x80-\xFF]/', $bundle)
    );
    $check(
        'bundle contains the correct "© SpryMedia Ltd" UTF-8 byte sequence',
        str_contains($bundle, "{$correctCopyright} SpryMedia Ltd")
    );
    $check(
        'bundle does NOT contain the "read-as-Latin-1" mojibake spelling of ©',
        !str_contains($bundle, $mojibakeCopyright)
    );
    $check(
        'bundle does NOT contain the ASCII "?" mangling of the licence byte',
        !str_contains($bundle, '? SpryMedia')
    );
} else {
    // The readability check above has already counted this as a failure; do not
    // count it twice. Say plainly that the four byte assertions never ran, so a
    // missing bundle cannot be mistaken for a passing charset check.
    echo "  ---- bundle unreadable; the four byte-level assertions did not run\n";
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
