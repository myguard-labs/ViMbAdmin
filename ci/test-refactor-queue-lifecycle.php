<?php

declare(strict_types=1);

const QUEUE_LIFECYCLE_DATABASE = 'vimbadmin_queue_lifecycle_test';

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Queue lifecycle fixture requires VIMBADMIN_DESTRUCTIVE_DB_TESTS=1.\n");
    exit(2);
}
if (getenv('DB_NAME') !== QUEUE_LIFECYCLE_DATABASE) {
    fwrite(STDERR, 'Queue lifecycle fixture requires DB_NAME=' . QUEUE_LIFECYCLE_DATABASE . ".\n");
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

function queueLifecycleManager(): EntityManager
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
        $config->setProxyNamespace('QueueLifecycleProxies');
    }

    return new EntityManager(DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => QUEUE_LIFECYCLE_DATABASE,
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ], $config), $config);
}

function queueLifecycleInteger(mixed $value): int
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
    throw new RuntimeException('Database result is not a non-negative integer.');
}

function queueLifecycleCheck(string $name, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    echo 'ok: ' . $name . "\n";
}

function queueLifecycleReset(EntityManager $em): void
{
    $em->clear();
    $connection = $em->getConnection();
    $connection->executeStatement('DELETE FROM resource_claim');
    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement('DELETE FROM queue_runner');
}

function queueLifecycleTask(
    EntityManager $em,
    string $status = \Entities\MailboxTask::STATUS_PENDING,
    string $type = \Entities\MailboxTask::TYPE_QUOTA_RECALC,
    string $username = 'queue-lifecycle@example.test',
    ?\Entities\Domain $domain = null
): \Entities\MailboxTask {
    $task = (new \Entities\MailboxTask())
        ->setType($type)
        ->setUsername($username)
        ->setStatus($status)
        ->setCreatedAt(new DateTime('2026-10-16 10:00:00'))
        ->setLog('seed')
        ->setDomain($domain);
    if ($status !== \Entities\MailboxTask::STATUS_PENDING) {
        $task->setFinishedAt(new DateTime('2026-10-16 10:00:00'));
    }
    $em->persist($task);
    $em->flush();
    return $task;
}

/** Delete only a fixture claim whose exact token and expected phase were observed. */
function queueLifecycleReconcileClaim(
    \Doctrine\DBAL\Connection $observer,
    string $username,
    string $phase
): void {
    $key = \ViMbAdmin_ResourceClaim::key($username);
    $row = $observer->fetchAssociative(
        'SELECT owner_token, phase FROM resource_claim WHERE resource_key = ?',
        [$key]
    );
    if (!is_array($row) || !is_string($row['owner_token'] ?? null) || ($row['phase'] ?? null) !== $phase) {
        throw new RuntimeException('Expected retained fixture claim was not present.');
    }
    $affected = $observer->executeStatement(
        'DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = ?',
        [$key, $row['owner_token'], $phase]
    );
    queueLifecycleCheck('fixture cleanup removes only the observed ' . $phase . ' owner token', $affected === 1);
}

final class QueueLifecycleDoveadm extends \ViMbAdmin_Doveadm
{
    /**
     * @param Closure():void $probeProgress
     * @param Closure(string,Closure():void):array<mixed> $probeOperation
     */
    public function __construct(private Closure $probeProgress, private Closure $probeOperation)
    {
    }

    /** @return array<mixed> */
    #[\Override]
    public function quotaRecalc($user)
    {
        return ($this->probeOperation)((string) $user, $this->probeProgress);
    }
}

final class QueueLifecycleService extends \ViMbAdmin_Service_QueueRunner
{
    public DateTime $clock;
    /** @var Closure(string,Closure():void):array<mixed> */
    public Closure $backend;
    public int $doveadmConstructions = 0;
    public ?Throwable $doveadmConstructionError = null;

    #[\Override]
    protected function now()
    {
        return clone $this->clock;
    }

    #[\Override]
    protected function newDoveadm($progress)
    {
        ++$this->doveadmConstructions;
        if ($this->doveadmConstructionError !== null) {
            throw $this->doveadmConstructionError;
        }
        $probeProgress = static function () use ($progress): void {
            $progress();
        };
        return new QueueLifecycleDoveadm($probeProgress, $this->backend);
    }
}

final class QueueLifecycleDeletionDoveadm extends \ViMbAdmin_Doveadm
{
    /** @var Closure():void */
    private Closure $progress;
    /** @var Closure(string,array<int,mixed>,Closure():void):mixed */
    private Closure $operation;

    /**
     * @param Closure():void $progress
     * @param Closure(string,array<int,mixed>,Closure():void):mixed $operation
     */
    public function __construct(Closure $progress, Closure $operation)
    {
        $this->progress = $progress;
        $this->operation = $operation;
    }

    /** @param array<int,mixed> $arguments */
    private function invoke(string $name, array $arguments): mixed
    {
        return ($this->operation)($name, $arguments, $this->progress);
    }

    /** @return array<mixed> */
    #[\Override]
    public function backup($user, $dest)
    {
        $result = $this->invoke('backup', [(string) $user, (string) $dest]);
        return is_array($result) ? $result : [];
    }

    /** @return array<mixed> */
    #[\Override]
    public function quotaRecalc($user)
    {
        $result = $this->invoke('quotaRecalc', [(string) $user]);
        return is_array($result) ? $result : [];
    }

    #[\Override]
    public function mailboxDelete($user)
    {
        $this->invoke('mailboxDelete', [(string) $user]);
    }

    /** @return list<string> */
    #[\Override]
    public function fsListDirs($path, $filter = 'posix')
    {
        $result = $this->invoke('fsListDirs', [(string) $path, (string) $filter]);
        if (!is_array($result) || !array_is_list($result)) {
            throw new RuntimeException('Deletion fixture directory result must be a list.');
        }
        foreach ($result as $directory) {
            if (!is_string($directory)) {
                throw new RuntimeException('Deletion fixture directory name must be a string.');
            }
        }
        /** @var list<string> $result */
        return $result;
    }

    #[\Override]
    public function maildirHasMail($maildir, $filter = 'posix')
    {
        return $this->invoke('maildirHasMail', [(string) $maildir, (string) $filter]) === true;
    }

    /** @return array<mixed> */
    #[\Override]
    public function fsDelete($path, $filter = 'posix')
    {
        $result = $this->invoke('fsDelete', [(string) $path, (string) $filter]);
        return is_array($result) ? $result : [];
    }
}

final class QueueLifecycleDeletionService extends \ViMbAdmin_Service_QueueRunner
{
    public DateTime $clock;
    /** @var Closure(string,array<int,mixed>,Closure():void):mixed */
    public Closure $backend;
    /** @var (Closure():void)|null */
    public ?Closure $beforeDoveadm = null;

    #[\Override]
    protected function now()
    {
        return clone $this->clock;
    }

    #[\Override]
    protected function newDoveadm($progress)
    {
        if ($this->beforeDoveadm !== null) {
            ($this->beforeDoveadm)();
        }
        $leaseProgress = static function () use ($progress): void {
            $progress();
        };
        return new QueueLifecycleDeletionDoveadm($leaseProgress, $this->backend);
    }
}

final class QueueLifecycleRemovalObserver
{
    /** @var array<string,array{entity:string,transaction:bool,nesting:int,claim_phase:mixed}> */
    public array $records = [];

    public function __construct(
        private EntityManager $manager,
        private \Doctrine\DBAL\Connection $observer
    ) {
    }

    public function preRemove(\Doctrine\ORM\Event\PreRemoveEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof \Entities\Archive && !$entity instanceof \Entities\Mailbox) {
            return;
        }
        $username = $entity->requiredUsername();
        $connection = $this->manager->getConnection();
        $this->records[$username] = [
            'entity' => $entity::class,
            'transaction' => $connection->isTransactionActive(),
            'nesting' => $connection->getTransactionNestingLevel(),
            'claim_phase' => $this->observer->fetchOne(
                'SELECT phase FROM resource_claim WHERE resource_key = ?',
                [\ViMbAdmin_ResourceClaim::key($username)]
            ),
        ];
    }
}

/** @return array{0:\Entities\Domain,1:\Entities\Mailbox} */
function queueLifecycleMailboxFixture(EntityManager $em, string $username): array
{
    [$localPart, $domainName] = explode('@', $username, 2);
    $domain = (new \Entities\Domain())
        ->setDomain($domainName)
        ->setQuota(0)
        ->setTransport('virtual')
        ->setBackupmx(false)
        ->setActive(true)
        ->setCreated(new DateTime('2026-10-16 09:00:00'))
        ->setMaxAliases(0)
        ->setMaxMailboxes(0)
        ->setMaxQuota(0)
        ->setAliasCount(0)
        ->setMailboxCount(1);
    $mailbox = (new \Entities\Mailbox())
        ->setUsername($username)
        ->setPassword('!queue-lifecycle-fixture')
        ->setName('Queue Lifecycle Fixture')
        ->setQuota(0)
        ->setLocalPart($localPart)
        ->setActive(true)
        ->setCreated(new DateTime('2026-10-16 09:00:00'))
        ->setDeletePending(false)
        ->setDomain($domain);
    $em->persist($domain);
    $em->persist($mailbox);
    $em->flush();
    return [$domain, $mailbox];
}

function queueLifecycleActor(EntityManager $em): \Entities\Admin
{
    $actor = (new \Entities\Admin())
        ->setUsername('queue-lifecycle-actor@example.test')
        ->setPassword('!queue-lifecycle-fixture')
        ->setSuper(true)
        ->setActive(true)
        ->setCreated(new DateTime('2026-10-16 09:00:00'));
    $em->persist($actor);
    $em->flush();
    return $actor;
}

function queueLifecycleArchiveFixture(
    EntityManager $em,
    string $username,
    ?\Entities\Domain $domain,
    ?\Entities\Admin $actor,
    string $destination
): \Entities\Archive {
    $archive = (new \Entities\Archive())
        ->setUsername($username)
        ->setStatus(\Entities\Archive::STATUS_ARCHIVED)
        ->setArchivedAt(new DateTime('2026-10-16 09:00:00'))
        ->setStatusChangedAt(new DateTime('2026-10-16 09:00:00'))
        ->setData('{}')
        ->setMaildirFile($destination)
        ->setAutoprune(false)
        ->setDomain($domain)
        ->setArchivedBy($actor);
    $em->persist($archive);
    $em->flush();
    return $archive;
}

function queueLifecycleResetDeletion(EntityManager $em): void
{
    $em->clear();
    $connection = $em->getConnection();
    $connection->executeStatement('DELETE FROM resource_claim');
    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement('DELETE FROM queue_runner');
    $connection->executeStatement('DELETE FROM log');
    $connection->executeStatement('DELETE FROM archive');
    $connection->executeStatement('DELETE FROM mailbox');
    $connection->executeStatement('DELETE FROM domain_admins');
    $connection->executeStatement('DELETE FROM domain');
    $connection->executeStatement('DELETE FROM admin_pref');
    $connection->executeStatement('DELETE FROM admin');
    $connection->executeStatement('DELETE FROM dovecot_quota');
}

/** @return array{status:string,QueueRunner_id:mixed,task_version:mixed,log:mixed} */
function queueLifecycleTaskRow(\Doctrine\DBAL\Connection $observer, int $id): array
{
    $row = $observer->fetchAssociative(
        'SELECT status, QueueRunner_id, task_version, log FROM mailbox_task WHERE id = ?',
        [$id]
    );
    if (!is_array($row)
        || !array_key_exists('status', $row) || !is_string($row['status'])
        || !array_key_exists('QueueRunner_id', $row)
        || !array_key_exists('task_version', $row)
        || !array_key_exists('log', $row)) {
        throw new RuntimeException('Mailbox-task fixture row is missing.');
    }
    return [
        'status' => $row['status'],
        'QueueRunner_id' => $row['QueueRunner_id'],
        'task_version' => $row['task_version'],
        'log' => $row['log'],
    ];
}

