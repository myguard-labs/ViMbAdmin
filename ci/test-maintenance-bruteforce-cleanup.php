<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/../tests/support/bruteforce-state-path.php';

final class BruteForceCleanupAssertions
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function cleanupCheck(string $label, bool $condition, string $detail = ''): void
{
    BruteForceCleanupAssertions::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . ($detail === '' ? '' : ' [' . $detail . ']') . "\n";
    if (!$condition) {
        BruteForceCleanupAssertions::$failures++;
    }
}

/** @param array{attempts:int,first:int,last:int,locked_until:int} $record */
function cleanupWriteRecord(string $path, array $record): void
{
    if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('could not write brute-force cleanup fixture');
    }
}

/** @param array{attempts:int,first:int,last:int,locked_until:int} $record */
function cleanupPublishRecord(string $path, array $record): void
{
    $temporary = $path . '.publisher.tmp';
    cleanupWriteRecord($temporary, $record);
    if (!rename($temporary, $path)) {
        throw new RuntimeException('could not publish brute-force replacement fixture');
    }
}

/**
 * @param-out int $observedPid
 * @return array{process:resource,pipes:array<int,resource>,ready:string,go:string}
 */
function cleanupStartReaper(
    string $stateDirectory,
    int $now,
    string $childMode = 'reap-child',
    int &$observedPid = 0,
    int $readyDeadlineNs = 5_000_000_000,
): array {
    $ready = $stateDirectory . '/child-ready';
    $go = $stateDirectory . '/child-go';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, $childMode, $stateDirectory, (string) $now, $ready, $go],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start brute-force cleanup child');
    }
    $child = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready, 'go' => $go];
    $observedPid = proc_get_status($process)['pid'];

    try {
        if (!stream_set_blocking($pipes[1], false) || !stream_set_blocking($pipes[2], false)) {
            throw new RuntimeException('could not configure brute-force cleanup child pipes');
        }

        $deadline = hrtime(true) + $readyDeadlineNs;
        while (!is_file($ready)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $result = cleanupFinishChild($child);
                throw new RuntimeException('cleanup child exited before ready: ' . trim($result['stderr']));
            }
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('cleanup child did not become ready');
            }
            usleep(5_000);
        }
    } catch (Throwable $error) {
        cleanupFinishChild($child, true);
        throw $error;
    }

    return $child;
}

/**
 * Wait until the cleanup child has opened the exact writer shard sidecar.
 * This is a state barrier, not a scheduling delay: a lock-disabled cleanup
 * exits without ever opening this descriptor and deterministically fails.
 *
 * @param array{process:resource,pipes:array<int,resource>,ready:string,go:string} $child
 */
function cleanupWaitForShard(array $child, string $lockPath): bool
{
    $status = proc_get_status($child['process']);
    if (!$status['running']) {
        return false;
    }
    $pid = $status['pid'];
    if (!is_dir('/proc/self/fd') || $pid <= 0) {
        throw new RuntimeException('cleanup lock barrier requires procfs');
    }
    if (!is_dir('/proc/' . $pid . '/fd')) {
        return proc_get_status($child['process'])['running']
            ? throw new RuntimeException('cleanup child procfs descriptors are unavailable')
            : false;
    }

    $deadline = hrtime(true) + 5_000_000_000;
    while (hrtime(true) < $deadline) {
        $status = proc_get_status($child['process']);
        if (!$status['running']) {
            return false;
        }
        foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $descriptor) {
            if (@readlink($descriptor) === $lockPath) {
                return true;
            }
        }
        usleep(5_000);
    }

    return false;
}

/**
 * @param array{process:resource,pipes:array<int,resource>,ready:string,go:string}|null $child
 * @param-out null $child
 * @return array{exit:int,stdout:string,stderr:string}
 */
function cleanupFinishChild(?array &$child, bool $terminate = false): array
{
    if ($child === null) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => ''];
    }
    $owned = $child;
    $child = null;
    $exit = -1;
    $stdout = '';
    $stderr = '';
    $reaped = false;
    try {
        $status = proc_get_status($owned['process']);
        if ($terminate && $status['running']) {
            proc_terminate($owned['process']);
        }
        $deadline = hrtime(true) + 5_000_000_000;
        while ($status['running'] && hrtime(true) < $deadline) {
            usleep(5_000);
            $status = proc_get_status($owned['process']);
        }
        if ($status['running']) {
            proc_terminate($owned['process'], 9);
            $deadline = hrtime(true) + 1_000_000_000;
            while ($status['running'] && hrtime(true) < $deadline) {
                usleep(5_000);
                $status = proc_get_status($owned['process']);
            }
        }
        if ($status['running']) {
            throw new RuntimeException('cleanup child could not be stopped');
        }
        $exit = $status['exitcode'];
        $stdout = (string) stream_get_contents($owned['pipes'][1]);
        $stderr = (string) stream_get_contents($owned['pipes'][2]);
        fclose($owned['pipes'][1]);
        fclose($owned['pipes'][2]);
        $closed = proc_close($owned['process']);
        $reaped = true;
        if ($exit < 0) {
            $exit = $closed;
        }
    } finally {
        foreach ($owned['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (!$reaped) {
            $status = proc_get_status($owned['process']);
            if ($status['running']) {
                proc_terminate($owned['process'], 9);
            }
            proc_close($owned['process']);
        }
        @unlink($owned['ready']);
        @unlink($owned['go']);
    }

    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function cleanupRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.??*') ?: []) as $path) {
        is_dir($path) && !is_link($path) ? cleanupRemoveTree($path) : unlink($path);
    }
    rmdir($directory);
}

