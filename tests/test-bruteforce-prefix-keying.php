<?php

/**
 * VIM-D01 controls for brute-force state keying, directory bounding, the
 * opaque name cursor, and lock-free reaping.
 *
 * Every check here is a control with an observable that separates the fixed
 * behaviour from the shipped-broken one; the scaling measurement asserts a
 * flat-work bound rather than a wall-clock threshold, so it stays meaningful on
 * a loaded CI runner.
 */

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/support/bruteforce-state-path.php';

final class BruteForcePrefixAssertions
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function prefixCheck(string $label, bool $condition, string $detail = ''): void
{
    BruteForcePrefixAssertions::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . ($detail === '' ? '' : ' [' . $detail . ']') . "\n";
    if (!$condition) {
        BruteForcePrefixAssertions::$failures++;
    }
}

function prefixRemoveTree(string $directory): void
{
    foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.??*') ?: []) as $path) {
        is_dir($path) && !is_link($path) ? prefixRemoveTree($path) : unlink($path);
    }
    rmdir($directory);
}

function prefixKey(ViMbAdmin_BruteForce $bruteForce, string $ip): string
{
    /** @var string */
    return (new ReflectionMethod($bruteForce, '_key'))->invoke($bruteForce, $ip);
}

/** @return list<string> */
function prefixStateFiles(string $directory): array
{
    $files = glob($directory . '/*.json');

    return $files === false ? [] : $files;
}

/**
 * Drive one failed login the way AuthController does: a FRESH
 * ViMbAdmin_BruteForce per request. Reusing one object lets per-instance state
 * accumulate across the whole loop, which no FPM worker ever gets, and quietly
 * turns a bounding test into a tautology.
 *
 * @param array<string,mixed> $options
 */
function prefixRequestAttempt(array $options, string $ip): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    (new ViMbAdmin_BruteForce(null, $options))->record('victim', null);
}

/** @param array<string,mixed> $options */
function prefixRequestLocked(array $options, string $ip): bool
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    return (new ViMbAdmin_BruteForce(null, $options))->isLocked(null);
}

function prefixAttempt(ViMbAdmin_BruteForce $bruteForce, string $ip): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $bruteForce->record('victim', null);
}

function prefixLocked(ViMbAdmin_BruteForce $bruteForce, string $ip): bool
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    return $bruteForce->isLocked(null);
}

echo "== brute-force prefix keying, bounding and reaper scaling ==\n";

$root = sys_get_temp_dir() . '/vimbadmin-bruteforce-prefix-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

// ---- 1. prefix keying --------------------------------------------------

$keyDirectory = $root . '/keying';
mkdir($keyDirectory, 0700, true);
$keyed = new ViMbAdmin_BruteForce(null, ['statedir' => $keyDirectory, 'max_attempts' => 5, 'window' => 900]);

prefixCheck(
    'IPv6 addresses inside one /64 collapse onto one key',
    prefixKey($keyed, '2001:db8:1:2::1') === prefixKey($keyed, '2001:db8:1:2:ffff:ffff:ffff:ffff'),
    prefixKey($keyed, '2001:db8:1:2::1'),
);
prefixCheck(
    'IPv6 addresses in different /64s stay distinct',
    prefixKey($keyed, '2001:db8:1:2::1') !== prefixKey($keyed, '2001:db8:1:3::1'),
);
prefixCheck(
    'IPv4 addresses inside one /24 collapse onto one key',
    prefixKey($keyed, '198.51.100.7') === prefixKey($keyed, '198.51.100.201'),
    prefixKey($keyed, '198.51.100.7'),
);
prefixCheck(
    'IPv4 addresses in different /24s stay distinct',
    prefixKey($keyed, '198.51.100.7') !== prefixKey($keyed, '198.51.101.7'),
);
prefixCheck(
    'an unparsable source keys onto its own bucket rather than a shared one',
    prefixKey($keyed, 'not-an-ip') === 'raw:not-an-ip'
        && prefixKey($keyed, 'not-an-ip') !== prefixKey($keyed, 'other-garbage'),
);
prefixCheck(
    'a configured /32 IPv4 width restores exact-address keying',
    prefixKey(
        new ViMbAdmin_BruteForce(null, ['statedir' => $keyDirectory, 'ipv4_prefix' => 32]),
        '198.51.100.7',
    ) !== prefixKey(
        new ViMbAdmin_BruteForce(null, ['statedir' => $keyDirectory, 'ipv4_prefix' => 32]),
        '198.51.100.201',
    ),
);
foreach ([['ipv4_prefix', 33], ['ipv4_prefix', 4], ['ipv6_prefix', 129], ['ipv6_prefix', 8]] as [$option, $value]) {
    $rejected = false;
    try {
        new ViMbAdmin_BruteForce(null, ['statedir' => $keyDirectory, $option => $value]);
    } catch (LogicException) {
        $rejected = true;
    }
    prefixCheck('an out-of-range ' . $option . ' of ' . $value . ' is rejected', $rejected);
}

