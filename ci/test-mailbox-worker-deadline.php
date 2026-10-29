<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../tests/test-mailbox-queue-atomic-mariadb.php');
if ($source === false) {
    throw new RuntimeException('worker helper unreadable');
}
$start = strpos($source, 'function waitMailboxQueueWorker(');
if ($start === false) {
    throw new RuntimeException('worker helper not found');
}
$end = strpos($source, "\nif (!in_array(\$argv[1] ?? ''", $start);
if ($end === false) {
    throw new RuntimeException('worker helper not found');
}
eval(substr($source, $start, $end - $start));

/**
 * @param list<int> $times
 * @param list<array{running:bool,exitcode:int}> $statuses
 * @param array{running:bool,exitcode:int} $initial
 */
function deadlineCase(string $name, array $times, array $statuses, bool $timeout, bool $terminate, int $exit = 0, array $initial = ['running' => true, 'exitcode' => -1]): void
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', ''], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('fixture process unavailable');
    }
    fclose($pipes[0]);
    $worker = ['process' => $process, 'pipes' => $pipes, 'last_status' => $initial, 'role' => $name];
    $terminated = false;
    $ops = [
        'now' => static function () use (&$times): int {
            if ($times === []) {
                throw new RuntimeException('clock exhausted');
            } return array_shift($times);
        },
        'status' => static function () use (&$statuses): array {
            if ($statuses === []) {
                throw new RuntimeException('status exhausted');
            } return array_shift($statuses);
        },
        'sleep' => static function (): void {
        },
        'terminate' => static function () use (&$terminated): bool {
            $terminated = true;
            return true;
        },
    ];
    try {
        $result = waitMailboxQueueWorker($worker, 0.00000001, $ops);
        if ($timeout || $result['exit'] !== $exit) {
            throw new RuntimeException('unexpected accepted result: ' . $name);
        }
    } catch (RuntimeException $error) {
        if (!$timeout || $error->getMessage() !== 'transaction worker timed out') {
            throw $error;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    if ($terminated !== $terminate || $statuses !== []) {
        throw new RuntimeException('unexpected termination or status count: ' . $name);
    }
}
$running = ['running' => true, 'exitcode' => -1];
$stopped = static fn (int $exit): array => ['running' => false, 'exitcode' => $exit];
deadlineCase('before', [0, 9], [$stopped(0)], false, false);
deadlineCase('exact', [0, 10], [$stopped(0)], false, false);
deadlineCase('nonzero', [0, 9], [$stopped(3)], false, false, 3);
deadlineCase('signal', [0, 9], [$stopped(-1)], false, false, -1);
deadlineCase('already-stopped', [0], [], false, false, 7, $stopped(7));
deadlineCase('late-stop', [0, 11, 12], [$stopped(0)], true, false);
deadlineCase('late-wake', [0, 1, 11, 12], [$running, $stopped(0)], true, false);
deadlineCase('refresh-stopped', [0, 10, 11], [$running, $stopped(0)], true, false);
deadlineCase('refresh-running', [0, 10, 11], [$running, $running], true, true);
$childPipes = [];
$child = proc_open([PHP_BINARY, '-r', ''], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes);
if (!is_resource($child)) {
    throw new RuntimeException('evidence fixture unavailable');
}
foreach ($childPipes as $pipe) {
    fclose($pipe);
}
$out = fopen('php://temp', 'w+');
$err = fopen('php://temp', 'w+');
if ($out === false || $err === false) {
    throw new RuntimeException('evidence streams unavailable');
}
fwrite($out, 'synthetic-out');
fwrite($err, 'synthetic-err');
rewind($out);
rewind($err);
$worker = ['process' => $child, 'pipes' => [1 => $out, 2 => $err], 'last_status' => $running, 'role' => 'evidence-role'];
$times = [0, 10, 11];
$statuses = [$running, $running];
$ordered = false;
$ops = [
    'now' => static function () use (&$times): int {
        if ($times === []) {
            throw new RuntimeException('clock exhausted');
        } return array_shift($times);
    },
    'status' => static function () use (&$statuses): array {
        if ($statuses === []) {
            throw new RuntimeException('status exhausted');
        } return array_shift($statuses);
    },
    'sleep' => static function (): void {
    },
    'terminate' => static function () use (&$ordered, $out, $err): bool {
        $ordered = ftell($out) === 13 && ftell($err) === 13;
        return true;
    },
];
try {
    waitMailboxQueueWorker($worker, 0.00000001, $ops);
    throw new RuntimeException('evidence timeout missed');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'transaction worker timed out') {
        throw $error;
    }
}
fclose($out);
fclose($err);
proc_close($child);
if (!$ordered) {
    throw new RuntimeException('streams not captured before termination');
}
echo "worker deadline controls passed\n";
