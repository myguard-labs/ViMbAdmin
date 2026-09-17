<?php

/**
 * Focused queue-runner lease tests. The doubles model a database UNIQUE(slot)
 * constraint and a fake clock so contention and long calls are deterministic.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/QueueRunner.php';
require __DIR__ . '/../application/Entities/MailboxTask.php';
require __DIR__ . '/../application/Repositories/MailboxTask.php';
require __DIR__ . '/../library/ViMbAdmin/Setting.php';
require __DIR__ . '/../library/ViMbAdmin/QueueRunner.php';
require __DIR__ . '/../library/ViMbAdmin/Doveadm.php';
require __DIR__ . '/../library/ViMbAdmin/Service/QueueRunner.php';

final class QueueRunnerLeaseQuery
{
    /** @var array<string,mixed> */
    private array $parameters = [];

    public function __construct(
        private QueueRunnerLeaseEntityManager $em,
        private string $dql
    ) {
    }

    public function setParameter(string $name, mixed $value): self
    {
        $this->parameters[$name] = $value;
        return $this;
    }

    public function getSingleScalarResult(): int
    {
        return count($this->em->leases);
    }

    public function execute(): int
    {
        if (str_starts_with($this->dql, 'DELETE')) {
            $cutoff = $this->parameters['cutoff'] ?? null;
            if (!$cutoff instanceof DateTime) {
                throw new RuntimeException('stale reaper omitted its cutoff');
            }
            $reaped = 0;
            foreach ($this->em->leases as $id => $lease) {
                $heartbeat = $lease->getHeartbeatAt();
                if ($heartbeat !== null && $heartbeat < $cutoff) {
                    unset($this->em->leases[$id]);
                    $reaped++;
                }
            }
            return $reaped;
        }

        if (str_starts_with($this->dql, 'UPDATE')) {
            $id = $this->parameters['id'] ?? null;
            $slot = $this->parameters['slot'] ?? null;
            $heartbeat = $this->parameters['heartbeat'] ?? null;
            if (!is_int($id) || !is_int($slot) || !$heartbeat instanceof DateTime) {
                throw new RuntimeException('heartbeat query parameters malformed');
            }
            $lease = $this->em->leases[$id] ?? null;
            if (!$lease instanceof \Entities\QueueRunner || $lease->getSlot() !== $slot) {
                return 0;
            }
            $lease->setHeartbeatAt(clone $heartbeat);
            return 1;
        }

        throw new RuntimeException('unexpected lease query: ' . $this->dql);
    }
}

final class QueueRunnerLeaseConnection
{
    public ?int $contendOnSlot = null;
    public mixed $nextAcquireLockResult = 1;
    public ?Throwable $nextAcquireLockError = null;
    public int $lockAcquisitions = 0;
    public int $lockReleases = 0;
    private bool $acquireLockHeld = false;
    private int $lastId = 0;

    public function __construct(private QueueRunnerLeaseEntityManager $em)
    {
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        if (!$this->acquireLockHeld) {
            throw new RuntimeException('lease insert happened outside the database mutex');
        }
        if ($table !== 'queue_runner'
            || !is_int($data['slot'] ?? null)
            || !is_string($data['host'] ?? null)
            || !is_int($data['pid'] ?? null)
            || !is_string($data['started_at'] ?? null)
            || !is_string($data['heartbeat_at'] ?? null)) {
            throw new RuntimeException('unexpected lease insert');
        }

        $slot = $data['slot'];
        if ($this->contendOnSlot === $slot) {
            $this->contendOnSlot = null;
            $this->em->addLease($slot, new DateTime($data['heartbeat_at']));
        }
        foreach ($this->em->leases as $lease) {
            if ($lease->getSlot() === $slot) {
                $duplicate = (new ReflectionClass(
                    \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class
                ))->newInstanceWithoutConstructor();
                throw $duplicate;
            }
        }

        $lease = $this->em->addLease($slot, new DateTime($data['heartbeat_at']));
        $lease->setHost($data['host'])
            ->setPid($data['pid'])
            ->setStartedAt(new DateTime($data['started_at']));
        $this->lastId = (int) $lease->getId();
        return 1;
    }

    public function lastInsertId(): int
    {
        return $this->lastId;
    }

