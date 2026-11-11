<?php

/**
 * Maintained OSS shorten callback through direct calls and locked Smarty renders.
 *
 * Run with the checkout's vendor dependencies and mbstring. For a private test
 * export, VI_SMARTY_TEST_ROOT selects its read-only checkout. A causal control
 * may select a copied callback with VI_SMARTY_SHORTEN_SOURCE and select only
 * registered renders with VI_SMARTY_SHORTEN_ONLY=registered.
 *
 * Contract: modifier.shorten.php declares string input and an integer maximum;
 * active application templates use positive limits 40 and 50. This test pins
 * positive integer limits, character counts, the additional "..." suffix and
 * trimming only when shortened. No maintained caller/document specifies zero,
 * negative or malformed limits: their policy remains unspecified and this test
 * deliberately does not prescribe rejection, coercion or output for them.
 * Malformed subject containers exercise PHP's documented string-only mb_strlen
 * boundary, without inventing any additional plugin input policy.
 *
 * Oracles:
 * - application/views/alias/list.phtml:64 and log/list.phtml:41.
 * - https://www.php.net/manual/en/function.mb-strlen.php (characters).
 * - https://www.php.net/manual/en/function.mb-substr.php (character cutoff).
 * - https://www.php.net/manual/en/function.rtrim.php (default ASCII whitespace).
 * - vendor/smarty/smarty/docs/programmers/api-functions/api-register-plugin.md.
 */

/** @return array{version:string,reference:string} */
function viSmartyShortenPackageMetadata(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read Smarty package metadata: ' . $path);
    }
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Smarty package metadata must be an object.');
    }
    foreach (['packages', 'packages-dev'] as $group) {
        $packages = $data[$group] ?? null;
        if ($group === 'packages-dev' && $packages === null) {
            continue;
        }
        if (!is_array($packages)) {
            throw new RuntimeException('Smarty package metadata requires an array of packages.');
        }
        foreach ($packages as $package) {
            if (!is_array($package) || ($package['name'] ?? null) !== 'smarty/smarty') {
                continue;
            }
            $version = $package['version'] ?? null;
            $source = $package['source'] ?? null;
            $reference = is_array($source) ? ($source['reference'] ?? null) : null;
            if (!is_string($version) || !is_string($reference)) {
                throw new RuntimeException('Smarty version and source reference must be strings.');
            }
            return ['version' => $version, 'reference' => $reference];
        }
    }
    throw new RuntimeException('Smarty package metadata is missing.');
}

$root = getenv('VI_SMARTY_TEST_ROOT') ?: dirname(__DIR__);
$source = getenv('VI_SMARTY_SHORTEN_SOURCE') ?: $root . '/library/OSS/Smarty/functions/modifier.shorten.php';
$only = getenv('VI_SMARTY_SHORTEN_ONLY') ?: 'all';
if (!in_array($only, ['all', 'direct', 'registered'], true)) {
    throw new RuntimeException('Expected VI_SMARTY_SHORTEN_ONLY=all|direct|registered');
}
if (!extension_loaded('mbstring') || !is_file($root . '/vendor/autoload.php') || !is_file($source)) {
    throw new RuntimeException('Required mbstring, locked vendor autoloader and maintained shorten callback must exist');
}
require $root . '/vendor/autoload.php';
require $source;
if (!function_exists('smarty_modifier_shorten')) {
    throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_shorten');
}

if (!class_exists(\Smarty\Smarty::class)) {
    throw new RuntimeException('Locked Smarty is required; missing dependency is not a skip');
}


$lockedSmarty = viSmartyShortenPackageMetadata($root . '/composer.lock');
if (ltrim($lockedSmarty['version'], 'v') !== \Smarty\Smarty::SMARTY_VERSION) {
    throw new RuntimeException('Installed Smarty version must match composer.lock before contract acceptance');
}

$failures = [];
$checks = 0;
$expect = static function (string $label, mixed $actual, mixed $expected) use (&$failures, &$checks): void {
    $checks++;
    if ($actual !== $expected) {
        $failures[] = $label;
        echo 'FAIL: ' . $label . '; expected ' . var_export($expected, true)
            . '; got ' . var_export($actual, true) . "\n";
    }
};
$typeError = static function (callable $operation): bool {
    try {
        $operation();
    } catch (TypeError) {
        return true;
    }
    return false;
};

