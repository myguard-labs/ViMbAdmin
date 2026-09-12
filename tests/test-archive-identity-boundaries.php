<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../application/Repositories/Archive.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';
require_once __DIR__ . '/../library/ViMbAdmin/Service/Archive.php';
require_once __DIR__ . '/../library/ViMbAdmin/Service/QueueRunner.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\ArchiveController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ArchiveIdentitySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function __get(string $key): mixed { return $this->data[$key] ?? null; }
    public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function __isset(string $key): bool { return isset($this->data[$key]); }
    public function __unset(string $key): void { unset($this->data[$key]); }
    /** @return array<string,mixed> */
    public function values(): array { return $this->data; }
}

final class ArchiveIdentityView
{
    public int $renders = 0;
    public function __set(string $key, mixed $value): void {}
    public function render(string $script): string
    {
        $this->renders++;
        return $script;
    }
}

final class ArchiveIdentityResources
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly ArchiveIdentitySession $session,
        private readonly ArchiveIdentityView $view,
        private readonly array $options = [],
    ) {}

    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }
    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->session,
            'smarty' => $this->view,
            default => null,
        };
    }
}

final class ArchiveIdentityAdmin extends \Entities\Admin
{
    public function __construct(private readonly bool $super) {}
    public function getId(): int { return 1; }
    public function getUsername(): string { return 'admin@example.test'; }
    public function getSuper(): bool { return $this->super; }
    public function getActive(): bool { return true; }
    public function isSuper(): bool { return $this->super; }
}

final class ArchiveIdentityArchiveRepository extends \Repositories\Archive
{
    public function __construct(private readonly \Entities\Archive $archive) {}

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): object
    {
        return $this->archive;
    }

    public function find(mixed $id, \Doctrine\DBAL\LockMode|int|null $lockMode = null, ?int $lockVersion = null): object
    {
        return $this->archive;
    }
}

final class ArchiveIdentityMailboxRepository extends \Repositories\Mailbox
{
    public function __construct(private readonly ?\Entities\Mailbox $mailbox) {}

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->mailbox;
    }
}

final class ArchiveIdentityAutopruneRepository extends \Repositories\Archive
{
    /** @param list<\Entities\Archive> $archives */
    public function __construct(private readonly array $archives) {}
    /** @return list<\Entities\Archive> */
    public function findAutoprune(?\DateTime $cutoff = null)
    {
        return $this->archives;
    }
}

final class ArchiveIdentityAutopruneQuery
{
    public function setParameter(string|int $key, mixed $value): self { return $this; }
    /** @return list<array{username:string}> */
    public function getArrayResult(): array { return []; }
}

final class ArchiveIdentityAutopruneConnection
{
    /** @param list<mixed> $parameters */
    public function executeStatement(string $sql, array $parameters): int
    {
        return str_starts_with($sql, 'UPDATE setting SET value = ?') ? 1 : 0;
    }
}

final class ArchiveIdentityAutopruneEntityManager
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;
    private readonly ArchiveIdentityAutopruneConnection $connection;
    public function __construct(private readonly ArchiveIdentityAutopruneRepository $repository)
    {
        $this->connection = new ArchiveIdentityAutopruneConnection();
    }
    public function getConnection(): ArchiveIdentityAutopruneConnection { return $this->connection; }
    public function getRepository(string $class): ArchiveIdentityAutopruneRepository
    {
        if ($class !== '\\Entities\\Archive') { throw new RuntimeException('Unexpected repository'); }
        return $this->repository;
    }
    public function createQuery(string $dql): ArchiveIdentityAutopruneQuery
    {
        if (!str_contains($dql, 'LOWER(t.username) IN (:users)')) {
            throw new RuntimeException('Unexpected autoprune query');
        }
        return new ArchiveIdentityAutopruneQuery();
    }
    public function persist(object $entity): void { $this->persisted[] = $entity; }
    public function flush(): void { $this->flushes++; }
}

/** @param array<string,EntityRepository<covariant object>> $repositories */
function archiveIdentityEntityManager(array $repositories): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $repositoryFactory = new DefaultRepositoryFactory();
    $configuration->setRepositoryFactory($repositoryFactory);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'dbname' => 'unreachable',
        'user' => 'unreachable',
        'password' => 'unreachable',
        'connectTimeout' => 1,
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new EntityManager($connection, $configuration);

    $repositoryList = [];
    foreach ($repositories as $entityName => $repository) {
        $metadata = $entityManager->getClassMetadata($entityName);
        $repositoryList[$metadata->getName() . spl_object_id($entityManager)] = $repository;
    }
    (new ReflectionProperty($repositoryFactory, 'repositoryList'))
        ->setValue($repositoryFactory, $repositoryList);

    return $entityManager;
}