    /** @param list<mixed> $parameters */
    public function fetchOne(string $sql, array $parameters = []): mixed
    {
        if ($sql === 'SELECT 1' && $parameters === []) {
            return 1;
        }
        if ($sql === 'SELECT GET_LOCK(?, ?)'
            && $parameters === [
                ViMbAdmin_QueueRunner::ACQUIRE_LOCK_NAME,
                ViMbAdmin_QueueRunner::ACQUIRE_LOCK_TIMEOUT,
            ]) {
            if ($this->nextAcquireLockError !== null) {
                $error = $this->nextAcquireLockError;
                $this->nextAcquireLockError = null;
                throw $error;
            }
            $result = $this->nextAcquireLockResult;
            $this->nextAcquireLockResult = 1;
            if ($result !== 1 && $result !== '1') {
                return $result;
            }
            if ($this->acquireLockHeld) {
                return 0;
            }
            $this->acquireLockHeld = true;
            $this->lockAcquisitions++;
            return 1;
        }

        if ($sql === 'SELECT RELEASE_LOCK(?)'
            && $parameters === [ViMbAdmin_QueueRunner::ACQUIRE_LOCK_NAME]) {
            if (!$this->acquireLockHeld) {
                return 0;
            }
            $this->acquireLockHeld = false;
            $this->lockReleases++;
            return 1;
        }

        throw new RuntimeException('unexpected scalar query: ' . $sql);
    }

    /** @param list<mixed> $parameters */
    public function executeStatement(string $sql, array $parameters = []): int
    {
        return 1;
    }
}

final class QueueRunnerLeaseTaskRepository extends \Repositories\MailboxTask
{
    public int $claims = 0;
    public int $terminalFlushes = 0;
    public bool $claimResult = true;
    public ?QueueRunnerLeaseEntityManager $fakeEntityManager = null;

    public function __construct()
    {
    }

    public function claim(\Entities\MailboxTask $task, \Entities\QueueRunner $runner)
    {
        $this->claims++;
        if ($this->claimResult) {
            $task->setStatus(\Entities\MailboxTask::STATUS_RUNNING);
        }
        return $this->claimResult;
    }

    public function publishIfOwned(
        \Entities\MailboxTask $task,
        \Entities\QueueRunner $runner,
        callable $publish
    ): bool {
        $publish();
        if (!$this->fakeEntityManager instanceof QueueRunnerLeaseEntityManager) {
            throw new RuntimeException('fake entity manager missing');
        }
        $this->fakeEntityManager->flush();
        $this->terminalFlushes++;
        return true;
    }
}

final class QueueRunnerLeaseEntityManager
{
    /** @var array<int,\Entities\QueueRunner> */
    public array $leases = [];
    public int $flushes = 0;
    public int $queries = 0;
    public QueueRunnerLeaseTaskRepository $taskRepository;
    private int $nextId = 1;
    private QueueRunnerLeaseConnection $connection;

    public function __construct()
    {
        $this->connection = new QueueRunnerLeaseConnection($this);
        $this->taskRepository = new QueueRunnerLeaseTaskRepository();
        $this->taskRepository->fakeEntityManager = $this;
    }

    public function createQuery(string $dql): QueueRunnerLeaseQuery
    {
        $this->queries++;
        return new QueueRunnerLeaseQuery($this, $dql);
    }

    public function getConnection(): QueueRunnerLeaseConnection
    {
        return $this->connection;
    }

    public function getRepository(string $class): object
    {
        if ($class !== '\Entities\MailboxTask') {
            throw new RuntimeException('unexpected repository request');
        }
        return $this->taskRepository;
    }

    public function find(string $class, int $id): ?object
    {
        return $class === '\Entities\QueueRunner' ? ($this->leases[$id] ?? null) : null;
    }

    public function remove(object $lease): void
    {
        if ($lease instanceof \Entities\QueueRunner && $lease->getId() !== null) {
            unset($this->leases[$lease->getId()]);
        }
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function addLease(int $slot, DateTime $heartbeat): \Entities\QueueRunner
    {
        $lease = (new \Entities\QueueRunner())
            ->setSlot($slot)
            ->setHost('test')
            ->setPid(1)
            ->setStartedAt(clone $heartbeat)
            ->setHeartbeatAt(clone $heartbeat);
        $property = new ReflectionProperty($lease, 'id');
        $property->setValue($lease, $this->nextId);
        $this->leases[$this->nextId++] = $lease;
        return $lease;
    }
}

final class QueueRunnerBlockingDoveadm extends ViMbAdmin_Doveadm
{
    /** @var callable():void */
    private $progress;
    /** @var callable(callable():void):void */
    private $block;