if (($argv[1] ?? '') === 'wait-without-ready') {
    usleep(30_000_000);
    exit(23);
}

if (($argv[1] ?? '') === 'reap-child') {
    $stateDirectory = $argv[2] ?? '';
    $now = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
    $ready = $argv[4] ?? '';
    $go = $argv[5] ?? '';
    if (!is_int($now) || !touch($ready)) {
        fwrite(STDERR, "invalid cleanup child arguments\n");
        exit(2);
    }
    $deadline = hrtime(true) + 5_000_000_000;
    while (!is_file($go)) {
        if (hrtime(true) >= $deadline) {
            fwrite(STDERR, "cleanup child start barrier timed out\n");
            exit(2);
        }
        usleep(5_000);
    }
    $bruteForce = new ViMbAdmin_BruteForce(null, [
        'statedir' => $stateDirectory,
        'window' => 60,
        'lockout' => 120,
        'max_entries' => 64,
    ]);
    (new ReflectionMethod($bruteForce, '_reapStale'))->invoke($bruteForce, $now);
    echo "REAP COMPLETE\n";
    exit(0);
}

echo "== locked brute-force generation cleanup ==\n";

$root = sys_get_temp_dir() . '/vimbadmin-bruteforce-cleanup-' . bin2hex(random_bytes(8));
$now = time();
$staleRecord = [
    'attempts' => 2,
    'first' => $now - 122,
    'last' => $now - 122,
    'locked_until' => 0,
];
$liveRecord = [
    'attempts' => 1,
    'first' => $now,
    'last' => $now,
    'locked_until' => $now + 120,
];