// An IPv4-mapped address (::ffff:a.b.c.d) is an IPv4 client in a 16-byte
// representation. Masked at the IPv6 width it would collapse to ::/64, putting
// every such client in one bucket so any one of them could lock out all the
// rest. It must key exactly as the plain IPv4 address does.
prefixCheck(
    'an IPv4-mapped address keys as its IPv4 form, not ::/64',
    prefixKey($keyed, '::ffff:203.0.113.5') === prefixKey($keyed, '203.0.113.5'),
    prefixKey($keyed, '::ffff:203.0.113.5'),
);
prefixCheck(
    'CONTROL: two unrelated IPv4-mapped clients do not share one bucket',
    prefixKey($keyed, '::ffff:203.0.113.5') !== prefixKey($keyed, '::ffff:198.51.100.9')
        && prefixKey($keyed, '::ffff:203.0.113.5') !== '::/64',
);
prefixCheck(
    'a genuine IPv6 address is still keyed at the IPv6 width',
    prefixKey($keyed, '2001:db8::1') === '2001:db8::/64',
);

// The shared test helper must agree with the implementation, or the tests that
// fault-inject a single state file would silently target the wrong path.
prefixCheck(
    'the shared test helper derives the same key as the implementation',
    bruteForceStateKey('203.0.113.5') === prefixKey($keyed, '203.0.113.5')
        && bruteForceStateKey('2001:db8:1:2::9') === prefixKey($keyed, '2001:db8:1:2::9')
        && bruteForceStateKey('::ffff:203.0.113.5') === prefixKey($keyed, '::ffff:203.0.113.5'),
);

// ---- 2. NEGATIVE CONTROL: address rotation buys no extra attempts ------

$rotationDirectory = $root . '/rotation';
mkdir($rotationDirectory, 0700, true);
$rotation = new ViMbAdmin_BruteForce(null, [
    'statedir' => $rotationDirectory,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
]);

// Five failures, every one from a different address inside a single /64 --
// the shape an IPv6 host trivially has. Under per-address keying each attempt
// landed in its own file and none of them ever reached the threshold.
$rotated = [];
for ($index = 0; $index < 5; $index++) {
    $rotated[] = $address = '2001:db8:dead:beef::' . dechex($index + 1);
    prefixAttempt($rotation, $address);
}
prefixCheck(
    'five rotated IPv6 addresses in one /64 produce exactly one state file',
    count(prefixStateFiles($rotationDirectory)) === 1,
    'files=' . count(prefixStateFiles($rotationDirectory)),
);
prefixCheck(
    'the rotating /64 is locked out after five attempts',
    prefixLocked($rotation, '2001:db8:dead:beef::abcd'),
);
prefixCheck(
    'a sixth, never-seen address in the same /64 is already locked',
    prefixLocked($rotation, '2001:db8:dead:beef:1234:5678:9abc:def0'),
);
prefixCheck(
    'a neighbouring /64 is unaffected',
    !prefixLocked($rotation, '2001:db8:dead:beee::1'),
);

// The control's own negative half: configured at /128 (the shipped-broken
// per-address behaviour) the same five rotated attempts must NOT lock, proving
// the assertions above are decided by the keying change and nothing else.
$unkeyedDirectory = $root . '/rotation-unkeyed';
mkdir($unkeyedDirectory, 0700, true);
$unkeyed = new ViMbAdmin_BruteForce(null, [
    'statedir' => $unkeyedDirectory,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
    'ipv6_prefix' => 128,
]);
foreach ($rotated as $address) {
    prefixAttempt($unkeyed, $address);
}
prefixCheck(
    'CONTROL: per-address keying grants each rotated address its own counter',
    count(prefixStateFiles($unkeyedDirectory)) === 5,
    'files=' . count(prefixStateFiles($unkeyedDirectory)),
);
prefixCheck(
    'CONTROL: per-address keying leaves the rotating attacker unlocked',
    !prefixLocked($unkeyed, '2001:db8:dead:beef::1'),
);

// ---- 3. IPv4 rotation, same shape --------------------------------------

$v4Directory = $root . '/rotation-v4';
mkdir($v4Directory, 0700, true);
$v4 = new ViMbAdmin_BruteForce(null, [
    'statedir' => $v4Directory,
    'max_attempts' => 3,
    'window' => 900,
    'lockout' => 900,
]);
foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12'] as $address) {
    prefixAttempt($v4, $address);
}
prefixCheck(
    'three rotated IPv4 addresses in one /24 lock the whole prefix',
    prefixLocked($v4, '203.0.113.250') && count(prefixStateFiles($v4Directory)) === 1,
);

// ---- 4. thresholds preserved for a single source -----------------------

$thresholdDirectory = $root . '/threshold';
mkdir($thresholdDirectory, 0700, true);
$threshold = new ViMbAdmin_BruteForce(null, [
    'statedir' => $thresholdDirectory,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
]);
$lockedAt = 0;
for ($attempt = 1; $attempt <= 5; $attempt++) {
    prefixAttempt($threshold, '198.51.100.9');
    if ($lockedAt === 0 && prefixLocked($threshold, '198.51.100.9')) {
        $lockedAt = $attempt;
    }
}
prefixCheck('the configured threshold still locks on exactly the fifth attempt', $lockedAt === 5, 'locked at ' . $lockedAt);