    /**
     * @param callable():void $progress
     * @param callable(callable():void):void $block
     */
    public function __construct(callable $progress, callable $block)
    {
        $this->progress = $progress;
        $this->block = $block;
    }

    /** @return array<mixed> */
    public function quotaRecalc($user)
    {
        ($this->block)($this->progress);
        return [];
    }
}

final class QueueRunnerBlockingService extends ViMbAdmin_Service_QueueRunner
{
    public DateTime $clock;
    /** @var callable(callable():void):void */
    public $block;

    protected function now()
    {
        return clone $this->clock;
    }

    protected function newDoveadm($progress)
    {
        return new QueueRunnerBlockingDoveadm($progress, $this->block);
    }
}

final class QueueRunnerSilentTransfer extends ViMbAdmin_Doveadm
{
    public function __construct(callable $progress)
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'test-key', 900, $progress);
    }

    public function runSilent(int $waits, callable $wait): void
    {
        $performs = 0;
        $this->driveTransfer(
            static function () use (&$performs, $waits): array {
                $running = $performs++ < $waits ? 1 : 0;
                return [0, $running];
            },
            $wait
        );
    }

    public function runCallAgainThenComplete(): int
    {
        $performs = 0;
        $this->driveTransfer(
            static function () use (&$performs): array {
                $performs++;
                return $performs === 1 ? [-1, 1] : [0, 0];
            },
            static function (): int {
                throw new RuntimeException('call-again result must not wait');
            }
        );
        return $performs;
    }

    public function runAlreadyComplete(): void
    {
        $this->driveTransfer(
            static function (): array {
                return [0, 0];
            },
            static function (): int {
                throw new RuntimeException('completed transfer must not wait');
            }
        );
    }
}

final class QueueRunnerLeaseAssertions
{
    public static int $failures = 0;
}

function queueRunnerCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        QueueRunnerLeaseAssertions::$failures++;
    }
}

/** @return array{queue:array{runner:array{max_concurrent:mixed}}} */
function queueRunnerOptions(mixed $max): array
{
    return ['queue' => ['runner' => ['max_concurrent' => $max]]];
}

function queueRunnerService(
    QueueRunnerLeaseEntityManager $em,
    DateTime $clock,
    callable $block,
    int $max = 1
): QueueRunnerBlockingService {
    $runner = (new ReflectionClass(QueueRunnerBlockingService::class))
        ->newInstanceWithoutConstructor();
    $runner->clock = clone $clock;
    $runner->block = $block;
    $emProperty = new ReflectionProperty(ViMbAdmin_Service_QueueRunner::class, 'em');
    $emProperty->setValue($runner, $em);
    $optionsProperty = new ReflectionProperty(ViMbAdmin_Service_QueueRunner::class, 'options');
    $optionsProperty->setValue($runner, queueRunnerOptions($max));
    return $runner;
}

/**
 * @param array{queue?:array{runner?:array{max_concurrent?:mixed, ...<string,mixed>}, ...<string,mixed>}, ...<string,mixed>} $options
 */
function queueRunnerAcquire(
    QueueRunnerLeaseEntityManager $em,
    array $options,
    DateTime $now
): mixed {
    return (new ReflectionMethod(ViMbAdmin_QueueRunner::class, 'acquireLease'))
        ->invoke(null, $em, $options, $now);
}

function queueRunnerRelease(
    QueueRunnerLeaseEntityManager $em,
    \Entities\QueueRunner $lease
): void {
    (new ReflectionMethod(ViMbAdmin_QueueRunner::class, 'release'))
        ->invoke(null, $em, $lease);
}

function queueRunnerReap(
    QueueRunnerLeaseEntityManager $em,
    DateTime $now
): int {
    $reaped = (new ReflectionMethod(ViMbAdmin_QueueRunner::class, 'reapStale'))
        ->invoke(null, $em, $now);
    if (!is_int($reaped)) {
        throw new RuntimeException('stale reaper returned a non-integer');
    }
    return $reaped;
}

echo "== QueueRunner leases ==\n";
$epoch = new DateTime('2026-01-01 00:00:00');

$available = new QueueRunnerLeaseEntityManager();
$first = queueRunnerAcquire($available, [], $epoch);
queueRunnerCheck(
    'default cap atomically claims slot one',
    $first instanceof \Entities\QueueRunner
        && $first->getSlot() === 1
        && count($available->leases) === 1
);
queueRunnerCheck(
    'occupied unique slot denies a second cap-one runner',
    queueRunnerAcquire($available, queueRunnerOptions(1), $epoch) === null
        && count($available->leases) === 1
);
if (!$first instanceof \Entities\QueueRunner) {
    throw new RuntimeException('first lease was not acquired');
}
queueRunnerRelease($available, $first);
queueRunnerCheck('release frees the claimed slot', count($available->leases) === 0);

