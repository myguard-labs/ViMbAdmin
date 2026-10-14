<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Alias.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;

final class AliasCreateSession implements SessionStorage
{
    public function has(string $key): bool
    {
        return false;
    }
    public function get(string $key): mixed
    {
        return null;
    }
    public function set(string $key, mixed $value): void
    {
    }
    public function remove(string $key): void
    {
    }
}

/** @extends EntityRepository<\Entities\Domain> */
final class AliasCreateDomainRepository extends EntityRepository
{
    public function __construct(private readonly \Entities\Domain $domain)
    {
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): object
    {
        return $this->domain;
    }
}

/** @extends EntityRepository<\Entities\Alias> */
final class AliasCreateAliasRepository extends EntityRepository
{
    public function __construct(private ?\Entities\Alias $alias = null)
    {
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->alias;
    }

    public function setAlias(?\Entities\Alias $alias): void
    {
        $this->alias = $alias;
    }
}

final class AliasCreateRepositoryFactory implements RepositoryFactory
{
    public function __construct(
        private readonly AliasCreateDomainRepository $domain,
        public readonly AliasCreateAliasRepository $alias,
    ) {
    }

    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        $entityName = ltrim($entityName, '\\');
        if ($entityName === \Entities\Domain::class) {
            return $this->domain;
        }
        if ($entityName === \Entities\Alias::class) {
            return $this->alias;
        }
        throw new LogicException('Unexpected repository: ' . $entityName);
    }
}

/** @SuppressWarnings("PHPMD.MissingImport") Fixture diagnostics deliberately use global LogicException. */
final class AliasCreateConnection extends \Doctrine\DBAL\Connection
{
    public int $transactionsStarted = 0;
    public int $transactionsCommitted = 0;
    public int $connectAttempts = 0;
    private bool $transactionActive = false;

    protected function connect(): \Doctrine\DBAL\Driver\Connection
    {
        $this->connectAttempts++;
        throw new LogicException('Alias-create unit fixture attempted a real database connection');
    }

    public function beginTransaction(): void
    {
        if ($this->transactionActive) {
            throw new LogicException('Alias-create unit fixture does not support nested transactions');
        }
        $this->transactionActive = true;
        $this->transactionsStarted++;
    }

    public function commit(): void
    {
        if (!$this->transactionActive) {
            throw new LogicException('Alias-create unit fixture has no active transaction to commit');
        }
        $this->transactionActive = false;
        $this->transactionsCommitted++;
    }

    public function rollBack(): void
    {
        if (!$this->transactionActive) {
            throw new LogicException('Alias-create unit fixture has no active transaction to roll back');
        }
        $this->transactionActive = false;
    }

    public function isTransactionActive(): bool
    {
        return $this->transactionActive;
    }
}

final class AliasCreateManager extends EntityManager
{
    /** @var list<object> */
    public array $persisted = [];
    /** @var list<object> */
    public array $removed = [];
    public int $flushes = 0;
    public int $refreshes = 0;
    public ?object $lastRefreshed = null;
    public \Doctrine\DBAL\LockMode|int|null $lastRefreshLock = null;
    public readonly AliasCreateRepositoryFactory $repositories;

    public function __construct(\Entities\Domain $domain)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
            $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
        }
        $this->repositories = new AliasCreateRepositoryFactory(
            new AliasCreateDomainRepository($domain),
            new AliasCreateAliasRepository(),
        );
        $configuration->setRepositoryFactory($this->repositories);
        parent::__construct(DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'serverVersion' => '8.0',
            'wrapperClass' => AliasCreateConnection::class,
        ], $configuration), $configuration);
    }

    public function persist(object $entity): void
    {
        $this->persisted[] = $entity;
    }
    public function flush(): void
    {
        $this->flushes++;
    }
    public function remove(object $entity): void
    {
        $this->removed[] = $entity;
    }
    public function refresh(object $object, \Doctrine\DBAL\LockMode|int|null $lockMode = \Doctrine\DBAL\LockMode::NONE): void
    {
        $this->refreshes++;
        $this->lastRefreshed = $object;
        $this->lastRefreshLock = $lockMode;
    }
}

final class AliasCreateResources
{
    public function __construct(private readonly AliasCreateManager $manager)
    {
    }