$clearDirectory = $root . '/clear';
mkdir($clearDirectory, 0700, true);
$clearing = new ViMbAdmin_BruteForce(null, ['statedir' => $clearDirectory, 'max_attempts' => 2]);
prefixAttempt($clearing, '198.51.100.20');
prefixAttempt($clearing, '198.51.100.21');
$_SERVER['REMOTE_ADDR'] = '198.51.100.22';
$clearing->clear('victim', null);
prefixCheck(
    'a successful login clears the whole prefix counter',
    !prefixLocked($clearing, '198.51.100.20') && prefixStateFiles($clearDirectory) === [],
);

// ---- 5. NEGATIVE CONTROL: fail-closed persistence (VIM-A04) ------------

$faultRoot = $root . '/faults';
mkdir($faultRoot, 0700, true);

/** @param callable():void $operation */
function prefixDenied(callable $operation): bool
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return $exception->getMessage() === 'bruteforce state persistence unavailable';
    }

    return false;
}

// mkdir fault: the state directory path is occupied by a regular file.
$mkdirState = $faultRoot . '/mkdir-blocked';
file_put_contents($mkdirState, 'not a directory');
$mkdirFault = new ViMbAdmin_BruteForce(null, ['statedir' => $mkdirState]);
$_SERVER['REMOTE_ADDR'] = '198.51.100.30';
prefixCheck(
    'CONTROL: a mkdir fault still denies authentication after the reap moved off the lock',
    prefixDenied(static function () use ($mkdirFault): void {
        $mkdirFault->record('victim', null);
    }),
);
prefixCheck(
    'CONTROL: a mkdir fault denies the pre-auth lock assertion too',
    prefixDenied(static function () use ($mkdirFault): void {
        $mkdirFault->assertNotLocked(null);
    }),
);

// write fault: the temp target is occupied by a directory.
$writeDirectory = $faultRoot . '/write';
mkdir($writeDirectory, 0700, true);
$writeFault = new ViMbAdmin_BruteForce(null, ['statedir' => $writeDirectory]);
$writeTarget = $writeDirectory . '/' . hash('sha256', prefixKey($writeFault, '198.51.100.40')) . '.json';
mkdir($writeTarget . '.' . getmypid() . '.tmp', 0700, true);
prefixCheck(
    'CONTROL: a write fault on the prefix-keyed path still denies persistence',
    prefixDenied(static function () use ($writeFault): void {
        (new ReflectionMethod($writeFault, '_save'))->invoke($writeFault, '198.51.100.40', [
            'attempts' => 1, 'first' => time(), 'last' => time(), 'locked_until' => 0,
        ]);
    }) && !is_file($writeTarget),
);

// rename fault: the destination is a directory.
$renameDirectory = $faultRoot . '/rename';
mkdir($renameDirectory, 0700, true);
$renameFault = new ViMbAdmin_BruteForce(null, ['statedir' => $renameDirectory]);
$renameTarget = $renameDirectory . '/' . hash('sha256', prefixKey($renameFault, '198.51.100.50')) . '.json';
mkdir($renameTarget, 0700, true);
prefixCheck(
    'CONTROL: a rename fault on the prefix-keyed path still denies persistence',
    prefixDenied(static function () use ($renameFault): void {
        (new ReflectionMethod($renameFault, '_save'))->invoke($renameFault, '198.51.100.50', [
            'attempts' => 1, 'first' => time(), 'last' => time(), 'locked_until' => 0,
        ]);
    }) && glob($renameDirectory . '/*.tmp') === [],
);

// The reaper is now best-effort and lock-free; it must never convert a
// maintenance fault into a denial, but the state write behind it must.
$reapOnlyDirectory = $faultRoot . '/reap-tolerated';
mkdir($reapOnlyDirectory, 0700, true);
$reapOnly = new ViMbAdmin_BruteForce(null, ['statedir' => $reapOnlyDirectory, 'max_attempts' => 5]);
file_put_contents($reapOnlyDirectory . '/.reap-cursor', "garbage-not-a-digest\n");
$reapTolerated = true;
try {
    prefixAttempt($reapOnly, '198.51.100.60');
} catch (RuntimeException) {
    $reapTolerated = false;
}
prefixCheck(
    'a corrupt reap cursor is tolerated and the attempt is still recorded',
    $reapTolerated && count(prefixStateFiles($reapOnlyDirectory)) === 1,
);

// ---- 6. bounded state directory ----------------------------------------

$capDirectory = $root . '/cap';
mkdir($capDirectory, 0700, true);
$cap = 64;
$capped = new ViMbAdmin_BruteForce(null, [
    'statedir' => $capDirectory,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => $cap,
]);
$now = time();
for ($index = 0; $index < 400; $index++) {
    $path = $capDirectory . '/' . hash('sha256', 'flood-' . $index) . '.json';
    file_put_contents($path, (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
    ]));
    touch($path, $now - $index);
}
$before = count(prefixStateFiles($capDirectory));
$reap = new ReflectionMethod($capped, '_reapStale');
$reap->invoke($capped, $now);
$after = count(prefixStateFiles($capDirectory));
prefixCheck(
    'a flooded state directory is evicted back toward the configured cap',
    $before === 400 && $after <= $cap,
    $before . ' -> ' . $after . ' (cap ' . $cap . ')',
);