$lockTimeout = new QueueRunnerLeaseEntityManager();
$lockTimeoutConnection = $lockTimeout->getConnection();
$lockTimeoutConnection->nextAcquireLockResult = '0';
queueRunnerCheck(
    'database mutex timeout is ordinary busy contention',
    queueRunnerAcquire($lockTimeout, queueRunnerOptions(1), $epoch) === null
        && $lockTimeout->queries === 0
        && $lockTimeoutConnection->lockAcquisitions === 0
        && $lockTimeoutConnection->lockReleases === 0
);
$lockTimeoutConnection->nextAcquireLockResult = 0;
$lockTimeoutCallbackRan = false;
$lockTimeoutRunner = queueRunnerService(
    $lockTimeout,
    clone $epoch,
    static function (callable $progress): void {
    },
);
$lockTimeoutTask = (new \Entities\MailboxTask())
    ->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
    ->setUsername('mutex-timeout@example.test')
    ->setStatus(\Entities\MailboxTask::STATUS_PENDING);
queueRunnerCheck(
    'manual run maps a database mutex timeout to busy without claiming',
    $lockTimeoutRunner->runOne(
        $lockTimeoutTask,
        static function () use (&$lockTimeoutCallbackRan): void {
            $lockTimeoutCallbackRan = true;
        }
    ) === ViMbAdmin_Service_QueueRunner::RUN_ONE_BUSY
        && !$lockTimeoutCallbackRan
        && $lockTimeout->taskRepository->claims === 0
);

$lockFailure = new QueueRunnerLeaseEntityManager();
$lockFailureConnection = $lockFailure->getConnection();
$lockFailureConnection->nextAcquireLockError = new RuntimeException('database unavailable');
$lockFailureObserved = false;
try {
    queueRunnerAcquire($lockFailure, queueRunnerOptions(1), $epoch);
} catch (RuntimeException $error) {
    $lockFailureObserved = $error->getMessage() === 'database unavailable';
}
queueRunnerCheck(
    'database mutex query failure remains an operational error',
    $lockFailureObserved
        && $lockFailure->queries === 0
        && $lockFailureConnection->lockAcquisitions === 0
);

$lockMalformed = new QueueRunnerLeaseEntityManager();
$lockMalformedConnection = $lockMalformed->getConnection();
$lockMalformedConnection->nextAcquireLockResult = null;
$lockMalformedObserved = false;
try {
    queueRunnerAcquire($lockMalformed, queueRunnerOptions(1), $epoch);
} catch (UnexpectedValueException $error) {
    $lockMalformedObserved = str_contains($error->getMessage(), 'invalid result');
}
queueRunnerCheck(
    'malformed database mutex result fails closed',
    $lockMalformedObserved
        && $lockMalformed->queries === 0
        && $lockMalformedConnection->lockAcquisitions === 0
);

$contended = new QueueRunnerLeaseEntityManager();
$contended->getConnection()->contendOnSlot = 1;
$winner = queueRunnerAcquire($contended, queueRunnerOptions(1), $epoch);
queueRunnerCheck(
    'database duplicate-key contention admits only its winner at cap one',
    $winner === null
        && count($contended->leases) === 1
        && reset($contended->leases)->getSlot() === 1
);

$twoSlots = new QueueRunnerLeaseEntityManager();
$twoSlots->getConnection()->contendOnSlot = 1;
$secondSlot = queueRunnerAcquire($twoSlots, queueRunnerOptions(2), $epoch);
queueRunnerCheck(
    'a loser on slot one can claim the next configured unique slot',
    $secondSlot instanceof \Entities\QueueRunner
        && $secondSlot->getSlot() === 2
        && count($twoSlots->leases) === 2
);

$stale = new QueueRunnerLeaseEntityManager();
$stale->addLease(
    1,
    (clone $epoch)->modify('-' . (ViMbAdmin_QueueRunner::LEASE_TTL + 1) . ' seconds')
);
$reclaimed = queueRunnerAcquire($stale, queueRunnerOptions(1), $epoch);
queueRunnerCheck(
    'a crash-stale lease is reaped and its unique slot reclaimed',
    $reclaimed instanceof \Entities\QueueRunner
        && $reclaimed->getSlot() === 1
        && count($stale->leases) === 1
);

