<?php

/**
 * Regression smoke test: the native Doctrine EM factory (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * Builds an entity manager from a synthetic options array the way the native
 * bootstrap will, against the REAL shipped XML mapping dir, and asserts the
 * factory reproduces the OSS_Resource_Doctrine2 wiring: an EntityManager whose
 * Configuration carries the XML metadata driver, the cache, and the proxy
 * settings. The EM is connection-lazy so this needs no database — the same
 * approach test-cache-bootstrap.php uses for the ORM call shape.
 *
 * Runs in the cache-wiring CI job (vendor + doctrine/orm present).
 * Exit 0 = all passed, non-zero = a failure.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

require __DIR__ . '/../src/Kernel/Doctrine/EntityManagerFactory.php';

use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\QueryBuilder;
use Entities\Admin as AdminEntity;
use Entities\DirectoryEntry as DirectoryEntryEntity;
use Entities\Domain as DomainEntity;
use Entities\Log as LogEntity;
use Entities\Mailbox as MailboxEntity;
use Entities\MailboxTask as MailboxTaskEntity;
use Entities\McpToken as McpTokenEntity;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionMethod as CoreReflectionMethod;
use Repositories\DirectoryEntry as DirectoryEntryRepository;
use Repositories\Log as LogRepository;
use Repositories\Mailbox as MailboxRepository;
use Repositories\MailboxTask as MailboxTaskRepository;
use Repositories\McpToken as McpTokenRepository;
use UnexpectedValueException as QueryProbeFailure;

$appPath = realpath(__DIR__ . '/../application');

$options = [
    'resources' => [
        'doctrine2' => [
            'connection' => [
                // pdo_sqlite :memory: — never connected (lazy), just a valid driver.
                'options' => [
                    'driver'        => 'pdo_sqlite',
                    'memory'        => true,
                    'driverOptions' => [PDO::ATTR_TIMEOUT => 1],
                ],
            ],
            'proxies_path'           => $appPath . '/Proxies',
            'proxies_namespace'      => 'Proxies',
            'models_path'            => $appPath,
            'models_namespace'       => 'Entities',
            'repositories_path'      => $appPath,
            'repositories_namespace' => 'Repositories',
            'autogen_proxies'        => '0',
        ],
        'doctrine2cache' => [
            'type'      => 'ArrayCache',
            'namespace' => 'ViMbAdmin3',
        ],
    ],
];

final class TestKernelEmFactoryHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelEmFactoryHarnessState::$count;
function emCheck(string $label, callable $fn): void
{

    try {
        $fn();
        echo "OK   $label\n";
    } catch (\Throwable $e) {
        TestKernelEmFactoryHarnessState::$count++;
        printf("FAIL %s :: %s: %s\n", $label, get_class($e), $e->getMessage());
    }
}

function requireEntityManager(mixed $value): EntityManagerInterface
{
    if (!$value instanceof EntityManagerInterface) {
        throw new QueryProbeFailure('factory did not return an EntityManagerInterface');
    }

    return $value;
}

// Register the Entities/Repositories autoloaders up front: repository probes
// below extend the real classes, and metadata loading reflects their entities.
EntityManagerFactory::registerEntityAutoloaders($options);

function checkDirectoryEntryRepositoryContract(mixed $entityManager): void
{


    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL DirectoryEntry metadata resolves its entity-specific repository\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }
    $metadata = $entityManager->getClassMetadata(DirectoryEntryEntity::class);
    $repository = $entityManager->getRepository(DirectoryEntryEntity::class);
    $ok = $metadata->customRepositoryClassName === DirectoryEntryRepository::class
        && $repository instanceof DirectoryEntryRepository
        && $repository->getClassName() === DirectoryEntryEntity::class;
    echo ($ok ? 'OK   ' : 'FAIL ')
        . "DirectoryEntry metadata resolves its entity-specific repository\n";
    if (!$ok) {
        TestKernelEmFactoryHarnessState::$count++;
    }
}

/** @return array<int|string,mixed> */
function logQueryParameters(QueryBuilder $query): array
{
    $parameters = [];
    foreach ($query->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter->getValue();
    }
    return $parameters;
}

/** @param list<mixed> $arguments */
function invokePrivateRepositoryMethod(object $repository, string $method, array $arguments): mixed
{
    return (new CoreReflectionMethod($repository, $method))->invokeArgs($repository, $arguments);
}

