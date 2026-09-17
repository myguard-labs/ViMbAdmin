<?php

/**
 * VIM-D08 controls for the sharded brute-force state lock.
 *
 * The login POST path serialises every state mutation on a flock'd sidecar.
 * It used to be ONE directory-wide `.lock` inode, so two unrelated source
 * prefixes contended on the same lock and every waiter burned FPM CPU spinning
 * at 1000 wakeups a second. The lock is now sharded by the first byte of the
 * same sha256 the record filename uses, and waiters back off geometrically.
 *
 * Each group below asserts an observable that separates the sharded behaviour
 * from the shipped-broken one:
 *   1. two prefixes in DIFFERENT shards do not serialise (distinct lock paths,
 *      and holding one shard does not block the other);
 *   2. two prefixes in the SAME shard still serialise (one lock path, and
 *      holding it does block);
 *   3. a wedged holder still fails closed inside the bounded wait, so the
 *      blocking-free backoff never becomes an unbounded hang;
 *   4. the .lock.xx sidecars are drawn from a fixed 256-name alphabet and are
 *      never counted as records by the cap/reap scan.
 */

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/support/bruteforce-state-path.php';

final class ShardLockAssertions
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function shardCheck(string $label, bool $condition, string $detail = ''): void
{
    ShardLockAssertions::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . ($detail === '' ? '' : ' [' . $detail . ']') . "\n";
    if (!$condition) {
        ShardLockAssertions::$failures++;
    }
}

function shardRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.??*') ?: []) as $path) {
        is_dir($path) && !is_link($path) ? shardRemoveTree($path) : unlink($path);
    }
    rmdir($directory);
}

/** @param array<string,mixed> $options */
function shardAttempt(array $options, string $ip): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    (new ViMbAdmin_BruteForce(null, $options))->record('victim', null);
}

/**
 * The production lock path the class actually opens for $ip, read straight off
 * the object under test rather than recomputed, so a keying change cannot make
 * this test agree with itself while diverging from the shipped behaviour.
 */
function shardProductionLockPath(string $stateDirectory, string $ip): string
{
    $bruteForce = new ViMbAdmin_BruteForce(null, ['statedir' => $stateDirectory]);

    /** @var string */
    return (new ReflectionMethod($bruteForce, '_lockFile'))->invoke($bruteForce, $ip);
}

/**
 * Attempt one state mutation while $lockPath is held exclusively elsewhere and
 * report whether it completed, plus how long it took.
 *
 * @param array<string,mixed> $options
 * @return array{completed:bool, denied:bool, seconds:float}
 */
/**
 * Voluntary context switches for this process, or null when the platform does
 * not report them. A wakeup-count assertion that silently degrades to "0 <= 50"
 * would be vacuous, so callers must treat null as "cannot measure" rather than
 * as a pass.
 */
function shardVoluntarySwitches(): ?int
{
    $usage = getrusage();

    return is_array($usage) && isset($usage['ru_nvcsw']) && is_int($usage['ru_nvcsw'])
        ? $usage['ru_nvcsw']
        : null;
}

/**
 * Attempt one state mutation while $lockPath is held exclusively elsewhere and
 * report whether it completed, how long it took, and how many times the retry
 * loop woke up (null when the platform does not report context switches).
 *
 * @param array<string,mixed> $options
 * @return array{completed:bool, denied:bool, seconds:float, switches:int|null}
 */
function shardAttemptUnderHeldLock(array $options, string $ip, string $lockPath): array
{
    $holder = fopen($lockPath, 'c');
    if ($holder === false || !flock($holder, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('could not establish the shard lock fixture: ' . $lockPath);
    }

    $started = hrtime(true);
    // Voluntary context switches are the direct observable of the retry loop's
    // wakeup count: each usleep() in the spin is one. Measuring the REAL loop
    // is the only way this control can notice the backoff being flattened back
    // to a fixed interval -- a test-side replica of the loop asserts on itself.
    $switchesBefore = shardVoluntarySwitches();
    $completed = false;
    $denied = false;
    try {
        shardAttempt($options, $ip);
        $completed = true;
    } catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'bruteforce state persistence unavailable';
    } finally {
        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        $switchesAfter = shardVoluntarySwitches();
        $switches = ($switchesBefore === null || $switchesAfter === null)
            ? null
            : $switchesAfter - $switchesBefore;
        flock($holder, LOCK_UN);
        fclose($holder);
    }

    return [
        'completed' => $completed,
        'denied' => $denied,
        'seconds' => $seconds,
        'switches' => $switches,
    ];
}

