<?php

declare(strict_types=1);

/**
 * Fault-injection regression coverage for durable DELETE task checkpoints.
 *
 * Each simulated interruption happens after a checkpoint flush has committed.
 * The retry is built from that persisted JSON, as a new worker process would be,
 * and must not repeat any completed side effect.
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../library/OSS/Doctrine2/WithPreferences.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../library/ViMbAdmin/Service/QueueRunner.php';

final class DeleteRetryState
{
    public ?\Entities\Mailbox $mailbox;
    public ?\Entities\Archive $archive = null;
    public string $persistedTaskData = '';
    public bool $sourceHasMail = true;
    public bool $sourceExists = true;
    public bool $sourceDisappearsBeforeDelete = false;
    public ?Throwable $sourceProbeError = null;
    /** @var list<Throwable|null> */
    public array $sourceListErrors = [];
    public ?Throwable $sourceDeleteError = null;
    public bool $destinationExists = false;
    public bool $destinationHasMail = false;
    public ?ViMbAdmin_Exception $destinationProbeError = null;
    public int $uncheckpointedAuditFlushes = 0;
    public bool $failAuditPersistOnce = false;

    /** @var array<string,int> */
    public array $calls = [
        'backup' => 0,
        'archive' => 0,
        'quota-recalc' => 0,
        'mailbox-delete' => 0,
        'maildir-home' => 0,
        'mailbox-row' => 0,
        'audit' => 0,
        'measure-task' => 0,
    ];

    public function __construct(public readonly \Entities\Domain $domain)
    {
        $this->mailbox = (new \Entities\Mailbox())
            ->setUsername('user@example.test')
            ->setLocalPart('user')
            ->setName('Retry User')
            ->setPassword('{CRYPT}preserved')
            ->setQuota(4096)
            ->setActive(true)
            ->setDomain($domain);
    }
}

final class DeleteRetryMailboxRepository
{
    public function __construct(private readonly DeleteRetryState $state)
    {
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria): ?\Entities\Mailbox
    {
        if ($criteria !== ['username' => 'user@example.test']) {
            throw new RuntimeException('unexpected mailbox criteria');
        }
        return $this->state->mailbox;
    }

    public function purgeMailbox(\Entities\Mailbox $mailbox, mixed $admin, bool $removeMailbox): bool
    {
        if ($mailbox !== $this->state->mailbox || $admin !== null || !$removeMailbox) {
            throw new RuntimeException('unexpected mailbox purge contract');
        }
        $this->state->calls['mailbox-row']++;
        $this->state->mailbox = null;
        return true;
    }
}

final class DeleteRetryArchiveRepository
{
    public function __construct(private readonly DeleteRetryState $state)
    {
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria): ?\Entities\Archive
    {
        if ($criteria !== ['username' => 'user@example.test']) {
            throw new RuntimeException('unexpected archive criteria');
        }
        return $this->state->archive;
    }
}

final class DeleteRetryQuery
{
    /** @var array<string,mixed> */
    private array $parameters = [];

    public function setParameter(string $name, mixed $value): self
    {
        $this->parameters[$name] = $value;
        return $this;
    }

    public function getSingleScalarResult(): int
    {
        if (array_keys($this->parameters) !== ['u', 't', 'open']) {
            throw new RuntimeException('unexpected measure-task query parameters');
        }
        return 0;
    }
}

final class DeleteRetryConnection
{
    /** @param list<mixed> $parameters */
    public function fetchOne(string $sql, array $parameters = []): int
    {
        if ($sql === 'SELECT 1' && $parameters === []) {
            return 1;
        }
        if (!str_contains($sql, 'SELECT bytes FROM dovecot_quota')
            || $parameters !== ['user@example.test']) {
            throw new RuntimeException('unexpected quota query');
        }
        return 2048;
    }

    /** @param list<mixed> $parameters */
    public function executeStatement(string $sql, array $parameters = []): int
    {
        if (!str_contains($sql, 'DELETE FROM dovecot_quota')
            || $parameters !== ['user@example.test']) {
            throw new RuntimeException('unexpected quota cleanup');
        }
        return 1;
    }
}

final class DeleteRetryEntityManager
{
    private readonly DeleteRetryConnection $connection;
    private readonly DeleteRetryMailboxRepository $mailboxes;
    private readonly DeleteRetryArchiveRepository $archives;
    private bool $auditStaged = false;