/** @param list<mixed> $arguments */
function invokeRepositoryQuery(object $repository, string $method, array $arguments): QueryBuilder
{
    $query = invokePrivateRepositoryMethod($repository, $method, $arguments);
    if (!$query instanceof QueryBuilder) {
        throw new QueryProbeFailure('Repository query probe did not return a QueryBuilder');
    }
    return $query;
}

function recordRepositoryCheck(string $label, bool $ok): void
{

    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        TestKernelEmFactoryHarnessState::$count++;
    }
}

function logMetadataContract(EntityManagerInterface $entityManager, LogRepository $repository): bool
{
    $metadata = $entityManager->getClassMetadata(LogEntity::class);
    return $metadata->customRepositoryClassName === LogRepository::class
        && $repository->getClassName() === LogEntity::class;
}

function logListHydrationContract(string $dql): bool
{
    return str_contains($dql, 'l.id as id')
        && str_contains($dql, 'l.timestamp as timestamp')
        && str_contains($dql, 'a.username as admin')
        && str_contains($dql, 'd.domain as domain');
}

/** @param array<int|string,mixed> $parameters */
function logListScopeContract(string $dql, array $parameters, AdminEntity $admin, DomainEntity $domain): bool
{
    return str_contains($dql, 'JOIN d.Admins d2a')
        && str_contains($dql, 'd2a = ?1')
        && str_contains($dql, 'l.Domain = ?2')
        && ($parameters[1] ?? null) === $admin
        && ($parameters[2] ?? null) === $domain;
}

function logCountContract(QueryBuilder $query): bool
{
    $dql = $query->getDQL();
    return str_contains($dql, 'COUNT(DISTINCT l.id)')
        && str_contains($dql, 'l.action LIKE :s')
        && str_contains($dql, 'a.username LIKE :s')
        && str_contains($dql, 'd.domain LIKE :s')
        && !str_contains($dql, 'l.data LIKE :s')
        && (logQueryParameters($query)['s'] ?? null) === 'mail%';
}

function logSelectedPageContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'ORDER BY l.action ASC')
        && $query->getFirstResult() === 0
        && $query->getMaxResults() === 1
        && (logQueryParameters($query)['s'] ?? null) === '50\\%\\_\\\\off%';
}

/**
 * The contains toggle keeps the same escaping but restores the leading wildcard,
 * so a regression that ignores $contains shows up as a changed bound value here
 * rather than as a silently anchored search.
 */
function logContainsPageContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'l.action LIKE :s')
        && (logQueryParameters($query)['s'] ?? null) === '%50\\%\\_\\\\off%';
}

function logFallbackPageContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'ORDER BY l.timestamp DESC')
        && !str_contains($query->getDQL(), 'LIKE :s')
        && $query->getFirstResult() === 4
        && $query->getMaxResults() === 9;
}