function queueLifecycleResetAutoprune(EntityManager $em): void
{
    queueLifecycleResetDeletion($em);
    $em->getConnection()->executeStatement(
        'DELETE FROM setting WHERE name IN (?, ?, ?)',
        [
            \ViMbAdmin_Setting::LAST_QUEUERUN,
            \ViMbAdmin_Setting::LAST_PRUNE,
            \ViMbAdmin_Setting::LAST_PRUNE_SWEEP,
        ]
    );
}

function queueLifecyclePruneTask(
    EntityManager $em,
    \Entities\Archive $archive,
    DateTimeInterface $cutoff,
    ?string $data = null
): \Entities\MailboxTask {
    $archiveId = $archive->getId();
    if (!is_int($archiveId) || $archiveId < 1) {
        throw new RuntimeException('Autoprune fixture archive must be persisted.');
    }
    $task = queueLifecycleTask(
        $em,
        type: \Entities\MailboxTask::TYPE_PRUNE,
        username: $archive->requiredUsername(),
        domain: $archive->getDomain()
    );
    $task->setData($data ?? json_encode([
        'dest' => $archive->getMaildirFile(),
        'archive_id' => $archiveId,
        'archive_generation' => $archive->getArchiveGeneration(),
        'archive_version' => $archive->getArchiveVersion(),
        'cutoff' => $cutoff->getTimestamp(),
    ], JSON_THROW_ON_ERROR));
    $em->flush();
    return $task;
}

final class QueueLifecyclePublicationObserver
{
    /** @var array<int,array{status:string,transaction:bool,nesting:int,primary_claim_count:int,observer_claim_count:int,lease_count:int}> */
    public array $records = [];

    public function __construct(
        private EntityManager $manager,
        private \Doctrine\DBAL\Connection $observer
    ) {
    }

    public function preUpdate(\Doctrine\ORM\Event\PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof \Entities\MailboxTask) {
            return;
        }
        $id = $entity->getId();
        if (!is_int($id)) {
            throw new RuntimeException('Published queue task must have an identifier.');
        }
        $status = $entity->getStatus();
        if (!is_string($status)) {
            throw new RuntimeException('Published queue task must have a status.');
        }
        $connection = $this->manager->getConnection();
        $this->records[$id] = [
            'status' => $status,
            'transaction' => $connection->isTransactionActive(),
            'nesting' => $connection->getTransactionNestingLevel(),
            'primary_claim_count' => queueLifecycleInteger($connection->fetchOne('SELECT COUNT(*) FROM resource_claim')),
            'observer_claim_count' => queueLifecycleInteger($this->observer->fetchOne('SELECT COUNT(*) FROM resource_claim')),
            'lease_count' => queueLifecycleInteger($this->observer->fetchOne('SELECT COUNT(*) FROM queue_runner')),
        ];
    }
}

final class QueueLifecycleOrphanBackend
{
    /** @var list<mixed> */
    public array $rootEntries = [];
    /** @var array<string,list<string>> */
    public array $directories = [];
    /** @var array<string,bool> */
    public array $hasMail = [];
    /** @var list<array{command:string,parameters:array<string,mixed>}> */
    public array $calls = [];
    public ?string $ambiguousCommand = null;
    /** @var (Closure(string,array<string,mixed>):void)|null */
    public ?Closure $beforeResponse = null;

    /** @param array<mixed> $parameters
     *  @return array<mixed>
     */
    public function respond(string $command, array $parameters): array
    {
        $validated = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Orphan backend parameters must use string keys.');
            }
            $validated[$key] = $value;
        }
        $parameters = $validated;
        $this->calls[] = ['command' => $command, 'parameters' => $parameters];
        if ($this->beforeResponse !== null) {
            ($this->beforeResponse)($command, $parameters);
        }
        if ($command === $this->ambiguousCommand) {
            throw new RuntimeException('injected orphan backend interruption');
        }

        if ($command === 'fsIterDirs') {
            $path = $parameters['path'] ?? null;
            if (!is_string($path)) {
                throw new RuntimeException('Orphan fixture directory path must be a string.');
            }
            $path = rtrim($path, '/');
            $names = $path === '/mail' ? $this->rootEntries : ($this->directories[$path] ?? []);
            return array_map(static fn (mixed $name): array => ['path' => $name], $names);
        }

        if ($command === 'fsIter') {
            $path = $parameters['path'] ?? null;
            if (!is_string($path)) {
                throw new RuntimeException('Orphan fixture file path must be a string.');
            }
            $path = rtrim($path, '/');
            $maildir = preg_replace('#/(cur|new)$#D', '', $path);
            if (!is_string($maildir)) {
                throw new RuntimeException('Orphan fixture could not resolve its maildir.');
            }
            return ($this->hasMail[$maildir] ?? false) && str_ends_with($path, '/cur')
                ? [['path' => 'message']]
                : [];
        }

        if ($command === 'mailboxDelete') {
            $user = $parameters['user'] ?? null;
            if (!is_string($user)) {
                throw new RuntimeException('Orphan fixture mailbox identity must be a string.');
            }
            $this->hasMail['/mail/' . $user] = false;
        }
        if ($command === 'fsDelete') {
            $paths = $parameters['path'] ?? null;
            $path = is_array($paths) ? ($paths[0] ?? null) : null;
            if (is_string($path)) {
                unset($this->directories[rtrim($path, '/')], $this->hasMail[rtrim($path, '/')]);
            }
        }
        return [];
    }
}

final class QueueLifecycleOrphanDoveadm extends \ViMbAdmin_Doveadm
{
    /** @var Closure(string,array<mixed>):array<mixed> */
    private Closure $operation;

    /**
     * @param Closure():void $progress
     * @param Closure(string,array<mixed>):array<mixed> $operation
     */
    public function __construct(Closure $progress, Closure $operation)
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'fixture-key', 1, $progress);
        $this->operation = $operation;
    }

    /** @return array{0:int,1:string} */
    #[\Override]
    protected function _post($payload)
    {
        if (!is_string($payload)) {
            throw new RuntimeException('Orphan fixture request must be JSON.');
        }
        $request = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $envelope = is_array($request) && count($request) === 1 ? ($request[0] ?? null) : null;
        $command = is_array($envelope) ? ($envelope[0] ?? null) : null;
        $parameters = is_array($envelope) ? ($envelope[1] ?? null) : null;
        $tag = is_array($envelope) ? ($envelope[2] ?? null) : null;
        if (!is_string($command) || !is_array($parameters) || !is_string($tag)) {
            throw new RuntimeException('Unexpected orphan fixture request.');
        }
        foreach ($parameters as $key => $_value) {
            if (!is_string($key)) {
                throw new RuntimeException('Orphan fixture request parameters must use string keys.');
            }
        }
        $result = ($this->operation)($command, $parameters);
        return [200, json_encode([['doveadmResponse', $result, $tag]], JSON_THROW_ON_ERROR)];
    }

    #[\Override]
    public function mailboxDelete($user)
    {
        $this->reportProgress();
        ($this->operation)('mailboxDelete', ['user' => (string) $user]);
    }
}

final class QueueLifecycleOrphanService extends \ViMbAdmin_Service_QueueRunner
{
    public DateTime $clock;
    public QueueLifecycleOrphanBackend $backend;
    /** @var (Closure():void)|null */
    public ?Closure $beforeDoveadm = null;

    #[\Override]
    protected function now()
    {
        return clone $this->clock;
    }

    #[\Override]
    protected function newDoveadm($progress)
    {
        if ($this->beforeDoveadm !== null) {
            ($this->beforeDoveadm)();
        }
        $leaseProgress = static function () use ($progress): void {
            $progress();
        };
        /** @param array<mixed> $parameters
         *  @return array<mixed>
         */
        $operation = function (string $command, array $parameters): array {
            return $this->backend->respond($command, $parameters);
        };
        return new QueueLifecycleOrphanDoveadm($leaseProgress, $operation);
    }
}

/** @return array{int,?Throwable,bool} */
function queueLifecycleRunOrphan(
    QueueLifecycleOrphanService $service,
    \Entities\MailboxTask $task,
    DateTime $finishedAt
): array {
    $receivedError = null;
    $completed = false;
    $result = $service->runOne($task, static function (?Throwable $error) use (
        $task,
        $finishedAt,
        &$receivedError,
        &$completed
    ): void {
        $receivedError = $error;
        $completed = true;
        $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
            ->setRunner(null)->setFinishedAt(clone $finishedAt);
        if ($error !== null) {
            $task->appendLog('FAILED: ' . $error->getMessage());
        }
    });
    return [$result, $receivedError, $completed];
}

$em = queueLifecycleManager();
queueLifecycleCheck(
    'fixture connection selects only the dedicated queue lifecycle database',
    $em->getConnection()->fetchOne('SELECT DATABASE()') === QUEUE_LIFECYCLE_DATABASE
);
$schema = new \ViMbAdmin_Schema($em);
$schema->apply($schema->pendingSql());
$observerManager = queueLifecycleManager();
$observer = $observerManager->getConnection();
$options = ['queue' => ['runner' => ['max_concurrent' => 1]]];

// The dedicated database starts empty, so seed the child row that makes the
// admin_pref-before-admin cleanup order observable without disabling FKs.
$cleanupActor = queueLifecycleActor($em);
$cleanupPreference = (new \Entities\AdminPreference())
    ->setAttribute('queue-lifecycle-cleanup')
    ->setIx(0)
    ->setOp(':=')
    ->setValue('owned-child')
    ->setExpire(0)
    ->setAdmin($cleanupActor);
$em->persist($cleanupPreference);
$em->flush();
queueLifecycleResetDeletion($em);
queueLifecycleCheck(
    'fixture cleanup removes its admin preference child before its admin owner',
    queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM admin_pref')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM admin')) === 0
);

// Both public callers share one claimed-task storage lifecycle while retaining
// their distinct claim, completion and result policies.
queueLifecycleReset($em);
$manualTask = queueLifecycleTask($em, username: 'shared-manual-lifecycle@example.test');
$manualService = new QueueLifecycleService($em, $options);
$manualService->clock = new DateTime('2026-10-16 09:00:00');
$manualBackendCalls = 0;
$manualCallbackError = null;
$manualService->backend = static function () use (&$manualBackendCalls): array {
    ++$manualBackendCalls;
    return [];
};
$manualResult = $manualService->runOne(
    $manualTask,
    static function (?Throwable $error) use ($manualTask, &$manualCallbackError): void {
        $manualCallbackError = $error;
        $manualTask->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
            ->setRunner(null)
            ->setFinishedAt(new DateTime('2026-10-16 09:00:00'));
    }
);
$manualRow = queueLifecycleTaskRow($observer, (int) $manualTask->getId());

queueLifecycleReset($em);
$drainTask = queueLifecycleTask($em, username: 'shared-drain-lifecycle@example.test');
$drainService = new QueueLifecycleService($em, $options);
$drainService->clock = new DateTime('2026-10-16 09:30:00');
$drainBackendCalls = 0;
$drainService->backend = static function () use (&$drainBackendCalls): array {
    ++$drainBackendCalls;
    return [];
};
$drainResult = $drainService->drain(1);
$drainRow = queueLifecycleTaskRow($observer, (int) $drainTask->getId());
queueLifecycleCheck(
    'shared lifecycle owner: runOne and drain both execute and publish claimed tasks without leaking lease or resource ownership',
    $manualResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $manualBackendCalls === 1
    && $manualCallbackError === null
    && $manualRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && $drainResult === 1
    && $drainBackendCalls === 1
    && $drainRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// The old callers intentionally differed after resource acquisition failed:
// drain still validated backend construction (and preferred that error), while
// runOne published the resource-contention error without constructing it.
queueLifecycleReset($em);
$manualTask = queueLifecycleTask($em, username: 'contended-manual-construction@example.test');
$manualClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $manualTask->requiredUsername());
$manualService = new QueueLifecycleService($em, $options);
$manualService->clock = new DateTime('2026-10-16 09:40:00');
$manualService->backend = static fn (): array => [];
$manualService->doveadmConstructionError = new RuntimeException('invalid manual backend configuration');
$manualError = null;
$manualResult = $manualService->runOne($manualTask, static function (?Throwable $error) use (
    $manualTask,
    &$manualError
): void {
    $manualError = $error;
    $manualTask->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 09:40:00'));
});
$manualRow = queueLifecycleTaskRow($observer, (int) $manualTask->getId());
$manualClaim->release();
$manualClaim->close();

