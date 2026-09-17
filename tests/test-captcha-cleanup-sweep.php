<?php

declare(strict_types=1);

/**
 * VIM-D11: OSS_Captcha_Image::cleanup() runs on every generate() call, a
 * pre-auth, unauthenticated-reachable path. This pins the amortisation added
 * on top of it:
 *  - a fresh marker (swept recently) skips the filesystem sweep entirely;
 *  - a stale/missing marker still sweeps and removes expired captcha files;
 *  - the marker is confined to the captcha directory and cannot suppress
 *    cleanup indefinitely (a corrupt/unwritable marker degrades to "always
 *    sweep", not "never sweep").
 * Captcha expiry enforcement itself (_isValid()'s timeout check) is untouched
 * by this change and is covered separately by test-captcha.php.
 */

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$root = sys_get_temp_dir() . '/vimbadmin-captcha-sweep-test-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);

$cleanup = new ReflectionMethod(OSS_Captcha_Image::class, 'cleanup');
$dueForSweep = new ReflectionMethod(OSS_Captcha_Image::class, 'dueForSweep');
$instance = (new ReflectionClass(OSS_Captcha_Image::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(OSS_Captcha_Image::class, 'timeout'))->setValue($instance, 1800);

echo "== captcha cleanup sweep amortisation ==\n";

// ---- a stale/missing marker sweeps and removes expired files ----------- //
$expired = $root . '/' . str_repeat('a', 32) . '.png';
file_put_contents($expired, 'expired');
touch($expired, time() - 3600); // older than the 1800s timeout cutoff

$check('no marker present yet', !file_exists($root . '/.sweep'));
$check(
    'cleanup() with no marker sweeps and removes the expired file',
    ($cleanup->invoke($instance, $root) === null) && !file_exists($expired)
);
$check('cleanup() creates the sweep marker in the captcha directory', file_exists($root . '/.sweep'));

// ---- a fresh marker suppresses the sweep on the next request ----------- //
$expired2 = $root . '/' . str_repeat('b', 32) . '.png';
file_put_contents($expired2, 'expired');
touch($expired2, time() - 3600);

$check('dueForSweep() is false immediately after a sweep', $dueForSweep->invoke(null, $root) === false);
$cleanup->invoke($instance, $root);
$check(
    'a fresh request does not sweep: expired file from before the marker survives',
    file_exists($expired2)
);

// ---- a stale marker sweeps again, so cleanup still happens eventually -- //
touch($root . '/.sweep', time() - 3600); // force the marker stale
$check(
    'dueForSweep() is true once the marker is older than the interval',
    $dueForSweep->invoke(null, $root) === true
);
// dueForSweep() above already re-touched the marker as a side effect; reset
// it stale again so cleanup() itself is exercised against a stale marker.
touch($root . '/.sweep', time() - 3600);
$cleanup->invoke($instance, $root);
$check('a stale marker still removes expired files eventually', !file_exists($expired2));

// ---- a corrupt/unwritable marker degrades to "always sweep" ------------ //
$lockedRoot = sys_get_temp_dir() . '/vimbadmin-captcha-sweep-locked-' . bin2hex(random_bytes(6));
mkdir($lockedRoot, 0770, true);
$lockedMarker = $lockedRoot . '/.sweep';
mkdir($lockedMarker); // a directory in place of the marker: filemtime()/touch() semantics differ
$check(
    'a directory at the marker path is never read as a fresh marker (fail open, not closed)',
    $dueForSweep->invoke(null, $lockedRoot) === true
);

// ---- a backward clock jump does not extend the throttle indefinitely --- //
$skewRoot = sys_get_temp_dir() . '/vimbadmin-captcha-sweep-skew-' . bin2hex(random_bytes(6));
mkdir($skewRoot, 0770, true);
touch($skewRoot . '/.sweep', time() + 3600); // marker stamped "in the future"
$check(
    'a future-stamped marker (backward clock jump) is treated as due, not fresh',
    $dueForSweep->invoke(null, $skewRoot) === true
);

// ---- MAX_FILES eviction is never throttled, even with a fresh marker --- //
// The expiry scan is amortised, but the disk-exhaustion cap is not: a flood
// during the throttled window must still be capped every request.
$capRoot = sys_get_temp_dir() . '/vimbadmin-captcha-sweep-cap-' . bin2hex(random_bytes(6));
mkdir($capRoot, 0770, true);
for ($i = 0; $i < 501; $i++) {
    file_put_contents($capRoot . '/' . str_pad((string) $i, 32, '0', STR_PAD_LEFT) . '.png', 'x');
}
$cleanup->invoke($instance, $capRoot); // no marker yet: this call also sweeps
$check(
    'a fresh directory over the file cap is trimmed even on the first (sweeping) call',
    count(glob($capRoot . '/*.png') ?: []) <= 500
);

// A second, immediate call has a fresh marker (sweep throttled) but the flood
// continues; the cap must still hold.
for ($i = 501; $i < 520; $i++) {
    file_put_contents($capRoot . '/' . str_pad((string) $i, 32, '0', STR_PAD_LEFT) . '.png', 'x');
}
$check('marker is fresh for the follow-up call', $dueForSweep->invoke(null, $capRoot) === false);
$cleanup->invoke($instance, $capRoot);
$check(
    'the file cap still holds while the expiry sweep is throttled',
    count(glob($capRoot . '/*.png') ?: []) <= 500
);

foreach (glob($root . '/*.png') ?: [] as $file) {
    @unlink($file);
}
foreach (glob($capRoot . '/*.png') ?: [] as $file) {
    @unlink($file);
}
@unlink($root . '/.sweep');
@unlink($skewRoot . '/.sweep');
@unlink($capRoot . '/.sweep');
@rmdir($root);
@rmdir($lockedMarker);
@rmdir($lockedRoot);
@rmdir($skewRoot);
@rmdir($capRoot);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