echo "== brute-force sharded state lock ==\n";

$root = sys_get_temp_dir() . '/vimbadmin-shard-lock-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('could not create the shard-lock fixture root');
}

// Two prefixes whose record digests start with DIFFERENT bytes, and two whose
// digests start with the SAME byte. Both pairs are asserted below to still have
// the intended relationship, so a keying change surfaces as a failed
// precondition rather than a silently vacuous test.
$shardA = '198.51.0.7';
$shardB = '198.51.1.7';
$shardBTwin = '198.51.175.7';

// ---- 1. different shards do not serialise -------------------------------

$directory = $root . '/split';
$pathA = shardProductionLockPath($directory, $shardA);
$pathB = shardProductionLockPath($directory, $shardB);

shardCheck(
    'the lock sidecar is derived from the record digest, not a single global inode',
    $pathA === $directory . '/.lock.' . substr(hash('sha256', bruteForceStateKey($shardA)), 0, 2),
    $pathA,
);
shardCheck(
    'two prefixes in different shards get distinct lock paths',
    $pathA !== $pathB,
    $pathA . ' vs ' . $pathB,
);

$options = ['statedir' => $directory, 'window' => 3600, 'lockout' => 3600];
// Seed both records so the directory exists and the shard sidecars are created
// before contention is applied.
shardAttempt($options, $shardA);
shardAttempt($options, $shardB);

$crossShard = shardAttemptUnderHeldLock($options, $shardB, $pathA);
shardCheck(
    'holding one shard does not block a source in another shard',
    $crossShard['completed'],
    sprintf('%.3fs, denied=%d', $crossShard['seconds'], (int) $crossShard['denied']),
);
shardCheck(
    'the unrelated source is not made to wait out the lock deadline',
    $crossShard['seconds'] < 0.5,
    sprintf('%.3fs', $crossShard['seconds']),
);
shardCheck(
    'the unrelated source actually persisted its attempt',
    is_file(bruteForceStatePath($directory, $shardB)),
);

// ---- 2. the same shard still serialises ---------------------------------

$pathBTwin = shardProductionLockPath($directory, $shardBTwin);
shardCheck(
    'two prefixes colliding in one shard share exactly one lock path',
    $pathB === $pathBTwin,
    $pathB . ' vs ' . $pathBTwin,
);
shardCheck(
    'the colliding prefixes are genuinely distinct records',
    bruteForceStatePath($directory, $shardB) !== bruteForceStatePath($directory, $shardBTwin),
);

$sameShard = shardAttemptUnderHeldLock($options, $shardBTwin, $pathB);
shardCheck(
    'a source colliding in an occupied shard is still serialised and fails closed',
    !$sameShard['completed'] && $sameShard['denied'],
    sprintf('%.3fs, completed=%d', $sameShard['seconds'], (int) $sameShard['completed']),
);
shardCheck(
    'the serialised source did not write state behind the held lock',
    !is_file(bruteForceStatePath($directory, $shardBTwin)),
);

// ---- 3. a wedged holder still times out within the bound ----------------

shardCheck(
    'a wedged shard lock raises the fail-closed RuntimeException, never hangs',
    $sameShard['denied'],
);
shardCheck(
    'the bounded wait is honoured: at least the 1s deadline, and no unbounded hang',
    $sameShard['seconds'] >= 0.9 && $sameShard['seconds'] < 3.0,
    sprintf('%.3fs', $sameShard['seconds']),
);

// The backoff must actually be coarse. A flat 1ms spin burns ~1000 wakeups over
// the 1s deadline; the geometric backoff capped at 64ms costs ~20. Measuring
// wakeups directly is not portable, so assert the invariant that produces the
// saving: the retry cap is far above the initial interval, and the sum of the
// geometric series over the deadline is a small number of sleeps.
$reflection = new ReflectionClass(ViMbAdmin_BruteForce::class);

/**
 * Read one integer class constant, failing loudly if it is missing or retyped.
 *
 * @param ReflectionClass<ViMbAdmin_BruteForce> $reflection
 */