function checkLogRepositoryQueryContract(mixed $entityManager): void
{


    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL Log repository query contract has an entity manager\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $admin = new AdminEntity();
    $domain = new DomainEntity();
    $repository = $entityManager->getRepository(LogEntity::class);
    if (!$repository instanceof LogRepository) {
        echo "FAIL Log metadata resolves its entity-specific repository\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $listQuery = invokeRepositoryQuery($repository, 'logListQuery', [$admin, $domain]);
    $pageQuery = invokeRepositoryQuery($repository, 'pagedLogRowsQuery', [$admin, $domain, '50%_\\off', false, 'action', 'ASC', -5, 0]);
    $containsQuery = invokeRepositoryQuery($repository, 'pagedLogRowsQuery', [$admin, $domain, '50%_\\off', true, 'action', 'ASC', -5, 0]);
    $fallbackQuery = invokeRepositoryQuery($repository, 'pagedLogRowsQuery', [null, null, '', false, 'unsupported', 'sideways', 4, 9]);
    $countQuery = invokeRepositoryQuery($repository, 'logCountQuery', [$admin, $domain, 'mail', false]);

    recordRepositoryCheck('Log metadata resolves its entity-specific repository', logMetadataContract($entityManager, $repository));
    recordRepositoryCheck('log list retains all array-hydration fields', logListHydrationContract($listQuery->getDQL()));
    recordRepositoryCheck('log list preserves admin/domain joins and positional parameters', logListScopeContract($listQuery->getDQL(), logQueryParameters($listQuery), $admin, $domain));
    recordRepositoryCheck('paged logs preserve search escaping and scoped count semantics', logCountContract($countQuery));
    recordRepositoryCheck('paged logs preserve selected sorting and clamp pagination bounds', logSelectedPageContract($pageQuery));
    recordRepositoryCheck('paged logs restore the leading wildcard for a contains search', logContainsPageContract($containsQuery));
    recordRepositoryCheck('paged logs fall back to timestamp descending without a search predicate', logFallbackPageContract($fallbackQuery));
}

/** @param array<int|string,mixed> $parameters */
function mailboxListQueryContract(string $dql, array $parameters, AdminEntity $admin, DomainEntity $domain): bool
{
    return str_contains($dql, 'm.id as id')
        && str_contains($dql, 'm.delete_pending')
        && str_contains($dql, 'm.delete_pending = FALSE')
        && str_contains($dql, 'JOIN d.Admins d2a')
        && str_contains($dql, 'd2a = ?1')
        && str_contains($dql, 'm.Domain = ?2')
        && ($parameters[1] ?? null) === $admin
        && ($parameters[2] ?? null) === $domain;
}

function mailboxUnscopedListContract(QueryBuilder $query): bool
{
    return !str_contains($query->getDQL(), 'd.Admins')
        && str_contains($query->getDQL(), 'm.delete_pending = FALSE')
        && count($query->getParameters()) === 0;
}

/** @param array<int|string,mixed> $parameters */
function mailboxUsernameQueryContract(string $dql, array $parameters, AdminEntity $admin, DomainEntity $domain): bool
{
    return str_contains($dql, 'm.id as id')
        && str_contains($dql, 'm.username as username')
        && str_contains($dql, 'd2a.Admin = ?1')
        && str_contains($dql, 'm.Domain = ?2')
        && ($parameters[1] ?? null) === $admin
        && ($parameters[2] ?? null) === $domain;
}

function checkMailboxRepositoryQueryContract(mixed $entityManager): void
{


    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL Mailbox repository query contract has an entity manager\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $repository = $entityManager->getRepository(MailboxEntity::class);
    if (!$repository instanceof MailboxRepository) {
        echo "FAIL Mailbox metadata resolves its entity-specific repository\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $admin = new AdminEntity();
    $admin->setSuper(false);
    $domain = new DomainEntity();
    $listQuery = invokeRepositoryQuery($repository, 'mailboxListQuery', [$admin, $domain]);
    $usernameQuery = invokeRepositoryQuery($repository, 'usernameListQuery', [$admin, $domain]);

    $superAdmin = new AdminEntity();
    $superAdmin->setSuper(true);
    $unscopedQuery = invokeRepositoryQuery($repository, 'mailboxListQuery', [$superAdmin, null]);
    $indexedRows = invokePrivateRepositoryMethod($repository, 'indexUsernameRows', [[
        ['id' => 7, 'username' => 'one@example.test'],
        ['id' => 11, 'username' => 'two@example.test'],
        ['id' => 7, 'username' => 'replacement@example.test'],
        ['id' => '9223372036854775808', 'username' => 'big@example.test'],
    ]]);
    $emptyRows = invokePrivateRepositoryMethod($repository, '_mergeQuotaUsage', [[]]);

    recordRepositoryCheck(
        'mailbox list retains hydration, deletion and scoped-filter contracts',
        mailboxListQueryContract($listQuery->getDQL(), logQueryParameters($listQuery), $admin, $domain),
    );
    recordRepositoryCheck('super-admin mailbox list omits ownership and domain filters', mailboxUnscopedListContract($unscopedQuery));
    recordRepositoryCheck(
        'mailbox username list retains selected fields and scoped parameters',
        mailboxUsernameQueryContract($usernameQuery->getDQL(), logQueryParameters($usernameQuery), $admin, $domain),
    );
    recordRepositoryCheck(
        'mailbox username rows preserve bigint keys and last duplicate wins',
        $indexedRows === [
            7 => 'replacement@example.test', 11 => 'two@example.test',
            '9223372036854775808' => 'big@example.test',
        ],
    );
    recordRepositoryCheck('empty mailbox hydration avoids quota queries', $emptyRows === []);
}

function mailboxTaskPendingQueryContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 't.status = :s')
        && str_contains($query->getDQL(), 'ORDER BY t.priority DESC, t.id ASC')
        && (logQueryParameters($query)['s'] ?? null) === MailboxTaskEntity::STATUS_PENDING
        && $query->getMaxResults() === 3;
}

