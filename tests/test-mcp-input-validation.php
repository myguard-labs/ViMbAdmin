<?php
/**
 * Regression test for the 2026-07-10 audit MAJOR: MCP create abilities must
 * enforce the SAME local_part / hostname shape the web forms enforce, so a
 * crafted value cannot traverse the Dovecot maildir/backup paths
 * (QueueRunner::backupDest '%d/%u', removeMaildirHome). This locks the
 * validators the MCP path reuses; if `Validators::localPart()`/`hostname()`
 * ever loosen to admit path separators / '..', this fails.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Form/Validators.php';

use ViMbAdmin\Kernel\Form\Validators;

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) { $fail++; }
}

$lp = Validators::localPart();
$hn = Validators::hostname();

// local_part: reject every maildir-path-hostile shape.
$badLocal = ['../../etc', '..', 'a/b', 'a\\b', '.foo', 'foo.', 'a..b', "a\nb", "a\0b", str_repeat('a', 65)];
foreach ($badLocal as $v) {
    check("localPart rejects " . json_encode($v), $lp($v) !== null);
}
// local_part: accept legitimate values.
foreach (['john', 'john.doe', 'a+b', "o'brien", 'x_y-z'] as $v) {
    check("localPart accepts " . json_encode($v), $lp($v) === null);
}

// hostname: reject traversal / separators / '@' / spaces.
foreach (['../evil', 'a/b', 'a b', 'x@y', '-lead.com', 'trail-.com', 'nodot'] as $v) {
    check("hostname rejects " . json_encode($v), $hn($v) !== null);
}
foreach (['example.com', 'a.b.co.uk', 'x-1.example.org'] as $v) {
    check("hostname accepts " . json_encode($v), $hn($v) === null);
}

if ($fail === 0) {
    echo "OK: all MCP input-validation assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAILED: {$fail} assertion(s)\n";
exit(1);
