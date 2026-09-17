<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/support/bruteforce-state-path.php';

const BRUTE_FORCE_PERSISTENCE_ERROR = 'bruteforce state persistence unavailable';

/** @return never */
function bruteForceWorker(
    string $stateDirectory,
    string $ip,
    string $barrier,
    string $ready,
    string $attempts,
): void {
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    if (!touch($ready)) {
        fwrite(STDERR, "could not signal readiness\n");
        exit(2);
    }

    $deadline = microtime(true) + 10;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "timed out waiting for barrier\n");
            exit(3);
        }
        usleep(1000);
    }

    $bruteForce = new ViMbAdmin_BruteForce(null, [
        'statedir' => $stateDirectory,
        'max_attempts' => 1000,
        'window' => 3600,
    ]);
    for ($attempt = 0; $attempt < (int) $attempts; $attempt++) {
        $bruteForce->record('worker', null);
    }
    exit(0);
}

if (($argv[1] ?? null) === '--worker') {
    if (count($argv) !== 7) {
        fwrite(STDERR, "invalid worker arguments\n");
        exit(64);
    }
    bruteForceWorker($argv[2], $argv[3], $argv[4], $argv[5], $argv[6]);
}

final class BruteForceAtomicStateAssertions
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function bruteForceCheck(string $label, bool $condition): void
{
    BruteForceAtomicStateAssertions::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        BruteForceAtomicStateAssertions::$failures++;
    }
}

function bruteForceCreateDirectory(string $directory): void
{
    if (!mkdir($directory, 0700, true)) {
        throw new RuntimeException('could not create brute-force test directory: ' . $directory);
    }
}

function bruteForceRemoveTree(string $directory): void
{
    $paths = array_merge(glob($directory . '/*') ?: [], glob($directory . '/.??*') ?: []);
    foreach ($paths as $path) {
        if (is_dir($path) && !is_link($path)) {
            bruteForceRemoveTree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

/** @param callable():void $operation */
function bruteForcePersistenceDenied(callable $operation): bool
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return $exception->getMessage() === BRUTE_FORCE_PERSISTENCE_ERROR;
    }

    return false;
}

/**
 * @return array{ready:bool,successful:bool,errors:string}
 */
function bruteForceRunWorkers(string $stateDirectory, string $ip, string $syncDirectory, int $workers, int $attempts): array
{
    $barrier = $syncDirectory . '/go';
    $processes = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $ready = $syncDirectory . '/ready-' . $worker;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--worker', $stateDirectory, $ip, $barrier, $ready, (string) $attempts],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            break;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processes[] = ['process' => $process, 'pipes' => $pipes, 'exit' => null];
    }

    $readyDeadline = microtime(true) + 10;
    do {
        $readyCount = count(glob($syncDirectory . '/ready-*') ?: []);
        if ($readyCount === $workers) {
            break;
        }
        usleep(1000);
    } while (microtime(true) < $readyDeadline);
    $allReady = $readyCount === $workers && count($processes) === $workers;
    touch($barrier);

    $finishDeadline = microtime(true) + 20;
    do {
        $running = false;
        foreach ($processes as &$entry) {
            if ($entry['exit'] !== null) {
                continue;
            }
            $status = proc_get_status($entry['process']);
            if ($status['running']) {
                $running = true;
            } else {
                $entry['exit'] = $status['exitcode'];
            }
        }
        unset($entry);
        if (!$running) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $finishDeadline);

    $errors = '';
    $successful = $allReady;
    foreach ($processes as $entry) {
        $status = proc_get_status($entry['process']);
        if ($status['running']) {
            proc_terminate($entry['process']);
            $successful = false;
        }
        $errors .= stream_get_contents($entry['pipes'][1]);
        $errors .= stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        $closedExit = proc_close($entry['process']);
        $exit = $entry['exit'] ?? $closedExit;
        if ($exit !== 0) {
            $successful = false;
        }
    }

    return ['ready' => $allReady, 'successful' => $successful, 'errors' => $errors];
}

echo "== brute-force atomic state ==\n";

$root = sys_get_temp_dir() . '/vimbadmin-bruteforce-atomic-' . bin2hex(random_bytes(8));
bruteForceCreateDirectory($root);
$stateDirectory = $root . '/state';
$syncDirectory = $root . '/sync';
bruteForceCreateDirectory($syncDirectory);
$ip = '192.0.2.77';
$workers = 20;
$attemptsPerWorker = 10;
$workerResult = bruteForceRunWorkers($stateDirectory, $ip, $syncDirectory, $workers, $attemptsPerWorker);
$stateFile = bruteForceStatePath($stateDirectory, $ip);
$decoded = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;

bruteForceCheck(
    'all synchronized worker processes complete within the bound',
    $workerResult['ready'] && $workerResult['successful'] && $workerResult['errors'] === ''
);
bruteForceCheck(
    'synchronized processes retain the exact failed-attempt count',
    is_array($decoded) && ($decoded['attempts'] ?? null) === $workers * $attemptsPerWorker
);
bruteForceCheck(
    'atomic updates preserve the compatible state shape',
    is_array($decoded) && array_keys($decoded) === ['attempts', 'first', 'last', 'locked_until']
);
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';
(new ViMbAdmin_BruteForce(null, ['statedir' => $stateDirectory]))->record('second-source', null);
$stateEntries = scandir($stateDirectory);
// The lock is sharded by the first byte of the record digest, so the sidecar
// alphabet is the fixed set .lock.00 .. .lock.ff -- bounded, and never
// attacker-grown however many source addresses turn up.
$lockEntries = is_array($stateEntries)
    ? array_values(array_filter(
        $stateEntries,
        static fn (string $entry): bool => str_starts_with($entry, '.lock'),
    ))
    : [];