function checkMailboxTaskRepositoryContract(mixed $entityManager): void
{


    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL MailboxTask repository contract has an entity manager\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $metadata = $entityManager->getClassMetadata(MailboxTaskEntity::class);
    $repository = $entityManager->getRepository(MailboxTaskEntity::class);
    if (!$repository instanceof MailboxTaskRepository) {
        echo "FAIL MailboxTask metadata resolves its entity-specific repository\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    recordRepositoryCheck(
        'MailboxTask metadata resolves its entity-specific repository',
        $metadata->customRepositoryClassName === MailboxTaskRepository::class
            && $repository->getClassName() === MailboxTaskEntity::class,
    );
    recordRepositoryCheck(
        'pending mailbox tasks retain status, priority, age and limit semantics',
        mailboxTaskPendingQueryContract(invokeRepositoryQuery($repository, 'pendingQuery', [3])),
    );
}

final class McpTokenLookupRepositoryProbe extends McpTokenRepository
{
    /** @var array<string,mixed>|null */
    public ?array $criteria = null;
    /** @var array<string,string>|null */
    public ?array $orderBy = null;

    public function __construct(private ?McpTokenEntity $result)
    {
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $this->criteria = $criteria;
        $this->orderBy = $orderBy;
        return $this->result;
    }
}

function checkMcpTokenRepositoryContract(mixed $entityManager): void
{


    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL McpToken repository contract has an entity manager\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    $metadata = $entityManager->getClassMetadata(McpTokenEntity::class);
    $repository = $entityManager->getRepository(McpTokenEntity::class);
    if (!$repository instanceof McpTokenRepository) {
        echo "FAIL McpToken metadata resolves its entity-specific repository\n";
        TestKernelEmFactoryHarnessState::$count++;
        return;
    }

    recordRepositoryCheck(
        'McpToken metadata resolves its entity-specific repository',
        $metadata->customRepositoryClassName === McpTokenRepository::class
            && $repository->getClassName() === McpTokenEntity::class,
    );

    $token = new McpTokenEntity();
    $probe = new \McpTokenLookupRepositoryProbe($token);
    recordRepositoryCheck(
        'token hash lookup retains the token_hash criterion and entity result',
        $probe->findByHash('abc123') === $token
            && $probe->criteria === ['token_hash' => 'abc123'],
    );
    recordRepositoryCheck(
        'token name lookup retains the name criterion and entity result',
        $probe->findByName('deploy') === $token
            && $probe->criteria === ['name' => 'deploy'],
    );

    $missing = new \McpTokenLookupRepositoryProbe(null);
    recordRepositoryCheck(
        'unknown token lookup remains nullable',
        $missing->findByHash('missing') === null
            && $missing->criteria === ['token_hash' => 'missing'],
    );
}

$em = null;

// The attribute metadata driver needs no extra extension (reflection only), so
// these asserts always run.
emCheck('factory builds an EntityManager', function () use ($options, &$em) {
    $em = EntityManagerFactory::create($options);
    $manager = requireEntityManager($em);
    if ($manager->getConnection()->isConnected()) {
        throw new RuntimeException('factory opened the database connection eagerly');
    }
});

