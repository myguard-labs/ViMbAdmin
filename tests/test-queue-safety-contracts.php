<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Setting.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

final class QueueSafetyConnection
{
    /** @var list<int|Throwable> */
    public array $results;
    /** @var list<array{string,array<int,mixed>}> */
    public array $calls = [];

    /** @param list<int|Throwable> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }
    /** @param array<int,mixed> $params */
    public function executeStatement(string $sql, array $params): int
    {
        $this->calls[] = [$sql, $params];
        $result = array_shift($this->results);
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_int($result)) {
            throw new RuntimeException('missing fake result');
        }
        return $result;
    }
}

final class QueueSafetyEntityManager
{
    public function __construct(private QueueSafetyConnection $connection)
    {
    }
    public function getConnection(): QueueSafetyConnection
    {
        return $this->connection;
    }
}

echo "== queue safety contracts ==\n";
$cutoff = new DateTimeImmutable('2026-09-03T00:00:00+00:00');
$now = new DateTimeImmutable('2026-09-03T08:00:00+00:00');
$claimTimestamp = new ReflectionMethod(ViMbAdmin_Setting::class, 'claimTimestamp');

$updated = new QueueSafetyConnection([1]);
$check(
    'an expired timestamp is claimed with one conditional update',
    $claimTimestamp->invoke(null, new QueueSafetyEntityManager($updated), 'gate', $cutoff, $now) === true
    && count($updated->calls) === 1
    && str_contains($updated->calls[0][0], 'CAST(value AS UNSIGNED) < ?')
    && $updated->calls[0][1] === [(string) $now->getTimestamp(), 'gate', $cutoff->getTimestamp()]
);

$inserted = new QueueSafetyConnection([0, 1]);
$check(
    'an absent timestamp is claimed with a race-safe insert-ignore',
    $claimTimestamp->invoke(null, new QueueSafetyEntityManager($inserted), 'gate', $cutoff, $now) === true
    && count($inserted->calls) === 2
    && str_contains($inserted->calls[1][0], 'INSERT IGNORE')
);

$lost = new QueueSafetyConnection([0, 0]);
$check(
    'a concurrent timestamp winner closes the gate',
    $claimTimestamp->invoke(null, new QueueSafetyEntityManager($lost), 'gate', $cutoff, $now) === false
);

$propagated = false;
try {
    $claimTimestamp->invoke(
        null,
        new QueueSafetyEntityManager(new QueueSafetyConnection([new RuntimeException('setting unavailable')])),
        'gate',
        $cutoff,
        $now
    );
} catch (RuntimeException $e) {
    $propagated = $e->getMessage() === 'setting unavailable';
}
$check('gating-key database failures propagate', $propagated);

$repo = file_get_contents(__DIR__ . '/../application/Repositories/MailboxTask.php');
$controller = file_get_contents(__DIR__ . '/../src/Kernel/Controller/QueueController.php');
$runner = file_get_contents(__DIR__ . '/../library/ViMbAdmin/Service/QueueRunner.php');
$runnerText = is_string($runner) ? $runner : '';
$check('cancel is a conditional database transition', is_string($repo)
    && str_contains($repo, 'WHERE id = :id AND status = :pending')
    && is_string($controller)
    && str_contains($controller, 'cancelIfPending($task, $admin->getFormattedName())'));
$check('delete and run retain live-owner conditional fencing', is_string($repo)
    && str_contains($repo, 'deleteUnlessActive')
    && str_contains($repo, 'r.id IS NULL')
    && str_contains($repo, 'publishIfOwned'));
$check('detached draining is batch/time bounded and clears the identity map', is_string($controller)
    && str_contains($controller, '$batches < 100')
    && str_contains($controller, 'microtime(true) < $deadline')
    && str_contains($controller, '$em->clear()')
    && substr_count($controller, "fetchOne('SELECT 1')") === 2
    && str_contains($controller, '$connection->close()'));
$check('orphan temp cleanup targets only sentinel rows without a live task', is_string($runner)
    && str_contains($runner, 'ORPHAN_TEMP_PASSWORD_PREFIX')
    && str_contains($runner, 'm.active = 0 AND m.password LIKE ? AND t.id IS NULL')
    && str_contains($runner, 'UniqueConstraintViolationException')
    && str_contains($runner, 'adopted temp user row'));
$drainMarker = strpos($runnerText, 'Recover before publishing either outcome');
$drainRecovery = strpos($runnerText, '$this->ensureDatabaseConnection();', $drainMarker === false ? 0 : $drainMarker);
$drainPublish = strpos($runnerText, '$published = $repo->publishIfOwned', $drainRecovery === false ? 0 : $drainRecovery);
$manualStart = strpos($runnerText, 'public function runOne');
$manualCatch = strpos($runnerText, '} catch (\Throwable $e) {', $manualStart === false ? 0 : $manualStart);
$manualRecovery = strpos($runnerText, '$this->ensureDatabaseConnection();', $manualCatch === false ? 0 : $manualCatch);
$manualPublish = strpos($runnerText, 'if ($repo->publishIfOwned', $manualRecovery === false ? 0 : $manualRecovery);
$check('failed doveadm work reconnects before batch and manual publication', is_string($runner)
    && substr_count($runner, '$this->ensureDatabaseConnection();') >= 5
    && is_int($drainRecovery) && is_int($drainPublish) && $drainRecovery < $drainPublish
    && is_int($manualCatch) && is_int($manualRecovery) && is_int($manualPublish)
    && $manualCatch < $manualRecovery && $manualRecovery < $manualPublish
    && str_contains($runner, '$connection->close()'));
$check('orphan temp finalization uses an exact compare-and-delete guard', is_string($runner)
    && str_contains($runner, 'DELETE FROM mailbox WHERE id = ? AND password = ? AND active = 0')
    && str_contains($runner, 'temp user row retained (changed concurrently)')
    && str_contains($runner, 'temp user row cleanup failed:'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
