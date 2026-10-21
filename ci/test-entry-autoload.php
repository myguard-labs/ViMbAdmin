<?php

declare(strict_types=1);

// Each probe is a cold PHP process: a loaded entity class must never mask the
// original pre-bootstrap signature-compatibility fatal.
$root = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/vimbadmin-entry-autoload-' . bin2hex(random_bytes(8));
$entry = getenv('VIMBADMIN_ENTRY_AUTOLOAD_CANDIDATE') ?: $root . '/public/index.php';
$probe = getenv('VIMBADMIN_ENTRY_AUTOLOAD_PROBE') ?: $root . '/ci/support/entry-autoload-probe.php';
$files = [];
$directories = [];
$checks = 0;
$write = static function (string $path, string $source) use (&$files): void {
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException('could not write entry-autoload fixture');
    }
    $files[] = $path;
};
$check = static function (bool $condition, string $name) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    echo 'ok: ' . $name . "\n";
};
$run = static function (string $application, string $route) use ($root, $entry, $probe): array {
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
        $probe, $root, $application, $entry, $route],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start entry-autoload probe');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        // The original fatal is safe to print: this fixture has no secrets.
        throw new RuntimeException('FAIL: cold native entry probe exited ' . $status . ': ' . $errors);
    }
    return [json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR), $errors];
};
try {
    foreach (['', '/application', '/application/configs', '/vendor', '/custom', '/custom/Entities', '/custom/Repositories'] as $suffix) {
        $directory = $scratch . $suffix;
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('could not create entry-autoload directory');
        }
        $directories[] = $directory;
    }
    $write($scratch . '/vendor/autoload.php', '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';');
    $write($scratch . '/custom/Entities/Admin.php', '<?php namespace Entities; class Admin {}');
    $write($scratch . '/application/configs/application.ini', "[probe]\n"
        . 'resources.doctrine2.models_path = "' . $scratch . "/custom\"\n"
        . 'resources.doctrine2.repositories_path = "' . $scratch . "/custom\"\n");
    [$observed, $errors] = $run($scratch . '/application', '/domain/list');
    $check(
        $observed['status'] === 500 && $observed['entity'] === true
        && $observed['repository'] === false && $observed['repositoryFile'] === null
        && str_contains($errors, 'entry-autoload probe reached resource construction'),
        'missing custom repository reports absence without a reflection fatal'
    );
    $write($scratch . '/custom/Repositories/Domain.php', '<?php namespace Repositories; class Domain {}');
    [$observed, $errors] = $run($scratch . '/application', '/domain/list');
    $check(
        $observed['status'] === 500 && $observed['body'] === "Internal server error\n"
        && str_contains($errors, 'entry-autoload probe reached resource construction'),
        'cold native controller probe reaches normal bootstrap without signature fatal'
    );
    $check(
        $observed['entityFile'] === $scratch . '/custom/Entities/Admin.php'
        && $observed['repositoryFile'] === $scratch . '/custom/Repositories/Domain.php',
        'configured entity and repository paths retain precedence'
    );
    $check($observed['session'] === PHP_SESSION_NONE, 'early autoload registration opens no session');
    foreach (['/domain/not-an-action', '/unknown/list', 'malformed'] as $route) {
        [$observed, $errors] = $run($scratch . '/application', $route);
        $check(
            $observed['status'] === ($route === 'malformed' ? 400 : 404)
            && !str_contains($errors, 'reached resource construction')
            && $observed['session'] === PHP_SESSION_NONE,
            $route . ' remains rejected before resource construction'
        );
    }
    // Unknown-controller and malformed requests do not even need config.
    foreach (['/unknown/list', 'malformed'] as $route) {
        [$observed] = $run($scratch . '/missing-config', $route);
        $check(
            $observed['status'] === ($route === 'malformed' ? 400 : 404) && !$observed['entity'],
            $route . ' needs no configuration or entity loader'
        );
    }
    [$observed] = $run($scratch . '/missing-config', '/domain/list');
    $check(
        $observed['status'] === 500 && $observed['body'] === "Internal server error\n",
        'configured loader error follows the entry-point error contract'
    );
    echo 'ALL PASSED (' . $checks . " checks)\n";
} finally {
    foreach (array_reverse($files) as $file) {
        unlink($file);
    }
    foreach (array_reverse($directories) as $directory) {
        rmdir($directory);
    }
}