bruteForceCheck(
    'source addresses use only fixed, bounded lock shard inodes',
    $lockEntries !== [] && $lockEntries === array_values(array_filter(
        $lockEntries,
        static fn (string $entry): bool => preg_match('/^\.lock\.[0-9a-f]{2}$/D', $entry) === 1,
    )),
);

$lockHolder = fopen(bruteForceLockShardPath($stateDirectory, '192.0.2.82'), 'c');
if ($lockHolder === false || !flock($lockHolder, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('could not establish the brute-force lock-contention fixture');
}
$lockWaitStarted = hrtime(true);
$lockDenied = bruteForcePersistenceDenied(static function () use ($stateDirectory): void {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.82';
    (new ViMbAdmin_BruteForce(null, ['statedir' => $stateDirectory]))->assertNotLocked(null);
});
$lockWait = (hrtime(true) - $lockWaitStarted) / 1_000_000_000;
flock($lockHolder, LOCK_UN);
fclose($lockHolder);
bruteForceCheck(
    'pre-auth lock contention fails closed within a bounded wait',
    $lockDenied && $lockWait >= 0.9 && $lockWait < 2.5
);

$thresholdDirectory = $root . '/threshold';
$_SERVER['REMOTE_ADDR'] = '192.0.2.78';
$threshold = new ViMbAdmin_BruteForce(null, [
    'statedir' => $thresholdDirectory,
    'max_attempts' => 2,
    'window' => 3600,
    'lockout' => 60,
]);
$threshold->record('first', null);
$lockedAfterFirst = $threshold->isLocked(null);
$threshold->record('second', null);
bruteForceCheck(
    'the configured threshold still locks on the second attempt',
    !$lockedAfterFirst && $threshold->isLocked(null)
);

$occupied = $root . '/occupied';
file_put_contents($occupied, 'not a directory');
$mkdirFailure = new ViMbAdmin_BruteForce(null, ['statedir' => $occupied . '/state']);
bruteForceCheck('state-directory creation failure denies before authentication', bruteForcePersistenceDenied(
    static function () use ($mkdirFailure): void {
        $mkdirFailure->assertNotLocked(null);
    },
));
$whitelisted = new ViMbAdmin_BruteForce(null, [
    'statedir' => $occupied . '/state',
    'whitelist' => ['192.0.2.78'],
]);
$whitelistClearSucceeded = true;
try {
    $whitelisted->clear('whitelisted', null);
} catch (Throwable) {
    $whitelistClearSucceeded = false;
}
bruteForceCheck('whitelisted sources skip persistence when clearing state', $whitelistClearSucceeded);

$deleteDirectory = $root . '/delete-failure';
bruteForceCreateDirectory($deleteDirectory);
$deleteIp = '192.0.2.83';
$deleteState = bruteForceStatePath($deleteDirectory, $deleteIp);
bruteForceCreateDirectory($deleteState);
$deleteFailure = new ViMbAdmin_BruteForce(null, ['statedir' => $deleteDirectory]);
$_SERVER['REMOTE_ADDR'] = $deleteIp;
bruteForceCheck('state removal failure denies persistence', bruteForcePersistenceDenied(
    static function () use ($deleteFailure): void {
        $deleteFailure->clear('delete-failure', null);
    },
));

$writeIp = '192.0.2.79';
$writeDirectory = $root . '/write-failure';
bruteForceCreateDirectory($writeDirectory);
$writeState = bruteForceStatePath($writeDirectory, $writeIp);
bruteForceCreateDirectory($writeState . '.' . getmypid() . '.tmp');
$writeFailure = new ViMbAdmin_BruteForce(null, ['statedir' => $writeDirectory]);
$writeDenied = bruteForcePersistenceDenied(static function () use ($writeFailure, $writeIp): void {
    (new ReflectionMethod($writeFailure, '_save'))->invoke($writeFailure, $writeIp, [
        'attempts' => 1,
        'first' => time(),
        'last' => time(),
        'locked_until' => 0,
    ]);
});
bruteForceCheck('state write failure denies persistence', $writeDenied && !is_file($writeState));

$renameDirectory = $root . '/rename-failure';
bruteForceCreateDirectory($renameDirectory);
$renameIp = '192.0.2.80';
$renameState = bruteForceStatePath($renameDirectory, $renameIp);
bruteForceCreateDirectory($renameState);
$renameFailure = new ViMbAdmin_BruteForce(null, ['statedir' => $renameDirectory]);
$renameDenied = bruteForcePersistenceDenied(static function () use ($renameFailure, $renameIp): void {
    (new ReflectionMethod($renameFailure, '_save'))->invoke($renameFailure, $renameIp, [
        'attempts' => 1,
        'first' => time(),
        'last' => time(),
        'locked_until' => 0,
    ]);
});
bruteForceCheck(
    'atomic rename failure denies persistence and removes the temporary file',
    $renameDenied && !is_file($renameState . '.' . getmypid() . '.tmp')
);

bruteForceCheck('fixed assertion count', BruteForceAtomicStateAssertions::$checks === 11);

bruteForceRemoveTree($root);
$failures = BruteForceAtomicStateAssertions::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