$reduced = new QueueRunnerLeaseEntityManager();
$reduced->addLease(5, clone $epoch);
$reducedConnection = $reduced->getConnection();
$reducedResult = queueRunnerAcquire($reduced, queueRunnerOptions(1), $epoch);
queueRunnerCheck(
    'a cap reduction counts a live out-of-range slot and denies a new runner',
    $reducedResult === null
        && count($reduced->leases) === 1
        && reset($reduced->leases)->getSlot() === 5
        && $reducedConnection->lockAcquisitions === 1
        && $reducedConnection->lockReleases === 1
);

$manual = new QueueRunnerLeaseEntityManager();
$manual->addLease(1, clone $epoch);
$manualCallbackRan = false;
$manualRunner = queueRunnerService(
    $manual,
    clone $epoch,
    static function (callable $progress): void {
    },
);
$manualTask = (new \Entities\MailboxTask())
    ->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
    ->setUsername('manual@example.test')
    ->setStatus(\Entities\MailboxTask::STATUS_PENDING);
$manualResult = $manualRunner->runOne(
    $manualTask,
    static function () use (&$manualCallbackRan): void {
        $manualCallbackRan = true;
    }
);
queueRunnerCheck(
    'manual runOne is denied before claim while cap one is occupied',
    $manualResult === ViMbAdmin_Service_QueueRunner::RUN_ONE_BUSY
        && !$manualCallbackRan
        && $manual->taskRepository->claims === 0
        && $manualTask->getStatus() === \Entities\MailboxTask::STATUS_PENDING
        && count($manual->leases) === 1
);

$live = new QueueRunnerLeaseEntityManager();
$liveLeaseVisible = false;
$liveRunner = null;
$liveRunner = queueRunnerService(
    $live,
    clone $epoch,
    static function (callable $progress) use (&$liveRunner, $live, $epoch, &$liveLeaseVisible): void {
        if (!$liveRunner instanceof QueueRunnerBlockingService) {
            throw new RuntimeException('blocking runner missing');
        }
        $liveRunner->clock = (clone $epoch)->modify(
            '+' . (ViMbAdmin_QueueRunner::LEASE_TTL - 1) . ' seconds'
        );
        $progress();
        $liveRunner->clock = (clone $epoch)->modify(
            '+' . (ViMbAdmin_QueueRunner::LEASE_TTL + 1) . ' seconds'
        );
        $liveLeaseVisible = queueRunnerReap(
            $live,
            $liveRunner->clock
        ) === 0 && count($live->leases) === 1;
    },
);
$task = (new \Entities\MailboxTask())
    ->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC)
    ->setUsername('alice@example.test')
    ->setStatus(\Entities\MailboxTask::STATUS_PENDING);
$liveResult = $liveRunner->runOne(
    $task,
    static function (?Throwable $error): void {
        if ($error !== null) {
            throw $error;
        }
    },
);
queueRunnerCheck(
    'blocking task progress renews its live lease before the stale reaper runs',
    $liveResult === ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
        && $liveLeaseVisible
        && $live->taskRepository->claims === 1
        && count($live->leases) === 0
);
queueRunnerCheck(
    'manual task finalization uses one ownership-fenced flush',
    $live->taskRepository->terminalFlushes === 1 && $live->flushes === 2
);
$queueControllerSource = file_get_contents(__DIR__ . '/../src/Kernel/Controller/QueueController.php');
$runTaskStart = is_string($queueControllerSource) ? strpos($queueControllerSource, 'public function runTaskAction()') : false;
$triggerStart = is_string($queueControllerSource) ? strpos($queueControllerSource, 'public function triggerAction()', $runTaskStart ?: 0) : false;
$runTaskSource = $runTaskStart !== false && $triggerStart !== false
    ? substr($queueControllerSource, $runTaskStart, $triggerStart - $runTaskStart)
    : '';
queueRunnerCheck(
    'manual completion callback leaves the ownership fence as its only flusher',
    $runTaskSource !== '' && !str_contains($runTaskSource, '$this->em()->flush()')
);

$silent = new QueueRunnerLeaseEntityManager();
$silentLease = queueRunnerAcquire($silent, queueRunnerOptions(1), $epoch);
if (!$silentLease instanceof \Entities\QueueRunner) {
    throw new RuntimeException('silent-call lease was not acquired');
}
$silentRunner = queueRunnerService(
    $silent,
    clone $epoch,
    static function (callable $progress): void {
    },
);
$leaseProgress = (new ReflectionMethod(ViMbAdmin_Service_QueueRunner::class, 'leaseProgress'))
    ->invoke($silentRunner, $silentLease);