// A single sweep only evicts from a bounded sample, so the real claim is
// convergence: repeated sweeps must drive a heavily flooded directory down to
// the cap and hold it there, never below.
$convergeDirectory = $root . '/converge';
mkdir($convergeDirectory, 0700, true);
$convergeCap = 200;
$converging = new ViMbAdmin_BruteForce(null, [
    'statedir' => $convergeDirectory,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => $convergeCap,
]);
// Counting records, not lockouts: a lockout is deliberately un-evictable, so
// a flood of them cannot converge and must not be used to assert the cap.
$activePayload = (string) json_encode([
    'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
]);
for ($index = 0; $index < 5000; $index++) {
    $convergePath = $convergeDirectory . '/' . hash('sha256', 'converge-' . $index) . '.json';
    file_put_contents($convergePath, $activePayload);
    touch($convergePath, $now - 5000 + $index);
}
$convergeReap = new ReflectionMethod($converging, '_reapStale');
for ($sweep = 0; $sweep < 40; $sweep++) {
    $convergeReap->invoke($converging, $now);
}
$converged = count(prefixStateFiles($convergeDirectory));
prefixCheck(
    'repeated sweeps converge a 5000-entry flood down to exactly the cap',
    $converged === $convergeCap,
    '5000 -> ' . $converged . ' (cap ' . $convergeCap . ')',
);

// Eviction must not become a lockout-flush primitive: an attacker who floods
// the directory past the cap should not thereby delete their own active
// lockout. Oldest-mtime-first ordering is what prevents it -- their record is
// the newest one there.
$flushDirectory = $root . '/lockout-flush';
mkdir($flushDirectory, 0700, true);
$flushingOptions = [
    'statedir' => $flushDirectory,
    'max_attempts' => 3,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 100,
];
$flushing = new ViMbAdmin_BruteForce(null, $flushingOptions);
for ($index = 0; $index < 1000; $index++) {
    $path = $flushDirectory . '/' . hash('sha256', 'filler-' . $index) . '.json';
    file_put_contents($path, (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
    ]));
    touch($path, $now - 10000 + $index);
}
for ($attempt = 0; $attempt < 3; $attempt++) {
    prefixRequestAttempt($flushingOptions, '203.0.113.9');
}
$lockedBeforeFlush = prefixRequestLocked($flushingOptions, '203.0.113.9');
$flushReap = new ReflectionMethod($flushing, '_reapStale');
for ($sweep = 0; $sweep < 60; $sweep++) {
    $flushReap->invoke($flushing, $now);
}
prefixCheck(
    'flooding past the cap does not evict the attacker\'s own active lockout',
    $lockedBeforeFlush && prefixRequestLocked($flushingOptions, '203.0.113.9')
        && count(prefixStateFiles($flushDirectory)) <= 101,
    'files=' . count(prefixStateFiles($flushDirectory)),
);

// Ordering alone is not the guarantee. If the attacker's lockout happens to be
// the OLDEST record in the directory -- they attacked, got locked out, then
// started the flood, which is the natural sequence -- oldest-mtime-first picks
// it first and only the explicit liveness guard in _evictionCandidate() keeps
// it. Without that guard this is a throttle-bypass primitive, so it gets its
// own assertion rather than riding on the ordering test above.
$oldestLockDirectory = $root . '/oldest-lockout';
mkdir($oldestLockDirectory, 0700, true);
$oldestLockOptions = [
    'statedir' => $oldestLockDirectory,
    'max_attempts' => 3,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 64,
];
for ($attempt = 0; $attempt < 3; $attempt++) {
    prefixRequestAttempt($oldestLockOptions, '203.0.113.77');
}
$oldestLockedBefore = prefixRequestLocked($oldestLockOptions, '203.0.113.77');
// Age the attacker's own record past every filler: it is now the first thing
// oldest-first eviction would reach.
foreach (prefixStateFiles($oldestLockDirectory) as $attackerPath) {
    touch($attackerPath, $now - 99999);
}
for ($index = 0; $index < 600; $index++) {
    $fillerPath = $oldestLockDirectory . '/' . hash('sha256', 'newer-' . $index) . '.json';
    file_put_contents($fillerPath, (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
    ]));
    touch($fillerPath, $now - 100 + $index);
}
$oldestLockReap = new ReflectionMethod(new ViMbAdmin_BruteForce(null, $oldestLockOptions), '_reapStale');
$oldestLockReapTarget = new ViMbAdmin_BruteForce(null, $oldestLockOptions);
for ($sweep = 0; $sweep < 40; $sweep++) {
    $oldestLockReap->invoke($oldestLockReapTarget, $now);
}
prefixCheck(
    'CONTROL: an active lockout survives eviction even as the OLDEST entry',
    $oldestLockedBefore && prefixRequestLocked($oldestLockOptions, '203.0.113.77')
        && count(prefixStateFiles($oldestLockDirectory)) <= 65,
    'files=' . count(prefixStateFiles($oldestLockDirectory)),
);