$encoding = mb_internal_encoding();
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$temporary = null;
try {
    mb_internal_encoding('UTF-8');
    $cases = [
        ['shorter ASCII unchanged', 'ab', 3, 'ab'],
        ['equal ASCII unchanged', 'abc', 3, 'abc'],
        ['empty subject unchanged', '', 1, ''],
        ['one character boundary', 'ab', 1, 'a...'],
        ['ASCII cutoff plus ellipsis', 'abcdef', 3, 'abc...'],
        ['Unicode shorter unchanged', 'é', 2, 'é'],
        ['Unicode exactly cutoff unchanged', 'é漢🙂', 3, 'é漢🙂'],
        ['Unicode character cutoff', 'é漢🙂Z', 3, 'é漢🙂...'],
        ['trim truncated spaces', 'ab  cd', 4, 'ab...'],
        ['trim truncated tab', "ab\tcd", 3, 'ab...'],
        ['equal whitespace unchanged', 'ab ', 3, 'ab '],
        ['shorter whitespace unchanged', 'a ', 3, 'a '],
        ['non-ASCII space retained', "ab\u{00A0}cd", 3, "ab\u{00A0}..."],
        ['active log limit', str_repeat('a', 41), 40, str_repeat('a', 40) . '...'],
        ['active alias limit', str_repeat('é', 51), 50, str_repeat('é', 50) . '...'],
    ];
    if ($only !== 'registered') {
        foreach ($cases as [$label, $subject, $limit, $expected]) {
            $expect('direct/' . $label, smarty_modifier_shorten($subject, $limit), $expected);
        }
        foreach ([[], new stdClass()] as $malformed) {
            $expect('native mb_strlen container TypeError', $typeError(static fn () => (new ReflectionFunction('mb_strlen'))->invoke($malformed)), true);
            $expect('direct/container subject TypeError', $typeError(static fn () => (new ReflectionFunction('smarty_modifier_shorten'))->invoke($malformed, 3)), true);
        }
    }
    if ($only !== 'direct') {
        $parent = getenv('PHPTEST_TMPDIR') ?: sys_get_temp_dir();
        $temporary = $parent . '/vi-smarty-shorten-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700) || !mkdir($temporary . '/compiled', 0700)) {
            throw new RuntimeException('Could not create private Smarty compilation directory');
        }
        $smarty = new \Smarty\Smarty();
        $smarty->setCompileDir($temporary . '/compiled');
        $smarty->setCacheDir($temporary);
        $smarty->setCaching(\Smarty\Smarty::CACHING_OFF);
        $smarty->setEscapeHtml(true);
        $smarty->registerPlugin('modifier', 'shorten', 'smarty_modifier_shorten');
        $template = 'string:{$subject|shorten:$limit}';
        foreach ($cases as [$label, $subject, $limit, $expected]) {
            $smarty->assign('subject', $subject);
            $smarty->assign('limit', $limit);
            $expect('registered/' . $label, $smarty->fetch($template), $expected);
        }
        foreach ([[], new stdClass()] as $malformed) {
            $smarty->assign('subject', $malformed);
            $smarty->assign('limit', 3);
            $expect('registered/container subject TypeError', $typeError(static fn () => $smarty->fetch($template)), true);
        }
        $compiled = glob($temporary . '/compiled/*.php');
        $expect('registered/real Smarty compiled template', is_array($compiled) && count($compiled) > 0, true);
    }
} finally {
    if ($temporary !== null && is_dir($temporary)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                throw new UnexpectedValueException('Smarty cleanup entries must be files.');
            }
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($temporary);
    }
    mb_internal_encoding($encoding);
    restore_error_handler();
}
if ($failures !== []) {
    throw new RuntimeException('FAIL: shorten contract; ' . count($failures) . ' assertion(s) failed');
}
echo 'PASS: shorten direct/registered contract; ' . $checks . ' assertions; Smarty '
    . \Smarty\Smarty::SMARTY_VERSION . '; PHP ' . PHP_VERSION . "\n";
echo 'OK: all Smarty shorten assertions passed (PHP ' . PHP_VERSION . ")\n";
