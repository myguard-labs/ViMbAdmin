<?php

declare(strict_types=1);

/** The build must publish portable proxies on every supported interpreter. */
$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/vimbadmin-proxy-' . bin2hex(random_bytes(8));
$run = static function (array $arguments) use ($root): array {
    $process = proc_open(
        [PHP_BINARY, $root . '/bin/generate-proxies.php', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start proxy generator');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
};
try {
    [$status, $output, $error] = $run([$directory]);
    $files = glob($directory . '/*.php') ?: [];
    if ($status !== 0 || preg_match('/generated ([1-9][0-9]*) proxy classes/', $output, $match) !== 1
        || count($files) !== (int) $match[1]) {
        throw new RuntimeException('proxy artifacts missing: ' . $error);
    }
    foreach ($files as $file) {
        if (!is_readable($file) || filesize($file) === 0) {
            throw new RuntimeException('proxy file is empty or unreadable');
        }
    }
    [$status] = $run([$files[0]]);
    if ($status === 0) {
        throw new RuntimeException('file output destination must be rejected');
    }
    [$status] = $run([$directory, 'unexpected']);
    if ($status === 0) {
        throw new RuntimeException('unexpected argument must be rejected');
    }
    echo "ALL PASSED: real proxy artifacts and invalid-output controls\n";
} finally {
    foreach (glob($directory . '/*.php') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