    public function __construct(
        private readonly DeleteRetryState $state,
        private readonly \Entities\MailboxTask $task,
        private ?string $interruptAfter = null,
    ) {
        $this->connection = new DeleteRetryConnection();
        $this->mailboxes = new DeleteRetryMailboxRepository($state);
        $this->archives = new DeleteRetryArchiveRepository($state);
    }

    public function getRepository(string $class): object
    {
        return match ($class) {
            '\\Entities\\Mailbox' => $this->mailboxes,
            '\\Entities\\Archive' => $this->archives,
            default => throw new RuntimeException('unexpected repository: ' . $class),
        };
    }

    public function createQuery(string $dql): DeleteRetryQuery
    {
        if (!str_contains($dql, 'TYPE_MEASURE_SIZE') && !str_contains($dql, 't.type = :t')) {
            throw new RuntimeException('unexpected task query');
        }
        return new DeleteRetryQuery();
    }

    public function getConnection(): DeleteRetryConnection
    {
        return $this->connection;
    }

    public function persist(object $entity): void
    {
        if ($entity instanceof \Entities\Archive) {
            $this->state->calls['archive']++;
            $this->state->archive = $entity;
        } elseif ($entity instanceof \Entities\Log) {
            if ($this->state->failAuditPersistOnce) {
                $this->state->failAuditPersistOnce = false;
                throw new RuntimeException('simulated audit persist failure');
            }
            $this->auditStaged = true;
        } elseif ($entity instanceof \Entities\MailboxTask && $entity !== $this->task) {
            $this->state->calls['measure-task']++;
        }
    }

    public function flush(): void
    {
        $this->state->persistedTaskData = (string) $this->task->getData();
        $data = json_decode($this->state->persistedTaskData, true);
        $completed = is_array($data)
            && is_array($data['_queue_runner_delete'] ?? null)
            && is_array($data['_queue_runner_delete']['completed'] ?? null)
                ? $data['_queue_runner_delete']['completed']
                : [];
        if ($this->auditStaged) {
            if (!in_array('audit', $completed, true)) {
                $this->state->uncheckpointedAuditFlushes++;
            }
            $this->state->calls['audit']++;
            $this->auditStaged = false;
        }
        if ($this->interruptAfter === null) {
            return;
        }
        if (in_array($this->interruptAfter, $completed, true)) {
            $step = $this->interruptAfter;
            $this->interruptAfter = null;
            throw new RuntimeException('simulated committed interruption after ' . $step);
        }
    }
}

final class DeleteRetryDoveadm extends ViMbAdmin_Doveadm
{
    public function __construct(private readonly DeleteRetryState $state)
    {
    }

    public function fsListDirs($path, $filter = 'posix')
    {
        if ($path !== '/mail/user@example.test' || $filter !== 'posix') {
            throw new RuntimeException('unexpected maildir-home existence probe');
        }
        if ($this->state->sourceListErrors !== []) {
            $error = array_shift($this->state->sourceListErrors);
            if ($error !== null) {
                throw $error;
            }
        }
        if (!$this->state->sourceExists) {
            throw new ViMbAdmin_Doveadm_CommandException('fsIterDirs', 'No such file or directory', 68);
        }
        return ['cur', 'new', 'tmp'];
    }

    public function maildirHasMail($maildir, $filter = 'posix')
    {
        if ($filter !== 'posix') {
            throw new RuntimeException('unexpected filesystem filter');
        }
        if (str_contains((string) $maildir, '/backups/')) {
            if ($this->state->destinationProbeError !== null) {
                throw $this->state->destinationProbeError;
            }
            if (!$this->state->destinationExists) {
                throw new ViMbAdmin_Exception(
                    "doveadm 'fsIter' failed: No such file or directory (exit 68)"
                );
            }
            return $this->state->destinationHasMail;
        }
        if ($this->state->sourceProbeError !== null) {
            throw $this->state->sourceProbeError;
        }
        if (!$this->state->sourceExists) {
            throw new ViMbAdmin_Exception("doveadm 'fsIter' failed: No such file or directory (exit 68)");
        }
        return $this->state->sourceHasMail;
    }

