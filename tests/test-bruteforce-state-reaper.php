<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';

final class BruteForceReaperAssertions
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function reaperCheck(string $label, bool $condition): void
{
    BruteForceReaperAssertions::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        BruteForceReaperAssertions::$failures++;
    }
}

/** @param array{attempts:int,first:int,last:int,locked_until:int} $record */
function reaperState(string $directory, string $seed, array $record): string
{
    $path = $directory . '/' . hash('sha256', $seed) . '.json';
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    return $path;
}

function reaperRun(ViMbAdmin_BruteForce $bruteForce, int $now, int $rounds = 1): void
{
    $reap = new ReflectionMethod($bruteForce, '_reapStale');
    for ($round = 0; $round < $rounds; $round++) {
        $reap->invoke($bruteForce, $now);
    }
}

function reaperRemoveTree(string $directory): void
{
    foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.??*') ?: []) as $path) {
        is_dir($path) && !is_link($path) ? reaperRemoveTree($path) : unlink($path);
    }
    rmdir($directory);
}

echo "== brute-force stale-state reaper ==\n";

$root = sys_get_temp_dir() . '/vimbadmin-bruteforce-reaper-' . bin2hex(random_bytes(8));
$stateDirectory = $root . '/state';
mkdir($stateDirectory, 0700, true);
$now = time();
$retention = 120;
$stale = reaperState($stateDirectory, 'stale', [
    'attempts' => 3,
    'first' => $now - $retention - 2,
    'last' => $now - $retention - 2,
    'locked_until' => 0,
]);
$activeWindow = reaperState($stateDirectory, 'active-window', [
    'attempts' => 1,
    'first' => $now - 30,
    'last' => $now - 30,
    'locked_until' => 0,
]);
$activeLockout = reaperState($stateDirectory, 'active-lockout', [
    'attempts' => 5,
    'first' => $now - 500,
    'last' => $now - 500,
    'locked_until' => $now + 30,
]);
$boundary = reaperState($stateDirectory, 'boundary', [
    'attempts' => 1,
    'first' => $now - $retention,
    'last' => $now - $retention,
    'locked_until' => 0,
]);
$malformed = $stateDirectory . '/' . hash('sha256', 'malformed') . '.json';
file_put_contents($malformed, '{');
$unrelated = $stateDirectory . '/operator-note.json';
file_put_contents($unrelated, 'keep');
$outside = $root . '/outside.json';
file_put_contents($outside, json_encode([
    'attempts' => 1,
    'first' => 1,
    'last' => 1,
    'locked_until' => 0,
]));
$symlink = $stateDirectory . '/' . hash('sha256', 'symlink-race') . '.json';
symlink($outside, $symlink);

$bruteForce = new ViMbAdmin_BruteForce(null, [
    'statedir' => $stateDirectory,
    'window' => 60,
    'lockout' => $retention,
]);
reaperRun($bruteForce, $now, 5);

reaperCheck('records beyond the maximum retention interval are removed', !file_exists($stale));
reaperCheck('active-window records are retained', is_file($activeWindow));
reaperCheck('active lockout records are retained', is_file($activeLockout));
reaperCheck('the exact retention boundary is retained', is_file($boundary));
reaperCheck('malformed state is retained for fail-closed reads', is_file($malformed));
reaperCheck('unrelated files are never selected', is_file($unrelated));
reaperCheck('symlink-shaped state entries are not followed or removed', is_link($symlink) && is_file($outside));
$other = reaperState($stateDirectory, 'other-inode', [
    'attempts' => 1,
    'first' => 1,
    'last' => 1,
    'locked_until' => 0,
]);
$stableFile = new ReflectionMethod($bruteForce, '_isStableRegularFile');
reaperCheck(
    'inode substitution is rejected before deletion',
    !$stableFile->invoke($bruteForce, lstat($boundary), lstat($other))
);

for ($index = 0; $index < 140; $index++) {
    reaperState($stateDirectory, 'prefix-active-' . $index, [
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
    ]);
}
$orderedStates = [];
foreach (new DirectoryIterator($stateDirectory) as $entry) {
    if (preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename()) === 1) {
        $orderedStates[] = $entry->getPathname();
    }
}
$lateStale = $orderedStates[135];
file_put_contents($lateStale, json_encode([
    'attempts' => 1, 'first' => 1, 'last' => 1, 'locked_until' => 0,
], JSON_THROW_ON_ERROR));
reaperRun($bruteForce, $now, 2);
reaperCheck('rotating cursor advances beyond 128 active prefix records', !file_exists($lateStale));

$capacityDirectory = $root . '/capacity';
mkdir($capacityDirectory, 0700);
for ($index = 0; $index < 128; $index++) {
    reaperState($capacityDirectory, 'stale-capacity-' . $index, [
        'attempts' => 1, 'first' => 1, 'last' => 1, 'locked_until' => 0,
    ]);
}
$capacityReaper = new ViMbAdmin_BruteForce(null, [
    'statedir' => $capacityDirectory, 'window' => 60, 'lockout' => $retention,
]);
reaperRun($capacityReaper, $now, 2);
reaperCheck(
    'each request has removal capacity above one created record',
    count(glob($capacityDirectory . '/*.json') ?: []) === 0
);

$cursorTarget = $root . '/cursor-target';
file_put_contents($cursorTarget, "do-not-change\n");
$cursorPath = $stateDirectory . '/.reap-cursor';
unlink($cursorPath);
symlink($cursorTarget, $cursorPath);
reaperRun($bruteForce, $now);
reaperCheck(
    'a substituted cursor is replaced without modifying its target',
    is_file($cursorPath) && !is_link($cursorPath)
        && file_get_contents($cursorTarget) === "do-not-change\n"
);

$linkedTarget = $root . '/linked-target';
mkdir($linkedTarget, 0700);
$linkedStale = reaperState($linkedTarget, 'linked-stale', [
    'attempts' => 1,
    'first' => 1,
    'last' => 1,
    'locked_until' => 0,
]);
$linkedDirectory = $root . '/linked-state';
symlink($linkedTarget, $linkedDirectory);
$linkedReaper = new ViMbAdmin_BruteForce(null, [
    'statedir' => $linkedDirectory,
    'window' => 60,
    'lockout' => $retention,
]);
(new ReflectionMethod($linkedReaper, '_maybeReapStale'))->invoke($linkedReaper);
reaperCheck('a symlink-substituted state directory is never traversed', is_file($linkedStale));

$contended = reaperState($stateDirectory, 'contended', [
    'attempts' => 1,
    'first' => 1,
    'last' => 1,
    'locked_until' => 0,
]);
$lock = fopen($stateDirectory . '/.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('could not establish reaper contention fixture');
}
(new ReflectionMethod($bruteForce, '_maybeReapStale'))->invoke($bruteForce);
flock($lock, LOCK_UN);
fclose($lock);
reaperCheck('opportunistic cleanup tolerates lock contention without deleting state', is_file($contended));

reaperCheck('fixed assertion count', BruteForceReaperAssertions::$checks === 13);

reaperRemoveTree($root);
$failures = BruteForceReaperAssertions::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