emCheck('malformed nested connection and cache maps fail before construction', function () use ($options) {
    $badConnection = $options;
    $badConnection['resources']['doctrine2']['connection']['options'] = ['driver' => ['pdo_sqlite']];
    try {
        EntityManagerFactory::create($badConnection);
    } catch (LogicException $e) {
        if ($e->getMessage() !== 'resources.doctrine2.connection.options.driver must be a non-empty string') {
            throw new RuntimeException('unexpected connection error: ' . $e->getMessage());
        }
        $connectionRejected = true;
    }
    if (!($connectionRejected ?? false)) {
        throw new RuntimeException('malformed connection driver was accepted');
    }

    $badMemory = $options;
    $badMemory['resources']['doctrine2']['connection']['options']['memory'] = 'yes';
    try {
        EntityManagerFactory::create($badMemory);
    } catch (LogicException $e) {
        if ($e->getMessage() !== 'resources.doctrine2.connection.options.memory must be boolean') {
            throw new RuntimeException('unexpected memory error: ' . $e->getMessage());
        }
        $memoryRejected = true;
    }
    if (!($memoryRejected ?? false)) {
        throw new RuntimeException('malformed memory option was accepted');
    }

    $badDriverClass = $options;
    $badDriverClass['resources']['doctrine2']['connection']['options']['driverClass'] = 'stdClass';
    try {
        EntityManagerFactory::create($badDriverClass);
    } catch (LogicException $e) {
        if ($e->getMessage() !== 'resources.doctrine2.connection.options.driverClass must implement Doctrine\\DBAL\\Driver') {
            throw new RuntimeException('unexpected driverClass error: ' . $e->getMessage());
        }
        $driverClassRejected = true;
    }
    if (!($driverClassRejected ?? false)) {
        throw new RuntimeException('invalid driverClass was accepted');
    }

    $badCache = $options;
    $badCache['resources']['doctrine2cache']['namespace'] = ['cache-key'];
    try {
        EntityManagerFactory::create($badCache);
    } catch (LogicException $e) {
        if ($e->getMessage() !== 'resources.doctrine2cache.namespace must be a string') {
            throw new RuntimeException('unexpected cache error: ' . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException('malformed cache namespace was accepted');
});

emCheck('metadata driver is the attribute driver over the Entities dir', function () use (&$em) {
    $driver = requireEntityManager($em)->getConfiguration()->getMetadataDriverImpl();
    if (!$driver instanceof AttributeDriver) {
        throw new RuntimeException('metadata driver is ' . get_debug_type($driver));
    }
});

emCheck('proxy namespace + autogen flag applied', function () use (&$em, $options) {
    $cfg = requireEntityManager($em)->getConfiguration();
    if ($cfg->getProxyNamespace() !== 'Proxies') {
        throw new RuntimeException('proxy namespace = ' . var_export($cfg->getProxyNamespace(), true));
    }
    // autogen_proxies '0' must map to AUTOGENERATE_NEVER (0), not a truthy mode.
    if ((int) $cfg->getAutoGenerateProxyClasses() !== 0) {
        throw new RuntimeException('autogen = ' . var_export($cfg->getAutoGenerateProxyClasses(), true));
    }

    foreach (range(0, 4) as $mode) {
        $modeOptions = $options;
        $modeOptions['resources']['doctrine2']['autogen_proxies'] = (string) $mode;
        $modeEm = EntityManagerFactory::create($modeOptions);
        $modeEm = requireEntityManager($modeEm);
        if ($modeEm->getConfiguration()->getAutoGenerateProxyClasses() !== $mode) {
            throw new RuntimeException("proxy mode $mode was not preserved");
        }
    }

    $invalidOptions = $options;
    $invalidOptions['resources']['doctrine2']['autogen_proxies'] = '5';
    $invalidFiveRejected = false;
    try {
        EntityManagerFactory::create($invalidOptions);
    } catch (InvalidArgumentException) {
        $invalidFiveRejected = true;
    }
    if (!$invalidFiveRejected) {
        throw new RuntimeException('invalid proxy mode 5 was accepted');
    }

    $invalidOptions['resources']['doctrine2']['autogen_proxies'] = '1junk';
    $invalidJunkRejected = false;
    try {
        EntityManagerFactory::create($invalidOptions);
    } catch (InvalidArgumentException) {
        $invalidJunkRejected = true;
    }
    if (!$invalidJunkRejected) {
        throw new RuntimeException('lossy proxy mode string was accepted');
    }
});

emCheck('metadata cache is wired (no exception fetching it)', function () use (&$em) {
    $cfg = requireEntityManager($em)->getConfiguration();
    foreach (['metadata' => $cfg->getMetadataCache(), 'query' => $cfg->getQueryCache(), 'result' => $cfg->getResultCache()] as $name => $cache) {
        if (!$cache instanceof CacheItemPoolInterface) {
            throw new RuntimeException("$name cache is " . get_debug_type($cache));
        }
    }
});

emCheck('a known entity attribute mapping loads through the driver', function () use (&$em) {
    // Proves the driver reads the #[ORM\...] attributes on Entities\Admin and
    // produces class metadata (no DB needed).
    $meta = requireEntityManager($em)->getClassMetadata('Entities\\Admin');
    if ($meta->getTableName() === '') {
        throw new RuntimeException('Admin metadata has no table name');
    }
});

checkDirectoryEntryRepositoryContract($em);
checkLogRepositoryQueryContract($em);
checkMailboxRepositoryQueryContract($em);
checkMailboxTaskRepositoryContract($em);
checkMcpTokenRepositoryContract($em);

emCheck('registerEntityAutoloaders loads an Entities class', function () use ($options) {
    EntityManagerFactory::registerEntityAutoloaders($options);
    if (!class_exists('Entities\\Admin')) {
        throw new RuntimeException('Entities\\Admin did not autoload');
    }
});

echo 'PHP ' . PHP_VERSION . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