queueLifecycleReset($em);
$drainTask = queueLifecycleTask($em, username: 'contended-drain-construction@example.test');
$drainClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $drainTask->requiredUsername());
$drainService = new QueueLifecycleService($em, $options);
$drainService->clock = new DateTime('2026-10-16 09:50:00');
$drainService->backend = static fn (): array => [];
$drainService->doveadmConstructionError = new RuntimeException('invalid drain backend configuration');
$drainResult = $drainService->drain(1);
$drainRow = queueLifecycleTaskRow($observer, (int) $drainTask->getId());
queueLifecycleCheck(
    'caller construction parity: drain preserves backend error precedence while runOne publishes resource contention without construction',
    $manualResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $manualService->doveadmConstructions === 0
    && $manualError instanceof \ViMbAdmin_Exception
    && $manualError->getMessage() === 'Mailbox resource is already claimed.'
    && $manualRow['status'] === \Entities\MailboxTask::STATUS_FAILED
    && $drainResult === 1
    && $drainService->doveadmConstructions === 1
    && is_string($drainRow['log'])
    && str_contains($drainRow['log'], 'FAILED: invalid drain backend configuration')
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
$drainClaim->release();
$drainClaim->close();

// Positive path: the real service, repository, lease helper and resource claim
// execute one representative maintenance mutation against real MariaDB state.
queueLifecycleReset($em);
$task = queueLifecycleTask($em);
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 10:00:00');
$backend = [];
$callback = [];
$backendCalls = 0;
$callbackCalls = 0;
$service->backend = static function (string $username, Closure $progress) use (
    $em,
    $observer,
    $service,
    $task,
    &$backendCalls,
    &$backend
): array {
    ++$backendCalls;
    $backend['username'] = $username;
    $backend['primary_transaction'] = $em->getConnection()->isTransactionActive();
    $backend['claim_phase'] = $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($username)]
    );
    $row = queueLifecycleTaskRow($observer, (int) $task->getId());
    $backend['task_status'] = $row['status'];
    $backend['task_owner'] = $row['QueueRunner_id'];
    $backend['lease_count'] = queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner'));
    $service->clock->modify('+61 seconds');
    $progress();
    $backend['heartbeat'] = $observer->fetchOne('SELECT heartbeat_at FROM queue_runner');
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use (
    $em,
    $task,
    &$callbackCalls,
    &$callback
): void {
    $connection = $em->getConnection();
    ++$callbackCalls;
    $callback['error'] = $error;
    $callback['transaction'] = $connection->isTransactionActive();
    $callback['claim_count'] = queueLifecycleInteger($connection->fetchOne('SELECT COUNT(*) FROM resource_claim'));
    $callback['lease_count'] = queueLifecycleInteger($connection->fetchOne('SELECT COUNT(*) FROM queue_runner'));
    $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 10:01:01'))
        ->appendLog($error === null ? 'done' : 'FAILED: ' . $error->getMessage());
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'positive: actual runOne reaches the backend only with RUNNING task, live lease and REMOTE resource claim',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $backendCalls === 1
    && ($backend['username'] ?? null) === $task->requiredUsername()
    && ($backend['primary_transaction'] ?? null) === false
    && ($backend['claim_phase'] ?? null) === 'REMOTE'
    && ($backend['task_status'] ?? null) === \Entities\MailboxTask::STATUS_RUNNING
    && is_numeric($backend['task_owner'] ?? null)
    && ($backend['lease_count'] ?? null) === 1
);
queueLifecycleCheck(
    'boundary: backend progress refreshes the same lease after the 60-second interval',
    ($backend['heartbeat'] ?? null) === '2026-10-16 10:01:01'
);
queueLifecycleCheck(
    'transaction owner: repository publication opens the transaction and releases the resource claim before callback state',
    $callbackCalls === 1
    && ($callback['error'] ?? null) === null
    && ($callback['transaction'] ?? null) === true
    && ($callback['claim_count'] ?? null) === 0
    && ($callback['lease_count'] ?? null) === 1
);
queueLifecycleCheck(
    'release owners: publication commits terminal task state, then withLease removes its exact lease',
    $row['status'] === \Entities\MailboxTask::STATUS_DONE
    && $row['QueueRunner_id'] === null
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Boundary and named disabled-guard control: a non-PENDING generation must not
// reach backend work when Repositories\MailboxTask::claim() returns false.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, \Entities\MailboxTask::STATUS_FAILED);
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 11:00:00');
$backendCalls = 0;
$callbackCalls = 0;
$service->backend = static function () use (&$backendCalls): array {
    ++$backendCalls;
    return [];
};
$result = $service->runOne($task, static function () use (&$callbackCalls): void {
    ++$callbackCalls;
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'disabled-claim-guard control: non-PENDING task never reaches backend or completion callback',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_NOT_CLAIMED
    && $backendCalls === 0
    && $callbackCalls === 0
    && $row['status'] === \Entities\MailboxTask::STATUS_FAILED
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Boundary: a live slot prevents claim acquisition and leaves the task untouched.
queueLifecycleReset($em);
$task = queueLifecycleTask($em);
$observer->executeStatement(
    "INSERT INTO queue_runner (slot, host, pid, started_at, heartbeat_at) VALUES (1, 'busy-fixture', 1, ?, ?)",
    ['2026-10-16 12:00:00', '2026-10-16 12:00:00']
);
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 12:00:00');
$backendCalls = 0;
$service->backend = static function () use (&$backendCalls): array {
    ++$backendCalls;
    return [];
};
$result = $service->runOne($task, static function (): void {
    throw new RuntimeException('busy task completion callback must not run');
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'boundary: occupied lease slot returns BUSY before task claim or backend mutation',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_BUSY
    && $backendCalls === 0
    && $row['status'] === \Entities\MailboxTask::STATUS_PENDING
    && $row['QueueRunner_id'] === null
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 1
);

// Error: independent resource ownership prevents backend entry but the already
// claimed task is published FAILED inside the repository-owned transaction.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, username: 'contended-lifecycle@example.test');
$existingClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $task->requiredUsername());
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 13:00:00');
$backendCalls = 0;
$receivedError = null;
$callbackTransaction = false;
$service->backend = static function () use (&$backendCalls): array {
    ++$backendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use (
    $em,
    $task,
    &$receivedError,
    &$callbackTransaction
): void {
    $receivedError = $error;
    $callbackTransaction = $em->getConnection()->isTransactionActive();
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 13:00:00'))
        ->appendLog('FAILED: ' . ($error?->getMessage() ?? 'missing error'));
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'error: resource contention fails before backend work and publishes the claimed task failure transactionally',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $backendCalls === 0
    && $receivedError instanceof \ViMbAdmin_Exception
    && $receivedError->getMessage() === 'Mailbox resource is already claimed.'
    && $callbackTransaction
    && $row['status'] === \Entities\MailboxTask::STATUS_FAILED
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
$existingClaim->release();
$existingClaim->close();

// A concurrently changed task generation rejects terminal publication. The
// repository rolls back and clears managed state; the uncommitted READY claim
// stays durable for exact reconciliation because its callback never owned it.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, username: 'lost-publication-lifecycle@example.test');
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 13:30:00');
$callbackCalls = 0;
$service->backend = static function () use ($observer, $task): array {
    $observer->executeStatement(
        'UPDATE mailbox_task SET task_version = task_version + 1 WHERE id = ?',
        [$task->getId()]
    );
    return [];
};
$result = $service->runOne($task, static function () use (&$callbackCalls): void {
    ++$callbackCalls;
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'lost publication owner: changed task generation returns NOT_CLAIMED, clears state and retains READY evidence',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_NOT_CLAIMED
    && $callbackCalls === 0
    && !$em->contains($task)
    && $row['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $row['QueueRunner_id'] === null
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'READY'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'READY');

// Malformed persisted work is terminally failed without invoking any backend method.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, type: 'MALFORMED_TASK_TYPE');
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 14:00:00');
$backendCalls = 0;
$receivedError = null;
$service->backend = static function () use (&$backendCalls): array {
    ++$backendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$receivedError): void {
    $receivedError = $error;
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 14:00:00'));
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'malformed: unknown persisted task type fails closed without backend mutation or retained resource ownership',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $backendCalls === 0
    && $receivedError instanceof \ViMbAdmin_Exception
    && $receivedError->getMessage() === 'unknown task type: MALFORMED_TASK_TYPE'
    && $row['status'] === \Entities\MailboxTask::STATUS_FAILED
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Fault after backend entry is ambiguous: terminal task failure may publish,
// but its REMOTE claim is intentionally retained for exact reconciliation.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, username: 'ambiguous-lifecycle@example.test');
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 15:00:00');
$receivedError = null;
$service->backend = static function (): array {
    throw new RuntimeException('injected ambiguous backend interruption');
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$receivedError): void {
    $receivedError = $error;
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 15:00:00'));
});
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'fault: ambiguous backend interruption publishes FAILED but retains REMOTE ownership',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $receivedError instanceof RuntimeException
    && $receivedError->getMessage() === 'injected ambiguous backend interruption'
    && $row['status'] === \Entities\MailboxTask::STATUS_FAILED
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'REMOTE'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'REMOTE');

// Lost lease during progress prevents terminal publication. The physical
// backend attempt and its REMOTE claim remain visible; no stale callback runs.
queueLifecycleReset($em);
$task = queueLifecycleTask($em, username: 'lost-lease-lifecycle@example.test');
$service = new QueueLifecycleService($em, $options);
$service->clock = new DateTime('2026-10-16 16:00:00');
$callbackCalls = 0;
$service->backend = static function (string $username, Closure $progress) use ($observer, $service): array {
    $observer->executeStatement('DELETE FROM queue_runner');
    $service->clock->modify('+61 seconds');
    $progress();
    return [];
};
$lostLeaseError = null;
try {
    $service->runOne($task, static function () use (&$callbackCalls): void {
        ++$callbackCalls;
    });
} catch (RuntimeException $error) {
    $lostLeaseError = $error;
}
$row = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'fault: lost lease blocks terminal publication and retains ambiguous backend ownership',
    $lostLeaseError instanceof RuntimeException
    && $lostLeaseError->getMessage() === 'Runner lease was lost before its heartbeat.'
    && $callbackCalls === 0
    && $row['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $row['QueueRunner_id'] === null
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'REMOTE'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'REMOTE');

// Actual archive deletion: the controller's production ownership sequence is
// resource claim -> generation guard -> remote deletion -> DB service delete.
queueLifecycleResetDeletion($em);
$removalObserver = new QueueLifecycleRemovalObserver($em, $observer);
$em->getEventManager()->addEventListener([\Doctrine\ORM\Events::preRemove], $removalObserver);
$actor = queueLifecycleActor($em);
[$archiveDomain] = queueLifecycleMailboxFixture($em, 'archive-delete@archive-success.q01b.test');
$archive = queueLifecycleArchiveFixture(
    $em,
    'archive-delete@archive-success.q01b.test',
    $archiveDomain,
    $actor,
    'maildir:/backups/archive-success.q01b.test/archive-delete'
);
$archiveBackendEffects = 0;
$archiveRemotePhase = null;
\ViMbAdmin_ResourceClaim::withMailbox(
    $em,
    $archive->requiredUsername(),
    static function (\ViMbAdmin_ResourceClaim $owner) use (
        $em,
        $observer,
        $archive,
        $actor,
        &$archiveBackendEffects,
        &$archiveRemotePhase
    ): void {
        $owner->guardArchive($archive);
        $owner->remote(static function () use (
            $observer,
            $archive,
            &$archiveBackendEffects,
            &$archiveRemotePhase
        ): void {
            ++$archiveBackendEffects;
            $archiveRemotePhase = $observer->fetchOne(
                'SELECT phase FROM resource_claim WHERE resource_key = ?',
                [\ViMbAdmin_ResourceClaim::key($archive->requiredUsername())]
            );
        });
        (new \ViMbAdmin_Service_Archive($em))->delete($archive, $actor);
    }
);
$archiveRemoval = $removalObserver->records['archive-delete@archive-success.q01b.test'] ?? null;
queueLifecycleCheck(
    'positive archive deletion: actual generation guard and REMOTE token precede committed service deletion',
    $archiveBackendEffects === 1
    && $archiveRemotePhase === 'REMOTE'
    && is_array($archiveRemoval)
    && $archiveRemoval['entity'] === \Entities\Archive::class
    && $archiveRemoval['transaction'] === false
    && $archiveRemoval['claim_phase'] === 'READY'
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-delete@archive-success.q01b.test'"
    )) === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM log WHERE data LIKE '%deleted archive backup for archive-delete@archive-success.q01b.test%'"
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT COUNT(*) FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key('archive-delete@archive-success.q01b.test')]
    )) === 0
);

// Caller-owned outer transactions remain the commit owner. Both production DB
// deletion methods flush inside that transaction; rollback retains source rows
// and the independently durable exact resource tokens.
queueLifecycleResetDeletion($em);
$actor = queueLifecycleActor($em);
[$outerArchiveDomain] = queueLifecycleMailboxFixture($em, 'archive-outer@archive-outer.q01b.test');
$outerArchive = queueLifecycleArchiveFixture(
    $em,
    'archive-outer@archive-outer.q01b.test',
    $outerArchiveDomain,
    $actor,
    'maildir:/backups/archive-outer.q01b.test/archive-outer'
);
[$outerMailboxDomain, $outerMailbox] = queueLifecycleMailboxFixture(
    $em,
    'mailbox-outer@mailbox-outer.q01b.test'
);
$outerMailboxId = $outerMailbox->getId();
$outerMailboxDomainId = $outerMailboxDomain->getId();
$outerActorId = $actor->getId();
$outerConnection = $em->getConnection();
$outerConnection->beginTransaction();
\ViMbAdmin_ResourceClaim::withMailbox(
    $em,
    $outerArchive->requiredUsername(),
    static function (\ViMbAdmin_ResourceClaim $owner) use ($em, $outerArchive, $actor): void {
        $owner->guardArchive($outerArchive);
        (new \ViMbAdmin_Service_Archive($em))->delete($outerArchive, $actor);
    }
);
$outerArchiveRemoval = $removalObserver->records['archive-outer@archive-outer.q01b.test'] ?? null;
$outerArchiveToken = $observer->fetchAssociative(
    'SELECT resource_key, owner_token, phase FROM resource_claim WHERE username = ?',
    ['archive-outer@archive-outer.q01b.test']
);
queueLifecycleCheck(
    'transaction owner: actual archive removal stays invisible while its caller outer transaction is open',
    $outerConnection->getTransactionNestingLevel() === 1
    && is_array($outerArchiveRemoval)
    && $outerArchiveRemoval['transaction'] === true
    && $outerArchiveRemoval['nesting'] === 1
    && $outerArchiveRemoval['claim_phase'] === 'READY'
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-outer@archive-outer.q01b.test'"
    )) === 1
    && is_array($outerArchiveToken)
    && $outerArchiveToken['phase'] === 'READY'
);
$outerConnection->rollBack();
$em->clear();
queueLifecycleCheck(
    'outer rollback: archive row, log absence and exact READY ownership remain durable',
    queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-outer@archive-outer.q01b.test'"
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM log WHERE data LIKE '%archive-outer.q01b.test%'"
    )) === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM resource_claim WHERE username = 'archive-outer@archive-outer.q01b.test' AND phase = 'READY'"
    )) === 1
);
$removed = is_array($outerArchiveToken) ? $observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
    [$outerArchiveToken['resource_key'], $outerArchiveToken['owner_token']]
) : 0;
queueLifecycleCheck('archive outer rollback fixture reconciles only its observed READY owner token', $removed === 1);