    public function getResource(string $name): object
    {
        return $this->manager;
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
}

/** @return array{McpController,AliasCreateManager,Entities\Domain} */
function aliasCreateFixture(string $domainName, int $aliasCount = 0, int $maxAliases = 0): array
{
    $domain = (new \Entities\Domain())
        ->setDomain($domainName)
        ->setAliasCount($aliasCount)
        ->setMaxAliases($maxAliases);
    $manager = new AliasCreateManager($domain);
    $session = new AliasCreateSession();
    $resources = new AliasCreateResources($manager);
    $controller = new McpController(
        new Container($resources, new Auth($session, static fn (int $id): null => null)),
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
    return [$controller, $manager, $domain];
}

/** @return array<string,mixed> */
function aliasDelete(string $address, McpController $controller): array
{
    $result = (new ReflectionMethod($controller, '_aliasDelete'))->invoke($controller, ['address' => $address]);
    if (!is_array($result)) {
        throw new LogicException('MCP alias delete returned a non-array result');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new LogicException('MCP alias delete returned a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

/** @param array<string,mixed> $params
 *  @return array<string,mixed> */
function aliasCreate(array $params, McpController $controller): array
{
    $result = (new ReflectionMethod($controller, '_aliasCreate'))->invoke($controller, $params);
    if (!is_array($result)) {
        throw new LogicException('MCP alias create returned a non-array result');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new LogicException('MCP alias create returned a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

function check(string $name, bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "ok: {$name}\n";
}

[$valid, $validManager, $validDomain] = aliasCreateFixture('example.test', 1, 2);
$created = aliasCreate(['domain' => 'example.test', 'address' => 'sales', 'goto' => 'user@example.test'], $valid);
check(
    'forwarding alias at available capacity increments and persists',
    $created['address'] === 'sales@example.test' && count($validManager->persisted) === 1
        && $validManager->flushes === 1 && $validDomain->getAliasCount() === 2
);

[$unlimited, $unlimitedManager, $unlimitedDomain] = aliasCreateFixture('example.test', 37, 0);
aliasCreate(['domain' => 'example.test', 'address' => 'unlimited', 'goto' => 'user@example.test'], $unlimited);
check(
    'zero maximum keeps forwarding aliases unlimited and increments the count',
    count($unlimitedManager->persisted) === 1 && $unlimitedManager->flushes === 1
        && $unlimitedDomain->getAliasCount() === 38
);

[$full, $fullManager, $fullDomain] = aliasCreateFixture('example.test', 2, 2);
$fullError = null;
try {
    aliasCreate(['domain' => 'example.test', 'address' => 'sales', 'goto' => 'user@example.test'], $full);
} catch (ViMbAdmin_Mcp_DomainException $exception) {
    $fullError = $exception->getMessage();
}
check(
    'exact alias capacity rejects another counting alias without side effects',
    $fullError === 'You have used all of your allocated aliases.'
        && $fullManager->persisted === [] && $fullManager->flushes === 0
        && $fullDomain->getAliasCount() === 2
);

[$self, $selfManager, $selfDomain] = aliasCreateFixture('example.test', 2, 2);
aliasCreate(
    ['domain' => 'example.test', 'address' => 'self@example.test', 'goto' => 'self@example.test'],
    $self,
);
check(
    'self-alias creation at capacity persists without consuming capacity',
    count($selfManager->persisted) === 1 && $selfManager->flushes === 1
        && $selfDomain->getAliasCount() === 2
);

$selfAlias = $selfManager->persisted[0];
if (!$selfAlias instanceof \Entities\Alias) {
    throw new LogicException('Expected persisted self-alias');
}
$selfManager->repositories->alias->setAlias($selfAlias);
aliasDelete('self@example.test', $self);
$selfConnection = $selfManager->getConnection();
check(
    'self-alias deletion uses the socketless transactional fixture without decrementing the persisted count',
    $selfManager->removed === [$selfAlias] && $selfManager->flushes === 2
        && $selfDomain->getAliasCount() === 2
        && $selfManager->refreshes === 1 && $selfManager->lastRefreshed === $selfAlias
        && $selfManager->lastRefreshLock === \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE
        && $selfConnection instanceof AliasCreateConnection
        && $selfConnection->transactionsStarted === 1 && $selfConnection->transactionsCommitted === 1
        && !$selfConnection->isTransactionActive() && $selfConnection->connectAttempts === 0 && !$selfConnection->isConnected()
);

[$deleting, $deletingManager, $deletingDomain] = aliasCreateFixture('example.test', 1, 2);
$forwardingAlias = (new \Entities\Alias())
    ->setAddress('sales@example.test')
    ->setGoto('user@example.test')
    ->setDomain($deletingDomain);
$deletingManager->repositories->alias->setAlias($forwardingAlias);
aliasDelete('sales@example.test', $deleting);
$deletingConnection = $deletingManager->getConnection();
check(
    'forwarding alias deletion uses the socketless transaction and decrements the persisted count',
    $deletingManager->removed === [$forwardingAlias] && $deletingManager->flushes === 1
        && $deletingDomain->getAliasCount() === 0
        && $deletingManager->refreshes === 1 && $deletingManager->lastRefreshed === $forwardingAlias
        && $deletingManager->lastRefreshLock === \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE
        && $deletingConnection instanceof AliasCreateConnection
        && $deletingConnection->transactionsStarted === 1 && $deletingConnection->transactionsCommitted === 1
        && !$deletingConnection->isTransactionActive() && $deletingConnection->connectAttempts === 0 && !$deletingConnection->isConnected()
);

[$invalid, $invalidManager, $invalidDomain] = aliasCreateFixture('bad/domain.test', 1, 2);
$error = null;
try {
    aliasCreate(['domain' => 'bad/domain.test', 'address' => 'sales', 'goto' => 'user@example.test'], $invalid);
} catch (ViMbAdmin_Mcp_Exception $exception) {
    $error = $exception->getMessage();
}
check(
    'invalid composed alias is rejected before persistence',
    $error !== null && str_contains($error, 'invalid address domain')
    && $invalidManager->persisted === [] && $invalidManager->flushes === 0
    && $invalidDomain->getAliasCount() === 1
);

[$typed, $typedManager, $typedDomain] = aliasCreateFixture('example.test', 1, 2);
$typeError = null;
try {
    aliasCreate(['domain' => 'example.test', 'address' => ['sales'], 'goto' => 'user@example.test'], $typed);
} catch (ViMbAdmin_Mcp_Exception $exception) {
    $typeError = $exception->getMessage();
}
check(
    'alias address type guard remains in force',
    $typeError === 'param "address" must be a string' && $typedManager->persisted === []
        && $typedDomain->getAliasCount() === 1
);

echo "ALL PASSED (8 checks)\n";
