<?php

/** @return array{int, string} */
function runPath(string $root, string $php, string $path): array
{
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

/** @return array{int, string} */
function runWithoutApplicationDirectory(string $root, string $php): array
{
    $directory = sys_get_temp_dir() . '/vimbadmin-entrypoint-' . bin2hex(random_bytes(8));
    mkdir($directory . '/public', 0700, true);
    copy($root . '/public/index.php', $directory . '/public/index.php');
    exec(
        escapeshellarg($php) . ' ' . escapeshellarg($directory . '/public/index.php') . ' 2>&1',
        $output,
        $status
    );
    unlink($directory . '/public/index.php');
    rmdir($directory . '/public');
    rmdir($directory);

    return [$status, implode("\n", $output)];
}

/** @return array{int, string} */
function runMalformedRequestUri(string $root, string $php): array
{
    $script = tempnam(sys_get_temp_dir(), 'vimbadmin-entrypoint-');
    file_put_contents($script, sprintf(
        '<?php $_SERVER["REQUEST_URI"] = ["/not-a-uri"]; ob_start(); include %s; $body = ob_get_clean(); echo http_response_code(), "\\n", $body;',
        var_export($root . '/public/index.php', true)
    ));
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script), $output, $status);
    unlink($script);

    return [$status, implode("\n", $output)];
}

final class TestNativeEntrypointHarnessState
{
    public static int $count = 0;
}

$failures = & TestNativeEntrypointHarnessState::$count;
function nativeEntrypointCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestNativeEntrypointHarnessState::$count++;
    }
}

echo "== native entry point ==\n";

$root = dirname(__DIR__);
$php = PHP_BINARY;

[$status, $unknown] = runPath($root, $php, '/totally/unknown');
nativeEntrypointCheck('unknown route exits successfully', $status === 0);
nativeEntrypointCheck('unknown route returns native 404', str_starts_with($unknown, "404\nNot found"));

[$status, $export] = runPath($root, $php, '/exportsettings/thunderbird/email/user@example.com');
nativeEntrypointCheck('removed export route exits successfully', $status === 0);
nativeEntrypointCheck('removed export route returns native 404', str_starts_with($export, "404\nNot found"));

[$status, $missingApplication] = runWithoutApplicationDirectory($root, $php);
nativeEntrypointCheck('missing application directory fails fast', $status !== 0);
nativeEntrypointCheck(
    'missing application directory reports the bootstrap location',
    str_contains($missingApplication, 'Unable to resolve the application directory')
);

[$status, $malformedUri] = runMalformedRequestUri($root, $php);
nativeEntrypointCheck('malformed request URI exits successfully', $status === 0);
nativeEntrypointCheck('malformed request URI fails closed with 400', str_starts_with($malformedUri, "400\nMalformed request URI"));

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