$outerMailbox = $em->find(\Entities\Mailbox::class, $outerMailboxId);
$actor = $em->find(\Entities\Admin::class, $outerActorId);
if (!$outerMailbox instanceof \Entities\Mailbox || !$actor instanceof \Entities\Admin) {
    throw new RuntimeException('Outer mailbox deletion fixture disappeared after archive rollback.');
}
$outerConnection->beginTransaction();
(new \ViMbAdmin_Service_Mailbox($em))->purge($outerMailbox, $actor, false);
$outerMailboxRemoval = $removalObserver->records['mailbox-outer@mailbox-outer.q01b.test'] ?? null;
$outerMailboxToken = $observer->fetchAssociative(
    'SELECT resource_key, owner_token, phase FROM resource_claim WHERE username = ?',
    ['mailbox-outer@mailbox-outer.q01b.test']
);
queueLifecycleCheck(
    'transaction owner: actual mailbox removal uses a nested recipient transaction under its caller outer transaction',
    $outerConnection->getTransactionNestingLevel() === 1
    && is_array($outerMailboxRemoval)
    && $outerMailboxRemoval['transaction'] === true
    && $outerMailboxRemoval['nesting'] === 2
    && $outerMailboxRemoval['claim_phase'] === 'READY'
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM mailbox WHERE username = 'mailbox-outer@mailbox-outer.q01b.test'"
    )) === 1
    && is_array($outerMailboxToken)
    && $outerMailboxToken['phase'] === 'READY'
);
$outerConnection->rollBack();
$em->clear();
queueLifecycleCheck(
    'outer rollback: mailbox row, domain count, log absence and exact READY ownership remain durable',
    queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM mailbox WHERE username = 'mailbox-outer@mailbox-outer.q01b.test'"
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT mailbox_count FROM domain WHERE id = ?',
        [$outerMailboxDomainId]
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM log WHERE data LIKE '%mailbox-outer.q01b.test%'"
    )) === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM resource_claim WHERE username = 'mailbox-outer@mailbox-outer.q01b.test' AND phase = 'READY'"
    )) === 1
);
$removed = is_array($outerMailboxToken) ? $observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
    [$outerMailboxToken['resource_key'], $outerMailboxToken['owner_token']]
) : 0;
queueLifecycleCheck('mailbox outer rollback fixture reconciles only its observed READY owner token', $removed === 1);

// The archive generation/version fence is the source-row guard for direct
// deletion. Replacing the row generation must prevent backend and DB deletion.
queueLifecycleResetDeletion($em);
$actor = queueLifecycleActor($em);
[$staleDomain] = queueLifecycleMailboxFixture($em, 'archive-stale@archive-stale.q01b.test');
$staleArchive = queueLifecycleArchiveFixture(
    $em,
    'archive-stale@archive-stale.q01b.test',
    $staleDomain,
    $actor,
    'maildir:/backups/archive-stale.q01b.test/archive-stale'
);
$staleBackendEffects = 0;
$staleGuardError = null;
try {
    \ViMbAdmin_ResourceClaim::withMailbox(
        $em,
        $staleArchive->requiredUsername(),
        static function (\ViMbAdmin_ResourceClaim $owner) use (
            $em,
            $observer,
            $staleArchive,
            $actor,
            &$staleBackendEffects
        ): void {
            $observer->executeStatement(
                'UPDATE archive SET archive_generation = ?, archive_version = archive_version + 1 WHERE id = ?',
                [str_repeat('f', 64), $staleArchive->getId()]
            );
            $owner->guardArchive($staleArchive);
            $owner->remote(static function () use (&$staleBackendEffects): void {
                ++$staleBackendEffects;
            });
            (new \ViMbAdmin_Service_Archive($em))->delete($staleArchive, $actor);
        }
    );
} catch (Throwable $error) {
    $staleGuardError = $error;
}
$em->clear();
queueLifecycleCheck(
    'disabled-archive-generation-guard control: stale archive refuses backend deletion and preserves the replacement row',
    $staleGuardError instanceof \ViMbAdmin_Exception
    && $staleGuardError->getMessage() === 'Archive changed before this operation; reload the archive list and retry.'
    && $staleBackendEffects === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-stale@archive-stale.q01b.test'"
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT COUNT(*) FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key('archive-stale@archive-stale.q01b.test')]
    )) === 0
);

// Exact resource tokens fence both the start and completion boundaries. A
// replacement before REMOTE prevents the callback; replacement after the
// callback prevents READY publication and all DB cleanup.
queueLifecycleResetDeletion($em);
$actor = queueLifecycleActor($em);
[$replacedDomain] = queueLifecycleMailboxFixture($em, 'archive-replaced@archive-replaced.q01b.test');
$replacedArchive = queueLifecycleArchiveFixture(
    $em,
    'archive-replaced@archive-replaced.q01b.test',
    $replacedDomain,
    $actor,
    'maildir:/backups/archive-replaced.q01b.test/archive-replaced'
);
$replacedKey = \ViMbAdmin_ResourceClaim::key($replacedArchive->requiredUsername());
$replacedToken = str_repeat('a', 64);
$replacedClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $replacedArchive->requiredUsername());
$replacedClaim->guardArchive($replacedArchive);
$originalToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$replacedKey]);
$observer->executeStatement(
    'UPDATE resource_claim SET owner_token = ? WHERE resource_key = ? AND owner_token = ?',
    [$replacedToken, $replacedKey, $originalToken]
);
$replacedBackendEffects = 0;
$replacedRemoteError = null;
try {
    $replacedClaim->remote(static function () use (&$replacedBackendEffects): void {
        ++$replacedBackendEffects;
    });
} catch (Throwable $error) {
    $replacedRemoteError = $error;
}
$replacedCleanupError = null;
try {
    $replacedClaim->finish();
} catch (Throwable $error) {
    $replacedCleanupError = $error;
}
queueLifecycleCheck(
    'lost resource owner: replacement before REMOTE refuses backend mutation and exact-owner cleanup',
    $replacedRemoteError instanceof RuntimeException
    && $replacedRemoteError->getMessage() === 'Mailbox resource ownership was lost.'
    && $replacedCleanupError instanceof RuntimeException
    && $replacedCleanupError->getMessage() === 'Mailbox resource release requires its current ready token.'
    && $replacedBackendEffects === 0
    && $observer->fetchOne(
        'SELECT owner_token FROM resource_claim WHERE resource_key = ?',
        [$replacedKey]
    ) === $replacedToken
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-replaced@archive-replaced.q01b.test'"
    )) === 1
);
$observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
    [$replacedKey, $replacedToken]
);

queueLifecycleResetDeletion($em);
$actor = queueLifecycleActor($em);
[$completionDomain] = queueLifecycleMailboxFixture($em, 'archive-completion@archive-completion.q01b.test');
$completionArchive = queueLifecycleArchiveFixture(
    $em,
    'archive-completion@archive-completion.q01b.test',
    $completionDomain,
    $actor,
    'maildir:/backups/archive-completion.q01b.test/archive-completion'
);
$completionKey = \ViMbAdmin_ResourceClaim::key($completionArchive->requiredUsername());
$completionToken = str_repeat('b', 64);
$completionClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $completionArchive->requiredUsername());
$completionClaim->guardArchive($completionArchive);
$completionBackendEffects = 0;
$completionError = null;
try {
    $completionClaim->remote(static function () use (
        $observer,
        $completionKey,
        $completionToken,
        &$completionBackendEffects
    ): void {
        ++$completionBackendEffects;
        $token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$completionKey]);
        $observer->executeStatement(
            'UPDATE resource_claim SET owner_token = ? WHERE resource_key = ? AND owner_token = ?',
            [$completionToken, $completionKey, $token]
        );
    });
} catch (Throwable $error) {
    $completionError = $error;
} finally {
    $completionClaim->finish();
}
queueLifecycleCheck(
    'lost resource owner: replacement after backend completion refuses archive-row cleanup and retains REMOTE evidence',
    $completionError instanceof RuntimeException
    && $completionError->getMessage() === 'Mailbox resource ownership was lost.'
    && $completionBackendEffects === 1
    && $observer->fetchOne(
        'SELECT owner_token FROM resource_claim WHERE resource_key = ?',
        [$completionKey]
    ) === $completionToken
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [$completionKey]
    ) === 'REMOTE'
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM archive WHERE username = 'archive-completion@archive-completion.q01b.test'"
    )) === 1
);
$observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'REMOTE'",
    [$completionKey, $completionToken]
);

