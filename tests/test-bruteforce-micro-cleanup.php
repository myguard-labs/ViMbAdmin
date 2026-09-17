<?php

declare(strict_types=1);

/**
 * VIM-D12a: micro cleanups in ViMbAdmin_BruteForce's private state persistence
 * (_load/_save/_delete) -- dropping file_exists() guards that were immediately
 * followed by an operation which already reports absence, and dropping the
 * redundant LOCK_EX flag on file_put_contents() writes to a private per-pid
 * temp path that is then atomically rename()d. No locking semantics on the
 * shared state files (the flock() calls in _withLock()) changed.
 *
 * This pins the *behaviour* the cleanup must not disturb:
 *  - state read/write round-trips exactly;
 *  - a missing state file still behaves as before (_load returns the zero
 *    default, _delete is a silent no-op);
 *  - a corrupt state file still throws LogicException, not something new.
 */

require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/support/bruteforce-state-path.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$statedir = sys_get_temp_dir() . '/vimbadmin-bruteforce-micro-' . bin2hex(random_bytes(6));
mkdir($statedir, 0770, true);

$bruteForce = new ViMbAdmin_BruteForce(null, [
    'statedir' => $statedir,
    'max_attempts' => 5,
    'window' => 3600,
]);

$load = new ReflectionMethod($bruteForce, '_load');
$save = new ReflectionMethod($bruteForce, '_save');
$delete = new ReflectionMethod($bruteForce, '_delete');
$fileMethod = new ReflectionMethod($bruteForce, '_file');

$ip = '203.0.113.5';
$stateFilePath = $fileMethod->invoke($bruteForce, $ip);
if (!is_string($stateFilePath)) {
    throw new RuntimeException('_file() did not return a string path');
}
$stateFile = $stateFilePath;

echo "== bruteforce state persistence micro-cleanup ==\n";

// ---- missing state file behaves as before ------------------------------ //
$check('no state file exists yet', !file_exists($stateFile));
$default = $load->invoke($bruteForce, $ip);
$check(
    '_load() with no file returns the zero-valued default record',
    $default === ['attempts' => 0, 'first' => 0, 'last' => 0, 'locked_until' => 0]
);

$deleteThrew = false;
try {
    $delete->invoke($bruteForce, $ip);
} catch (Throwable $e) {
    $deleteThrew = true;
}
$check('_delete() on a missing file is a silent no-op (does not throw)', !$deleteThrew);

// ---- round-trip: save then load returns exactly what was saved --------- //
$record = ['attempts' => 3, 'first' => 1000, 'last' => 2000, 'locked_until' => 0];
$save->invoke($bruteForce, $ip, $record);
$check('_save() leaves no leftover .tmp file', glob($stateFile . '.*.tmp') === []);
$check('_save() writes the state file', file_exists($stateFile));

$roundTripped = $load->invoke($bruteForce, $ip);
$check('_load() after _save() round-trips the exact record', $roundTripped === $record);

// ---- overwrite round-trips too (rename() replaces the prior file) ------ //
$record2 = ['attempts' => 5, 'first' => 1000, 'last' => 3000, 'locked_until' => 9999999999];
$save->invoke($bruteForce, $ip, $record2);
$check('_save() overwrite round-trips the new record', $load->invoke($bruteForce, $ip) === $record2);

// ---- _delete() removes an existing state file --------------------------- //
$delete->invoke($bruteForce, $ip);
$check('_delete() removes an existing state file', !file_exists($stateFile));
$check(
    '_load() after _delete() returns the zero-valued default again',
    $load->invoke($bruteForce, $ip) === ['attempts' => 0, 'first' => 0, 'last' => 0, 'locked_until' => 0]
);

// ---- a corrupt state file still throws LogicException, unchanged ------- //
file_put_contents($stateFile, 'not json');
$corruptThrew = null;
try {
    $load->invoke($bruteForce, $ip);
} catch (Throwable $e) {
    $corruptThrew = $e::class;
}
$check(
    '_load() on a corrupt file still throws LogicException',
    $corruptThrew === LogicException::class
);

@unlink($stateFile);
// GLOB_NOSORT has no bearing here; plain glob() does not return dotfiles, and
// _notePrefixCreated() writes .reap-growth alongside the state files.
@unlink($statedir . '/.reap-growth');
foreach (glob($statedir . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($statedir);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
