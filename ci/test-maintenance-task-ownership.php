<?php

declare(strict_types=1);

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 for the isolated test database.\n");
    exit(2);
}
require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => __DIR__ . '/../application',
    'repositories_path' => __DIR__ . '/../application',
]]]);

final class MaintenanceTaskSqlLog extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    /** @param array<string,mixed> $context */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

function maintenanceTaskManager(?MaintenanceTaskSqlLog $log = null): EntityManager
{
    $legacyType = \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::class;
    if (!\Doctrine\DBAL\Types\Type::hasType($legacyType::NAME)) {
        \Doctrine\DBAL\Types\Type::addType($legacyType::NAME, $legacyType);
    }
    $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../application/Entities'], true);
    if (PHP_VERSION_ID >= 80400) {
        $config->enableNativeLazyObjects(true);
    } else {
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('MaintenanceProxies');
    }
    if ($log !== null) {
        $config->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($log)]);
    }
    return new EntityManager(DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ], $config), $config);
}

function maintenanceTaskRepository(EntityManager $em): \Repositories\MailboxTask
{
    $repo = $em->getRepository(\Entities\MailboxTask::class);
    if (!$repo instanceof \Repositories\MailboxTask) {
        throw new RuntimeException('mailbox-task repository mismatch');
    }
    return $repo;
}

function maintenanceTaskInteger(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (is_int($integer)) {
            return $integer;
        }
    }
    throw new RuntimeException('invalid database integer');
}

/** @return array{\Entities\MailboxTask,\Entities\QueueRunner} */
function maintenanceTaskFixture(EntityManager $em, string $status = 'RUNNING'): array
{
    $em->clear();
    $conn = $em->getConnection();
    $conn->executeStatement('DELETE FROM mailbox_task');
    $conn->executeStatement('DELETE FROM queue_runner');
    $runner = (new \Entities\QueueRunner())->setSlot(1)->setHost('maintenance')
        ->setPid(1)->setStartedAt(new DateTime())->setHeartbeatAt(new DateTime());
    $task = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
        ->setUsername('maintenance@example.test')->setStatus($status)
        ->setCreatedAt(new DateTime())->setStartedAt(new DateTime())
        ->setLog('original log')->setData('{"preserved":true}')
        ->setRunner($status === 'RUNNING' ? $runner : null);
    if ($status === 'FAILED') {
        $task->setFinishedAt(new DateTime());
    }
    $em->persist($runner);
    $em->persist($task);
    $em->flush();
    return [$task, $runner];
}

function maintenanceTaskCheck(string $name, bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    echo 'ok: ' . $name . "\n";
}

function maintenanceTaskLaterFlush(EntityManager $em): void
{
    if (!$em->isOpen()) {
        $em = maintenanceTaskManager();
    }
    $unrelated = (new \Entities\QueueRunner())->setSlot(10)->setHost('unrelated-flush')
        ->setPid(10)->setStartedAt(new DateTime())->setHeartbeatAt(new DateTime());
    $em->persist($unrelated);
    $em->flush();
}

final class MaintenanceTaskDoveadm extends \ViMbAdmin_Doveadm
{
    public function __construct(private \Closure $operation)
    {
    }

    #[\Override]
    public function quotaRecalc($user)
    {
        ($this->operation)();
        return [];
    }
}

final class MaintenanceTaskService extends \ViMbAdmin_Service_QueueRunner
{
    public DateTime $clock;
    public \Closure $backend;

    #[\Override]
    protected function now()
    {
        return clone $this->clock;
    }

    #[\Override]
    protected function newDoveadm($progress)
    {
        return new MaintenanceTaskDoveadm($this->backend);
    }
}

