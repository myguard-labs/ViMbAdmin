<?php

declare(strict_types=1);

define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';

function maintenanceRetryWait(callable $condition): void
{
    $deadline = microtime(true) + 15;
    do {
        clearstatcache();
        if ($condition()) {
            return;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('retry synchronization timed out');
}

if (($argv[1] ?? '') === 'retry-worker') {
    $em = maintenanceTaskManager();
    $task = $em->find(\Entities\MailboxTask::class, (int) $argv[2]);
    if (!$task instanceof \Entities\MailboxTask) {
        throw new RuntimeException('retry worker fixture missing');
    }
    $sync = $argv[3];
    $role = $argv[4];
    touch($sync . '/ready-' . $role);
    maintenanceRetryWait(static fn (): bool => is_file($sync . '/go'));
    echo maintenanceTaskRepository($em)->retryIfFailed($task, $role) ? 'won' : 'stale';
    exit(0);
}

$em = maintenanceTaskManager();
$observer = maintenanceTaskManager()->getConnection();
$unsaved = (new \Entities\MailboxTask())->setStatus('FAILED');
maintenanceTaskCheck('unpersisted retry snapshot cannot publish', !maintenanceTaskRepository($em)->retryIfFailed($unsaved, 'invalid'));

[$task, $runner] = maintenanceTaskFixture($em, 'FAILED');
$version = $task->getTaskVersion();
$ok = maintenanceTaskRepository($em)->retryIfFailed($task, 'operator');
$row = $observer->fetchAssociative('SELECT status,finished_at,log,data,task_version FROM mailbox_task WHERE id = ?', [$task->getId()]);
maintenanceTaskCheck('current failed generation retries with actor log and preserved data', $ok && is_array($row)
    && $row['status'] === 'PENDING' && $row['finished_at'] === null && is_string($row['log']) && str_contains($row['log'], 'retry queued by operator')
    && $row['data'] === '{"preserved":true}' && maintenanceTaskInteger($row['task_version']) === $version + 1);

foreach (['PENDING', 'RUNNING', 'DONE', 'CANCELLED', 'FAILED_ABANDONED'] as $state) {
    [$task] = maintenanceTaskFixture($em, $state === 'FAILED_ABANDONED' ? 'FAILED' : $state);
    if ($state === 'FAILED_ABANDONED') {
        $task->setAbandoned(true);
        $em->flush();
    }
    $before = $observer->fetchAssociative('SELECT status,log,data,task_version FROM mailbox_task WHERE id = ?', [$task->getId()]);
    $retry = maintenanceTaskRepository($em)->retryIfFailed($task, 'forbidden');
    maintenanceTaskLaterFlush($em);
    $after = $observer->fetchAssociative('SELECT status,log,data,task_version FROM mailbox_task WHERE id = ?', [$task->getId()]);
    maintenanceTaskCheck($state . ' is not safely retryable and remains unchanged', !$retry && $after === $before);
}

[$stale, $runner] = maintenanceTaskFixture($em, 'FAILED');
$id = $stale->getId();
$other = maintenanceTaskManager();
$current = $other->find(\Entities\MailboxTask::class, $id);
$otherRunner = $other->find(\Entities\QueueRunner::class, $runner->getId());
if (!$current instanceof \Entities\MailboxTask || !$otherRunner instanceof \Entities\QueueRunner) {
    throw new RuntimeException('ABA actor fixture missing');
}
$repo = maintenanceTaskRepository($other);
$repo->retryIfFailed($current, 'new actor');
$repo->claim($current, $otherRunner);
$repo->publishIfOwned($current, $otherRunner, static function () use ($current, $stale): void {
    // Deliberately restore every old field: only the generation differs.
    $current->setStatus('FAILED')->setRunner(null)->setStartedAt($stale->getStartedAt())
        ->setFinishedAt($stale->getFinishedAt())->setLog($stale->getLog())->setData($stale->getData());
});
$before = $observer->fetchAssociative('SELECT status,log,data,task_version FROM mailbox_task WHERE id = ?', [$id]);
$retry = maintenanceTaskRepository($em)->retryIfFailed($stale, 'STALE ACTOR');
maintenanceTaskLaterFlush($em);
$after = $observer->fetchAssociative('SELECT status,log,data,task_version FROM mailbox_task WHERE id = ?', [$id]);
maintenanceTaskCheck('stale FAILED snapshot cannot retry a newer identical FAILED generation (ABA)', !$retry
    && $after === $before && is_array($after) && maintenanceTaskInteger($after['task_version']) > $stale->getTaskVersion());

[$task] = maintenanceTaskFixture($em, 'FAILED');
$observer->executeStatement("CREATE TRIGGER maintenance_retry_fault BEFORE UPDATE ON mailbox_task FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected task flush failure'");
$caught = false;
try {
    maintenanceTaskRepository($em)->retryIfFailed($task, 'faulted actor');
} catch (\Doctrine\DBAL\Exception $error) {
    $caught = str_contains($error->getMessage(), 'injected task flush failure');
} finally {
    $observer->executeStatement('DROP TRIGGER maintenance_retry_fault');
}
maintenanceTaskLaterFlush($em);
$row = $observer->fetchAssociative('SELECT status,log,data FROM mailbox_task WHERE id = ?', [$task->getId()]);
maintenanceTaskCheck('retry flush failure rolls back and closes or clears ORM dirt', $caught && is_array($row)
    && $row['status'] === 'FAILED' && $row['log'] === 'original log' && $row['data'] === '{"preserved":true}');
$em = maintenanceTaskManager();

[$task] = maintenanceTaskFixture($em, 'FAILED');
$sync = sys_get_temp_dir() . '/maintenance-retry-' . bin2hex(random_bytes(8));
mkdir($sync, 0700);
$workers = [];
try {
    foreach (['one', 'two'] as $role) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, 'retry-worker', (string) $task->getId(), $sync, $role],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start retry actor');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    maintenanceRetryWait(static fn (): bool => is_file($sync . '/ready-one') && is_file($sync . '/ready-two'));
    $observer->beginTransaction();
    $observer->fetchOne('SELECT id FROM mailbox_task WHERE id = ? FOR UPDATE', [$task->getId()]);
    touch($sync . '/go');
    maintenanceRetryWait(static function () use ($observer, &$workers): bool {
        foreach ($workers as [$process, $pipes]) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                throw new RuntimeException('retry actor exited before contention: ' . stream_get_contents($pipes[2]));
            }
        }
        return maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE 'SELECT t.QueueRunner_id FROM mailbox_task t%'")) >= 2;
    });
    $observer->commit();
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $outcomes[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        maintenanceTaskCheck('independent retry actor completed', proc_close($process) === 0 && $error === '');
    }
    $workers = [];
    sort($outcomes);
    $row = $observer->fetchAssociative('SELECT status,task_version,log FROM mailbox_task WHERE id = ?', [$task->getId()]);
    maintenanceTaskCheck('contending retries of one failed generation have exactly one winner', $outcomes === ['stale', 'won']
        && is_array($row) && $row['status'] === 'PENDING' && is_string($row['log']) && substr_count($row['log'], 'retry queued by') === 1);
} finally {
    if ($observer->isTransactionActive()) {
        $observer->rollBack();
    }
    foreach ($workers as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    foreach (glob($sync . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($sync);
}

foreach (['success', 'stale', 'unauthorized', 'csrf', 'get', 'malformed-id'] as $case) {
    $em = maintenanceTaskManager();
    [$task] = maintenanceTaskFixture($em, 'FAILED');
    $admin = (new \Entities\Admin())->setUsername('operator@example.test')->setActive(true)->setSuper($case !== 'unauthorized');
    (new ReflectionProperty(\Entities\Admin::class, 'id'))->setValue($admin, 1);
    $session = (object) ['identity' => ['id' => 1], 'csrfToken' => 'fixture-token'];
    $storage = new \ViMbAdmin\Kernel\Session\MagicPropertyStorage($session);
    $auth = new \ViMbAdmin\Kernel\Security\Auth($storage, static fn (int $id): \Entities\Admin => $admin);
    $bootstrap = new class ($em, $session) {
        public function __construct(private \Doctrine\ORM\EntityManager $em, private object $session)
        {
        }
        public function getResource(string $name): object
        {
            return match ($name) {
                'doctrine2' => $this->em,
                'namespace' => $this->session,
                default => throw new RuntimeException('unexpected controller resource'),
            };
        }
        /** @return array<string,mixed> */
        public function getOptions(): array
        {
            return [];
        }
    };
    $controller = new \ViMbAdmin\Kernel\Controller\QueueController(
        new \ViMbAdmin\Kernel\Container($bootstrap, $auth),
        new \ViMbAdmin\Kernel\RouteMatch('queue', 'retry', 'QueueController', 'retryAction', [])
    );
    if ($case === 'stale') {
        $observer->executeStatement("UPDATE mailbox_task SET status = 'DONE', task_version = task_version + 1 WHERE id = ?", [$task->getId()]);
    }
    $before = $observer->fetchAssociative('SELECT status,log,data FROM mailbox_task WHERE id = ?', [$task->getId()]);
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = $case === 'get' ? 'GET' : 'POST';
        $_POST = ['id' => $case === 'malformed-id' ? ['invalid'] : $task->getId(),
            'csrf' => $case === 'csrf' ? 'wrong-token' : 'fixture-token'];
        $response = $controller->retryAction();
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
    }
    $after = $observer->fetchAssociative('SELECT status,log,data FROM mailbox_task WHERE id = ?', [$task->getId()]);
    $messages = (new \ViMbAdmin\Kernel\Flash\FlashMessages($storage))->peek();
    $expectedFlash = match ($case) {
        'success' => 'Task re-queued.',
        'csrf' => 'Invalid or missing security token. Please retry from the queue page.',
        'stale', 'malformed-id' => 'Task not found or not safely retryable.',
        default => null,
    };
    maintenanceTaskCheck('retry route preserves redirect, flash and guards: ' . $case, $response->status === 302
        && str_ends_with($response->headers['Location'], $case === 'unauthorized' ? '/auth/login' : '/queue/index')
        && ($expectedFlash === null ? $messages === [] : count($messages) === 1
            && $messages[0]->text === $expectedFlash && $messages[0]->level === ($case === 'success' ? 'success' : 'error'))
        && ($case === 'success' ? is_array($after) && $after['status'] === 'PENDING' && count($messages) === 1
            : $before === $after));
}

echo "ALL PASSED\n";
