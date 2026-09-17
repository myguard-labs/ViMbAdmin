<?php

declare(strict_types=1);

/**
 * MariaDB integration regression for atomic mailbox-task deduplication.
 *
 * Two independent transactions enqueue the same open task. The first keeps
 * its insert uncommitted while the second attempts its insert, proving the
 * database constraint serializes the race rather than a process-local guard.
 */

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType;

$applicationDir = __DIR__ . '/../application';
EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => $applicationDir,
    'repositories_path' => $applicationDir,
]]]);

function mailboxQueueEntityManager(): EntityManager
{
    $entityDir = realpath(__DIR__ . '/../application/Entities');
    if ($entityDir === false) {
        throw new RuntimeException('application/Entities directory not found');
    }

    $config = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
    $config->enableNativeLazyObjects(true);
    if (!Type::hasType(LegacyObjectType::NAME)) {
        Type::addType(LegacyObjectType::NAME, LegacyObjectType::class);
    }

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8',
    ], $config);

    return new EntityManager($connection, $config);
}

function mailboxQueueFixture(string $username): \Entities\Mailbox
{
    $mailbox = new \Entities\Mailbox();
    $mailbox->setUsername($username);
    return $mailbox;
}

function waitForPath(string $path, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('timed out waiting for synchronization path: ' . basename($path));
        }
        usleep(10_000);
    }
}

function runMailboxQueueWorker(string $role, string $syncDir): int
{
    $em = mailboxQueueEntityManager();
    $connection = $em->getConnection();
    $connection->beginTransaction();

    try {
        if ($role === 'B' && !touch($syncDir . '/b-enqueueing')) {
            throw new RuntimeException('failed to publish the enqueue attempt');
        }

        $task = \ViMbAdmin_MailboxQueue::enqueue(
            $em,
            mailboxQueueFixture('atomic@example.test'),
            \Entities\MailboxTask::TYPE_REPAIR,
        );
        // Retain the historical caller contract: enqueue may schedule ORM work
        // and every production caller flushes after it returns.
        $em->flush();

        if ($role === 'A') {
            if (!$task instanceof \Entities\MailboxTask) {
                throw new RuntimeException('first transaction did not insert its task');
            }
            touch($syncDir . '/a-inserted');
            waitForPath($syncDir . '/release-a', 10.0);
        }

        $connection->commit();
        echo $role . ':' . ($task instanceof \Entities\MailboxTask ? 'inserted' : 'duplicate') . "\n";
        return 0;
    } catch (Throwable $e) {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        fwrite(STDERR, $role . ': ' . $e->getMessage() . "\n");
        return 1;
    }
}

function runMailboxTaskTransitionWorker(string $role, string $syncDir, int $taskId): int
{
    $em = mailboxQueueEntityManager();
    $connection = $em->getConnection();
    if ($role === 'schema-migrate') {
        $sql = array_values(array_filter(
            (new \ViMbAdmin_Schema($em))->pendingSql(),
            static fn (string $statement): bool => stripos($statement, 'mailbox_task') !== false,
        ));
        array_unshift($sql, 'DO SLEEP(1)');
        touch($syncDir . '/schema-migrate-started');
        (new \ViMbAdmin_Schema($em))->apply($sql);
        echo "migrated\n";
        return 0;
    }
    if ($role === 'acquire-lease') {
        touch($syncDir . '/lease-acquire-started');
        $lease = \ViMbAdmin_QueueRunner::acquireLease($em, ['queue' => ['runner' => ['max_concurrent' => 1]]]);
        echo $lease instanceof \Entities\QueueRunner ? "acquired\n" : "busy\n";
        return 0;
    }
    if ($role === 'resume') {
        $task = $em->find(\Entities\MailboxTask::class, $taskId);
        if (!$task instanceof \Entities\MailboxTask || !$task->getRunner() instanceof \Entities\QueueRunner) {
            throw new RuntimeException('owned resume fixture missing');
        }
        $runner = $task->getRunner();
        $repo = $em->getRepository(\Entities\MailboxTask::class);
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new RuntimeException('mailbox-task repository mismatch');
        }
        touch($syncDir . '/old-owner-paused');
        waitForPath($syncDir . '/resume-old-owner', 10.0);
        $published = $repo->publishIfOwned($task, $runner, static function () use ($task): void {
            $task->setStatus(\Entities\MailboxTask::STATUS_DONE)->setRunner(null)->setFinishedAt(new DateTime());
        });
        echo 'published:' . ($published ? '1' : '0') . "\n";
        return 0;
    }
    if ($role === 'publish-hold') {
        $task = $em->find(\Entities\MailboxTask::class, $taskId);
        if (!$task instanceof \Entities\MailboxTask || !$task->getRunner() instanceof \Entities\QueueRunner) {
            throw new RuntimeException('owned publish fixture missing');
        }
        $runner = $task->getRunner();
        $repo = $em->getRepository(\Entities\MailboxTask::class);
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new RuntimeException('mailbox-task repository mismatch');
        }
        $published = $repo->publishIfOwned($task, $runner, static function () use ($task, $syncDir): void {
            touch($syncDir . '/publish-locks-held');
            waitForPath($syncDir . '/release-publish', 10.0);
            $task->setStatus(\Entities\MailboxTask::STATUS_DONE)->setRunner(null)->setFinishedAt(new DateTime());
        });
        echo 'published:' . ($published ? '1' : '0') . "\n";
        return 0;
    }
    if ($role === 'delete-owner') {
        touch($syncDir . '/delete-owner-started');
        $deleted = $connection->executeStatement(
            'DELETE FROM queue_runner WHERE id = ?',
            [$taskId],
        );
        echo 'deleted:' . $deleted . "\n";
        return 0;
    }
    if ($role === 'complete') {
        $connection->beginTransaction();
        $connection->executeStatement(
            'UPDATE mailbox_task SET status = ?, finished_at = CURRENT_TIMESTAMP, QueueRunner_id = NULL WHERE id = ? AND status = ?',
            [\Entities\MailboxTask::STATUS_DONE, $taskId, \Entities\MailboxTask::STATUS_RUNNING],
        );
        touch($syncDir . '/completion-locked');
        waitForPath($syncDir . '/release-completion', 10.0);
        $connection->commit();
        echo "complete\n";
        return 0;
    }
    if ($role === 'reap') {
        touch($syncDir . '/reaper-started');
        $repo = $em->getRepository(\Entities\MailboxTask::class);
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new RuntimeException('mailbox-task repository mismatch');
        }
        echo 'reaped:' . $repo->reapStaleRunning() . "\n";
        return 0;
    }
    throw new RuntimeException('unknown transition worker role');
}

