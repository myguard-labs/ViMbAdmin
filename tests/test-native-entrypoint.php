<?php

$root = dirname(__DIR__);
$php = PHP_BINARY;

function runPath(string $path): array
{
    global $root, $php;

    $script = tempnam(sys_get_temp_dir(), 'vimbadmin-entrypoint-');
    file_put_contents($script, sprintf(
        '<?php $_SERVER["REQUEST_URI"] = %s; ob_start(); include %s; $body = ob_get_clean(); echo http_response_code(), "\\n", $body;',
        var_export($path, true),
        var_export($root . '/public/index.php', true)
    ));
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script), $output, $status);
    unlink($script);

    return [$status, implode("\n", $output)];
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native entry point ==\n";

[$status, $unknown] = runPath('/totally/unknown');
check('unknown route exits successfully', $status === 0);
check('unknown route returns native 404', str_starts_with($unknown, "404\nNot found"));

[$status, $export] = runPath('/exportsettings/thunderbird/email/user@example.com');
check('removed export route exits successfully', $status === 0);
check('removed export route returns native 404', str_starts_with($export, "404\nNot found"));

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