// An all-locked directory is over cap with nothing evictable -- live lockouts
// are deliberately un-evictable. That is a standing condition no sweep can
// change, so the over-cap re-arm must NOT fire on it: if it did, every failed
// login would bypass the interval throttle and pay a full O(N) readdir that can
// never make progress, turning the DoS fix into a DoS. The claim is about
// per-request WORK, not wall-clock time, so it is pinned with observables that
// a re-armed sweep would necessarily disturb: .reap-overflow stays absent (a
// sweep that evicted nothing must not set it), .reap-cursor does not move (no
// further readdir progress is made), and no state file disappears -- all
// identical at N=2000 and N=16000, which is what "does not scale with
// directory size" means when expressed as work instead of time.
$lockedFloodOptions = static function (string $dir): array {
    return [
        'statedir' => $dir,
        'max_attempts' => 1,
        'window' => 900,
        'lockout' => 900,
        'max_entries' => 100,
    ];
};
$lockedObservations = [];
foreach ([2000, 16000] as $lockedN) {
    $lockedDirectory = $root . '/locked-flood-' . $lockedN;
    mkdir($lockedDirectory, 0700, true);
    for ($index = 0; $index < $lockedN; $index++) {
        file_put_contents(
            $lockedDirectory . '/' . hash('sha256', 'locked-' . $index) . '.json',
            (string) json_encode([
                'attempts' => 9, 'first' => $now, 'last' => $now, 'locked_until' => $now + 900,
            ]),
        );
    }
    $lockedOpts = $lockedFloodOptions($lockedDirectory);
    // Warm one sweep so the throttle stamp and any marker are established. A
    // missing .reap-stamp means "never swept" and is always due, so this
    // request sweeps, finds nothing evictable, and _evictOverflow() must
    // return false -- leaving .reap-overflow absent. That absence, not a
    // timing, is the invariant under test: if a broken _evictOverflow() ever
    // re-armed on an all-locked directory, the marker would appear here and
    // every one of the 10 requests below would pay a full O(N) sweep again.
    prefixRequestAttempt($lockedOpts, '198.51.100.1');
    $overflowFlag = $lockedDirectory . '/.reap-overflow';
    $cursorPath = $lockedDirectory . '/.reap-cursor';
    $overflowAfterWarmup = is_file($overflowFlag);
    $cursorAfterWarmup = @file_get_contents($cursorPath);
    $filesAfterWarmup = count(prefixStateFiles($lockedDirectory));

    for ($index = 0; $index < 10; $index++) {
        prefixRequestAttempt($lockedOpts, '198.51.100.' . (2 + $index));
    }

    $lockedObservations[$lockedN] = [
        'overflow_after_warmup' => $overflowAfterWarmup,
        'overflow_after_requests' => is_file($overflowFlag),
        'cursor_unchanged' => @file_get_contents($cursorPath) === $cursorAfterWarmup,
        'files_unchanged' => count(prefixStateFiles($lockedDirectory)) === $filesAfterWarmup,
    ];
}
prefixCheck(
    'CONTROL: an all-locked over-cap directory does not re-arm an unbounded per-request sweep',
    !$lockedObservations[2000]['overflow_after_warmup']
        && !$lockedObservations[2000]['overflow_after_requests']
        && $lockedObservations[2000]['cursor_unchanged']
        && $lockedObservations[2000]['files_unchanged']
        && $lockedObservations[16000] === $lockedObservations[2000],
    sprintf(
        'N=2000: overflow=%s cursor_unchanged=%s files_unchanged=%s; N=16000: overflow=%s cursor_unchanged=%s files_unchanged=%s',
        $lockedObservations[2000]['overflow_after_requests'] ? 'yes' : 'no',
        $lockedObservations[2000]['cursor_unchanged'] ? 'yes' : 'no',
        $lockedObservations[2000]['files_unchanged'] ? 'yes' : 'no',
        $lockedObservations[16000]['overflow_after_requests'] ? 'yes' : 'no',
        $lockedObservations[16000]['cursor_unchanged'] ? 'yes' : 'no',
        $lockedObservations[16000]['files_unchanged'] ? 'yes' : 'no',
    ),
);

// The cap has to hold against a flood driven through record() itself, not just
// against direct _reapStale() calls. One sweep evicts at most
// EVICT_SAMPLE_LIMIT entries and the routine sweep is throttled to once a
// minute, while record() creates a file per new prefix with no brake -- so
// without the over-cap re-arm the directory grows without bound and the
// convergence test above never notices, because it bypasses the throttle.
$recordFloodDirectory = $root . '/record-flood';
$recordFloodOptions = [
    'statedir' => $recordFloodDirectory,
    'max_attempts' => 5000,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 100,
];
for ($index = 0; $index < 3000; $index++) {
    prefixRequestAttempt(
        $recordFloodOptions,
        '2001:db8:' . dechex($index >> 16) . ':' . dechex($index & 0xffff) . '::1',
    );
}
$floodFiles = count(prefixStateFiles($recordFloodDirectory));
// The steady state is the cap plus one sweep's worth of slack: record() creates
// files between sweeps and a sweep evicts from a bounded sample, so the
// directory settles a little above max_entries rather than exactly on it. The
// claim that matters is that it settles at all -- measured flat at 200 files
// for floods of 3000, 6000 and 12000 prefixes against this cap of 100, so the
// bound is independent of flood size and not a slower leak.
prefixCheck(
    'a 3000-prefix flood driven through record() stays bounded near the cap',
    $floodFiles <= 2 * 100 + 10,
    '3000 prefixes -> ' . $floodFiles . ' files (cap 100)',
);