function archiveIdentityManage(EntityManager $entityManager, \Entities\Archive $archive, int $id): void
{
    (new ReflectionProperty($archive, 'id'))->setValue($archive, $id);
    $entityManager->getUnitOfWork()->registerManaged($archive, ['id' => $id], []);
}

final class ArchiveIdentityState
{
    public static int $failures = 0;
}

function archiveIdentityCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { ArchiveIdentityState::$failures++; }
}

/** @param array<string,mixed> $options */
function archiveIdentityContainer(
    EntityManager $entityManager,
    ArchiveIdentitySession $session,
    ArchiveIdentityView $view,
    bool $super,
    array $options = [],
): Container {
    $admin = new ArchiveIdentityAdmin($super);
    return new Container(
        new ArchiveIdentityResources($entityManager, $session, $view, $options),
        new Auth($session, static fn(int $id): object => $admin),
    );
}

/** @param array<string,mixed> $params */
function archiveIdentityMcpState(McpController $controller, array $params, string $operation): mixed
{
    return (new ReflectionMethod($controller, '_archiveState'))->invoke($controller, $params, $operation);
}

echo "== Archive identity caller boundaries ==\n";

$oldGet = $_GET;
$_GET = ['search' => ['value' => 'abc']];
foreach ([
    'list-specific override' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 2,
        'archive' => ['min_search_str' => 4],
    ]]]],
    'shared fallback' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 4,
        'archive' => [],
    ]]]],
] as $case => $options) {
    $searchController = new ArchiveController(
        archiveIdentityContainer(
            archiveIdentityEntityManager([]),
            new ArchiveIdentitySession(['identity' => ['id' => 1]]),
            new ArchiveIdentityView(),
            true,
            $options,
        ),
        new RouteMatch('archive', 'list-data', ArchiveController::class, 'listDataAction', []),
    );
    $response = $searchController->listDataAction();
    archiveIdentityCheck("archive list-data enforces {$case} search minimum argument",
        $response->status === 400
            && $response->body === 'Search must be empty or at least 4 characters');
}
$_GET = $oldGet;

// The destructive archive actions are POST-only and read their CSRF token from
// the request body (VIM-D05), so drive them as a POST from here on.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf'] = 'test-token';

// Non-super authorization requires a real domain before any mutation.
$missingDomain = (new \Entities\Archive())->setUsername('box@example.test');
$authManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($missingDomain),
]);
$authSession = new ArchiveIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$authView = new ArchiveIdentityView();
$authController = new ArchiveController(
    archiveIdentityContainer($authManager, $authSession, $authView, false),
    new RouteMatch('archive', 'toggle-autoprune', ArchiveController::class, 'toggleAutopruneAction', [
        'arid' => '7',
    ]),
);
$authError = null;
try {
    $authController->toggleAutopruneAction();
} catch (Throwable $e) {
    $authError = $e->getMessage();
}
archiveIdentityCheck('non-super authorization rejects a null archive domain',
    $authError === 'Archive domain cannot be null.');
archiveIdentityCheck('authorization identity failure occurs before mutation or output',
    $missingDomain->getAutoprune() === false
        && $authManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $authManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $authView->renders === 0
        && $authSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

// A super-admin delete does not need a domain and remains operational.
$optionalDomainDelete = (new \Entities\Archive())->setUsername('box@example.test');
$deleteManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($optionalDomainDelete),
]);
archiveIdentityManage($deleteManager, $optionalDomainDelete, 7);
$deleteSession = new ArchiveIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$deleteView = new ArchiveIdentityView();
$deleteController = new ArchiveController(
    archiveIdentityContainer($deleteManager, $deleteSession, $deleteView, true),
    new RouteMatch('archive', 'delete', ArchiveController::class, 'deleteAction', [
        'arid' => '7',
    ]),
);
$deleteError = null;
try {
    $deleteController->deleteAction();
} catch (Throwable $e) {
    $deleteError = $e;
}
archiveIdentityCheck('super-admin delete preserves the optional-domain flow to persistence',
    $deleteError instanceof Throwable
        && $deleteError->getMessage() !== 'Archive domain cannot be null.'
        && $optionalDomainDelete->getDomain() === null
        && in_array($optionalDomainDelete, $deleteManager->getUnitOfWork()->getScheduledEntityDeletions(), true));

// Restore needs a domain only when it must recreate the mailbox.
$restoreArchive = (new \Entities\Archive())
    ->setUsername('box@example.test')
    ->setStatus(\Entities\Archive::STATUS_ARCHIVED)
    ->setData('{"mailbox":{"username":"box@example.test"}}');
$restoreManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($restoreArchive),
    'Entities\\Mailbox' => new ArchiveIdentityMailboxRepository(null),
]);
$restoreSession = new ArchiveIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$restoreController = new ArchiveController(
    archiveIdentityContainer($restoreManager, $restoreSession, new ArchiveIdentityView(), true),
    new RouteMatch('archive', 'restore', ArchiveController::class, 'restoreAction', [
        'arid' => '7',
    ]),
);
$restoreError = null;
try {
    $restoreController->restoreAction();
} catch (Throwable $e) {
    $restoreError = $e->getMessage();
}
archiveIdentityCheck('mailbox recreation rejects a null archive domain',
    $restoreError === 'Archive domain cannot be null.');
archiveIdentityCheck('restore domain failure precedes persist, flush, remove and doveadm',
    $restoreManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $restoreManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$nativeSnapshotDomain = (new \Entities\Domain())->setDomain('example.test')->setMailboxCount(1);
$nativeSnapshotArchive = (new \Entities\Archive())
    ->setUsername('box@example.test')
    ->setDomain($nativeSnapshotDomain)
    ->setStatus(\Entities\Archive::STATUS_ARCHIVED)
    ->setData(json_encode([
        'mailbox' => [
            'username' => 'other@example.test', 'local_part' => 'other',
            'name' => null, 'password' => '{CRYPT}hash', 'quota' => 1024, 'active' => true,
        ],
    ], JSON_THROW_ON_ERROR));
$nativeSnapshotManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($nativeSnapshotArchive),
    'Entities\\Mailbox' => new ArchiveIdentityMailboxRepository(null),
]);
$nativeSnapshotSession = new ArchiveIdentitySession(['identity' => ['id' => 1], 'csrfToken' => 'test-token']);
$nativeSnapshotController = new ArchiveController(
    archiveIdentityContainer($nativeSnapshotManager, $nativeSnapshotSession, new ArchiveIdentityView(), true),
    new RouteMatch('archive', 'restore', ArchiveController::class, 'restoreAction', [
        'arid' => '7',
    ]),
);
$nativeSnapshotResponse = $nativeSnapshotController->restoreAction();
archiveIdentityCheck('native restore rejects a mismatched snapshot identity before persistence',
    $nativeSnapshotResponse->status === 302
        && $nativeSnapshotDomain->getMailboxCount() === 1
        && $nativeSnapshotManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $nativeSnapshotManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

// MCP delete intentionally authorizes only when a domain is attached.
$mcpArchive = (new \Entities\Archive())->setUsername('box@example.test');
$mcpManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($mcpArchive),
]);
archiveIdentityManage($mcpManager, $mcpArchive, 8);
$mcpSession = new ArchiveIdentitySession();
$mcpController = new McpController(
    new Container(
        new ArchiveIdentityResources($mcpManager, $mcpSession, new ArchiveIdentityView(), [
            'doveadm' => ['http' => ['url' => 'http://doveadm.invalid/v1', 'api_key' => 'test']],
        ]),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$unknownOperationError = null;
try {
    archiveIdentityMcpState($mcpController, ['username' => 'box@example.test'], 'unknown');
} catch (ViMbAdmin_Mcp_Exception $e) {
    $unknownOperationError = $e->getMessage();
}
archiveIdentityCheck('MCP archive rejects unknown operation before persistence',
    $unknownOperationError === 'unknown archive operation'
    && $mcpManager->getUnitOfWork()->getScheduledEntityDeletions() === []);
$mcpError = null;
try {
    archiveIdentityMcpState(
        $mcpController,
        ['username' => 'box@example.test'],
        'delete',
    );
} catch (Throwable $e) {
    $mcpError = $e;
}
archiveIdentityCheck('MCP delete preserves the optional-domain flow to persistence',
    $mcpError instanceof Throwable
        && $mcpError->getMessage() !== 'Archive domain cannot be null.'
        && $mcpArchive->getDomain() === null
        && in_array($mcpArchive, $mcpManager->getUnitOfWork()->getScheduledEntityDeletions(), true));

$snapshotDomain = (new \Entities\Domain())->setDomain('example.test')->setMailboxCount(1);
$mismatchedSnapshotArchive = (new \Entities\Archive())
    ->setUsername('box@example.test')
    ->setDomain($snapshotDomain)
    ->setData(json_encode([
        'mailbox' => [
            'username' => 'other@example.test',
            'local_part' => 'other',
            'name' => 'Other mailbox',
            'password' => '{CRYPT}hash',
            'quota' => 1024,
            'active' => true,
        ],
    ], JSON_THROW_ON_ERROR));
$mismatchedSnapshotManager = archiveIdentityEntityManager([
    'Entities\\Archive' => new ArchiveIdentityArchiveRepository($mismatchedSnapshotArchive),
    'Entities\\Mailbox' => new ArchiveIdentityMailboxRepository(null),
]);
$mismatchedSnapshotSession = new ArchiveIdentitySession();
$mismatchedSnapshotMcp = new McpController(
    new Container(
        new ArchiveIdentityResources($mismatchedSnapshotManager, $mismatchedSnapshotSession, new ArchiveIdentityView(), [
            'doveadm' => ['http' => ['url' => 'http://doveadm.invalid/v1', 'api_key' => 'test']],
        ]),
        new Auth($mismatchedSnapshotSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$mismatchedSnapshotError = null;
try {
    archiveIdentityMcpState(
        $mismatchedSnapshotMcp,
        ['username' => 'box@example.test'],
        'restore',
    );
} catch (ViMbAdmin_Mcp_Exception $e) {
    $mismatchedSnapshotError = $e->getMessage();
}
archiveIdentityCheck('MCP restore rejects a snapshot for another mailbox before mutation',
    $mismatchedSnapshotError === 'archive mailbox snapshot identity mismatch'
        && $snapshotDomain->getMailboxCount() === 1
        && $mismatchedSnapshotManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $mismatchedSnapshotManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

// QueueRunner deliberately copies a nullable Archive domain into a nullable task.
$malformedQueueArchive = new \Entities\Archive();
$validQueueArchive = (new \Entities\Archive())->setUsername('valid@example.test');
$queueRunner = (new ReflectionClass(ViMbAdmin_Service_QueueRunner::class))
    ->newInstanceWithoutConstructor();
$queueCandidates = [];
$queueIsolationError = null;
try {
    $candidateIterator = (new ReflectionMethod($queueRunner, 'initializedAutopruneArchives'))
        ->invoke($queueRunner, [$malformedQueueArchive, $validQueueArchive]);
    if (!$candidateIterator instanceof Traversable) {
        throw new RuntimeException('QueueRunner archive candidates are not iterable.');
    }
    foreach ($candidateIterator as $candidate) {
        if (!is_array($candidate)
            || count($candidate) !== 2
            || !$candidate[0] instanceof \Entities\Archive
            || !is_string($candidate[1])) {
            throw new RuntimeException('QueueRunner archive candidate has an invalid shape.');
        }
        $queueCandidates[] = [$candidate[0], $candidate[1]];
    }
} catch (Throwable $e) {
    $queueIsolationError = $e->getMessage();
}
archiveIdentityCheck('QueueRunner isolates a malformed archive before considering the next',
    $queueIsolationError === null
        && $queueCandidates === [[$validQueueArchive, 'valid@example.test']]);

$nullableDomainArchive = (new \Entities\Archive())
    ->setUsername('box@example.test')
    ->setMaildirFile('/backups/example.test/box');
$mappedDomain = (new \Entities\Domain())->setDomain('mapped.example.test');
$mappedDomainArchive = (new \Entities\Archive())
    ->setUsername('mapped@mapped.example.test')
    ->setMaildirFile('/backups/mapped.example.test/mapped')
    ->setDomain($mappedDomain);
$autopruneManager = new ArchiveIdentityAutopruneEntityManager(
    new ArchiveIdentityAutopruneRepository([$nullableDomainArchive, $mappedDomainArchive]),
);
$autopruneRunner = (new ReflectionClass(ViMbAdmin_Service_QueueRunner::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($autopruneRunner, 'em'))->setValue($autopruneRunner, $autopruneManager);
(new ReflectionProperty($autopruneRunner, 'options'))->setValue($autopruneRunner, []);
(new ReflectionMethod($autopruneRunner, 'autopruneSweep'))->invoke($autopruneRunner);
$queuedPrune = $autopruneManager->persisted[0] ?? null;
$mappedPrune = $autopruneManager->persisted[1] ?? null;
archiveIdentityCheck('QueueRunner retains its nullable archive-domain association',
    $queuedPrune instanceof \Entities\MailboxTask
        && $queuedPrune->requiredUsername() === 'box@example.test'
        && $queuedPrune->getDomain() === null
        && $mappedPrune instanceof \Entities\MailboxTask
        && $mappedPrune->getDomain() === $mappedDomain
        && $autopruneManager->flushes === 1);

echo ArchiveIdentityState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . ArchiveIdentityState::$failures . " FAILED\n";
exit(ArchiveIdentityState::$failures === 0 ? 0 : 1);