function maintenanceTaskMigrationTest(): void
{
    $em = maintenanceTaskManager();
    maintenanceTaskFixture($em, 'FAILED');
    $em->clear();
    $connection = $em->getConnection();
    $columns = 'id,type,username,status,priority,created_at,started_at,finished_at,log,data,abandoned';
    $before = $connection->fetchAllAssociative('SELECT ' . $columns . ' FROM mailbox_task ORDER BY id');
    $connection->executeStatement('ALTER TABLE mailbox_task DROP task_version');
    foreach ([1, 2] as $pass) {
        $pipes = [];
        $process = proc_open(
            [
            'mariadb', '--ssl=0', '--host=' . (getenv('DB_HOST') ?: '127.0.0.1'),
            '--port=' . (getenv('DB_PORT') ?: '3306'), '--user=' . (getenv('DB_USER') ?: 'root'),
            getenv('DB_NAME') ?: 'vimbadmin',
        ],
            [0 => ['file', __DIR__ . '/../contrib/migrations/2026-06-fork-schema.sql', 'r'],
            1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['MYSQL_PWD' => getenv('DB_PASS') ?: ''])
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start additive migration client');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('shipped fork migration failed: ' . $stderr . $stdout);
        }
        maintenanceTaskCheck(
            'additive task generation migration preserves legacy history, pass ' . $pass,
            $connection->fetchAllAssociative('SELECT ' . $columns . ' FROM mailbox_task ORDER BY id') === $before
            && maintenanceTaskInteger($connection->fetchOne('SELECT task_version FROM mailbox_task')) === 1
            && maintenanceTaskInteger($connection->fetchOne("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox_task' AND COLUMN_NAME = 'task_version'")) === 1
            && (new \ViMbAdmin_Schema($em))->pendingSql() === []
        );
    }
}