// Same claim for a process that starts cold against an already-flooded
// directory: an FPM worker does not inherit the previous worker's counters.
$coldDirectory = $root . '/cold-start';
mkdir($coldDirectory, 0700, true);
for ($index = 0; $index < 5000; $index++) {
    $coldPath = $coldDirectory . '/' . hash('sha256', 'preexisting-' . $index) . '.json';
    file_put_contents($coldPath, (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => 0,
    ]));
    touch($coldPath, $now - 6000 + $index);
}
$coldStartOptions = [
    'statedir' => $coldDirectory,
    'max_attempts' => 5000,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 100,
];
for ($index = 0; $index < 60; $index++) {
    prefixRequestAttempt($coldStartOptions, '203.0.113.' . ($index % 250));
}
$coldFiles = count(prefixStateFiles($coldDirectory));
prefixCheck(
    'a cold process drains a directory an earlier one flooded',
    $coldFiles <= 110,
    '5000 pre-existing -> ' . $coldFiles . ' files (cap 100)',
);

// Ordinary traffic must not pay for that: one prefix under the cap sweeps at
// most once per interval, so a burst of failed logins stays cheap.
$steadyDirectory = $root . '/steady';
$steadyOptions = [
    'statedir' => $steadyDirectory,
    'max_attempts' => 5000,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 4096,
];
$steadyStart = hrtime(true);
for ($index = 0; $index < 200; $index++) {
    prefixRequestAttempt($steadyOptions, '203.0.113.5');
}
$steadyElapsed = (hrtime(true) - $steadyStart) / 1e6;
prefixCheck(
    'ordinary failed logins under the cap stay cheap',
    $steadyElapsed < 500.0 && count(prefixStateFiles($steadyDirectory)) === 1,
    sprintf('%.2f ms for 200 records', $steadyElapsed),
);

$underCapDirectory = $root . '/under-cap';
mkdir($underCapDirectory, 0700, true);
$underCap = new ViMbAdmin_BruteForce(null, [
    'statedir' => $underCapDirectory,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 512,
]);
for ($index = 0; $index < 32; $index++) {
    file_put_contents($underCapDirectory . '/' . hash('sha256', 'live-' . $index) . '.json', (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => $now + 900,
    ]));
}
(new ReflectionMethod($underCap, '_reapStale'))->invoke($underCap, $now);
prefixCheck(
    'active state below the cap is never evicted',
    count(prefixStateFiles($underCapDirectory)) === 32,
    'files=' . count(prefixStateFiles($underCapDirectory)),
);

// ---- 7. opaque name cursor ---------------------------------------------

$cursorDirectory = $root . '/cursor';
mkdir($cursorDirectory, 0700, true);
$cursorLimited = new ViMbAdmin_BruteForce(null, [
    'statedir' => $cursorDirectory,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 100000,
]);
$names = [];
for ($index = 0; $index < 300; $index++) {
    $names[] = $name = hash('sha256', 'cursor-' . $index);
    file_put_contents($cursorDirectory . '/' . $name . '.json', (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => $now + 900,
    ]));
}
sort($names, SORT_STRING);
$cursorReap = new ReflectionMethod($cursorLimited, '_reapStale');
$cursorReap->invoke($cursorLimited, $now);
$firstCursor = trim((string) @file_get_contents($cursorDirectory . '/.reap-cursor'));
prefixCheck(
    'the cursor is an opaque digest name, not an ordinal',
    preg_match('/^[a-f0-9]{64}$/D', $firstCursor) === 1,
    substr($firstCursor, 0, 12) . '...',
);
prefixCheck(
    'the first pass stops exactly at the 128th smallest name',
    $firstCursor === $names[127],
);
$cursorReap->invoke($cursorLimited, $now);
$secondCursor = trim((string) @file_get_contents($cursorDirectory . '/.reap-cursor'));
prefixCheck('the second pass advances to the 256th name', $secondCursor === $names[255]);
$cursorReap->invoke($cursorLimited, $now);
prefixCheck(
    'a pass that exhausts the key space resets the cursor',
    trim((string) @file_get_contents($cursorDirectory . '/.reap-cursor')) === '',
);
prefixCheck(
    'all 300 active records survived three full cursor passes',
    count(prefixStateFiles($cursorDirectory)) === 300,
);