// Actual queued mailbox deletion: remote store emptying stays outside a primary
// transaction, mailbox-row removal owns a recipient transaction, and terminal
// publication releases the exact READY claim before committing DONE.
queueLifecycleResetDeletion($em);
[$deleteDomain] = queueLifecycleMailboxFixture($em, 'mailbox-delete@mailbox-delete.q01b.test');
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_DELETE,
    username: 'mailbox-delete@mailbox-delete.q01b.test',
    domain: $deleteDomain
);
$service = new QueueLifecycleDeletionService($em, [
    'queue' => [
        'runner' => ['max_concurrent' => 1],
        'autoprune' => ['days' => 0],
    ],
    'doveadm' => ['maildir_root' => '/mail'],
]);
$service->clock = new DateTime('2026-10-16 17:00:00');
$deleteCommands = [];
$service->backend = static function (string $name, array $arguments, Closure $progress) use (
    $em,
    $observer,
    $task,
    &$deleteCommands
): mixed {
    $progress();
    $deleteCommands[] = [
        'name' => $name,
        'arguments' => $arguments,
        'transaction' => $em->getConnection()->isTransactionActive(),
        'claim_phase' => $observer->fetchOne(
            'SELECT phase FROM resource_claim WHERE resource_key = ?',
            [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
        ),
        'mailbox_count' => queueLifecycleInteger($observer->fetchOne(
            'SELECT COUNT(*) FROM mailbox WHERE username = ?',
            [$task->requiredUsername()]
        )),
    ];
    return match ($name) {
        'maildirHasMail' => false,
        default => [],
    };
};
$deleteCallback = [];
$result = $service->runOne($task, static function (?Throwable $error) use (
    $em,
    $task,
    &$deleteCallback
): void {
    $deleteCallback = [
        'error' => $error,
        'transaction' => $em->getConnection()->isTransactionActive(),
        'claim_count' => queueLifecycleInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM resource_claim')),
        'mailbox_count' => queueLifecycleInteger($em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM mailbox WHERE username = ?',
            [$task->requiredUsername()]
        )),
    ];
    $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 17:00:00'))
        ->appendLog($error === null ? 'done' : 'FAILED: ' . $error->getMessage());
});
$deleteRow = queueLifecycleTaskRow($observer, (int) $task->getId());
$deleteData = $task->getData();
$deleteProgress = is_string($deleteData) ? json_decode($deleteData, true) : null;
$deleteCheckpoint = is_array($deleteProgress) ? ($deleteProgress['_queue_runner_delete'] ?? null) : null;
$deleteCompleted = is_array($deleteCheckpoint) ? ($deleteCheckpoint['completed'] ?? null) : null;
$mailboxRemoval = $removalObserver->records[$task->requiredUsername()] ?? null;
$deleteCommandNames = array_column($deleteCommands, 'name');
$mutationRows = array_values(array_filter(
    $deleteCommands,
    static fn (array $command): bool => in_array($command['name'], ['mailboxDelete', 'fsDelete'], true)
));
queueLifecycleCheck(
    'positive mailbox deletion: actual backend and repository methods remove store, row, quota and audit state',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $deleteRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && $deleteCommandNames === ['mailboxDelete', 'fsListDirs', 'maildirHasMail', 'fsDelete']
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM mailbox WHERE username = 'mailbox-delete@mailbox-delete.q01b.test'"
    )) === 0
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT mailbox_count FROM domain WHERE id = ?',
        [$deleteDomain->getId()]
    )) === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM log WHERE data LIKE 'deleted mailbox-delete@mailbox-delete.q01b.test%'"
    )) === 1
    && $deleteCompleted === ['mailbox-delete', 'maildir-home', 'mailbox-row', 'audit']
);
queueLifecycleCheck(
    'mailbox transaction owner: remote mutations are claim-fenced outside SQL and recipient removal owns its transaction',
    count($mutationRows) === 2
    && array_reduce(
        $mutationRows,
        static fn (bool $ok, array $command): bool => $ok
            && $command['transaction'] === false
            && $command['claim_phase'] === 'REMOTE'
            && $command['mailbox_count'] === 1,
        true
    )
    && is_array($mailboxRemoval)
    && $mailboxRemoval['entity'] === \Entities\Mailbox::class
    && $mailboxRemoval['transaction'] === true
    && $mailboxRemoval['nesting'] === 1
    && $mailboxRemoval['claim_phase'] === 'READY'
);
queueLifecycleCheck(
    'mailbox release owners: publication transaction sees committed row deletion and releases claim before DONE',
    ($deleteCallback['error'] ?? null) === null
    && ($deleteCallback['transaction'] ?? null) === true
    && ($deleteCallback['claim_count'] ?? null) === 0
    && ($deleteCallback['mailbox_count'] ?? null) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Malformed durable checkpoints fail before any backend or row deletion.
queueLifecycleResetDeletion($em);
[$malformedDomain] = queueLifecycleMailboxFixture($em, 'mailbox-malformed@mailbox-malformed.q01b.test');
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_DELETE,
    username: 'mailbox-malformed@mailbox-malformed.q01b.test',
    domain: $malformedDomain
);
$task->setData(json_encode(['_queue_runner_delete' => [
    'version' => 1,
    'mode' => 'instant',
    'destination' => null,
    'completed' => ['mailbox-row'],
]], JSON_THROW_ON_ERROR));
$em->flush();
$service = new QueueLifecycleDeletionService($em, [
    'queue' => ['runner' => ['max_concurrent' => 1], 'autoprune' => ['days' => 0]],
    'doveadm' => ['maildir_root' => '/mail'],
]);
$service->clock = new DateTime('2026-10-16 18:00:00');
$malformedBackendCalls = 0;
$malformedError = null;
$service->backend = static function () use (&$malformedBackendCalls): array {
    ++$malformedBackendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$malformedError): void {
    $malformedError = $error;
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)
        ->setFinishedAt(new DateTime('2026-10-16 18:00:00'));
});
queueLifecycleCheck(
    'malformed mailbox deletion: out-of-order checkpoint fails closed before backend mutation or database cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $malformedError instanceof RuntimeException
    && $malformedError->getMessage() === 'mailbox delete task contains invalid progress metadata'
    && $malformedBackendCalls === 0
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM mailbox WHERE username = 'mailbox-malformed@mailbox-malformed.q01b.test'"
    )) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Losing the live queue lease at the first actual deletion boundary makes the
// progress guard throw before the fixture records a backend effect. No mailbox
// cleanup runs and the REMOTE token is retained for exact reconciliation.
queueLifecycleResetDeletion($em);
[$lostDeleteDomain] = queueLifecycleMailboxFixture($em, 'mailbox-lost@mailbox-lost.q01b.test');
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_DELETE,
    username: 'mailbox-lost@mailbox-lost.q01b.test',
    domain: $lostDeleteDomain
);
$service = new QueueLifecycleDeletionService($em, [
    'queue' => ['runner' => ['max_concurrent' => 1], 'autoprune' => ['days' => 0]],
    'doveadm' => ['maildir_root' => '/mail'],
]);
$service->clock = new DateTime('2026-10-16 19:00:00');
$lostDeleteBackendEffects = 0;
$service->backend = static function (string $name, array $arguments, Closure $progress) use (
    $observer,
    $service,
    &$lostDeleteBackendEffects
): array {
    $observer->executeStatement('DELETE FROM queue_runner');
    $service->clock->modify('+61 seconds');
    $progress();
    ++$lostDeleteBackendEffects;
    return [];
};
$lostDeleteError = null;
$lostDeleteCallbackCalls = 0;
try {
    $service->runOne($task, static function () use (&$lostDeleteCallbackCalls): void {
        ++$lostDeleteCallbackCalls;
    });
} catch (Throwable $error) {
    $lostDeleteError = $error;
}
$lostDeleteRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'lost deletion owner: lease heartbeat refuses backend mutation and mailbox cleanup while retaining REMOTE evidence',
    $lostDeleteError instanceof RuntimeException
    && $lostDeleteError->getMessage() === 'Runner lease was lost before its heartbeat.'
    && $lostDeleteBackendEffects === 0
    && $lostDeleteCallbackCalls === 0
    && $lostDeleteRow['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $lostDeleteRow['QueueRunner_id'] === null
    && queueLifecycleInteger($observer->fetchOne(
        "SELECT COUNT(*) FROM mailbox WHERE username = 'mailbox-lost@mailbox-lost.q01b.test'"
    )) === 1
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'REMOTE'
);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'REMOTE');