function maintenanceOwnershipTests(): void
{
    $em = maintenanceTaskManager();
    $schema = new \ViMbAdmin_Schema($em);
    $schema->apply($schema->pendingSql());
    $observer = maintenanceTaskManager()->getConnection();

    foreach (['DONE', 'FAILED'] as $terminal) {
        [$task, $runner] = maintenanceTaskFixture($em);
        $repo = maintenanceTaskRepository($em);
        $published = $repo->publishIfOwned($task, $runner, static function () use ($task, $terminal): void {
            $task->setStatus($terminal)->setRunner(null)->setFinishedAt(new DateTime())->appendLog('owned completion');
        });
        maintenanceTaskLaterFlush($em);
        maintenanceTaskCheck('current owner publishes ' . $terminal . ' atomically', $published
            && $observer->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [$task->getId()]) === $terminal);
    }

    foreach (['lost', 'replaced', 'terminal'] as $case) {
        [$task, $runner] = maintenanceTaskFixture($em);
        $repo = maintenanceTaskRepository($em);
        if ($case === 'lost') {
            $observer->executeStatement('DELETE FROM queue_runner WHERE id = ?', [$runner->getId()]);
        } elseif ($case === 'replaced') {
            $observer->executeStatement("INSERT INTO queue_runner (slot,host,pid,started_at,heartbeat_at) VALUES (2,'new-owner',2,NOW(),NOW())");
            $observer->executeStatement('UPDATE mailbox_task SET QueueRunner_id = ? WHERE id = ?', [$observer->lastInsertId(), $task->getId()]);
        } else {
            $observer->executeStatement("UPDATE mailbox_task SET status = 'FAILED', QueueRunner_id = NULL WHERE id = ?", [$task->getId()]);
        }
        $before = $observer->fetchAssociative('SELECT status,log,data,QueueRunner_id FROM mailbox_task WHERE id = ?', [$task->getId()]);
        // The old drain prepared terminal state before checking its heartbeat.
        $task->setStatus('DONE')->setRunner(null)->appendLog('STALE TERMINAL');
        $callback = false;
        $published = $repo->publishIfOwned($task, $runner, static function () use (&$callback): void {
            $callback = true;
        });
        \ViMbAdmin_QueueRunner::release($em, $runner);
        maintenanceTaskLaterFlush($em);
        $after = $observer->fetchAssociative('SELECT status,log,data,QueueRunner_id FROM mailbox_task WHERE id = ?', [$task->getId()]);
        // Releasing this old lease may null its FK, but cannot overwrite task data.
        maintenanceTaskCheck($case . ' owner cannot publish dirty terminal state through release or later flush', !$published && !$callback
            && is_array($before) && is_array($after) && $after['status'] === $before['status']
            && $after['log'] === $before['log'] && $after['data'] === $before['data']
            && ($case !== 'replaced' || $after['QueueRunner_id'] === $before['QueueRunner_id']));
    }

    [$task] = maintenanceTaskFixture($em);
    $task->setStatus('DONE')->appendLog('INVALID LEASE');
    $caught = false;
    try {
        maintenanceTaskRepository($em)->publishIfOwned($task, new \Entities\QueueRunner(), static function (): void {
            throw new RuntimeException('invalid lease callback must not run');
        });
    } catch (LogicException $error) {
        $caught = $error->getMessage() === 'Runner lease must be persisted before terminal publication.';
    }
    maintenanceTaskLaterFlush($em);
    maintenanceTaskCheck('unpersisted lease fails closed and clears terminal dirt', $caught
        && $observer->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [$task->getId()]) === 'RUNNING');

    [$task, $runner] = maintenanceTaskFixture($em);
    $caught = false;
    try {
        maintenanceTaskRepository($em)->publishIfOwned($task, $runner, static function () use ($task): void {
            $task->setStatus('FAILED')->setRunner(null)->appendLog('ROLLED BACK');
            throw new RuntimeException('injected completion failure');
        });
    } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'injected completion failure';
    }
    \ViMbAdmin_QueueRunner::release($em, $runner);
    maintenanceTaskLaterFlush($em);
    $row = $observer->fetchAssociative('SELECT status,log FROM mailbox_task WHERE id = ?', [$task->getId()]);
    maintenanceTaskCheck('rollback discards terminal ORM dirt before unrelated later flush', $caught && is_array($row)
        && $row['status'] === 'RUNNING' && $row['log'] === 'original log');

    foreach ([false, true] as $fail) {
        [$task, $runner] = maintenanceTaskFixture($em);
        $extra = (new \Entities\QueueRunner())->setSlot(3)->setHost('callback-entity')
            ->setPid(3)->setStartedAt(new DateTime())->setHeartbeatAt(new DateTime());
        $caught = false;
        try {
            maintenanceTaskRepository($em)->publishIfOwned($task, $runner, static function () use ($em, $task, $extra, $fail): void {
                $task->setStatus('DONE')->setRunner(null);
                $em->persist($extra);
                if ($fail) {
                    throw new RuntimeException('callback group failure');
                }
            });
        } catch (RuntimeException $error) {
            $caught = $error->getMessage() === 'callback group failure';
        }
        maintenanceTaskLaterFlush($em);
        maintenanceTaskCheck('callback ORM effects ' . ($fail ? 'rollback' : 'commit') . ' with the task', $caught === $fail
            && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner WHERE slot = 3')) === ($fail ? 0 : 1)
            && $observer->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [$task->getId()]) === ($fail ? 'RUNNING' : 'DONE'));
    }

    foreach (['manual', 'batch'] as $mode) {
        foreach (['success', 'failure', 'lease-loss'] as $outcome) {
            [$task] = maintenanceTaskFixture($em, 'PENDING');
            // The fixture lease is not the one the service will acquire.
            $observer->executeStatement('DELETE FROM queue_runner');
            $service = new MaintenanceTaskService($em, ['queue' => ['runner' => ['max_concurrent' => 1]]]);
            $service->clock = new DateTime();
            $calls = 0;
            $service->backend = static function () use ($observer, $service, $outcome, &$calls): void {
                $calls++;
                if ($outcome === 'lease-loss') {
                    $observer->executeStatement('DELETE FROM queue_runner');
                    $service->clock->modify('+61 seconds');
                } elseif ($outcome === 'failure') {
                    throw new RuntimeException('injected backend error');
                }
            };
            $caught = false;
            $completed = false;
            try {
                if ($mode === 'batch') {
                    $completed = $service->drain(1) === 1;
                } else {
                    $completed = $service->runOne($task, static function (?Throwable $error) use ($task): void {
                        $task->setStatus($error === null ? 'DONE' : 'FAILED')->setRunner(null)
                            ->setFinishedAt(new DateTime())->appendLog($error === null ? 'done' : 'FAILED: ' . $error->getMessage());
                    }) === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED;
                }
            } catch (RuntimeException $error) {
                $caught = $error->getMessage() === 'Runner lease was lost before its heartbeat.';
            }
            maintenanceTaskLaterFlush($em);
            $row = $observer->fetchAssociative('SELECT status,log,QueueRunner_id FROM mailbox_task WHERE id = ?', [$task->getId()]);
            maintenanceTaskCheck($mode . ' service ' . $outcome . ' keeps completion within the ownership fence', $calls === 1
                && is_array($row) && ($outcome === 'lease-loss'
                    ? $caught && !$completed && $row['status'] === 'RUNNING' && $row['log'] === 'original log'
                    : $completed && !$caught && $row['status'] === ($outcome === 'failure' ? 'FAILED' : 'DONE')));
        }
    }

    foreach ([false, true] as $deleteSecond) {
        [$first] = maintenanceTaskFixture($em, 'PENDING');
        $observer->executeStatement('DELETE FROM queue_runner');
        $second = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
            ->setUsername('independent@example.test')->setStatus('PENDING')->setCreatedAt(new DateTime());
        $em->persist($second);
        $em->flush();
        $third = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
            ->setUsername('third@example.test')->setStatus('PENDING')->setCreatedAt(new DateTime());
        if ($deleteSecond) {
            $em->persist($third);
            $em->flush();
        }
        $service = new MaintenanceTaskService($em, ['queue' => ['runner' => ['max_concurrent' => 1]]]);
        $service->clock = new DateTime();
        $calls = 0;
        $service->backend = static function () use ($observer, $first, $second, $deleteSecond, &$calls): void {
            if (++$calls === 1) {
                $observer->executeStatement("UPDATE mailbox_task SET status = 'FAILED', QueueRunner_id = NULL, task_version = task_version + 1 WHERE id = ?", [$first->getId()]);
                if ($deleteSecond) {
                    $observer->executeStatement('DELETE FROM mailbox_task WHERE id = ?', [$second->getId()]);
                }
            }
        };
        $processed = null;
        try {
            $processed = $service->drain($deleteSecond ? 3 : 2);
        } catch (Throwable $error) {
            fwrite(STDERR, 'two-task continuation error: ' . $error->getMessage() . "\n");
        }
        maintenanceTaskCheck('stale first task leaves next pending task independently runnable' . ($deleteSecond ? ' after deleted snapshot' : ''), $processed === 1 && $calls === 2
            && $observer->fetchOne('SELECT status FROM mailbox_task WHERE id = ?', [($deleteSecond ? $third : $second)->getId()]) === 'DONE'
            && $observer->fetchOne('SELECT log FROM mailbox_task WHERE id = ?', [$first->getId()]) === 'original log');

    }

    [$task, $runner] = maintenanceTaskFixture($em);
    $task->setStatus('DONE')->appendLog('STALE RELEASE FAILURE');
    $observer->executeStatement('UPDATE mailbox_task SET QueueRunner_id = NULL WHERE id = ?', [$task->getId()]);
    maintenanceTaskRepository($em)->publishIfOwned($task, $runner, static function (): void {
        throw new RuntimeException('lost owner callback must not run');
    });
    $observer->executeStatement("CREATE TRIGGER maintenance_release_fault BEFORE DELETE ON queue_runner FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected release failure'");
    try {
        \ViMbAdmin_QueueRunner::release($em, $runner);
    } finally {
        $observer->executeStatement('DROP TRIGGER maintenance_release_fault');
    }
    maintenanceTaskLaterFlush($em);
    $row = $observer->fetchAssociative('SELECT status,log FROM mailbox_task WHERE id = ?', [$task->getId()]);
    maintenanceTaskCheck('best-effort release failure cannot revive rejected terminal dirt', is_array($row)
        && $row['status'] === 'RUNNING' && $row['log'] === 'original log'
        && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner WHERE id = ?', [$runner->getId()])) === 1);

    [$task, $runner] = maintenanceTaskFixture($em);
    $observer->executeStatement("CREATE TRIGGER maintenance_terminal_fault BEFORE UPDATE ON mailbox_task FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected terminal flush failure'");
    $caught = false;
    try {
        maintenanceTaskRepository($em)->publishIfOwned($task, $runner, static function () use ($task): void {
            $task->setStatus('DONE')->setRunner(null)->appendLog('FAILED FLUSH');
        });
    } catch (\Doctrine\DBAL\Exception $error) {
        $caught = str_contains($error->getMessage(), 'injected terminal flush failure');
    } finally {
        $observer->executeStatement('DROP TRIGGER maintenance_terminal_fault');
    }
    \ViMbAdmin_QueueRunner::release($em, $runner);
    maintenanceTaskLaterFlush($em);
    $row = $observer->fetchAssociative('SELECT status,log FROM mailbox_task WHERE id = ?', [$task->getId()]);
    maintenanceTaskCheck('terminal database flush failure rolls back before later external flush', $caught
        && is_array($row) && $row['status'] === 'RUNNING' && $row['log'] === 'original log');

    maintenanceTaskMigrationTest();

    echo "ALL PASSED\n";
}

if (!defined('MAINTENANCE_TASK_HELPERS_ONLY')) {
    maintenanceOwnershipTests();
}