function shardIntConstant(ReflectionClass $reflection, string $name): int
{
    $value = $reflection->getConstant($name);
    if (!is_int($value)) {
        throw new RuntimeException($name . ' is not an int class constant');
    }

    return $value;
}

$initial = shardIntConstant($reflection, 'LOCK_RETRY_MICROSECONDS');
$maximum = shardIntConstant($reflection, 'LOCK_MAX_RETRY_MICROSECONDS');
$deadlineMicroseconds = intdiv(shardIntConstant($reflection, 'LOCK_TIMEOUT_NANOSECONDS'), 1000);
shardCheck(
    'the retry interval backs off well above its starting value',
    $maximum >= $initial * 32,
    $initial . ' -> ' . $maximum,
);
// The wakeup count comes from the contended wait executed above, not from a
// replica of the loop: $sameShard burned the whole deadline against a held
// lock, so its voluntary context switches ARE the retry loop's wakeups.
//
// Asserting only an upper bound would be too weak -- a FLAT delay of
// deadline/ceiling microseconds also lands under any ceiling while having
// removed the growth entirely. So the expected count is derived from the real
// constants by walking the doubling schedule, and the assertion is a BAND
// around it. Any flat spin, at any rate, produces deadline/rate wakeups, which
// falls outside that band unless the rate coincidentally equals the doubling
// schedule's mean -- and the growth check below rules that out too, because a
// flat schedule reaches its total in equal steps while a doubling one spends
// most of its wakeups near the cap.
$scheduleWakeups = 0;
$scheduleElapsed = 0;
$scheduleBackoff = $initial;
while ($scheduleElapsed < $deadlineMicroseconds) {
    $scheduleWakeups++;
    $scheduleElapsed += $scheduleBackoff;
    if ($scheduleBackoff < $maximum) {
        $scheduleBackoff *= 2;
    }
}
// A flat spin at the STARTING interval is the regression this control exists
// to catch: dropping the doubling leaves the loop hammering at
// LOCK_RETRY_MICROSECONDS, which is the wakeup storm the coarse backoff was
// introduced to remove. It must fall outside the band.
//
// A flat spin at the CAP is NOT excluded here, and deliberately so: it wakes
// about as often as the doubling schedule does, because the doubling schedule
// already spends nearly the whole deadline at the cap. Wakeup count cannot
// separate them and no honest assertion on this observable can pretend to. It
// is also not the regression worth guarding -- a flat 64ms spin is coarser
// than what ships, not finer, so it costs less CPU rather than more. What it
// would cost is latency on a briefly-held lock, and that is pinned separately
// by the uncontended-path checks above.
$flatSpinWakeups = intdiv($deadlineMicroseconds, $initial);
// The band is tight on purpose. The doubling schedule's count is sharply
// determined by the constants, and the measured value only drifts by scheduler
// noise, so a +/-25% window still absorbs a loaded runner while excluding
// the flat-at-start storm by two orders of magnitude. A wider band
// would readmit it and the assertion would stop discriminating.
$wakeupFloor = (int) floor($scheduleWakeups * 0.75);
$wakeupCeiling = (int) ceil($scheduleWakeups * 1.25);
shardCheck(
    'the fixture can measure wakeups and the band excludes a flat spin at the start interval',
    $sameShard['switches'] !== null && $flatSpinWakeups > $wakeupCeiling,
    'measurable=' . var_export($sameShard['switches'] !== null, true)
        . ', band=' . $wakeupFloor . '..' . $wakeupCeiling
        . ', flat@start=' . $flatSpinWakeups,
);
shardCheck(
    'a full deadline wait wakes the number of times a DOUBLING schedule predicts',
    $sameShard['switches'] !== null
        && $sameShard['switches'] >= $wakeupFloor
        && $sameShard['switches'] <= $wakeupCeiling,
    var_export($sameShard['switches'], true) . ' in ' . $wakeupFloor . '..' . $wakeupCeiling,
);

// ---- 4. sidecars are never records ---------------------------------------

$capDirectory = $root . '/cap';
$capOptions = ['statedir' => $capDirectory, 'window' => 3600, 'lockout' => 3600];
foreach (['198.51.0.7', '198.51.1.7', '198.51.2.7', '198.51.3.7'] as $capIp) {
    shardAttempt($capOptions, $capIp);
}

