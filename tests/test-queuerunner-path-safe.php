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
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';

$m = new ReflectionMethod('ViMbAdmin_Service_QueueRunner', 'assertPathSafe');
$m->setAccessible(true);

final class TestQueuerunnerPathSafeHarnessState
{
    public static int $count = 0;
}

final class TestQueuerunnerNoIoSpy
{
    public int $calls = 0;

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): never
    {
        $this->calls++;
        throw new RuntimeException('unexpected side effect: ' . $name);
    }
}

$fail = & TestQueuerunnerPathSafeHarnessState::$count;
function check(string $name, bool $ok): void
{

    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        TestQueuerunnerPathSafeHarnessState::$count++;
    }
}

$rejects = static function (mixed $v) use ($m): bool {
    try {
        $m->invoke(null, $v);
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};

// Traversal / separator / null shapes must be refused.
foreach (['../../../../etc/cron.d/x@d.com', 'a/b@d.com', '..', 'a..b@d.com', "x\0y", ''] as $v) {
    check('rejects ' . json_encode($v), $rejects($v));
}
// A legitimate username (no '/', no '..') passes through unchanged.
foreach (['john.doe@example.com', 'a+b@sub.example.org'] as $v) {
    check('accepts ' . json_encode($v), !$rejects($v) && $m->invoke(null, $v) === $v);
}
check('rejects container path components before string conversion', $rejects(['user@example.test']));

$requiredUsernameError = null;
try {
    (new \Entities\MailboxTask())->requiredUsername();
} catch (\Throwable $e) {
    $requiredUsernameError = $e->getMessage();
}
check(
    'uninitialized task username fails before queue side effects',
    $requiredUsernameError === 'Mailbox task username cannot be null.'
);

$positive = new ReflectionMethod('ViMbAdmin_Service_QueueRunner', 'positiveInteger');
$positive->setAccessible(true);
$positiveRejects = static function (mixed $value) use ($positive): bool {
    try {
        $positive->invoke(null, $value, 'Queue drain maximum');
        return false;
    } catch (\Throwable $e) {
        return true;
    }
};
check(
    'queue limit preserves canonical positive integers',
    $positive->invoke(null, 2, 'Queue drain maximum') === 2
        && $positive->invoke(null, '3', 'Queue drain maximum') === 3
);
check(
    'queue limit rejects lossy or non-positive coercion',
    $positiveRejects(0) && $positiveRejects('2junk') && $positiveRejects(['2'])
);

$runner = (new ReflectionClass('ViMbAdmin_Service_QueueRunner'))->newInstanceWithoutConstructor();
$options = new ReflectionProperty($runner, 'options');
$options->setValue($runner, ['doveadm' => ['backup' => ['dest' => 'maildir:/backups/%d/%u']]]);
$backupDest = new ReflectionMethod($runner, 'backupDest');
$withoutDomain = (new \Entities\MailboxTask())->setUsername('user@example.test');
check(
    'absent optional domain uses the validated username suffix',
    $backupDest->invoke($runner, $withoutDomain) === 'maildir:/backups/example.test/user@example.test'
);
$namedDomain = (new \Entities\Domain())->setDomain('domain.example');
$withDomain = (new \Entities\MailboxTask())->setUsername('user@example.test')->setDomain($namedDomain);
check(
    'present domain uses its required initialized name',
    $backupDest->invoke($runner, $withDomain) === 'maildir:/backups/domain.example/user@example.test'
);
$unnamedDomain = new \Entities\Domain();
$malformed = (new \Entities\MailboxTask())->setUsername('user@example.test')->setDomain($unnamedDomain);
$malformedError = null;
try {
    $backupDest->invoke($runner, $malformed);
} catch (\Throwable $e) {
    $malformedError = $e->getPrevious()?->getMessage() ?? $e->getMessage();
}
check(
    'present malformed domain never falls back to the username suffix',
    $malformedError === 'Domain name cannot be null.'
);

$options->setValue($runner, ['queue' => ['runner' => ['max_concurrent' => '4'], 'autoprune' => ['days' => '30']]]);
$leaseOptions = new ReflectionMethod($runner, 'leaseOptions');
$autopruneDays = new ReflectionMethod($runner, 'autopruneDays');
$maildirRoot = new ReflectionMethod($runner, 'maildirRoot');
check(
    'validated queue configuration reaches exact downstream shapes',
    $leaseOptions->invoke($runner) === ['queue' => ['runner' => ['max_concurrent' => 4]]]
        && $autopruneDays->invoke($runner) === 30
);
$options->setValue($runner, ['queue' => ['autoprune' => ['days' => null]]]);
$nullConfigRejected = false;
try {
    $autopruneDays->invoke($runner);
} catch (\Throwable $e) {
    $nullConfigRejected = true;
}
check('explicit null queue configuration cannot masquerade as absent', $nullConfigRejected);

foreach (['', '/', 'relative/maildir', "bad\0root"] as $unsafeRoot) {
    $options->setValue($runner, ['doveadm' => ['maildir_root' => $unsafeRoot]]);
    $rootRejected = false;
    try {
        $maildirRoot->invoke($runner);
    } catch (\Throwable $e) {
        $rootRejected = true;
    }
    check('rejects unsafe maildir root ' . json_encode($unsafeRoot), $rootRejected);
}
$options->setValue($runner, ['doveadm' => ['maildir_root' => '/srv/maildir/']]);
check('normalizes an absolute non-root maildir root', $maildirRoot->invoke($runner) === '/srv/maildir');

$em = new TestQueuerunnerNoIoSpy();
$doveadm = new TestQueuerunnerNoIoSpy();
(new ReflectionProperty($runner, 'em'))->setValue($runner, $em);
$backupOrphan = new ReflectionMethod($runner, 'backupOrphan');
foreach ([
    [(new \Entities\MailboxTask())->setUsername('../escape@example.test'), '/srv/maildir'],
    [(new \Entities\MailboxTask())->setUsername('user@example.test'), '/'],
] as [$unsafeTask, $configuredRoot]) {
    $options->setValue($runner, ['doveadm' => ['maildir_root' => $configuredRoot]]);
    try {
        $backupOrphan->invoke($runner, $unsafeTask, $doveadm);
    } catch (\Throwable $e) {
    }
}
check(
    'orphan identity and root validation precede every database or Doveadm side effect',
    $em->calls === 0 && $doveadm->calls === 0
);

if ($fail === 0) {
    echo "OK: all QueueRunner path-safety assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAILED: {$fail} assertion(s)\n";
exit(1);
