<?php
/**
 * Regression test for the 2026-07-10 audit MAJOR (defence-in-depth half):
 * QueueRunner::assertPathSafe() must reject any username/domain component that
 * could escape the maildir/backup jail before it is substituted into a
 * filesystem path (backupDest '%d/%u', removeMaildirHome). Guards against a
 * legacy or externally-inserted row that bypassed create-time validation.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';

$m = new ReflectionMethod('ViMbAdmin_Service_QueueRunner', 'assertPathSafe');
$m->setAccessible(true);

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) { $fail++; }
}

$rejects = static function (string $v) use ($m): bool {
    try { $m->invoke(null, $v); return false; }
    catch (\Throwable $e) { return true; }
};

// Traversal / separator / null shapes must be refused.
foreach (['../../../../etc/cron.d/x@d.com', 'a/b@d.com', '..', 'a..b@d.com', "x\0y", ''] as $v) {
    check('rejects ' . json_encode($v), $rejects($v));
}
// A legitimate username (no '/', no '..') passes through unchanged.
foreach (['john.doe@example.com', 'a+b@sub.example.org'] as $v) {
    check('accepts ' . json_encode($v), !$rejects($v) && $m->invoke(null, $v) === $v);
}

if ($fail === 0) {
    echo "OK: all QueueRunner path-safety assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAILED: {$fail} assertion(s)\n";
exit(1);
