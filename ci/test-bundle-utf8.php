<?php

declare(strict_types=1);

require __DIR__ . '/../tests/support/resolve-bundle-v.php';

/** @return list<string> */
$bundleUtf8Problems = static function (string $bytes): array {
    $problems = [];
    $encoding = (new finfo(FILEINFO_MIME_ENCODING))->buffer($bytes);
    if ($encoding !== 'utf-8' || preg_match('//u', $bytes) !== 1) {
        $problems[] = 'bundle charset must be utf-8';
    }
    if (!str_contains($bytes, '© SpryMedia Ltd - datatables.net/license')) {
        $problems[] = 'missing copyright attribution';
    }
    if (str_contains($bytes, 'J?rn')) {
        $problems[] = 'mangled Jörn attribution';
    }
    if (preg_match('/\?200[0-9]-/', $bytes) === 1) {
        $problems[] = 'mangled copyright attribution';
    }

    return $problems;
};

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        ++$failures;
    }
};

echo "== bundle UTF-8 guard ==\n";
$valid = "© SpryMedia Ltd - datatables.net/license\nJörn Zaefferer\n";
$check('valid UTF-8 attribution passes', $bundleUtf8Problems($valid) === []);
$check(
    'valid UTF-8 without copyright attribution is rejected',
    in_array('missing copyright attribution', $bundleUtf8Problems("Résumé\n"), true)
);
$check(
    'J?rn attribution is rejected',
    in_array('mangled Jörn attribution', $bundleUtf8Problems("Copyright © J?rn\n"), true)
);
$check(
    '?2008- attribution is rejected',
    in_array('mangled copyright attribution', $bundleUtf8Problems("Copyright © ?2008-2024\n"), true)
);
$check(
    'invalid UTF-8 is rejected',
    in_array('bundle charset must be utf-8', $bundleUtf8Problems("Copyright ©\xFF\n"), true)
);
$check(
    'ASCII-only output is rejected',
    in_array('bundle charset must be utf-8', $bundleUtf8Problems("Copyright (c) 2008\n"), true)
);

$root = dirname(__DIR__);
$path = $argv[1] ?? $root . '/public/js/' . resolveBundleV();
$bundle = @file_get_contents($path);
$check('bundle is readable', is_string($bundle));
if (is_string($bundle)) {
    foreach ($bundleUtf8Problems($bundle) as $problem) {
        echo "  FAIL {$problem}\n";
        ++$failures;
    }
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
