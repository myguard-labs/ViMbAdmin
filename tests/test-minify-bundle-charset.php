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

// Collect the /* ... */ block comments of a JS source with a small lexer that
// skips string, template and regex literals and // line comments, so a "/*"
// inside a string ("/* J?rn */" as data) is not mistaken for a comment and a
// quote inside a regex or line comment does not desynchronise the scan.
// Template `${...}` nesting is not tracked; the bundle check below asserts the
// lexer reaches the DataTables licence comment, which catches a desync.
$blockComments = static function (string $js): array {
    $regexAfter = '(,=:[!&|?{;+-*%<>~^';
    $regexKeywords = ['return', 'typeof', 'case', 'do', 'else', 'in', 'instanceof',
        'new', 'delete', 'void', 'throw', 'yield', 'await'];
    $comments = [];
    $len = strlen($js);
    $prevPos = -1; // offset of the last significant character outside comments
    for ($i = 0; $i < $len; $i++) {
        $c = $js[$i];
        $next = $js[$i + 1] ?? '';
        if ($c === '/' && $next === '*') {
            $end = strpos($js, '*/', $i + 2);
            $end = $end === false ? $len : $end + 2;
            $comments[] = substr($js, $i, $end - $i);
            $i = $end - 1;
            continue;
        }
        if ($c === '/' && $next === '/') {
            $end = strpos($js, "\n", $i);
            $i = $end === false ? $len : $end;
            continue;
        }
        if (ctype_space($c)) {
            continue;
        }
        $isRegex = false;
        if ($c === '/') {
            $prev = $prevPos < 0 ? '' : $js[$prevPos];
            if ($prev === '' || str_contains($regexAfter, $prev)) {
                $isRegex = true;
            } elseif (preg_match('/[A-Za-z_$]/', $prev) === 1) {
                $wordStart = $prevPos;
                while ($wordStart > 0 && preg_match('/[A-Za-z0-9_$]/', $js[$wordStart - 1]) === 1) {
                    $wordStart--;
                }
                $isRegex = in_array(substr($js, $wordStart, $prevPos - $wordStart + 1), $regexKeywords, true);
            }
        }
        if ($c === '"' || $c === "'" || $c === '`' || $isRegex) {
            $inClass = false;
            for ($i++; $i < $len; $i++) {
                $d = $js[$i];
                if ($d === '\\') {
                    $i++;
                } elseif ($isRegex && $d === '[') {
                    $inClass = true;
                } elseif ($isRegex && $d === ']') {
                    $inClass = false;
                } elseif ($d === $c && !$inClass) {
                    break;
                }
            }
        }
        $prevPos = min($i, $len - 1);
    }
    return $comments;
};