/** @return array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} */
function startMailboxQueueWorker(string $role, string $syncDir, ?int $transitionTaskId = null): array
{
    $pipes = [];
    $command = $transitionTaskId === null
        ? [PHP_BINARY, __FILE__, '--worker', $role, $syncDir]
        : [PHP_BINARY, __FILE__, '--transition-worker', $role, $syncDir, (string) $transitionTaskId];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__ . '/..',
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start transaction worker ' . $role);
    }
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'last_status' => proc_get_status($process)];
}

/**
 * @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker
 * @return array{exit:int,stdout:string,stderr:string}
 */
function waitMailboxQueueWorker(array &$worker, float $timeoutSeconds): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    if ($worker['last_status']['running']) {
        proc_terminate($worker['process']);
        throw new RuntimeException('transaction worker timed out');
    }

    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);

    return [
        'exit' => (int) $worker['last_status']['exitcode'],
        'stdout' => trim($stdout === false ? '' : $stdout),
        'stderr' => trim($stderr === false ? '' : $stderr),
    ];
}

if (($argv[1] ?? '') === '--transition-worker') {
    exit(runMailboxTaskTransitionWorker($argv[2] ?? '', $argv[3] ?? '', (int) ($argv[4] ?? 0)));
}
if (($argv[1] ?? '') === '--worker') {
    exit(runMailboxQueueWorker($argv[2] ?? '', $argv[3] ?? ''));
}

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Refusing to run: this test deletes mailbox_task rows and alters its schema.\n"
        . "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 only for a disposable database.\n");
    exit(2);
}

final class MailboxQueueAtomicState
{
    public static int $failures = 0;
}

function mailboxQueueRequiredCount(mixed $value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($count !== false) {
            return $count;
        }
    }
    throw new UnexpectedValueException('MariaDB returned an invalid mailbox-task count');
}

/** @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker */
function mailboxQueueWorkerRemainsBlocked(array &$worker, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            return false;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    return true;
}

function mailboxQueueAtomicCheck(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) {
        MailboxQueueAtomicState::$failures++;
    }
}

echo "== MariaDB mailbox-task atomic deduplication ==\n";
$em = mailboxQueueEntityManager();
$connection = $em->getConnection();
$connection->executeStatement('DELETE FROM mailbox_task');

$syncDir = sys_get_temp_dir() . '/vimbadmin-mailbox-atomic-' . bin2hex(random_bytes(8));
if (!mkdir($syncDir, 0700)) {
    throw new RuntimeException('failed to create synchronization directory');
}

