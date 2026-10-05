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
    public function __construct()
    {
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return null;
    }
}

final class AliasCreateRepositoryFactory implements RepositoryFactory
{
    public function __construct(
        private readonly AliasCreateDomainRepository $domain,
        private readonly AliasCreateAliasRepository $alias,
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

final class AliasCreateManager extends EntityManager
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;

    public function __construct(\Entities\Domain $domain)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setRepositoryFactory(new AliasCreateRepositoryFactory(
            new AliasCreateDomainRepository($domain),
            new AliasCreateAliasRepository(),
        ));
        parent::__construct(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration), $configuration);
    }

    public function persist(object $entity): void
    {
        $this->persisted[] = $entity;
    }
    public function flush(): void
    {
        $this->flushes++;
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

/** @return array{McpController,AliasCreateManager} */
function aliasCreateFixture(string $domainName): array
{
    $domain = (new \Entities\Domain())->setDomain($domainName)->setAliasCount(0);
    $manager = new AliasCreateManager($domain);
    $session = new AliasCreateSession();
    $resources = new AliasCreateResources($manager);
    $controller = new McpController(
        new Container($resources, new Auth($session, static fn (int $id): null => null)),
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
    return [$controller, $manager];
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

[$valid, $validManager] = aliasCreateFixture('example.test');
$created = aliasCreate(['domain' => 'example.test', 'address' => 'sales', 'goto' => 'user@example.test'], $valid);
check(
    'local-part-only alias creates a valid composed address',
    $created['address'] === 'sales@example.test' && count($validManager->persisted) === 1 && $validManager->flushes === 1
);

[$invalid, $invalidManager] = aliasCreateFixture('bad/domain.test');
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
);

[$typed, $typedManager] = aliasCreateFixture('example.test');
$typeError = null;
try {
    aliasCreate(['domain' => 'example.test', 'address' => ['sales'], 'goto' => 'user@example.test'], $typed);
} catch (ViMbAdmin_Mcp_Exception $exception) {
    $typeError = $exception->getMessage();
}
check(
    'alias address type guard remains in force',
    $typeError === 'param "address" must be a string' && $typedManager->persisted === []
);