    public function backup($user, $dest)
    {
        if ($user !== 'user@example.test'
            || $dest !== 'maildir:/backups/example.test/user@example.test') {
            throw new RuntimeException('unexpected backup identity');
        }
        $this->state->calls['backup']++;
        $this->state->destinationExists = true;
        $this->state->destinationHasMail = $this->state->sourceHasMail;
        return [];
    }

    public function quotaRecalc($user)
    {
        if ($user !== 'user@example.test') {
            throw new RuntimeException('unexpected quota identity');
        }
        $this->state->calls['quota-recalc']++;
        return [];
    }

    public function mailboxDelete($user)
    {
        if ($user !== 'user@example.test') {
            throw new RuntimeException('unexpected mailbox-delete identity');
        }
        $this->state->calls['mailbox-delete']++;
        $this->state->sourceHasMail = false;
    }

    public function fsDelete($path, $filter = 'posix')
    {
        if ($path !== '/mail/user@example.test' || $filter !== 'posix') {
            throw new RuntimeException('unexpected maildir-home delete');
        }
        $this->state->calls['maildir-home']++;
        if ($this->state->sourceDeleteError !== null) {
            throw $this->state->sourceDeleteError;
        }
        if ($this->state->sourceDisappearsBeforeDelete) {
            $this->state->sourceExists = false;
            throw new ViMbAdmin_Doveadm_CommandException('fsDelete', 'No such file or directory', 68);
        }
        $this->state->sourceExists = false;
        return [];
    }
}

final class DeleteRetryAssertions
{
    public static int $failures = 0;
}

function deleteRetryCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        DeleteRetryAssertions::$failures++;
    }
}

/** @param array<string,mixed> $legacy */
function deleteRetryTask(\Entities\Domain $domain, array $legacy = []): \Entities\MailboxTask
{
    $task = (new \Entities\MailboxTask())
        ->setType(\Entities\MailboxTask::TYPE_DELETE)
        ->setUsername('user@example.test')
        ->setStatus(\Entities\MailboxTask::STATUS_RUNNING)
        ->setDomain($domain);
    if ($legacy !== []) {
        $task->setData(json_encode($legacy, JSON_THROW_ON_ERROR));
    }
    return $task;
}