// The periodic sweep owns only discovery and enqueueing. Disabled and recent
// archives are excluded before task creation, even though the sweep timestamp
// itself is claimed while the live runner lease is held.
queueLifecycleResetAutoprune($em);
$disabledArchive = queueLifecycleArchiveFixture(
    $em,
    'disabled@autoprune-disabled.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-disabled.q01c.test/disabled'
);
$recentArchive = queueLifecycleArchiveFixture(
    $em,
    'recent@autoprune-recent.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-recent.q01c.test/recent'
);
$recentArchive->setAutoprune(true)->setArchivedAt(new DateTime('2027-01-15 12:00:00'));
$em->flush();
$autopruneOptions = ['queue' => [
    'runner' => ['max_concurrent' => 1],
    'autoprune' => ['days' => 90],
]];
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 12:00:00');
$noEligibleBackendCalls = 0;
$service->backend = static function () use (&$noEligibleBackendCalls): array {
    ++$noEligibleBackendCalls;
    return [];
};
$result = $service->drain(1);
queueLifecycleCheck(
    'disabled/no-eligible sweep: actual repository cutoff enqueues no task and performs no backend work',
    $result === 0
    && $noEligibleBackendCalls === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox_task')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 2
    && $observer->fetchOne('SELECT value FROM setting WHERE name = ?', [\ViMbAdmin_Setting::LAST_PRUNE_SWEEP])
        === (string) (new DateTimeImmutable('2027-02-01 12:00:00'))->getTimestamp()
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Two eligible rows prove both successful pruning and the caller's drain(1)
// bound. The next drain consumes the already-enqueued task without duplicating
// it because the eight-hour sweep gate remains closed.
queueLifecycleResetAutoprune($em);
$publicationObserver = new QueueLifecyclePublicationObserver($em, $observer);
$em->getEventManager()->addEventListener([\Doctrine\ORM\Events::preUpdate], $publicationObserver);
$eligiblePaths = [];
foreach (['first', 'second'] as $label) {
    $username = $label . '@autoprune-success.q01c.test';
    $path = 'maildir:/backups/autoprune-success.q01c.test/' . $label;
    $archive = queueLifecycleArchiveFixture($em, $username, null, null, $path);
    $archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
    $eligiblePaths[$path] = $username;
}
$em->flush();
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 13:00:00');
$sweepBackend = [];
$service->backend = static function (string $name, array $arguments, Closure $progress) use (
    $em,
    $observer,
    $eligiblePaths,
    &$sweepBackend
): array {
    $path = $arguments[0] ?? null;
    $username = is_string($path) ? ($eligiblePaths[$path] ?? null) : null;
    if ($name !== 'fsDelete' || !is_string($username)) {
        throw new RuntimeException('Autoprune fixture reached an unexpected backend operation.');
    }
    $taskRow = $observer->fetchAssociative(
        'SELECT id, status, QueueRunner_id, task_version, data FROM mailbox_task'
        . ' WHERE username = ? AND type = ? AND status = ?',
        [$username, \Entities\MailboxTask::TYPE_PRUNE, \Entities\MailboxTask::STATUS_RUNNING]
    );
    $archiveRow = $observer->fetchAssociative(
        'SELECT id, archive_generation, archive_version FROM archive WHERE username = ?',
        [$username]
    );
    $claimRow = $observer->fetchAssociative(
        'SELECT owner_token, phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($username)]
    );
    $taskData = is_array($taskRow) && is_string($taskRow['data'] ?? null)
        ? json_decode($taskRow['data'], true)
        : null;
    $sweepBackend[] = [
        'username' => $username,
        'path' => $path,
        'transaction' => $em->getConnection()->isTransactionActive(),
        'task' => $taskRow,
        'archive' => $archiveRow,
        'claim' => $claimRow,
        'data' => $taskData,
        'lease_id' => $observer->fetchOne('SELECT id FROM queue_runner'),
        'archive_count' => queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')),
    ];
    $progress();
    return [];
};
$firstDrain = $service->drain(1);
$firstDoneId = queueLifecycleInteger($observer->fetchOne(
    'SELECT id FROM mailbox_task WHERE type = ? AND status = ?',
    [\Entities\MailboxTask::TYPE_PRUNE, \Entities\MailboxTask::STATUS_DONE]
));
$firstBoundary = $sweepBackend[0] ?? null;
$firstTask = is_array($firstBoundary) ? $firstBoundary['task'] : null;
$firstArchive = is_array($firstBoundary) ? $firstBoundary['archive'] : null;
$firstClaim = is_array($firstBoundary) ? $firstBoundary['claim'] : null;
$firstData = is_array($firstBoundary) ? $firstBoundary['data'] : null;
$firstRemoval = is_array($firstBoundary)
    ? ($removalObserver->records[$firstBoundary['username']] ?? null)
    : null;
$firstPublication = $publicationObserver->records[$firstDoneId] ?? null;
queueLifecycleCheck(
    'positive autoprune: actual sweep snapshot reaches fsDelete with live lease, RUNNING generation and REMOTE token',
    $firstDrain === 1
    && count($sweepBackend) === 1
    && is_array($firstBoundary)
    && $firstBoundary['transaction'] === false
    && $firstBoundary['archive_count'] === 2
    && is_array($firstTask)
    && $firstTask['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && is_numeric($firstTask['QueueRunner_id'])
    && $firstTask['QueueRunner_id'] == $firstBoundary['lease_id']
    && is_array($firstArchive)
    && is_array($firstData)
    && $firstData['archive_id'] == $firstArchive['id']
    && $firstData['archive_generation'] === $firstArchive['archive_generation']
    && $firstData['archive_version'] == $firstArchive['archive_version']
    && is_int($firstData['cutoff'] ?? null)
    && is_array($firstClaim)
    && $firstClaim['phase'] === 'REMOTE'
    && is_string($firstClaim['owner_token'])
    && preg_match('/^[a-f0-9]{64}$/D', $firstClaim['owner_token']) === 1
);
queueLifecycleCheck(
    'autoprune transaction owners: archive removal is staged READY outside SQL, then publication releases inside its transaction',
    is_array($firstRemoval)
    && $firstRemoval['entity'] === \Entities\Archive::class
    && $firstRemoval['transaction'] === false
    && $firstRemoval['nesting'] === 0
    && $firstRemoval['claim_phase'] === 'READY'
    && is_array($firstPublication)
    && $firstPublication['status'] === \Entities\MailboxTask::STATUS_DONE
    && $firstPublication['transaction'] === true
    && $firstPublication['nesting'] === 2
    && $firstPublication['primary_claim_count'] === 0
    && $firstPublication['observer_claim_count'] === 1
    && $firstPublication['lease_count'] === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleCheck(
    'bounded autoprune: drain(1) commits one prune and leaves one exact snapshot pending',
    queueLifecycleInteger($observer->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE type = ? AND status = ?',
        [\Entities\MailboxTask::TYPE_PRUNE, \Entities\MailboxTask::STATUS_DONE]
    )) === 1
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE type = ? AND status = ?',
        [\Entities\MailboxTask::TYPE_PRUNE, \Entities\MailboxTask::STATUS_PENDING]
    )) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
);
$secondDrain = $service->drain(1);
queueLifecycleCheck(
    'sweep gate boundary: second drain executes the pending snapshot without duplicate enqueue or overrun',
    $secondDrain === 1
    && count($sweepBackend) === 2
    && count(array_unique(array_column($sweepBackend, 'username'))) === 2
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox_task')) === 2
    && queueLifecycleInteger($observer->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE type = ? AND status = ?',
        [\Entities\MailboxTask::TYPE_PRUNE, \Entities\MailboxTask::STATUS_DONE]
    )) === 2
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// A task can outlive the eligibility snapshot that created it. Turning
// autoprune off must make the executor skip before remote or database cleanup.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'disabled-after-queue@autoprune-guard.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-guard.q01c.test/disabled-after-queue'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$archive->setAutoprune(false);
$em->flush();
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 14:00:00');
$disabledBackendCalls = 0;
$disabledError = null;
$service->backend = static function () use (&$disabledBackendCalls): array {
    ++$disabledBackendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$disabledError): void {
    $disabledError = $error;
    $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)->setFinishedAt(new DateTime('2027-02-01 14:00:00'));
});
$disabledRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'disabled-autoprune-eligibility-guard control: source row changed after enqueue is retained without backend mutation',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $disabledError === null
    && $disabledBackendCalls === 0
    && $disabledRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && is_string($disabledRow['log'])
    && str_contains($disabledRow['log'], 'prune: archive generation or eligibility changed — skipping')
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Malformed durable task data is treated as an obsolete snapshot, not as
// authority to delete the current archive row.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'malformed@autoprune-malformed.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-malformed.q01c.test/malformed'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'), '{malformed');
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 15:00:00');
$malformedPruneBackendCalls = 0;
$service->backend = static function () use (&$malformedPruneBackendCalls): array {
    ++$malformedPruneBackendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task): void {
    $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)->setFinishedAt(new DateTime('2027-02-01 15:00:00'));
});
$malformedPruneRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'malformed autoprune snapshot: invalid JSON fails closed before backend or archive cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $malformedPruneBackendCalls === 0
    && $malformedPruneRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && is_string($malformedPruneRow['log'])
    && str_contains($malformedPruneRow['log'], 'prune: archive generation or eligibility changed — skipping')
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
);

// Generation and version are copied into the task at enqueue time and checked
// again after refreshing the managed archive at execution time.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'stale-generation@autoprune-generation.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-generation.q01c.test/stale-generation'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$observer->executeStatement(
    'UPDATE archive SET archive_generation = ?, archive_version = archive_version + 1 WHERE id = ?',
    [str_repeat('c', 64), $archive->getId()]
);
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 16:00:00');
$stalePruneBackendCalls = 0;
$service->backend = static function () use (&$stalePruneBackendCalls): array {
    ++$stalePruneBackendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task): void {
    $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
        ->setRunner(null)->setFinishedAt(new DateTime('2027-02-01 16:00:00'));
});
$stalePruneRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'archive generation fence: refreshed replacement generation is retained and the stale task performs no backend mutation',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $stalePruneBackendCalls === 0
    && $stalePruneRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && $observer->fetchOne('SELECT archive_generation FROM archive WHERE id = ?', [$archive->getId()]) === str_repeat('c', 64)
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

// Resource contention is decided after the task generation is claimed but
// before any autoprune backend or archive mutation begins.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'contended@autoprune-claim.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-claim.q01c.test/contended'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$existingClaim = \ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $task->requiredUsername());
$existingToken = $observer->fetchOne(
    'SELECT owner_token FROM resource_claim WHERE resource_key = ?',
    [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
);
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 17:00:00');
$contendedPruneBackendCalls = 0;
$contendedPruneError = null;
$service->backend = static function () use (&$contendedPruneBackendCalls): array {
    ++$contendedPruneBackendCalls;
    return [];
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$contendedPruneError): void {
    $contendedPruneError = $error;
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2027-02-01 17:00:00'));
});
$contendedPruneRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'conflicting autoprune claim: existing exact token blocks backend and archive cleanup while task failure publishes',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $contendedPruneError instanceof \ViMbAdmin_Exception
    && $contendedPruneError->getMessage() === 'Mailbox resource is already claimed.'
    && $contendedPruneBackendCalls === 0
    && $contendedPruneRow['status'] === \Entities\MailboxTask::STATUS_FAILED
    && $observer->fetchOne(
        'SELECT owner_token FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === $existingToken
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'READY'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
$existingClaim->release();
$existingClaim->close();

// Replacing the resource token between acquisition and REMOTE publication
// prevents both backend entry and exact-owner cleanup.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'replaced-claim@autoprune-claim.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-claim.q01c.test/replaced-claim'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$replacedPruneKey = \ViMbAdmin_ResourceClaim::key($task->requiredUsername());
$replacedPruneToken = str_repeat('d', 64);
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 18:00:00');
$replacedPruneBackendCalls = 0;
$replacedPruneCallbackCalls = 0;
$service->beforeDoveadm = static function () use ($observer, $replacedPruneKey, $replacedPruneToken): void {
    $token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$replacedPruneKey]);
    $observer->executeStatement(
        'UPDATE resource_claim SET owner_token = ? WHERE resource_key = ? AND owner_token = ?',
        [$replacedPruneToken, $replacedPruneKey, $token]
    );
};
$service->backend = static function () use (&$replacedPruneBackendCalls): array {
    ++$replacedPruneBackendCalls;
    return [];
};
$replacedPruneError = null;
try {
    $service->runOne($task, static function () use (&$replacedPruneCallbackCalls): void {
        ++$replacedPruneCallbackCalls;
    });
} catch (Throwable $error) {
    $replacedPruneError = $error;
}
$replacedPruneRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'replaced autoprune claim: exact token fence refuses backend mutation, publication cleanup and replacement deletion',
    $replacedPruneError instanceof RuntimeException
    && $replacedPruneError->getMessage() === 'Mailbox resource release requires its current ready token.'
    && $replacedPruneBackendCalls === 0
    && $replacedPruneCallbackCalls === 0
    && $replacedPruneRow['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $replacedPruneRow['QueueRunner_id'] === null
    && $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$replacedPruneKey])
        === $replacedPruneToken
    && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key = ?', [$replacedPruneKey]) === 'READY'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
if ($observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
    [$replacedPruneKey, $replacedPruneToken]
) !== 1) {
    throw new RuntimeException('Replaced autoprune resource token cleanup lost exact ownership.');
}

// A transport interruption after REMOTE publication is ambiguous. The task
// can publish FAILED, but the archive and REMOTE claim remain for reconciliation.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'interrupted@autoprune-backend.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-backend.q01c.test/interrupted'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 19:00:00');
$interruptedPruneBackendCalls = 0;
$interruptedPruneError = null;
$service->backend = static function () use (&$interruptedPruneBackendCalls): array {
    ++$interruptedPruneBackendCalls;
    throw new RuntimeException('injected autoprune backend interruption');
};
$result = $service->runOne($task, static function (?Throwable $error) use ($task, &$interruptedPruneError): void {
    $interruptedPruneError = $error;
    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED)->setRunner(null)
        ->setFinishedAt(new DateTime('2027-02-01 19:00:00'))
        ->appendLog('FAILED: ' . ($error?->getMessage() ?? 'missing error'));
});
$interruptedPruneRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'interrupted autoprune backend: FAILED task retains archive and REMOTE token after ambiguous work',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $interruptedPruneBackendCalls === 1
    && $interruptedPruneError instanceof RuntimeException
    && $interruptedPruneError->getMessage() === 'injected autoprune backend interruption'
    && $interruptedPruneRow['status'] === \Entities\MailboxTask::STATUS_FAILED
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'REMOTE'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'REMOTE');