try {
    // Both startup failures and scenario failures must close pipes, terminate
    // live children, reap them, and remove their barrier files.
    $lifecycleDirectory = $root . '/lifecycle';
    mkdir($lifecycleDirectory, 0700, true);
    $startupTimeoutPid = 0;
    $startupTimeoutCaught = false;
    try {
        cleanupStartReaper(
            $lifecycleDirectory,
            $now,
            'wait-without-ready',
            $startupTimeoutPid,
            100_000_000,
        );
    } catch (RuntimeException) {
        $startupTimeoutCaught = true;
    }
    cleanupCheck('startup timeout is reported while the child remains live', $startupTimeoutCaught);
    cleanupCheck(
        'startup timeout terminates and reaps the child and removes its barriers',
        $startupTimeoutPid > 0 && !is_dir('/proc/' . $startupTimeoutPid)
            && !file_exists($lifecycleDirectory . '/child-ready')
            && !file_exists($lifecycleDirectory . '/child-go')
    );

    $scenarioPid = 0;
    $scenarioChild = cleanupStartReaper($lifecycleDirectory, $now, 'reap-child', $scenarioPid);
    $scenarioFailureCaught = false;
    try {
        throw new RuntimeException('controlled post-spawn scenario failure');
    } catch (RuntimeException) {
        $scenarioFailureCaught = true;
    } finally {
        cleanupFinishChild($scenarioChild, true);
    }
    cleanupCheck('post-spawn scenario failure reaches bounded cleanup', $scenarioFailureCaught);
    cleanupCheck(
        'scenario failure terminates and reaps the child and removes its barriers',
        $scenarioPid > 0 && !is_dir('/proc/' . $scenarioPid)
            && !file_exists($lifecycleDirectory . '/child-ready')
            && !file_exists($lifecycleDirectory . '/child-go')
    );

    // Stale expiry cleanup: hold the writer's exact shard while cleanup starts,
    // publish a replacement generation under that lock, then let cleanup
    // re-check the record. The replacement must never be unlinked.
    $staleDirectory = $root . '/stale';
    mkdir($staleDirectory, 0700, true);
    $staleIp = '198.51.100.7';
    $stalePath = bruteForceStatePath($staleDirectory, $staleIp);
    $staleLockPath = bruteForceLockShardPath($staleDirectory, $staleIp);
    cleanupWriteRecord($stalePath, $staleRecord);
    $expired = $staleDirectory . '/' . hash('sha256', 'expired-control') . '.json';
    cleanupWriteRecord($expired, $staleRecord);
    $boundary = $staleDirectory . '/' . hash('sha256', 'retention-boundary') . '.json';
    cleanupWriteRecord($boundary, [
        'attempts' => 1,
        'first' => $now - 120,
        'last' => $now - 120,
        'locked_until' => 0,
    ]);
    $malformed = $staleDirectory . '/' . hash('sha256', 'malformed-control') . '.json';
    file_put_contents($malformed, '{');

    $staleChild = cleanupStartReaper($staleDirectory, $now);
    try {
        $staleHolder = fopen($staleLockPath, 'c');
        if ($staleHolder === false) {
            throw new RuntimeException('could not open stale-cleanup writer shard');
        }
        if (!flock($staleHolder, LOCK_EX | LOCK_NB)) {
            fclose($staleHolder);
            throw new RuntimeException('could not hold stale-cleanup writer shard');
        }
        try {
            if (!touch($staleChild['go'])) {
                throw new RuntimeException('could not release stale-cleanup start barrier');
            }
            $staleWaited = cleanupWaitForShard($staleChild, $staleLockPath);
            cleanupCheck('stale cleanup waits on the writer shard before deletion', $staleWaited);
            cleanupPublishRecord($stalePath, $liveRecord);
        } finally {
            flock($staleHolder, LOCK_UN);
            fclose($staleHolder);
        }
        $staleResult = cleanupFinishChild($staleChild);
        cleanupCheck('stale cleanup child completes after the writer releases', $staleResult['exit'] === 0, $staleResult['stderr']);
        cleanupCheck('stale cleanup retains the exact replacement generation', file_get_contents($stalePath) === json_encode($liveRecord, JSON_THROW_ON_ERROR));
        cleanupCheck('records older than the retention interval are removed', !file_exists($expired));
        cleanupCheck('the exact expiry boundary remains live', is_file($boundary));
        cleanupCheck('malformed records remain for fail-closed reads', is_file($malformed));
    } finally {
        cleanupFinishChild($staleChild, true);
    }

    // Capacity cleanup has its own deletion site. Keep the target outside the
    // stale-name window with the cursor, make it the oldest eviction candidate,
    // and repeat the exact writer-shard publication barrier.
    $overflowDirectory = $root . '/overflow';
    mkdir($overflowDirectory, 0700, true);
    $overflowIp = '203.0.113.7';
    $overflowPath = bruteForceStatePath($overflowDirectory, $overflowIp);
    $overflowLockPath = bruteForceLockShardPath($overflowDirectory, $overflowIp);
    cleanupWriteRecord($overflowPath, [
        'attempts' => 1,
        'first' => $now,
        'last' => $now,
        'locked_until' => 0,
    ]);
    touch($overflowPath, $now - 1000);
    $overflowDigest = basename($overflowPath, '.json');
    file_put_contents($overflowDirectory . '/.reap-cursor', $overflowDigest . "\n");
    for ($index = 0; $index < 64; $index++) {
        $path = $overflowDirectory . '/' . hash('sha256', 'overflow-' . $index) . '.json';
        cleanupWriteRecord($path, [
            'attempts' => 1,
            'first' => $now,
            'last' => $now,
            'locked_until' => 0,
        ]);
        touch($path, $now);
    }

    $overflowChild = cleanupStartReaper($overflowDirectory, $now);
    try {
        $overflowHolder = fopen($overflowLockPath, 'c');
        if ($overflowHolder === false) {
            throw new RuntimeException('could not open overflow-cleanup writer shard');
        }
        if (!flock($overflowHolder, LOCK_EX | LOCK_NB)) {
            fclose($overflowHolder);
            throw new RuntimeException('could not hold overflow-cleanup writer shard');
        }
        try {
            if (!touch($overflowChild['go'])) {
                throw new RuntimeException('could not release overflow-cleanup start barrier');
            }
            $overflowWaited = cleanupWaitForShard($overflowChild, $overflowLockPath);
            cleanupCheck('overflow cleanup waits on the writer shard before deletion', $overflowWaited);
            cleanupPublishRecord($overflowPath, $liveRecord);
        } finally {
            flock($overflowHolder, LOCK_UN);
            fclose($overflowHolder);
        }
        $overflowResult = cleanupFinishChild($overflowChild);
        cleanupCheck('overflow cleanup child completes after the writer releases', $overflowResult['exit'] === 0, $overflowResult['stderr']);
        cleanupCheck('overflow cleanup retains the exact replacement generation', file_get_contents($overflowPath) === json_encode($liveRecord, JSON_THROW_ON_ERROR));
        cleanupCheck('overflow cleanup still restores the configured capacity', count(glob($overflowDirectory . '/*.json') ?: []) === 64);
    } finally {
        cleanupFinishChild($overflowChild, true);
    }

    cleanupCheck('fixed assertion count', BruteForceCleanupAssertions::$checks === 14);
} finally {
    cleanupRemoveTree($root);
}

$failures = BruteForceCleanupAssertions::$failures;
echo $failures === 0 ? "ALL PASSED (15 checks)\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