$entries = scandir($capDirectory);
$entries = is_array($entries) ? $entries : [];
$lockEntries = array_values(array_filter(
    $entries,
    static fn (string $entry): bool => str_starts_with($entry, '.lock'),
));
shardCheck(
    'lock sidecars exist and are drawn from the fixed .lock.00 .. .lock.ff alphabet',
    $lockEntries !== [] && array_values(array_filter(
        $lockEntries,
        static fn (string $entry): bool => preg_match('/^\.lock\.[0-9a-f]{2}$/D', $entry) === 1,
    )) === $lockEntries,
    implode(',', $lockEntries),
);
shardCheck(
    'the shard alphabet cannot exceed 256 names however many sources appear',
    count(array_unique($lockEntries)) <= 256,
    (string) count($lockEntries),
);

// The reap/eviction scan counts only ^[a-f0-9]{64}\.json$ entries, so the
// dotfile sidecars must be invisible to the cap. Prove it by asking the
// reaper's own scan what it counted, with a cap set below the entry total once
// sidecars are included and above it when they are not: if a sidecar were
// counted as a record, the sweep would evict live state.
// The reap/eviction scan counts only ^[a-f0-9]{64}\.json$ entries, so the
// dotfile sidecars must be invisible to the cap. Set max_entries to EXACTLY the
// record count: the sweep must report "not over cap". The directory also holds
// a sidecar per occupied shard, so if the scan counted those the total would
// exceed the cap, the sweep would report overflow, and it would start evicting
// live state.
$reapDirectory = $root . '/reap';
$reapAddresses = [];
for ($i = 0; $i < 70; $i++) {
    $reapAddresses[] = '203.0.' . $i . '.0';
}
$reapOptions = [
    'statedir' => $reapDirectory,
    'window' => 3600,
    'lockout' => 3600,
    'max_entries' => count($reapAddresses),
];
foreach ($reapAddresses as $reapIp) {
    shardAttempt($reapOptions, $reapIp);
}
$recordsBefore = glob($reapDirectory . '/*.json') ?: [];
$sidecarsBefore = glob($reapDirectory . '/.lock.*') ?: [];
shardCheck(
    'the fixture produced one record per prefix plus lock sidecars',
    count($recordsBefore) === count($reapAddresses) && count($sidecarsBefore) > 0,
    count($recordsBefore) . ' records, ' . count($sidecarsBefore) . ' sidecars',
);

$reaper = new ViMbAdmin_BruteForce(null, $reapOptions);
$atCap = (new ReflectionMethod($reaper, '_reapStale'))->invoke($reaper, time());
shardCheck(
    'with the cap set to the record count the sweep is NOT over cap: sidecars are not counted',
    $atCap === false,
    var_export($atCap, true),
);
shardCheck(
    'the sweep evicted no live record',
    count(glob($reapDirectory . '/*.json') ?: []) === count($recordsBefore),
    (string) count(glob($reapDirectory . '/*.json') ?: []),
);
shardCheck(
    'the sweep did not delete the lock sidecars either',
    count(glob($reapDirectory . '/.lock.*') ?: []) === count($sidecarsBefore),
);

// Positive discrimination: the same scan DOES act on overflow once the cap is
// genuinely below the record count. Without this, "no eviction happened" above
// would be satisfied by a scan that can never evict anything, and the sidecar
// assertion would be vacuous.
$overOptions = $reapOptions;
$overOptions['max_entries'] = 64;
$overReaper = new ViMbAdmin_BruteForce(null, $overOptions);
@unlink($reapDirectory . '/.reap-cursor');
(new ReflectionMethod($overReaper, '_reapStale'))->invoke($overReaper, time());
$recordsAfterOverflow = count(glob($reapDirectory . '/*.json') ?: []);
shardCheck(
    'the same scan does evict down to the cap when it is truly below the record count',
    $recordsAfterOverflow === 64,
    $recordsAfterOverflow . ' records left, cap 64',
);

// ---- 5. fixed assertion count -------------------------------------------

shardCheck('fixed assertion count (21 preceding checks)', ShardLockAssertions::$checks === 21, (string) ShardLockAssertions::$checks);

shardRemoveTree($root);

if (ShardLockAssertions::$failures > 0) {
    echo 'FAIL: ' . ShardLockAssertions::$failures . " assertion(s) failed\n";
    exit(1);
}
echo 'ALL PASSED (' . ShardLockAssertions::$checks . " checks)\n";