/** @param array<string,mixed> $options */
function deleteRetryRunner(
    DeleteRetryState $state,
    \Entities\MailboxTask $task,
    array $options,
    ?string $interruptAfter = null,
): ViMbAdmin_Service_QueueRunner {
    $runner = (new ReflectionClass(ViMbAdmin_Service_QueueRunner::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($runner, 'em'))->setValue(
        $runner,
        new DeleteRetryEntityManager($state, $task, $interruptAfter),
    );
    (new ReflectionProperty($runner, 'options'))->setValue($runner, $options);
    return $runner;
}

function deleteRetryExecute(
    ViMbAdmin_Service_QueueRunner $runner,
    \Entities\MailboxTask $task,
    DeleteRetryDoveadm $doveadm,
): ?Throwable {
    try {
        (new ReflectionMethod($runner, 'execute'))->invoke($runner, $task, $doveadm);
        return null;
    } catch (Throwable $error) {
        return $error->getPrevious() ?? $error;
    }
}

/** @return list<string>|null */
function deleteRetryCompleted(mixed $data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    $progress = $data['_queue_runner_delete'] ?? null;
    if (!is_array($progress)) {
        return null;
    }
    $completed = $progress['completed'] ?? null;
    if (!is_array($completed) || !array_is_list($completed)) {
        return null;
    }
    $steps = [];
    foreach ($completed as $step) {
        if (!is_string($step)) {
            return null;
        }
        $steps[] = $step;
    }
    return $steps;
}

/** @return array<string,mixed> */
function deleteRetryOptions(
    int $days,
    string $destination = 'maildir:/backups/%d/%u',
): array {
    return [
        'queue' => ['autoprune' => ['days' => $days]],
        'doveadm' => [
            'maildir_root' => '/mail',
            'backup' => ['dest' => $destination],
            'http' => ['url' => 'http://doveadm.test/v1'],
        ],
    ];
}

echo "== QueueRunner destructive retry checkpoints ==\n";

$expectedCalls = [
    'backup' => 1,
    'archive' => 1,
    'quota-recalc' => 1,
    'mailbox-delete' => 1,
    'maildir-home' => 1,
    'mailbox-row' => 1,
    'audit' => 1,
    'measure-task' => 1,
];

foreach (['backup', 'archive', 'mailbox-delete', 'maildir-home', 'mailbox-row', 'audit'] as $boundary) {
    $domain = (new \Entities\Domain())->setDomain('example.test');
    $state = new DeleteRetryState($domain);
    $task = deleteRetryTask($domain, ['legacy' => ['request' => 'preserve']]);
    $doveadm = new DeleteRetryDoveadm($state);
    $error = deleteRetryExecute(
        deleteRetryRunner($state, $task, deleteRetryOptions(90), $boundary),
        $task,
        $doveadm,
    );
    deleteRetryCheck(
        "fault injected after {$boundary} checkpoint",
        $error?->getMessage() === 'simulated committed interruption after ' . $boundary,
    );

    $retry = deleteRetryTask($domain);
    $retry->setData($state->persistedTaskData);
    $retryError = deleteRetryExecute(
        deleteRetryRunner($state, $retry, deleteRetryOptions(90)),
        $retry,
        $doveadm,
    );
    $retryData = json_decode((string) $retry->getData(), true);
    $retryProgress = is_array($retryData) ? ($retryData['_queue_runner_delete'] ?? null) : null;
    deleteRetryCheck("retry after {$boundary} completes", $retryError === null);
    deleteRetryCheck(
        "retry after {$boundary} does not repeat completed side effects",
        $state->calls === $expectedCalls,
    );
    deleteRetryCheck(
        "retry after {$boundary} commits audit with its checkpoint",
        $state->uncheckpointedAuditFlushes === 0,
    );
    deleteRetryCheck(
        "retry after {$boundary} preserves existing task JSON",
        is_array($retryData)
            && ($retryData['legacy'] ?? null) === ['request' => 'preserve']
            && is_array($retryProgress)
            && ($retryProgress['destination'] ?? null)
                === 'maildir:/backups/example.test/user@example.test'
            && ($retryProgress['completed'] ?? null)
                === ['backup', 'archive', 'mailbox-delete', 'maildir-home', 'mailbox-row', 'audit'],
    );
}

// Old FAILED tasks have no checkpoint metadata. If their destructive step had
// already emptied the source, the populated backup must remain untouched.
$legacyDomain = (new \Entities\Domain())->setDomain('example.test');
$legacyState = new DeleteRetryState($legacyDomain);
$legacyState->sourceHasMail = false;
$legacyState->destinationExists = true;
$legacyState->destinationHasMail = true;
$legacyTask = deleteRetryTask($legacyDomain, ['legacy' => 'pre-checkpoint-task']);
$legacyDoveadm = new DeleteRetryDoveadm($legacyState);
$legacyError = deleteRetryExecute(
    deleteRetryRunner($legacyState, $legacyTask, deleteRetryOptions(90)),
    $legacyTask,
    $legacyDoveadm,
);

// Destination configuration is resolved once, before backup. A later retry
// must record the archive at that same location even if configuration changed.
$destinationDomain = (new \Entities\Domain())->setDomain('example.test');
$destinationState = new DeleteRetryState($destinationDomain);
$destinationTask = deleteRetryTask($destinationDomain);
$destinationDoveadm = new DeleteRetryDoveadm($destinationState);
$destinationError = deleteRetryExecute(
    deleteRetryRunner(
        $destinationState,
        $destinationTask,
        deleteRetryOptions(90),
        'backup',
    ),
    $destinationTask,
    $destinationDoveadm,
);
$destinationRetry = deleteRetryTask($destinationDomain);
$destinationRetry->setData($destinationState->persistedTaskData);
$destinationRetryError = deleteRetryExecute(
    deleteRetryRunner(
        $destinationState,
        $destinationRetry,
        deleteRetryOptions(90, 'maildir:/changed/%d/%u'),
    ),
    $destinationRetry,
    $destinationDoveadm,
);
deleteRetryCheck(
    'backup destination remains pinned across retry configuration changes',
    $destinationError?->getMessage() === 'simulated committed interruption after backup'
        && $destinationRetryError === null
        && $destinationState->calls === $expectedCalls
        && $destinationState->uncheckpointedAuditFlushes === 0
        && $destinationState->archive?->getMaildirFile()
            === 'maildir:/backups/example.test/user@example.test',
);
deleteRetryCheck(
    'empty legacy source cannot overwrite a populated backup',
    $legacyError?->getMessage()
        === 'refusing to overwrite existing backup for user@example.test from an empty mail store'
        && $legacyState->calls['backup'] === 0
        && $legacyState->calls['archive'] === 0
        && $legacyState->calls['mailbox-delete'] === 0,
);

$probeDomain = (new \Entities\Domain())->setDomain('example.test');
$probeState = new DeleteRetryState($probeDomain);
$probeState->destinationProbeError = new ViMbAdmin_Exception('doveadm transport unavailable');
$probeTask = deleteRetryTask($probeDomain);
$probeError = deleteRetryExecute(
    deleteRetryRunner($probeState, $probeTask, deleteRetryOptions(90)),
    $probeTask,
    new DeleteRetryDoveadm($probeState),
);
deleteRetryCheck(
    'destination probe errors fail closed before backup',
    $probeError?->getMessage() === 'doveadm transport unavailable'
        && array_sum($probeState->calls) === 0,
);

foreach (['mail remains' => true, 'probe fails' => false] as $keepReason => $mailRemains) {
    $keepDomain = (new \Entities\Domain())->setDomain('example.test');
    $keepState = new DeleteRetryState($keepDomain);
    $keepTask = deleteRetryTask($keepDomain);
    $keepDoveadm = new DeleteRetryDoveadm($keepState);
    if ($mailRemains) {
        // Model a mailbox-delete command that succeeds without emptying the home.
        $keepState->sourceHasMail = true;
    } else {
        $keepState->sourceProbeError = new RuntimeException('simulated home probe failure');
    }

    $keepError = deleteRetryExecute(
        deleteRetryRunner($keepState, $keepTask, deleteRetryOptions(0), 'mailbox-delete'),
        $keepTask,
        $keepDoveadm,
    );
    // Restore the retained-home condition after the injected post-mailbox-delete interruption.
    $keepState->sourceHasMail = $mailRemains;
    $keepRetry = deleteRetryTask($keepDomain);
    $keepRetry->setData($keepState->persistedTaskData);
    $keepError = deleteRetryExecute(
        deleteRetryRunner($keepState, $keepRetry, deleteRetryOptions(0)),
        $keepRetry,
        $keepDoveadm,
    );
    $keptData = json_decode((string) $keepRetry->getData(), true);
    $keptCompleted = deleteRetryCompleted($keptData);
    deleteRetryCheck(
        "{$keepReason} keeps later delete steps pending",
        ($mailRemains
            ? $keepError instanceof ViMbAdmin_Exception
                && $keepError->getMessage() === 'maildir home still contains mail: /mail/user@example.test'
            : $keepError instanceof RuntimeException
                && $keepError->getMessage() === 'simulated home probe failure')
            && $keptCompleted === ['mailbox-delete']
            && $keepState->calls['maildir-home'] === 0
            && $keepState->calls['mailbox-row'] === 0
            && $keepState->calls['audit'] === 0,
    );

    // Once the retained home is externally emptied or the probe recovers, retry resumes here.
    $keepState->sourceHasMail = false;
    $keepState->sourceProbeError = null;
    $cleanupRetry = deleteRetryTask($keepDomain);
    $cleanupRetry->setData((string) $keepRetry->getData());
    $cleanupError = deleteRetryExecute(
        deleteRetryRunner($keepState, $cleanupRetry, deleteRetryOptions(0)),
        $cleanupRetry,
        $keepDoveadm,
    );
    $cleanupData = json_decode((string) $cleanupRetry->getData(), true);
    deleteRetryCheck(
        "{$keepReason} retry eventually cleans up without replaying mailbox delete",
        $cleanupError === null
            && deleteRetryCompleted($cleanupData)
                === ['mailbox-delete', 'maildir-home', 'mailbox-row', 'audit']
            && $keepState->calls['mailbox-delete'] === 1
            && $keepState->calls['maildir-home'] === 1
            && $keepState->calls['mailbox-row'] === 1
            && $keepState->calls['audit'] === 1,
    );
}

foreach (['absent before probe' => false, 'disappears before delete' => true] as $absence => $duringDelete) {
    $absentDomain = (new \Entities\Domain())->setDomain('example.test');
    $absentState = new DeleteRetryState($absentDomain);
    $absentState->sourceHasMail = false;
    $absentState->sourceExists = $duringDelete;
    $absentState->sourceDisappearsBeforeDelete = $duringDelete;
    $absentTask = deleteRetryTask($absentDomain);
    $absentError = deleteRetryExecute(
        deleteRetryRunner($absentState, $absentTask, deleteRetryOptions(0)),
        $absentTask,
        new DeleteRetryDoveadm($absentState),
    );
    $absentData = json_decode((string) $absentTask->getData(), true);
    deleteRetryCheck(
        "maildir {$absence} is checkpointed as already complete",
        $absentError === null
            && deleteRetryCompleted($absentData)
                === ['mailbox-delete', 'maildir-home', 'mailbox-row', 'audit']
            && $absentState->calls['maildir-home'] === ($duringDelete ? 1 : 0)
            && $absentState->calls['mailbox-row'] === 1
            && $absentState->calls['audit'] === 1,
    );
}

foreach ([
    'missing cur with populated new' => "doveadm 'fsIter' failed: No such file or directory (exit 68)",
    'non-path missing text' => "doveadm 'fsIter' failed: backend metadata doesn't exist (exit 75)",
] as $probeFailure => $probeMessage) {
    $partialDomain = (new \Entities\Domain())->setDomain('example.test');
    $partialState = new DeleteRetryState($partialDomain);
    $partialState->sourceProbeError = new ViMbAdmin_Exception($probeMessage);
    $partialTask = deleteRetryTask($partialDomain);
    $partialError = deleteRetryExecute(
        deleteRetryRunner($partialState, $partialTask, deleteRetryOptions(0)),
        $partialTask,
        new DeleteRetryDoveadm($partialState),
    );
    $partialData = json_decode((string) $partialTask->getData(), true);
    deleteRetryCheck(
        "{$probeFailure} remains retryable while the home exists",
        $partialError instanceof ViMbAdmin_Exception
            && $partialError->getMessage() === $probeMessage
            && deleteRetryCompleted($partialData) === ['mailbox-delete']
            && $partialState->calls['maildir-home'] === 0
            && $partialState->calls['mailbox-row'] === 0
            && $partialState->calls['audit'] === 0,
    );
}

foreach (['initial', 'mail-probe confirmation', 'delete confirmation'] as $listCallSite) {
    $ambiguousDomain = (new \Entities\Domain())->setDomain('example.test');
    $ambiguousState = new DeleteRetryState($ambiguousDomain);
    $ambiguousState->sourceHasMail = false;
    $expectedMessage = $listCallSite . " backend doesn't exist";
    $ambiguousListError = new ViMbAdmin_Doveadm_CommandException('fsIterDirs', $expectedMessage, 68);
    $expectedError = $ambiguousListError;
    if ($listCallSite === 'initial') {
        $ambiguousState->sourceListErrors = [$ambiguousListError];
    } elseif ($listCallSite === 'mail-probe confirmation') {
        $expectedError = new ViMbAdmin_Exception('missing child maildir');
        $ambiguousState->sourceProbeError = $expectedError;
        $ambiguousState->sourceListErrors = [null, $ambiguousListError];
    } else {
        $expectedError = new ViMbAdmin_Exception('delete filter unavailable');
        $ambiguousState->sourceDeleteError = $expectedError;
        $ambiguousState->sourceListErrors = [null, $ambiguousListError];
    }
    $ambiguousTask = deleteRetryTask($ambiguousDomain);
    $ambiguousError = deleteRetryExecute(
        deleteRetryRunner($ambiguousState, $ambiguousTask, deleteRetryOptions(0)),
        $ambiguousTask,
        new DeleteRetryDoveadm($ambiguousState),
    );
    $ambiguousData = json_decode((string) $ambiguousTask->getData(), true);
    deleteRetryCheck(
        "missing filter/backend at {$listCallSite} does not checkpoint maildir home",
        $ambiguousError === $expectedError
            && deleteRetryCompleted($ambiguousData) === ['mailbox-delete']
            && $ambiguousState->calls['mailbox-row'] === 0
            && $ambiguousState->calls['audit'] === 0,
    );
}

$auditPersistDomain = (new \Entities\Domain())->setDomain('example.test');
$auditPersistState = new DeleteRetryState($auditPersistDomain);
$auditPersistState->failAuditPersistOnce = true;
$auditPersistTask = deleteRetryTask($auditPersistDomain);
$auditPersistDoveadm = new DeleteRetryDoveadm($auditPersistState);
$auditPersistError = deleteRetryExecute(
    deleteRetryRunner($auditPersistState, $auditPersistTask, deleteRetryOptions(90)),
    $auditPersistTask,
    $auditPersistDoveadm,
);
$failedAuditData = json_decode($auditPersistState->persistedTaskData, true);
$failedAuditProgress = is_array($failedAuditData)
    ? ($failedAuditData['_queue_runner_delete'] ?? null)
    : null;
$auditPersistRetry = deleteRetryTask($auditPersistDomain);
$auditPersistRetry->setData($auditPersistState->persistedTaskData);
$auditPersistRetryError = deleteRetryExecute(
    deleteRetryRunner($auditPersistState, $auditPersistRetry, deleteRetryOptions(90)),
    $auditPersistRetry,
    $auditPersistDoveadm,
);
deleteRetryCheck(
    'audit persist failure leaves checkpoint incomplete and retry records one row',
    $auditPersistError?->getMessage() === 'simulated audit persist failure'
        && is_array($failedAuditProgress)
        && ($failedAuditProgress['completed'] ?? null)
            === ['backup', 'archive', 'mailbox-delete', 'maildir-home', 'mailbox-row']
        && $auditPersistRetryError === null
        && $auditPersistState->calls === $expectedCalls
        && $auditPersistState->uncheckpointedAuditFlushes === 0,
);

// The initial mode is part of the checkpoint. A configuration change between
// attempts cannot reinterpret instant-delete checkpoints as backup checkpoints.
$instantDomain = (new \Entities\Domain())->setDomain('example.test');
$instantState = new DeleteRetryState($instantDomain);
$instantTask = deleteRetryTask($instantDomain);
$instantDoveadm = new DeleteRetryDoveadm($instantState);
$instantError = deleteRetryExecute(
    deleteRetryRunner($instantState, $instantTask, deleteRetryOptions(0), 'mailbox-delete'),
    $instantTask,
    $instantDoveadm,
);
$instantRetry = deleteRetryTask($instantDomain);
$instantRetry->setData($instantState->persistedTaskData);
$instantRetryError = deleteRetryExecute(
    deleteRetryRunner($instantState, $instantRetry, deleteRetryOptions(90)),
    $instantRetry,
    $instantDoveadm,
);
deleteRetryCheck(
    'instant-delete retry keeps its pinned mode after configuration changes',
    $instantError?->getMessage() === 'simulated committed interruption after mailbox-delete'
        && $instantRetryError === null
        && $instantState->calls['backup'] === 0
        && $instantState->calls['archive'] === 0
        && $instantState->calls['mailbox-delete'] === 1
        && $instantState->calls['audit'] === 1
        && $instantState->uncheckpointedAuditFlushes === 0,
);

$invalidDomain = (new \Entities\Domain())->setDomain('example.test');
$invalidState = new DeleteRetryState($invalidDomain);
$invalidTask = deleteRetryTask($invalidDomain, [
    '_queue_runner_delete' => [
        'version' => 1,
        'mode' => 'backup',
        'destination' => 'maildir:/backups/example.test/user@example.test',
        'completed' => ['not-a-delete-step'],
    ],
]);
$invalidError = deleteRetryExecute(
    deleteRetryRunner($invalidState, $invalidTask, deleteRetryOptions(90)),
    $invalidTask,
    new DeleteRetryDoveadm($invalidState),
);
deleteRetryCheck(
    'invalid checkpoint data fails closed before side effects',
    $invalidError?->getMessage() === 'mailbox delete task contains invalid progress metadata'
        && array_sum($invalidState->calls) === 0,
);

echo DeleteRetryAssertions::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . DeleteRetryAssertions::$failures . " FAILED\n";
exit(DeleteRetryAssertions::$failures === 0 ? 0 : 1);