// Make the active lease stale at the first real fsDelete boundary, then let a
// second manager reap it and acquire the same slot. Heartbeat of the old id
// fails before the fixture records a backend effect; exact release must leave
// the replacement lease untouched.
queueLifecycleResetAutoprune($em);
$archive = queueLifecycleArchiveFixture(
    $em,
    'replaced-lease@autoprune-lease.q01c.test',
    null,
    null,
    'maildir:/backups/autoprune-lease.q01c.test/replaced-lease'
);
$archive->setAutoprune(true)->setArchivedAt(new DateTime('2026-10-01 12:00:00'));
$em->flush();
$task = queueLifecyclePruneTask($em, $archive, new DateTimeImmutable('2026-11-03 13:00:00'));
$service = new QueueLifecycleDeletionService($em, $autopruneOptions);
$service->clock = new DateTime('2027-02-01 20:00:00');
$replacedLeaseBackendEffects = 0;
$replacedLeaseCallbackCalls = 0;
$originalLeaseId = null;
$replacementLease = null;
$service->backend = static function (string $name, array $arguments, Closure $progress) use (
    $observer,
    $observerManager,
    $autopruneOptions,
    $service,
    &$replacedLeaseBackendEffects,
    &$originalLeaseId,
    &$replacementLease
): array {
    if ($name !== 'fsDelete') {
        throw new RuntimeException('Autoprune lease fixture reached an unexpected backend operation.');
    }
    $originalLeaseId = $observer->fetchOne('SELECT id FROM queue_runner');
    $observer->executeStatement(
        'UPDATE queue_runner SET heartbeat_at = ? WHERE id = ?',
        ['2027-02-01 18:00:00', $originalLeaseId]
    );
    $replacementLease = \ViMbAdmin_QueueRunner::acquireLease(
        $observerManager,
        $autopruneOptions,
        new DateTime('2027-02-01 20:00:00')
    );
    $service->clock->modify('+61 seconds');
    $progress();
    ++$replacedLeaseBackendEffects;
    return [];
};
$replacedLeaseError = null;
try {
    $service->runOne($task, static function () use (&$replacedLeaseCallbackCalls): void {
        ++$replacedLeaseCallbackCalls;
    });
} catch (Throwable $error) {
    $replacedLeaseError = $error;
}
$replacedLeaseRow = queueLifecycleTaskRow($observer, (int) $task->getId());
$replacementLeaseId = $replacementLease instanceof \Entities\QueueRunner ? $replacementLease->getId() : null;
queueLifecycleCheck(
    'stale/lost/replaced autoprune lease: old heartbeat refuses further mutation and exact cleanup preserves replacement',
    $replacedLeaseError instanceof RuntimeException
    && $replacedLeaseError->getMessage() === 'Runner lease was lost before its heartbeat.'
    && $replacedLeaseBackendEffects === 0
    && $replacedLeaseCallbackCalls === 0
    && is_numeric($originalLeaseId)
    && is_int($replacementLeaseId)
    && $replacementLeaseId != $originalLeaseId
    && $observer->fetchOne('SELECT id FROM queue_runner WHERE slot = 1') == $replacementLeaseId
    && $replacedLeaseRow['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $replacedLeaseRow['QueueRunner_id'] === null
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 1
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($task->requiredUsername())]
    ) === 'REMOTE'
);
if (!$replacementLease instanceof \Entities\QueueRunner) {
    throw new RuntimeException('Autoprune replacement lease fixture did not acquire a lease.');
}
\ViMbAdmin_QueueRunner::release($observerManager, $replacementLease);
queueLifecycleReconcileClaim($observer, $task->requiredUsername(), 'REMOTE');

// Orphan discovery owns one bounded filesystem enumeration. Exactly 500
// entries proceed to classification; the 501st fails before per-maildir work.
$orphanOptions = [
    'queue' => ['runner' => ['max_concurrent' => 1]],
    'doveadm' => [
        'maildir_root' => '/mail',
        'backup' => ['dest' => 'maildir:/backups/%d/%u'],
        'http' => ['url' => 'http://doveadm.invalid/doveadm/v1', 'api_key' => 'fixture-key'],
    ],
];
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$exactNames = array_map(
    static fn (int $number): string => sprintf('orphan-%03d@scan-boundary.q01d.test', $number),
    range(1, 500)
);
$backend->rootEntries = array_reverse($exactNames);
foreach ($exactNames as $name) {
    $backend->directories['/mail/' . $name] = ['cur'];
}
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 09:00:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_SCAN_ORPHANS,
    username: 'scan-exactly-500@scan-boundary.q01d.test'
);
[$result, $scanError, $scanCompleted] = queueLifecycleRunOrphan($service, $task, $service->clock);
$scanData = json_decode($task->getData() ?? '', true);
queueLifecycleCheck(
    'orphan scan boundary: exactly 500 actual candidates are sorted and classified under one live task generation',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $scanCompleted && $scanError === null
    && is_array($scanData) && ($scanData['orphans'] ?? null) === $exactNames
    && count($backend->calls) === 501
    && ($backend->calls[0]['command'] ?? null) === 'fsIterDirs'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

queueLifecycleResetDeletion($em);
[$knownDomain] = queueLifecycleMailboxFixture($em, 'known@scan-classification.q01d.test');
$backend = new QueueLifecycleOrphanBackend();
$backend->rootEntries = [
    'orphan@scan-classification.q01d.test',
    'known@scan-classification.q01d.test',
    'empty@scan-classification.q01d.test',
];
$backend->directories['/mail/orphan@scan-classification.q01d.test'] = ['cur'];
$backend->directories['/mail/known@scan-classification.q01d.test'] = ['cur'];
$backend->directories['/mail/empty@scan-classification.q01d.test'] = [];
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 09:10:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_SCAN_ORPHANS,
    username: 'scan-classification@scan-classification.q01d.test',
    domain: $knownDomain
);
[$result, $classificationError] = queueLifecycleRunOrphan($service, $task, $service->clock);
queueLifecycleCheck(
    'orphan scan classification: known and empty stores are retained while only the unmanaged mail-bearing path is returned',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $classificationError === null
    && json_decode($task->getData() ?? '', true, 512, JSON_THROW_ON_ERROR) === [
        'orphans' => ['orphan@scan-classification.q01d.test'],
    ]
    && count($backend->calls) === 3
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 1
);

queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$backend->rootEntries = array_map(
    static fn (int $number): string => sprintf('orphan-%03d@scan-overflow.q01d.test', $number),
    range(1, 501)
);
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 09:20:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_SCAN_ORPHANS,
    username: 'scan-over-500@scan-overflow.q01d.test'
);
[$result, $overflowError] = queueLifecycleRunOrphan($service, $task, $service->clock);
$overflowRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'orphan scan bound: more than 500 fails closed before child work or cached delivery',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $overflowError instanceof \ViMbAdmin_Exception
    && $overflowError->getMessage() === 'doveadm HTTP: filesystem response exceeds 500 entries'
    && $overflowRow['status'] === \Entities\MailboxTask::STATUS_FAILED
    && $task->getData() === null
    && count($backend->calls) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$backend->rootEntries = ['bad/name'];
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 09:30:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_SCAN_ORPHANS,
    username: 'scan-malformed@scan-malformed.q01d.test'
);
[$result, $malformedScanError] = queueLifecycleRunOrphan($service, $task, $service->clock);
queueLifecycleCheck(
    'malformed orphan scan path: unsafe component fails before child backend or database cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $malformedScanError instanceof \ViMbAdmin_Exception
    && $malformedScanError->getMessage() === 'Maildir name is not a safe path component'
    && count($backend->calls) === 1
    && $task->getData() === null
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
);

// A real mailbox that appears before execution is the current source owner.
// The orphan task still owns/relinquishes its task and resource generations,
// but it performs no backend or transient-row work.
queueLifecycleResetDeletion($em);
[$knownDomain] = queueLifecycleMailboxFixture($em, 'known-before-backup@known-orphan.q01d.test');
$backend = new QueueLifecycleOrphanBackend();
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:00:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN,
    username: 'known-before-backup@known-orphan.q01d.test',
    domain: $knownDomain
);
[$result, $knownError] = queueLifecycleRunOrphan($service, $task, $service->clock);
queueLifecycleCheck(
    'known orphan source row: actual backupOrphan skips before backend or transient cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $knownError === null && $backend->calls === []
    && str_contains($task->getLog() ?? '', 'a real mailbox now exists')
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
);

queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:10:00');
$service->backend = $backend;
$task = queueLifecycleTask(
    $em,
    type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN,
    username: 'unsafe/../orphan@malformed-orphan.q01d.test'
);
[$result, $malformedBackupError] = queueLifecycleRunOrphan($service, $task, $service->clock);
queueLifecycleCheck(
    'malformed orphan backup path: validation fails before backend, temp rows or archive publication',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $malformedBackupError instanceof \ViMbAdmin_Exception
    && str_starts_with($malformedBackupError->getMessage(), 'refusing unsafe path component in mailbox task:')
    && $backend->calls === []
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
);

// The mail-bearing path creates exact task-owned temp state, performs the
// backup, records a fresh archive generation, cleans the sentinel, and lets
// repository publication atomically release the READY resource token.
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$fullUsername = 'full@orphan-success.q01d.test';
$backend->directories['/mail/' . $fullUsername] = ['cur', 'new', 'tmp'];
$backend->hasMail['/mail/' . $fullUsername] = true;
$fullBoundary = null;
$backend->beforeResponse = static function (string $command) use (
    $observer,
    $em,
    $fullUsername,
    &$fullBoundary
): void {
    if ($command !== 'backup' || $fullBoundary !== null) {
        return;
    }
    $fullBoundary = [
        'transaction' => $em->getConnection()->isTransactionActive(),
        'task' => $observer->fetchAssociative(
            'SELECT id, status, QueueRunner_id, task_version FROM mailbox_task WHERE username = ? AND type = ?',
            [$fullUsername, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN]
        ),
        'temp' => $observer->fetchAssociative(
            'SELECT id, password, active, Domain_id FROM mailbox WHERE username = ?',
            [$fullUsername]
        ),
        'claim' => $observer->fetchAssociative(
            'SELECT owner_token, phase FROM resource_claim WHERE resource_key = ?',
            [\ViMbAdmin_ResourceClaim::key($fullUsername)]
        ),
        'lease' => $observer->fetchOne('SELECT id FROM queue_runner'),
    ];
};
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:20:00');
$service->backend = $backend;
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $fullUsername);
$fullTaskId = (int) $task->getId();
[$result, $fullError] = queueLifecycleRunOrphan($service, $task, $service->clock);
$fullRow = queueLifecycleTaskRow($observer, $fullTaskId);
$fullArchive = $observer->fetchAssociative(
    'SELECT archive_generation, archive_version, data, status, Domain_id FROM archive WHERE username = ?',
    [$fullUsername]
);
$fullArchiveData = is_array($fullArchive) && is_string($fullArchive['data'] ?? null)
    ? json_decode($fullArchive['data'], true)
    : null;
$fullPublication = $publicationObserver->records[$fullTaskId] ?? null;
$fullBoundaryTask = is_array($fullBoundary) ? $fullBoundary['task'] : null;
$fullBoundaryTemp = is_array($fullBoundary) ? $fullBoundary['temp'] : null;
$fullBoundaryClaim = is_array($fullBoundary) ? $fullBoundary['claim'] : null;
queueLifecycleCheck(
    'positive orphan backup: actual temp owner, RUNNING task generation and REMOTE token reach backup before exact cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $fullError === null
    && is_array($fullBoundary) && $fullBoundary['transaction'] === false
    && is_array($fullBoundaryTask) && $fullBoundaryTask['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $fullBoundaryTask['QueueRunner_id'] == $fullBoundary['lease']
    && is_numeric($fullBoundaryTask['task_version']) && (int) $fullBoundaryTask['task_version'] === 2
    && is_array($fullBoundaryTemp)
    && $fullBoundaryTemp['password'] === '{PLAIN}!vimbadmin-orphan-backup-temp-v1:' . $fullTaskId . '!'
    && in_array($fullBoundaryTemp['active'], [0, '0', false], true)
    && is_array($fullBoundaryClaim) && $fullBoundaryClaim['phase'] === 'REMOTE'
    && is_string($fullBoundaryClaim['owner_token'])
    && preg_match('/^[a-f0-9]{64}$/D', $fullBoundaryClaim['owner_token']) === 1
);
queueLifecycleCheck(
    'orphan backup cleanup and transaction owners: archive generation commits, temp state disappears and publication releases inside its transaction',
    $fullRow['status'] === \Entities\MailboxTask::STATUS_DONE
    && is_array($fullArchive)
    && $fullArchive['status'] === \Entities\Archive::STATUS_ARCHIVED
    && is_string($fullArchive['archive_generation'])
    && preg_match('/^[a-f0-9]{64}$/D', $fullArchive['archive_generation']) === 1
    && is_array($fullArchiveData) && ($fullArchiveData['task_id'] ?? null) === $fullTaskId
    && ($fullArchiveData['dest'] ?? null) === 'maildir:/backups/orphan-success.q01d.test/' . $fullUsername
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE username = ?', [$fullUsername])) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM domain WHERE domain = ?', ['orphan-success.q01d.test'])) === 1
    && is_array($fullPublication)
    && $fullPublication['status'] === \Entities\MailboxTask::STATUS_DONE
    && $fullPublication['transaction'] === true && $fullPublication['nesting'] === 2
    && $fullPublication['primary_claim_count'] === 0
    && $fullPublication['observer_claim_count'] === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);

queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$emptyUsername = 'empty@orphan-empty.q01d.test';
$backend->directories['/mail/' . $emptyUsername] = ['cur', 'new', 'tmp'];
$backend->hasMail['/mail/' . $emptyUsername] = false;
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:30:00');
$service->backend = $backend;
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $emptyUsername);
[$result, $emptyError] = queueLifecycleRunOrphan($service, $task, $service->clock);
$emptyCommands = array_column($backend->calls, 'command');
queueLifecycleCheck(
    'empty orphan cleanup: actual performOrphanBackup removes home, exact temp mailbox and transient domain without an archive',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $emptyError === null
    && !in_array('backup', $emptyCommands, true) && in_array('fsDelete', $emptyCommands, true)
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM domain')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM archive')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
);

// This assertion is the named source-row defect control. A concurrent actor
// replaces every sentinel attribute before finally; cleanup must not delete it.
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$guardUsername = 'changed-temp@orphan-guard.q01d.test';
$replacementPassword = '{PLAIN}!concurrent-real-owner!';
$backend->directories['/mail/' . $guardUsername] = ['cur', 'new', 'tmp'];
$backend->hasMail['/mail/' . $guardUsername] = false;
$changedTempId = null;
$backend->beforeResponse = static function (string $command) use (
    $observer,
    $guardUsername,
    $replacementPassword,
    &$changedTempId
): void {
    if ($command !== 'force-resync' || $changedTempId !== null) {
        return;
    }
    $row = $observer->fetchAssociative(
        'SELECT id, password FROM mailbox WHERE username = ? AND active = 0',
        [$guardUsername]
    );
    if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['password'] ?? null)) {
        throw new RuntimeException('Orphan changed-row fixture did not observe the exact temp owner.');
    }
    $changedTempId = (int) $row['id'];
    if ($observer->executeStatement(
        'UPDATE mailbox SET password = ?, active = 1 WHERE id = ? AND password = ? AND active = 0',
        [$replacementPassword, $changedTempId, $row['password']]
    ) !== 1) {
        throw new RuntimeException('Orphan changed-row fixture did not replace the exact temp owner.');
    }
};
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:40:00');
$service->backend = $backend;
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $guardUsername);
[$result, $guardError] = queueLifecycleRunOrphan($service, $task, $service->clock);
$changedTemp = $observer->fetchAssociative(
    'SELECT id, password, active FROM mailbox WHERE username = ?',
    [$guardUsername]
);
queueLifecycleCheck(
    'disabled-orphan-temp-source-row-guard control: changed temp owner row is retained without unsafe cleanup',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $guardError === null
    && is_int($changedTempId) && is_array($changedTemp)
    && $changedTemp['id'] == $changedTempId
    && $changedTemp['password'] === $replacementPassword
    && in_array($changedTemp['active'], [1, '1', true], true)
    && str_contains($task->getLog() ?? '', 'temp user row retained (changed concurrently)')
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM domain WHERE domain = ?', ['orphan-guard.q01d.test'])) === 1
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0
);

// Replacing the independent resource token before the first remote call makes
// both mutation and old-owner release fail; the replacement remains exact.
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$replacedUsername = 'replaced-token@orphan-token.q01d.test';
$backend->directories['/mail/' . $replacedUsername] = ['cur'];
$replacedKey = \ViMbAdmin_ResourceClaim::key($replacedUsername);
$replacementToken = str_repeat('e', 64);
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 10:50:00');
$service->backend = $backend;
$service->beforeDoveadm = static function () use ($observer, $replacedKey, $replacementToken): void {
    $oldToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$replacedKey]);
    if (!is_string($oldToken) || $observer->executeStatement(
        'UPDATE resource_claim SET owner_token = ? WHERE resource_key = ? AND owner_token = ?',
        [$replacementToken, $replacedKey, $oldToken]
    ) !== 1) {
        throw new RuntimeException('Orphan token fixture did not replace the exact owner.');
    }
};
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $replacedUsername);
$replacedError = null;
try {
    queueLifecycleRunOrphan($service, $task, $service->clock);
} catch (Throwable $error) {
    $replacedError = $error;
}
$replacedRow = queueLifecycleTaskRow($observer, (int) $task->getId());
queueLifecycleCheck(
    'replaced orphan resource token: exact owner fence refuses backend mutation, publication cleanup and replacement deletion',
    $replacedError instanceof RuntimeException
    && $replacedError->getMessage() === 'Mailbox resource release requires its current ready token.'
    && $backend->calls === []
    && $replacedRow['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $replacedRow['QueueRunner_id'] === null
    && $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key = ?', [$replacedKey])
        === $replacementToken
    && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key = ?', [$replacedKey]) === 'READY'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
if ($observer->executeStatement(
    "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
    [$replacedKey, $replacementToken]
) !== 1) {
    throw new RuntimeException('Replaced orphan token cleanup lost exact ownership.');
}

// Failure after the remote fsDelete starts is ambiguous. The failed task and
// archive generation publish, but the exact REMOTE claim blocks a replay.
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$ambiguousUsername = 'ambiguous@orphan-backend.q01d.test';
$backend->directories['/mail/' . $ambiguousUsername] = ['cur', 'new', 'tmp'];
$backend->hasMail['/mail/' . $ambiguousUsername] = true;
$backend->ambiguousCommand = 'fsDelete';
$ambiguousToken = null;
$backend->beforeResponse = static function (string $command) use (
    $observer,
    $ambiguousUsername,
    &$ambiguousToken
): void {
    if ($command !== 'fsDelete') {
        return;
    }
    $row = $observer->fetchAssociative(
        'SELECT owner_token, phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($ambiguousUsername)]
    );
    if (!is_array($row) || ($row['phase'] ?? null) !== 'REMOTE' || !is_string($row['owner_token'] ?? null)) {
        throw new RuntimeException('Ambiguous orphan fixture did not observe its REMOTE owner.');
    }
    $ambiguousToken = $row['owner_token'];
};
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 11:00:00');
$service->backend = $backend;
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $ambiguousUsername);
[$result, $ambiguousError] = queueLifecycleRunOrphan($service, $task, $service->clock);
$ambiguousArchive = $observer->fetchAssociative(
    'SELECT archive_generation, data FROM archive WHERE username = ?',
    [$ambiguousUsername]
);
$backendCallCount = count($backend->calls);
$retryTask = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $ambiguousUsername);
[$retryResult, $retryError] = queueLifecycleRunOrphan($service, $retryTask, $service->clock);
$retainedClaim = $observer->fetchAssociative(
    'SELECT owner_token, phase FROM resource_claim WHERE resource_key = ?',
    [\ViMbAdmin_ResourceClaim::key($ambiguousUsername)]
);
queueLifecycleCheck(
    'ambiguous orphan backend cleanup: FAILED generation and archive retain exact REMOTE ownership and refuse replay',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $ambiguousError instanceof RuntimeException
    && $ambiguousError->getMessage() === 'injected orphan backend interruption'
    && is_string($ambiguousToken) && is_array($ambiguousArchive)
    && is_string($ambiguousArchive['archive_generation'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/D', $ambiguousArchive['archive_generation']) === 1
    && $retryResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $retryError instanceof \ViMbAdmin_Exception
    && $retryError->getMessage() === 'Mailbox resource is already claimed.'
    && count($backend->calls) === $backendCallCount
    && is_array($retainedClaim) && $retainedClaim['owner_token'] === $ambiguousToken
    && $retainedClaim['phase'] === 'REMOTE'
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE username = ?', [$ambiguousUsername])) === 0
    && queueLifecycleInteger($observer->fetchOne('SELECT COUNT(*) FROM queue_runner')) === 0
);
queueLifecycleReconcileClaim($observer, $ambiguousUsername, 'REMOTE');

// Replace the runner after the first real orphan backend response. The next
// heartbeat rejects the old id before another response, and old release cannot
// delete the replacement slot owner.
queueLifecycleResetDeletion($em);
$backend = new QueueLifecycleOrphanBackend();
$leaseUsername = 'replaced-lease@orphan-lease.q01d.test';
$backend->directories['/mail/' . $leaseUsername] = ['cur', 'new', 'tmp'];
$backend->hasMail['/mail/' . $leaseUsername] = false;
$service = new QueueLifecycleOrphanService($em, $orphanOptions);
$service->clock = new DateTime('2027-02-02 11:10:00');
$service->backend = $backend;
$originalOrphanLeaseId = null;
$replacementOrphanLease = null;
$backend->beforeResponse = static function (string $command) use (
    $observer,
    $observerManager,
    $orphanOptions,
    $service,
    $backend,
    &$originalOrphanLeaseId,
    &$replacementOrphanLease
): void {
    if ($command !== 'auth cache flush' || $replacementOrphanLease !== null) {
        return;
    }
    $originalOrphanLeaseId = $observer->fetchOne('SELECT id FROM queue_runner');
    $observer->executeStatement(
        'UPDATE queue_runner SET heartbeat_at = ? WHERE id = ?',
        ['2027-02-02 09:00:00', $originalOrphanLeaseId]
    );
    $replacementOrphanLease = \ViMbAdmin_QueueRunner::acquireLease(
        $observerManager,
        $orphanOptions,
        new DateTime('2027-02-02 11:10:00')
    );
    $service->clock->modify('+61 seconds');
    $backend->beforeResponse = null;
};
$task = queueLifecycleTask($em, type: \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, username: $leaseUsername);
$replacedLeaseError = null;
$replacedLeaseCallbackCalls = 0;
try {
    $service->runOne($task, static function () use (&$replacedLeaseCallbackCalls): void {
        ++$replacedLeaseCallbackCalls;
    });
} catch (Throwable $error) {
    $replacedLeaseError = $error;
}
$replacedLeaseRow = queueLifecycleTaskRow($observer, (int) $task->getId());
$replacementOrphanLeaseId = $replacementOrphanLease instanceof \Entities\QueueRunner
    ? $replacementOrphanLease->getId()
    : null;
queueLifecycleCheck(
    'stale/lost/replaced orphan lease: old heartbeat refuses further backend responses and exact release preserves replacement',
    $replacedLeaseError instanceof RuntimeException
    && $replacedLeaseError->getMessage() === 'Runner lease was lost before its heartbeat.'
    && $replacedLeaseCallbackCalls === 0
    && is_numeric($originalOrphanLeaseId) && is_int($replacementOrphanLeaseId)
    && $replacementOrphanLeaseId != $originalOrphanLeaseId
    && $observer->fetchOne('SELECT id FROM queue_runner WHERE slot = 1') == $replacementOrphanLeaseId
    && array_column($backend->calls, 'command') === ['auth cache flush']
    && $replacedLeaseRow['status'] === \Entities\MailboxTask::STATUS_RUNNING
    && $replacedLeaseRow['QueueRunner_id'] === null
    && $observer->fetchOne(
        'SELECT phase FROM resource_claim WHERE resource_key = ?',
        [\ViMbAdmin_ResourceClaim::key($leaseUsername)]
    ) === 'REMOTE'
);
if (!$replacementOrphanLease instanceof \Entities\QueueRunner) {
    throw new RuntimeException('Orphan replacement lease fixture did not acquire a lease.');
}
\ViMbAdmin_QueueRunner::release($observerManager, $replacementOrphanLease);
queueLifecycleReconcileClaim($observer, $leaseUsername, 'REMOTE');

queueLifecycleResetDeletion($em);
$observerManager->close();
$em->close();

echo 'OK: all queue lifecycle assertions passed (PHP ' . PHP_VERSION . ")\n";