$workerA = null;
$workerB = null;
$completeWorker = null;
$reapWorker = null;
try {
    $workerA = startMailboxQueueWorker('A', $syncDir);
    waitForPath($syncDir . '/a-inserted', 10.0);
    $workerB = startMailboxQueueWorker('B', $syncDir);
    waitForPath($syncDir . '/b-enqueueing', 10.0);

    // With the unique open-task invariant, B must wait on A's uncommitted
    // unique-key record. Observing the worker itself avoids the intermittent
    // gaps in MariaDB's information_schema.INNODB_LOCK_WAITS instrumentation.
    // Without the constraint, B commits a second row well inside this window.
    mailboxQueueAtomicCheck(
        'second transaction waits on the first uncommitted open task',
        mailboxQueueWorkerRemainsBlocked($workerB, 0.25),
    );

    touch($syncDir . '/release-a');
    $resultA = waitMailboxQueueWorker($workerA, 10.0);
    $resultB = waitMailboxQueueWorker($workerB, 10.0);
    mailboxQueueAtomicCheck('first transaction inserts', $resultA['exit'] === 0 && $resultA['stdout'] === 'A:inserted', $resultA['stderr']);
    mailboxQueueAtomicCheck('second transaction is deduplicated', $resultB['exit'] === 0 && $resultB['stdout'] === 'B:duplicate', $resultB['stderr']);

    $openRepair = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR, \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck('exactly one open task remains for one username and type', $openRepair === 1, 'count=' . $openRepair);

    $archive = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('atomic@example.test'),
        \Entities\MailboxTask::TYPE_ARCHIVE,
    );
    $em->flush();
    $openAllTypes = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck('a distinct task type remains independently queueable', $archive instanceof \Entities\MailboxTask && $openAllTypes === 2);

    $connection->executeStatement(
        'UPDATE mailbox_task SET status = ?, finished_at = CURRENT_TIMESTAMP WHERE username = ? AND type = ?',
        [\Entities\MailboxTask::STATUS_DONE, 'atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR],
    );
    $replacement = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('atomic@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $em->flush();
    $repairHistory = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ?',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR],
    ));
    $openReplacement = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR, \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck(
        'completed history remains while a replacement task is queueable',
        $replacement instanceof \Entities\MailboxTask && $repairHistory === 2 && $openReplacement === 1,
    );

    // RUNNING recovery is ownership-based, never elapsed-time-based. An old
    // task with a live lease remains protected indefinitely; an orphan is
    // immediately and atomically terminal regardless of started_at shape.
    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement('DELETE FROM queue_runner');
    $connection->executeStatement(
        'INSERT INTO queue_runner (slot, host, pid, started_at, heartbeat_at)'
        . " VALUES (1, 'integration', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    );
    $liveRunnerId = mailboxQueueRequiredCount($connection->lastInsertId());
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, started_at, QueueRunner_id) VALUES'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY), ?),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, NULL, NULL),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, NULL, NULL)',
        [
            \Entities\MailboxTask::TYPE_REPAIR, 'live-owned@example.test', \Entities\MailboxTask::STATUS_RUNNING, $liveRunnerId,
            \Entities\MailboxTask::TYPE_REPAIR, 'fresh-orphan@example.test', \Entities\MailboxTask::STATUS_RUNNING,
            \Entities\MailboxTask::TYPE_REPAIR, 'null-start@example.test', \Entities\MailboxTask::STATUS_RUNNING,
            \Entities\MailboxTask::TYPE_REPAIR, 'pending-boundary@example.test', \Entities\MailboxTask::STATUS_PENDING,
        ],
    );
    $taskRepo = $em->getRepository(\Entities\MailboxTask::class);
    if (!$taskRepo instanceof \Repositories\MailboxTask) {
        throw new RuntimeException('mailbox-task repository mismatch');
    }
    $runnerEntity = $em->find(\Entities\QueueRunner::class, $liveRunnerId);
    if (!$runnerEntity instanceof \Entities\QueueRunner) {
        throw new RuntimeException('live runner could not be loaded');
    }
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'claimed@example.test', \Entities\MailboxTask::STATUS_PENDING],
    );
    $claimedId = mailboxQueueRequiredCount($connection->lastInsertId());
    $claimedTask = $taskRepo->find($claimedId);
    if (!$claimedTask instanceof \Entities\MailboxTask) {
        throw new RuntimeException('claim fixture could not be loaded');
    }
    $firstClaim = $taskRepo->claim($claimedTask, $runnerEntity);
    $claimRow = $connection->fetchAssociative(
        'SELECT status, QueueRunner_id, ABS(TIMESTAMPDIFF(SECOND, started_at, CURRENT_TIMESTAMP)) AS age'
        . ' FROM mailbox_task WHERE id = ?',
        [$claimedId],
    );
    mailboxQueueAtomicCheck(
        'claim atomically binds fresh work to its owner using database time',
        $firstClaim && is_array($claimRow)
        && ($claimRow['status'] ?? null) === \Entities\MailboxTask::STATUS_RUNNING
        && mailboxQueueRequiredCount($claimRow['QueueRunner_id'] ?? null) === $liveRunnerId
        && mailboxQueueRequiredCount($claimRow['age'] ?? null) <= 2
        && !$taskRepo->claim($claimedTask, $runnerEntity)
    );
    $connection->executeStatement(
        'UPDATE mailbox_task SET status = ?, finished_at = CURRENT_TIMESTAMP, QueueRunner_id = NULL WHERE id = ?',
        [\Entities\MailboxTask::STATUS_DONE, $claimedId],
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, finished_at, QueueRunner_id)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'terminal-owned@example.test', \Entities\MailboxTask::STATUS_DONE, $liveRunnerId],
    );
    $terminalOwnedId = mailboxQueueRequiredCount($connection->lastInsertId());
    $terminalOwnedTask = $taskRepo->find($terminalOwnedId);
    if (!$terminalOwnedTask instanceof \Entities\MailboxTask) {
        throw new RuntimeException('terminal owner fixture could not be loaded');
    }
    $terminalCallback = false;
    $terminalRepublished = $taskRepo->publishIfOwned(
        $terminalOwnedTask,
        $runnerEntity,
        static function () use (&$terminalCallback): void {
            $terminalCallback = true;
        },
    );
    mailboxQueueAtomicCheck(
        'terminal publication requires both matching owner and RUNNING status',
        !$terminalRepublished && !$terminalCallback
    );
    $initialReaped = $taskRepo->reapStaleRunning();
    $recoveryRows = $connection->fetchAllKeyValue(
        'SELECT username, status FROM mailbox_task WHERE username IN (?, ?, ?, ?)',
        ['live-owned@example.test', 'fresh-orphan@example.test', 'null-start@example.test', 'pending-boundary@example.test'],
    );
    mailboxQueueAtomicCheck(
        'reaper fails fresh and null-start ownerless tasks but preserves pending and arbitrarily old live-owned work',
        $initialReaped === 2
        && ($recoveryRows['live-owned@example.test'] ?? null) === \Entities\MailboxTask::STATUS_RUNNING
        && ($recoveryRows['fresh-orphan@example.test'] ?? null) === \Entities\MailboxTask::STATUS_FAILED
        && ($recoveryRows['null-start@example.test'] ?? null) === \Entities\MailboxTask::STATUS_FAILED
        && ($recoveryRows['pending-boundary@example.test'] ?? null) === \Entities\MailboxTask::STATUS_PENDING
    );

    $liveTaskId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['live-owned@example.test'],
    ));
    $liveTask = $taskRepo->find($liveTaskId);
    if (!$liveTask instanceof \Entities\MailboxTask) {
        throw new RuntimeException('live-owned task could not be loaded');
    }
    mailboxQueueAtomicCheck('operator deletion refuses a task with a live owner lease', !$taskRepo->deleteUnlessActive($liveTask));
    $resumeWorker = startMailboxQueueWorker('resume', $syncDir, $liveTaskId);
    waitForPath($syncDir . '/old-owner-paused', 10.0);
    $em->clear();
    $connection->executeStatement('DELETE FROM queue_runner WHERE id = ?', [$liveRunnerId]);
    mailboxQueueAtomicCheck('stale owner removal exposes and reaps its task', $taskRepo->reapStaleRunning() === 1);
    $blockedDuplicate = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('live-owned@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    touch($syncDir . '/resume-old-owner');
    $resumeResult = waitMailboxQueueWorker($resumeWorker, 10.0);
    $fencedStatus = $connection->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [$liveTaskId]);
    mailboxQueueAtomicCheck(
        'lease reap fences a paused old owner and keeps destructive retry blocked',
        $blockedDuplicate === null && $resumeResult['stdout'] === 'published:0'
        && $fencedStatus === \Entities\MailboxTask::STATUS_FAILED,
        $resumeResult['stderr']
    );
    $orphanTaskId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['live-owned@example.test'],
    ));
    $orphanTask = $taskRepo->find($orphanTaskId);
    if (!$orphanTask instanceof \Entities\MailboxTask) {
        throw new RuntimeException('orphaned task could not be loaded');
    }
    mailboxQueueAtomicCheck('operator deletion accepts a recovered ownerless task', $taskRepo->deleteUnlessActive($orphanTask));

    $blockedRetry = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('fresh-orphan@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, finished_at)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        [
            \Entities\MailboxTask::TYPE_REPAIR, 'clear-done@example.test', \Entities\MailboxTask::STATUS_DONE,
            \Entities\MailboxTask::TYPE_REPAIR, 'clear-failed@example.test', \Entities\MailboxTask::STATUS_FAILED,
            \Entities\MailboxTask::TYPE_REPAIR, 'clear-cancelled@example.test', \Entities\MailboxTask::STATUS_CANCELLED,
        ],
    );
    $cleared = $em->createQuery(
        'DELETE FROM \\Entities\\MailboxTask t WHERE t.status IN (:done) AND t.abandoned = false'
    )
        ->setParameter('done', [
            \Entities\MailboxTask::STATUS_DONE,
            \Entities\MailboxTask::STATUS_FAILED,
            \Entities\MailboxTask::STATUS_CANCELLED,
        ])
        ->execute();
    $blockedAfterClear = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('fresh-orphan@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $remainingClearFixtures = mailboxQueueRequiredCount($connection->fetchOne(
        "SELECT COUNT(*) FROM mailbox_task WHERE username LIKE 'clear-%@example.test'",
    ));
    $abandonedId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['fresh-orphan@example.test'],
    ));
    $abandonedTask = $taskRepo->find($abandonedId);
    if (!$abandonedTask instanceof \Entities\MailboxTask) {
        throw new RuntimeException('abandoned task could not be loaded');
    }
    $deletedAbandoned = $taskRepo->deleteUnlessActive($abandonedTask);
    $retry = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('fresh-orphan@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $em->flush();
    mailboxQueueAtomicCheck(
        'bulk clear removes normal terminal tasks but preserves an abandoned failure dedupe fence',
        is_int($cleared) && $cleared >= 3 && $remainingClearFixtures === 0
        && $blockedRetry === null && $blockedAfterClear === null
    );
    mailboxQueueAtomicCheck(
        'abandoned failure blocks retry until explicit operator deletion',
        $deletedAbandoned && $retry instanceof \Entities\MailboxTask
    );

    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, started_at)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'transition@example.test', \Entities\MailboxTask::STATUS_RUNNING],
    );
    $transitionId = mailboxQueueRequiredCount($connection->lastInsertId());
    $completeWorker = startMailboxQueueWorker('complete', $syncDir, $transitionId);
    waitForPath($syncDir . '/completion-locked', 10.0);
    $reapWorker = startMailboxQueueWorker('reap', $syncDir, $transitionId);
    waitForPath($syncDir . '/reaper-started', 10.0);
    mailboxQueueAtomicCheck(
        'competing reaper waits for an in-flight terminal transition',
        mailboxQueueWorkerRemainsBlocked($reapWorker, 0.25)
    );
    touch($syncDir . '/release-completion');
    $completeResult = waitMailboxQueueWorker($completeWorker, 10.0);
    $reapResult = waitMailboxQueueWorker($reapWorker, 10.0);
    $transitionStatus = $connection->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [$transitionId]);
    mailboxQueueAtomicCheck(
        'terminal transition wins deterministically and is never reaped',
        $completeResult['exit'] === 0 && $reapResult['stdout'] === 'reaped:0'
        && $transitionStatus === \Entities\MailboxTask::STATUS_DONE,
        $completeResult['stderr'] . ' ' . $reapResult['stderr']
    );

    $connection->executeStatement(
        'INSERT INTO queue_runner (slot, host, pid, started_at, heartbeat_at)'
        . " VALUES (2, 'integration', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    );
    $publishRunnerId = mailboxQueueRequiredCount($connection->lastInsertId());
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, started_at, QueueRunner_id)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'lock-order@example.test', \Entities\MailboxTask::STATUS_RUNNING, $publishRunnerId],
    );
    $publishTaskId = mailboxQueueRequiredCount($connection->lastInsertId());
    $publishWorker = startMailboxQueueWorker('publish-hold', $syncDir, $publishTaskId);
    waitForPath($syncDir . '/publish-locks-held', 10.0);
    $deleteOwnerWorker = startMailboxQueueWorker('delete-owner', $syncDir, $publishRunnerId);
    waitForPath($syncDir . '/delete-owner-started', 10.0);
    mailboxQueueAtomicCheck(
        'runner deletion waits behind runner-to-task terminal publication locks',
        mailboxQueueWorkerRemainsBlocked($deleteOwnerWorker, 0.25)
    );
    touch($syncDir . '/release-publish');
    $publishOwnerResult = waitMailboxQueueWorker($publishWorker, 10.0);
    $deleteOwnerResult = waitMailboxQueueWorker($deleteOwnerWorker, 10.0);
    mailboxQueueAtomicCheck(
        'runner-to-task order completes publication then lease deletion without deadlock',
        $publishOwnerResult['stdout'] === 'published:1' && $deleteOwnerResult['stdout'] === 'deleted:1',
        $publishOwnerResult['stderr'] . ' ' . $deleteOwnerResult['stderr']
    );

    $connection->executeStatement(
        'INSERT INTO domain (domain, created) VALUES (?, CURRENT_TIMESTAMP)',
        ['bulk.example.test'],
    );
    $domainId = mailboxQueueRequiredCount($connection->lastInsertId());
    $connection->executeStatement(
        'INSERT INTO mailbox (username, password, local_part, active, created, Domain_id) VALUES'
        . ' (?, ?, ?, 1, CURRENT_TIMESTAMP, ?), (?, ?, ?, 1, CURRENT_TIMESTAMP, ?),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, ?)',
        [
            'existing@bulk.example.test', '!', 'existing', $domainId,
            'new@bulk.example.test', '!', 'new', $domainId,
            'inactive@bulk.example.test', '!', 'inactive', $domainId,
        ],
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, Domain_id)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, ?)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'existing@bulk.example.test', \Entities\MailboxTask::STATUS_PENDING, $domainId],
    );
    $bulkQueued = \ViMbAdmin_MailboxQueue::enqueueAllActive(
        $em,
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $bulkRows = $connection->fetchAllAssociative(
        'SELECT username, type, status, Domain_id FROM mailbox_task'
        . ' WHERE username LIKE ? ORDER BY username',
        ['%@bulk.example.test'],
    );
    mailboxQueueAtomicCheck(
        'bulk enqueue inserts active missing tasks and deduplicates existing open tasks',
        $bulkQueued === 1
        && count($bulkRows) === 2
        && ($bulkRows[0]['username'] ?? null) === 'existing@bulk.example.test'
        && ($bulkRows[1]['username'] ?? null) === 'new@bulk.example.test'
        && array_reduce(
            $bulkRows,
            static fn (bool $valid, array $row): bool => $valid
                && ($row['type'] ?? null) === \Entities\MailboxTask::TYPE_REPAIR
                && ($row['status'] ?? null) === \Entities\MailboxTask::STATUS_PENDING
                && mailboxQueueRequiredCount($row['Domain_id'] ?? null) === $domainId,
            true,
        ),
        'queued=' . $bulkQueued . ' rows=' . json_encode($bulkRows),
    );
    $connection->executeStatement('DELETE FROM mailbox_task WHERE username LIKE ?', ['%@bulk.example.test']);
    $connection->executeStatement('DELETE FROM mailbox WHERE Domain_id = ?', [$domainId]);
    $connection->executeStatement('DELETE FROM domain WHERE id = ?', [$domainId]);

    // Operator transitions must make their status decision in the UPDATE, not
    // from a possibly stale managed entity.
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at) VALUES'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP), (?, ?, ?, 0, CURRENT_TIMESTAMP)',
        [
            \Entities\MailboxTask::TYPE_REPAIR, 'cancel-race@example.test', \Entities\MailboxTask::STATUS_PENDING,
            \Entities\MailboxTask::TYPE_REPAIR, 'cancel-win@example.test', \Entities\MailboxTask::STATUS_PENDING,
        ],
    );
    $cancelRaceId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['cancel-race@example.test'],
    ));
    $cancelWinId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['cancel-win@example.test'],
    ));
    $cancelRace = $taskRepo->find($cancelRaceId);
    $cancelWin = $taskRepo->find($cancelWinId);
    if (!$cancelRace instanceof \Entities\MailboxTask || !$cancelWin instanceof \Entities\MailboxTask) {
        throw new RuntimeException('cancel transition fixtures could not be loaded');
    }
    $connection->executeStatement(
        'UPDATE mailbox_task SET status = ? WHERE id = ?',
        [\Entities\MailboxTask::STATUS_RUNNING, $cancelRaceId],
    );
    $lostCancel = $taskRepo->cancelIfPending($cancelRace, 'Integration Admin');
    $wonCancel = $taskRepo->cancelIfPending($cancelWin, 'Integration Admin');
    $cancelRows = $connection->fetchAllKeyValue(
        'SELECT username, status FROM mailbox_task WHERE username IN (?, ?)',
        ['cancel-race@example.test', 'cancel-win@example.test'],
    );
    mailboxQueueAtomicCheck(
        'conditional cancellation loses safely to a concurrent claim and updates pending work',
        !$lostCancel && $wonCancel
        && ($cancelRows['cancel-race@example.test'] ?? null) === \Entities\MailboxTask::STATUS_RUNNING
        && ($cancelRows['cancel-win@example.test'] ?? null) === \Entities\MailboxTask::STATUS_CANCELLED
    );
    $connection->executeStatement('DELETE FROM mailbox_task WHERE username LIKE ?', ['cancel-%@example.test']);

    $connection->executeStatement(
        'CREATE TABLE IF NOT EXISTS setting (name VARCHAR(64) NOT NULL, value VARCHAR(255) NULL,'
        . ' updated_at DATETIME NULL, PRIMARY KEY (name)) ENGINE=InnoDB'
    );
    $connection->delete('setting', ['name' => \ViMbAdmin_Setting::LAST_PRUNE_SWEEP]);
    $gateNow = new DateTimeImmutable('2026-09-03T08:00:00+00:00');
    $gateCutoff = $gateNow->modify('-8 hours');
    $firstGateClaim = \ViMbAdmin_Setting::claimTimestamp(
        $em,
        \ViMbAdmin_Setting::LAST_PRUNE_SWEEP,
        $gateCutoff,
        $gateNow
    );
    $secondGateClaim = \ViMbAdmin_Setting::claimTimestamp(
        $em,
        \ViMbAdmin_Setting::LAST_PRUNE_SWEEP,
        $gateCutoff,
        $gateNow
    );
    mailboxQueueAtomicCheck(
        'timestamp gate has one winner while the fresh value closes later claims',
        $firstGateClaim && !$secondGateClaim
    );

    $connection->executeStatement(
        'INSERT INTO domain (domain, created) VALUES (?, CURRENT_TIMESTAMP)',
        ['orphan-temp.example.test'],
    );
    $tempDomainId = mailboxQueueRequiredCount($connection->lastInsertId());
    $connection->executeStatement(
        'INSERT INTO queue_runner (slot, host, pid, started_at, heartbeat_at)'
        . " VALUES (3, 'integration', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    );
    $tempRunnerId = mailboxQueueRequiredCount($connection->lastInsertId());
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, QueueRunner_id)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, ?), (?, ?, ?, 0, CURRENT_TIMESTAMP, NULL)',
        [
            \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'live-temp@orphan-temp.example.test', \Entities\MailboxTask::STATUS_RUNNING, $tempRunnerId,
            \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'dead-temp@orphan-temp.example.test', \Entities\MailboxTask::STATUS_FAILED,
        ],
    );
    $liveTempTaskId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['live-temp@orphan-temp.example.test'],
    ));
    $deadTempTaskId = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT id FROM mailbox_task WHERE username = ?',
        ['dead-temp@orphan-temp.example.test'],
    ));
    $tempPrefix = '{PLAIN}!vimbadmin-orphan-backup-temp-v1:';
    $connection->executeStatement(
        'INSERT INTO mailbox (username, password, local_part, active, created, Domain_id) VALUES'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, ?), (?, ?, ?, 0, CURRENT_TIMESTAMP, ?),'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP, ?)',
        [
            'live-temp@orphan-temp.example.test', $tempPrefix . $liveTempTaskId . '!', 'live-temp', $tempDomainId,
            'dead-temp@orphan-temp.example.test', $tempPrefix . $deadTempTaskId . '!', 'dead-temp', $tempDomainId,
            'unrelated@orphan-temp.example.test', '{PLAIN}!ordinary-inactive!', 'unrelated', $tempDomainId,
        ],
    );
    $sweeper = new \ViMbAdmin_Service_QueueRunner($em, []);
    (new ReflectionMethod($sweeper, 'sweepOrphanBackupTemps'))->invoke($sweeper);
    $remainingTemps = $connection->fetchFirstColumn(
        'SELECT username FROM mailbox WHERE Domain_id = ? ORDER BY username',
        [$tempDomainId],
    );
    mailboxQueueAtomicCheck(
        'orphan-temp sweep deletes dead sentinels but preserves live and unrelated inactive rows',
        $remainingTemps === ['live-temp@orphan-temp.example.test', 'unrelated@orphan-temp.example.test']
    );
    $connection->executeStatement(
        'INSERT INTO mailbox (username, password, local_part, active, created, Domain_id)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, ?)',
        ['finalize-temp@orphan-temp.example.test', $tempPrefix . '999!', 'finalize-temp', $tempDomainId],
    );
    $finalizeTempId = mailboxQueueRequiredCount($connection->lastInsertId());
    $tempFinalizer = new ReflectionMethod($sweeper, 'deleteOrphanBackupTemp');
    $connection->executeStatement(
        'UPDATE mailbox SET password = ?, active = 1 WHERE id = ?',
        ['{PLAIN}!replacement!', $finalizeTempId],
    );
    $replacedDeleted = $tempFinalizer->invoke($sweeper, $finalizeTempId, $tempPrefix . '999!');
    $replacementStillPresent = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox WHERE id = ? AND password = ? AND active = 1',
        [$finalizeTempId, '{PLAIN}!replacement!'],
    ));
    mailboxQueueAtomicCheck(
        'orphan-temp finalization preserves a concurrently activated replacement',
        $replacedDeleted === false && $replacementStillPresent === 1
    );
    $connection->executeStatement(
        'UPDATE mailbox SET password = ?, active = 0 WHERE id = ?',
        [$tempPrefix . '999!', $finalizeTempId],
    );
    mailboxQueueAtomicCheck(
        'orphan-temp finalization deletes an unchanged exact sentinel',
        $tempFinalizer->invoke($sweeper, $finalizeTempId, $tempPrefix . '999!') === true
        && mailboxQueueRequiredCount($connection->fetchOne(
            'SELECT COUNT(*) FROM mailbox WHERE id = ?',
            [$finalizeTempId],
        )) === 0
    );
    $connection->executeStatement('DELETE FROM mailbox WHERE Domain_id = ?', [$tempDomainId]);
    $connection->executeStatement('DELETE FROM mailbox_task WHERE username LIKE ?', ['%@orphan-temp.example.test']);
    $connection->executeStatement('DELETE FROM queue_runner WHERE id = ?', [$tempRunnerId]);
    $connection->executeStatement('DELETE FROM domain WHERE id = ?', [$tempDomainId]);

    // Upgrade fencing: pre-ownership RUNNING work may still have an old runner.
    // The additive migrator must remain entirely inert until that work reaches
    // a terminal state under the old schema.
    $connection->executeStatement('DELETE FROM mailbox_task');
    $ownerForeignKey = $connection->fetchOne(
        'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        . ' AND REFERENCED_TABLE_NAME = ? LIMIT 1',
        ['mailbox_task', 'QueueRunner_id', 'queue_runner'],
    );
    if (is_string($ownerForeignKey) && $ownerForeignKey !== '') {
        $connection->executeStatement('ALTER TABLE mailbox_task DROP FOREIGN KEY `' . str_replace('`', '``', $ownerForeignKey) . '`');
    }
    $ownerIndex = $connection->fetchOne(
        'SELECT INDEX_NAME FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        ['mailbox_task', 'QueueRunner_id'],
    );
    $dropOwnerIndex = is_string($ownerIndex) && $ownerIndex !== ''
        ? ', DROP INDEX `' . str_replace('`', '``', $ownerIndex) . '`'
        : '';
    $connection->executeStatement(
        'ALTER TABLE mailbox_task DROP INDEX mailbox_task_open_unique, DROP COLUMN open_task'
        . $dropOwnerIndex . ', DROP COLUMN QueueRunner_id, DROP COLUMN abandoned',
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at, started_at)'
        . ' VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        [\Entities\MailboxTask::TYPE_REPAIR, 'legacy-running@example.test', \Entities\MailboxTask::STATUS_RUNNING],
    );
    $ownershipSql = array_values(array_filter(
        (new \ViMbAdmin_Schema($em))->pendingSql(),
        static fn (string $statement): bool => stripos($statement, 'mailbox_task') !== false,
    ));
    $ownershipMessage = '';
    try {
        (new \ViMbAdmin_Schema($em))->apply($ownershipSql);
    } catch (RuntimeException $e) {
        $ownershipMessage = $e->getMessage();
    }
    $ownershipColumnsBeforeCompletion = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
        ['mailbox_task', 'QueueRunner_id', 'abandoned'],
    ));
    $legacyStatus = $connection->fetchOne(
        'SELECT status FROM mailbox_task WHERE username = ?',
        ['legacy-running@example.test'],
    );
    mailboxQueueAtomicCheck(
        'ownership upgrade is inert while a legacy RUNNING task may still complete',
        str_contains($ownershipMessage, 'quiesce queue runners')
        && $ownershipColumnsBeforeCompletion === 0
        && $legacyStatus === \Entities\MailboxTask::STATUS_RUNNING,
        $ownershipMessage
    );
    $connection->executeStatement(
        'UPDATE mailbox_task SET status = ?, finished_at = CURRENT_TIMESTAMP WHERE username = ?',
        [\Entities\MailboxTask::STATUS_DONE, 'legacy-running@example.test'],
    );
    $connection->executeStatement(
        'INSERT INTO queue_runner (slot, host, pid, started_at, heartbeat_at)'
        . " VALUES (1, 'legacy-between-tasks', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    );
    $idleLeaseMessage = '';
    try {
        (new \ViMbAdmin_Schema($em))->apply($ownershipSql);
    } catch (RuntimeException $e) {
        $idleLeaseMessage = $e->getMessage();
    }
    $connection->executeStatement('DELETE FROM queue_runner');
    mailboxQueueAtomicCheck(
        'ownership upgrade refuses an old runner lease between task claims',
        str_contains($idleLeaseMessage, 'runner lease(s)')
    );
    $schemaWorker = startMailboxQueueWorker('schema-migrate', $syncDir, 0);
    waitForPath($syncDir . '/schema-migrate-started', 10.0);
    $lockDeadline = microtime(true) + 5.0;
    do {
        $migrationLockOwner = $connection->fetchOne(
            'SELECT IS_USED_LOCK(?)',
            [\ViMbAdmin_QueueRunner::ACQUIRE_LOCK_NAME],
        );
        if ($migrationLockOwner !== false && $migrationLockOwner !== null) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $lockDeadline);
    $acquireWorker = startMailboxQueueWorker('acquire-lease', $syncDir, 0);
    waitForPath($syncDir . '/lease-acquire-started', 10.0);
    $acquireBlocked = mailboxQueueWorkerRemainsBlocked($acquireWorker, 0.25);
    $schemaResult = waitMailboxQueueWorker($schemaWorker, 10.0);
    $acquireResult = waitMailboxQueueWorker($acquireWorker, 10.0);
    $connection->executeStatement('DELETE FROM queue_runner');
    $ownershipColumnsAfterCompletion = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
        ['mailbox_task', 'QueueRunner_id', 'abandoned'],
    ));
    mailboxQueueAtomicCheck(
        'ownership upgrade succeeds after the old runner publishes terminal state',
        $ownershipColumnsAfterCompletion === 2
        && $acquireBlocked
        && $schemaResult['stdout'] === 'migrated'
        && $acquireResult['stdout'] === 'acquired'
    );

    // Exercise the production Schema helper's upgrade path from the previous
    // queue shape. It must reject existing duplicates before adding even the
    // generated column, so a failed upgrade cannot leave half the invariant.
    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement(
        'ALTER TABLE mailbox_task DROP INDEX mailbox_task_open_unique, DROP COLUMN open_task',
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at) VALUES'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP), (?, ?, ?, 0, CURRENT_TIMESTAMP)',
        [
            \Entities\MailboxTask::TYPE_REPAIR,
            'duplicate@example.test',
            \Entities\MailboxTask::STATUS_PENDING,
            \Entities\MailboxTask::TYPE_REPAIR,
            'duplicate@example.test',
            \Entities\MailboxTask::STATUS_RUNNING,
        ],
    );
    $preflightMessage = '';
    try {
        (new \ViMbAdmin_Schema($em))->migrate();
    } catch (RuntimeException $e) {
        $preflightMessage = $e->getMessage();
    } finally {
        $openTaskColumns = mailboxQueueRequiredCount($connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['mailbox_task', 'open_task'],
        ));
        $connection->executeStatement('DELETE FROM mailbox_task');
        $schema = new \ViMbAdmin_Schema($em);
        $mailboxTaskSql = array_values(array_filter(
            $schema->pendingSql(),
            static fn (string $statement): bool => stripos($statement, 'mailbox_task') !== false,
        ));
        $schema->apply($mailboxTaskSql);
    }
    mailboxQueueAtomicCheck(
        'schema migration rejects duplicate open identities before any DDL',
        str_contains($preflightMessage, 'resolve the duplicate PENDING/RUNNING tasks') && $openTaskColumns === 0,
        $preflightMessage,
    );
    $openTaskIndexColumns = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
        ['mailbox_task', 'mailbox_task_open_unique'],
    ));
    mailboxQueueAtomicCheck('schema migration restores the complete open-task invariant', $openTaskIndexColumns === 3);
    $lookupIndexColumns = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 1',
        ['mailbox_task', 'mailbox_task_username_type_status_idx'],
    ));
    mailboxQueueAtomicCheck('schema migration installs the bulk deduplication lookup index', $lookupIndexColumns === 3);
} catch (Throwable $e) {
    mailboxQueueAtomicCheck('integration harness completed', false, $e->getMessage());
} finally {
    touch($syncDir . '/release-a');
    foreach ([$workerA, $workerB, $completeWorker, $reapWorker, $resumeWorker ?? null,
        $publishWorker ?? null, $deleteOwnerWorker ?? null, $schemaWorker ?? null,
        $acquireWorker ?? null] as $worker) {
        if (is_array($worker) && is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
        }
    }
    foreach (glob($syncDir . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($syncDir);
}

$failures = MailboxQueueAtomicState::$failures;
echo $failures === 0 ? "ALL PASSED\n" : $failures . " FAILED\n";
exit($failures === 0 ? 0 : 1);