if (!is_callable($leaseProgress)) {
    throw new RuntimeException('lease progress callback missing');
}
$silentCompetitorDenied = true;
$silentWaits = (int) ceil(
    (ViMbAdmin_QueueRunner::LEASE_TTL + 1) /
    (ViMbAdmin_Service_QueueRunner::LEASE_HEARTBEAT_INTERVAL + 1)
);
$silentTransfer = new QueueRunnerSilentTransfer($leaseProgress);
$silentTransfer->runSilent(
    $silentWaits,
    static function () use (
        $silent,
        $silentRunner,
        &$silentCompetitorDenied
    ): int {
        $silentRunner->clock->modify(
            '+' . (ViMbAdmin_Service_QueueRunner::LEASE_HEARTBEAT_INTERVAL + 1) . ' seconds'
        );
        if (queueRunnerAcquire(
            $silent,
            queueRunnerOptions(1),
            $silentRunner->clock
        ) !== null) {
            $silentCompetitorDenied = false;
        }
        return 0;
    }
);
$silentElapsed = $silentRunner->clock->getTimestamp() - $epoch->getTimestamp();
queueRunnerCheck(
    'silent transfer beyond TTL stays live and denies every competing acquire',
    $silentElapsed > ViMbAdmin_QueueRunner::LEASE_TTL
        && $silentCompetitorDenied
        && count($silent->leases) === 1
        && reset($silent->leases) === $silentLease
        && $silentLease->getHeartbeatAt() instanceof DateTime
        && $silentLease->getHeartbeatAt()->getTimestamp()
            === $silentRunner->clock->getTimestamp()
                - ViMbAdmin_Service_QueueRunner::LEASE_HEARTBEAT_INTERVAL - 1
);
queueRunnerRelease($silent, $silentLease);

$callAgainTicks = 0;
$callAgainTransfer = new QueueRunnerSilentTransfer(
    static function () use (&$callAgainTicks): void {
        $callAgainTicks++;
    }
);
queueRunnerCheck(
    'legacy CURLM_CALL_MULTI_PERFORM immediately retries before progress or wait',
    $callAgainTransfer->runCallAgainThenComplete() === 2
        && $callAgainTicks === 0
);

$completedHeartbeatCalls = 0;
$completedTransfer = new QueueRunnerSilentTransfer(
    static function () use (&$completedHeartbeatCalls): void {
        $completedHeartbeatCalls++;
        throw new RuntimeException('completed transfer heartbeat must not run');
    }
);
$completedResponsePreserved = true;
try {
    $completedTransfer->runAlreadyComplete();
} catch (RuntimeException) {
    $completedResponsePreserved = false;
}
queueRunnerCheck(
    'completed destructive transfer preserves its response when heartbeat would fail',
    $completedResponsePreserved && $completedHeartbeatCalls === 0
);

$liveHeartbeatCalls = 0;
$liveTransfer = new QueueRunnerSilentTransfer(
    static function () use (&$liveHeartbeatCalls): void {
        $liveHeartbeatCalls++;
        throw new RuntimeException('live transfer heartbeat failed');
    }
);
$liveHeartbeatFailurePropagated = false;
try {
    $liveTransfer->runSilent(1, static function (): int {
        throw new RuntimeException('failed live heartbeat must abort before wait');
    });
} catch (RuntimeException $e) {
    $liveHeartbeatFailurePropagated = $e->getMessage() === 'live transfer heartbeat failed';
}
queueRunnerCheck(
    'live transfer still propagates heartbeat failure before waiting',
    $liveHeartbeatFailurePropagated && $liveHeartbeatCalls === 1
);

$boundary = new QueueRunnerLeaseEntityManager();
foreach ([-4, 'not-a-number', null, []] as $max) {
    $rejected = false;
    try {
        queueRunnerAcquire($boundary, queueRunnerOptions($max), $epoch);
    } catch (TypeError) {
        $rejected = true;
    }
    queueRunnerCheck(
        'malformed max fails closed before queue access: ' . get_debug_type($max),
        $rejected && $boundary->queries === 0
    );
}

$exitCode = QueueRunnerLeaseAssertions::$failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all QueueRunner lease assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: " . QueueRunnerLeaseAssertions::$failures . " assertion(s)\n";
exit($exitCode);