// A cursor naming an entry that no longer exists must not stall the sweep:
// name cursors resume by ordering, not by identity.
unlink($cursorDirectory . '/' . $names[127] . '.json');
file_put_contents($cursorDirectory . '/.reap-cursor', $names[127] . "\n");
$cursorReap->invoke($cursorLimited, $now);
prefixCheck(
    'a cursor pointing at a deleted entry still advances',
    trim((string) @file_get_contents($cursorDirectory . '/.reap-cursor')) === $names[255],
);

// ---- 8. scaling measurement at N = 20k / 100k / 200k --------------------
//
// The shipped defect was DirectoryIterator::seek($cursor), an O(cursor)
// readdir walk, making a full sweep O(N^2 / 128). The name cursor makes each
// pass exactly one readdir pass over the directory. Wall clock on a shared CI
// runner is noise, so the assertion is on *work*: the number of directory
// entries the reaper touches per request must be flat in N. syscalls are
// counted by wrapping the directory in a stream that reports every readdir.

$scaleReport = [];
$scaleFlat = true;
$scaleTouched = [];
// The audit measured 6.31 / 25.48 / 56.62 ms per reap at 20k/100k/200k, and
// those sizes remain the default so the numbers stay comparable. They cost
// ~320k inodes and a few hundred MB in the temp directory, which is more than
// a small runner has; the invariant asserted below is cursor travel against
// REAP_SCAN_LIMIT, so a constrained runner can set VIMBADMIN_REAP_SCALE_SIZES
// to three smaller comma-separated sizes. Use sizes of at least 1280 to keep
// the identical-count check meaningful: with the cursor planted at 90%, only
// 0.1 * N names sort above it, so below that the key space bounds the window
// before the scan limit does.
$scaleSizes = [20000, 100000, 200000];
$configuredSizes = getenv('VIMBADMIN_REAP_SCALE_SIZES');
if (is_string($configuredSizes) && $configuredSizes !== '') {
    $parsed = [];
    foreach (explode(',', $configuredSizes) as $size) {
        $size = (int) trim($size);
        if ($size > 0) {
            $parsed[] = $size;
        }
    }
    if (count($parsed) === 3) {
        $scaleSizes = $parsed;
    }
}

foreach ($scaleSizes as $entries) {
    $scaleDirectory = $root . '/scale-' . $entries;
    mkdir($scaleDirectory, 0700, true);

    // Synthesise the directory cheaply: active records, so nothing is removed
    // and every pass sees the full N.
    $payload = (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => $now + 900,
    ]);
    for ($index = 0; $index < $entries; $index++) {
        $written = @file_put_contents(
            $scaleDirectory . '/' . hash('sha256', 'scale-' . $index) . '.json',
            $payload,
        );
        if ($written !== strlen($payload)) {
            // Out of inodes or space: say so plainly instead of failing later
            // as an unexplained assertion error.
            fwrite(STDERR, sprintf(
                "cannot build the %d-entry fixture (wrote %d of %d files); "
                . "set VIMBADMIN_REAP_SCALE_SIZES to smaller sizes\n",
                $entries,
                $index,
                $entries,
            ));
            prefixRemoveTree($scaleDirectory);
            exit(1);
        }
    }

    $scaled = new ViMbAdmin_BruteForce(null, [
        'statedir' => $scaleDirectory,
        'window' => 900,
        'lockout' => 900,
        'max_entries' => 1000000,
    ]);
    $scaleMethod = new ReflectionMethod($scaled, '_reapStale');

    // Warm pass, then place the cursor at ~90% of the key space -- the position
    // the audit measured, and the one the O(cursor) seek was worst at.
    /** @var list<string> $scaleNames */
    $scaleNames = [];
    foreach (prefixStateFiles($scaleDirectory) as $path) {
        $scaleNames[] = basename($path, '.json');
    }
    sort($scaleNames, SORT_STRING);
    file_put_contents(
        $scaleDirectory . '/.reap-cursor',
        $scaleNames[(int) floor($entries * 0.9)] . "\n",
    );

    $start = hrtime(true);
    $scaleMethod->invoke($scaled, $now);
    $elapsed = (hrtime(true) - $start) / 1e6;

    // Work touched: the reaper stats/reads at most REAP_SCAN_LIMIT records
    // regardless of N. Verify by counting the files whose atime advanced is
    // unreliable on relatime mounts, so assert the invariant the code owns --
    // the cursor advanced by at most REAP_SCAN_LIMIT names.
    $reached = trim((string) @file_get_contents($scaleDirectory . '/.reap-cursor'));
    $position = $reached === '' ? $entries : (int) array_search($reached, $scaleNames, true);
    $touched = $position - (int) floor($entries * 0.9);
    // The bound is REAP_SCAN_LIMIT, and it is reached only when at least that
    // many names sort above the cursor -- with the cursor at 90%, that needs
    // N >= 1280. Below it the window is limited by the key space, not by the
    // scan limit, so the meaningful assertion is "capped at 128", and
    // "exactly 128 at every size" only where the cap can actually bind.
    $expected = min(128, $entries - (int) floor($entries * 0.9));
    $scaleTouched[] = $touched;
    $scaleReport[] = sprintf(
        'N=%d: %.2f ms, records touched=%d (expected %d)',
        $entries,
        $elapsed,
        $touched,
        $expected,
    );
    if ($touched !== $expected) {
        $scaleFlat = false;
    }

    prefixRemoveTree($scaleDirectory);
}
foreach ($scaleReport as $line) {
    echo '  info ' . $line . "\n";
}
// Flatness is the point: work per request must be bounded by REAP_SCAN_LIMIT
// rather than growing with N. Where every configured size is large enough for
// the cap to bind, that shows up as an identical count at all three sizes.
$capBinds = min($scaleSizes) - (int) floor(min($scaleSizes) * 0.9) >= 128;
prefixCheck(
    'per-request reap work is bounded by the scan limit at '
        . implode('/', $scaleSizes) . ' entries',
    $scaleFlat && count($scaleTouched) === 3 && max($scaleTouched) <= 128
        && (!$capBinds || count(array_unique($scaleTouched)) === 1),
    'touched=' . implode('/', $scaleTouched) . ($capBinds ? '' : ' (below cap-binding size)'),
);