// ASCII-mangled attribution as produced by Closure's default US-ASCII output:
// "Jörn Zaefferer" -> "J?rn", "©2008-2024" -> "?2008-". Both forms are only
// looked for inside block comments (licence headers): outside them "J?rn" could
// be legitimate string data and "?2000-" a ternary. The shipped bundle keeps
// its licences as /*! ... */ blocks.
$mangledAttribution = static function (string $text) use ($blockComments): bool {
    foreach ($blockComments($text) as $comment) {
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
// Each "ignores" input carries a clean comment, so a scan that finds no
// comment at all cannot pass it; the comment scoping is what is exercised.
$check(
    'guard ignores "J?rn" in a string literal outside a comment',
    !$mangledAttribution('/*! ok */ var a="J?rn";')
);
$check(
    'guard ignores a "/* J?rn */" lookalike inside string literals',
    !$mangledAttribution(
        '/*! ok */ var attributionExample="/* J?rn */"; var b=\'/* J?rn */\'; var c=`/* J?rn */`; var d="\\"/* J?rn */";'
    )
);
$check(
    'guard ignores a "/* J?rn */" lookalike inside a regex literal',
    !$mangledAttribution('/*! ok */ var r=/\/* J?rn *\//; return /"/.test(s);')
);
$check(
    'guard still flags a comment after a quote in a regex or line comment',
    $mangledAttribution("/*! ok */ var r=/\"/; // don't\n/* J?rn */")
);
$check('guard flags "?2008-" year range', $mangledAttribution('/*! ?2008-2024 SpryMedia Ltd */'));
$check('guard flags "? 2008-" year range', $mangledAttribution('/*! ? 2008-2024 SpryMedia Ltd */'));
$check(
    'guard ignores a "?200x-" ternary in code',
    !$mangledAttribution('/*! ok */ var a=b ?2000-10:3;')
);
$check(
    'guard accepts correct UTF-8 attribution',
    !$mangledAttribution("/* J\xC3\xB6rn Zaefferer \xC2\xA92008-2024 */")
);

// Evaluate the `$js_compiler` value bin/minify-options.php actually produces
// rather than inspecting its source: any expression that computes a different
// command (a str_replace() wrapper, a later reassignment) is seen as such. The
// options file runs in a child PHP with the JS lane selected, so the clean-css
// checks are skipped; the compiler digest check may exit(1) when compiler.jar
// is absent, which is after the assignment, so a shutdown function still
// reports the value.
$evaluatedCompiler = static function (string $optionsFile): ?string {
    $marker = "\0JS_COMPILER\0";
    $code = 'define("VIMBADMIN_MINIFY_LANE", "js");'
        . 'register_shutdown_function(static function (): void {'
        . ' echo ' . var_export($marker, true) . ', json_encode($GLOBALS["js_compiler"] ?? null);'
        . '});'
        . 'require $argv[1];';
    $process = proc_open(
        [PHP_BINARY, '-r', $code, $optionsFile],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return null;
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $at = strrpos($output, $marker);
    if ($at === false) {
        return null;
    }
    $value = json_decode(substr($output, $at + strlen($marker)));
    return is_string($value) ? $value : null;
};

// Every --charset the command passes must be UTF-8, and there must be one.
$charsetIsUtf8 = static function (?string $command): bool {
    if ($command === null
        || preg_match_all('/(?:^|\s)--charset(?:\s+|=)(\S+)/', $command, $matches) < 1
    ) {
        return false;
    }
    return array_values(array_unique($matches[1])) === ['UTF-8'];
};

// Negative controls: the evaluation must reject a US-ASCII compiler however
// the source spells "--charset UTF-8", else the real check below is vacuous.
$fixtureDir = sys_get_temp_dir() . '/minify-charset-' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0700);
$fixtureCharset = static function (string $body) use ($fixtureDir, $evaluatedCompiler, $charsetIsUtf8): bool {
    $file = $fixtureDir . '/options-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($file, "<?php\n" . $body . "\n");
    $result = $charsetIsUtf8($evaluatedCompiler($file));
    unlink($file);
    return $result;
};
$check(
    'charset check accepts --charset UTF-8 in $js_compiler',
    $fixtureCharset('$js_compiler = "java -jar c.jar --charset UTF-8"; exit(1);')
);
$check(
    'charset check rejects a str_replace() wrapper that swaps in US-ASCII',
    !$fixtureCharset('$js_compiler = str_replace("--charset UTF-8", "--charset US-ASCII", "java -jar c.jar --charset UTF-8");')
);
$check(
    'charset check rejects US-ASCII compiler with an unrelated UTF-8 string',
    !$fixtureCharset('$js_compiler = "java -jar c.jar --charset US-ASCII"; $x = "--charset UTF-8";')
);
$check(
    'charset check rejects a later conflicting --charset',
    !$fixtureCharset('$js_compiler = "java -jar c.jar --charset UTF-8 --charset US-ASCII";')
);
$check(
    'charset check rejects a file without $js_compiler',
    !$fixtureCharset('$css_compiler = "--charset UTF-8";')
);
rmdir($fixtureDir);

$check(
    'minify-options.php passes --charset UTF-8 to Closure Compiler',
    $charsetIsUtf8($evaluatedCompiler($root . '/bin/minify-options.php'))
);

if (is_string($bundle)) {
    $check('bundle is valid UTF-8', preg_match('//u', $bundle) === 1);
    $check(
        'comment lexer reaches the "SpryMedia Ltd" licence comment',
        array_filter($blockComments($bundle), static fn (string $c): bool => str_contains($c, 'SpryMedia Ltd')) !== []
    );
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
    echo "  ---- bundle unreadable; the bundle assertions did not run\n";
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