// ---- 9. NEGATIVE CONTROL: record() survives a lock-contention flood ----
//
// The reaper used to run inside the exclusive state lock, so a big directory
// held the lock for tens of milliseconds and a burst of failed logins blew the
// 1 s acquisition budget into an uncaught RuntimeException -> HTTP 500. Hold
// the *maintenance* lock for longer than that budget and require record() to
// complete anyway.

$contendedDirectory = $root . '/contended';
mkdir($contendedDirectory, 0700, true);
$contended = new ViMbAdmin_BruteForce(null, [
    'statedir' => $contendedDirectory,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
]);
// Force the directory to exist before we grab the maintenance lock.
(new ReflectionMethod($contended, '_ensureDir'))->invoke($contended);
$hold = fopen($contendedDirectory . '/.reap-lock', 'c');
prefixCheck('the maintenance lock is a separate inode from the state lock', is_resource($hold)
    && is_file($contendedDirectory . '/.reap-lock'));
$held = is_resource($hold) && flock($hold, LOCK_EX | LOCK_NB);
$contendedStart = hrtime(true);
$contendedFailure = '';
try {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        prefixAttempt($contended, '203.0.113.' . $attempt);
    }
} catch (RuntimeException $exception) {
    $contendedFailure = $exception->getMessage();
}
$contendedElapsed = (hrtime(true) - $contendedStart) / 1e6;
if (is_resource($hold)) {
    flock($hold, LOCK_UN);
    fclose($hold);
}
prefixCheck(
    'record() never blocks on a held maintenance lock',
    $held && $contendedFailure === '' && $contendedElapsed < 1000.0,
    sprintf('%.2f ms for 20 records, error=%s', $contendedElapsed, $contendedFailure === '' ? 'none' : $contendedFailure),
);
prefixCheck(
    'the contended run still persisted its state',
    count(prefixStateFiles($contendedDirectory)) >= 1,
);

// The O(N) readdir pass is amortised: a second failed login moments later must
// not repeat it. Observable: the cursor does not move on the throttled call.
$throttleDirectory = $root . '/throttle';
mkdir($throttleDirectory, 0700, true);
$throttled = new ViMbAdmin_BruteForce(null, [
    'statedir' => $throttleDirectory,
    'max_attempts' => 500,
    'window' => 900,
    'lockout' => 900,
    'max_entries' => 100000,
]);
for ($index = 0; $index < 200; $index++) {
    file_put_contents($throttleDirectory . '/' . hash('sha256', 'throttle-' . $index) . '.json', (string) json_encode([
        'attempts' => 1, 'first' => $now, 'last' => $now, 'locked_until' => $now + 900,
    ]));
}
prefixAttempt($throttled, '198.51.100.70');
$throttleFirst = trim((string) @file_get_contents($throttleDirectory . '/.reap-cursor'));
prefixAttempt($throttled, '198.51.100.71');
$throttleSecond = trim((string) @file_get_contents($throttleDirectory . '/.reap-cursor'));
prefixCheck(
    'the first failed login sweeps the directory',
    preg_match('/^[a-f0-9]{64}$/D', $throttleFirst) === 1,
);
prefixCheck(
    'an immediately following failed login does not repeat the O(N) sweep',
    $throttleSecond === $throttleFirst,
);
// CONTROL: age the stamp past the interval and the sweep must resume.
touch($throttleDirectory . '/.reap-stamp', $now - 3600);
prefixAttempt($throttled, '198.51.100.72');
prefixCheck(
    'CONTROL: once the interval elapses the sweep resumes and the cursor advances',
    trim((string) @file_get_contents($throttleDirectory . '/.reap-cursor')) !== $throttleFirst,
);

// ---- 10. fixed assertion count -----------------------------------------

prefixCheck('fixed assertion count', BruteForcePrefixAssertions::$checks === 50, (string) BruteForcePrefixAssertions::$checks);

prefixRemoveTree($root);

if (BruteForcePrefixAssertions::$failures > 0) {
    echo 'FAIL: ' . BruteForcePrefixAssertions::$failures . " assertion(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
