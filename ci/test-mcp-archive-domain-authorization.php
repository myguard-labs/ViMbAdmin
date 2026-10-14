<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/McpToken.php';
require_once __DIR__ . '/../application/controllers/McpController.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ViMbAdmin_Doveadm
{
    public static int $deletes = 0;

    public static function fromOptions(mixed $options = null, mixed $progress = null): self
    {
        return new self();
    }

    /** @return list<mixed> */
    public function fsDelete(mixed $path, mixed $filter = 'posix'): array
    {
        self::$deletes++;
        return [];
    }
}

final class ArchiveAuthorizationSession implements SessionStorage
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

/** @extends EntityRepository<\Entities\Archive> */
final class ArchiveAuthorizationRepository extends EntityRepository
{
    public function __construct(private readonly \Entities\Archive $archive)
    {
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $username = $criteria['username'] ?? null;
        if (!is_string($username)
            || ViMbAdmin_Identity::canonical($username)
                !== ViMbAdmin_Identity::canonical($this->archive->requiredUsername())
        ) {
            return null;
        }
        return $this->archive;
    }
}

final class ArchiveAuthorizationRepositoryFactory implements RepositoryFactory
{
    public function __construct(private readonly ArchiveAuthorizationRepository $repository)
    {
    }

    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        if (ltrim($entityName, '\\') !== \Entities\Archive::class) {
            throw new LogicException('Unexpected repository: ' . $entityName);
        }
        return $this->repository;
    }
}

/**
 * @SuppressWarnings("PHPMD.MissingImport") Fixture diagnostics deliberately use global LogicException.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") DBAL override signatures require unused type arguments.
 * @SuppressWarnings("PHPMD.CyclomaticComplexity") The fixture rejects every unsupported SQL shape explicitly.
 * @SuppressWarnings("PHPMD.NPathComplexity") Exact SQL and parameter validation keeps every rejection explicit.
 */
final class ArchiveAuthorizationConnection extends \Doctrine\DBAL\Connection
{
    public const CLAIM_UPDATE_SQL = 'UPDATE resource_claim SET phase = ? WHERE resource_key = ? AND owner_token = ?';
    public const CLAIM_DELETE_SQL = "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'";
    public const ARCHIVE_SELECT_SQL = 'SELECT archive_generation, archive_version FROM archive WHERE id = ?';

    public static int $connectAttempts = 0;
    public static int $claimInserts = 0;
    public static int $claimDeletes = 0;
    public static int $archiveReads = 0;
    /** @var list<string> */
    public static array $claimPhases = [];
    /** @var array{resource_key:string,owner_token:string,username:string,phase:string}|null */
    private static ?array $claim = null;
    private static string $archiveGeneration = '';
    private static int $archiveVersion = 0;
    private static int $archiveId = 0;

    public function reset(\Entities\Archive $archive): void
    {
        self::$connectAttempts = 0;
        self::$claimInserts = 0;
        self::$claimDeletes = 0;
        self::$archiveReads = 0;
        self::$claimPhases = [];
        self::$claim = null;
        self::$archiveGeneration = $archive->getArchiveGeneration();
        self::$archiveVersion = $archive->getArchiveVersion();
        $archiveId = $archive->getId();
        if (!is_int($archiveId)) {
            throw new LogicException('Archive-authorization fixture requires a persisted archive ID');
        }
        self::$archiveId = $archiveId;
    }

    /** @return array{connectAttempts:int,claimInserts:int,claimDeletes:int,archiveReads:int,claimPhases:list<string>,hasClaim:bool} */
    public function observations(): array
    {
        return [
            'connectAttempts' => self::$connectAttempts,
            'claimInserts' => self::$claimInserts,
            'claimDeletes' => self::$claimDeletes,
            'archiveReads' => self::$archiveReads,
            'claimPhases' => self::$claimPhases,
            'hasClaim' => self::$claim !== null,
        ];
    }

    protected function connect(): \Doctrine\DBAL\Driver\Connection
    {
        self::$connectAttempts++;
        throw new LogicException('Archive-authorization unit fixture attempted a real database connection');
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table !== 'resource_claim' || self::$claim !== null
            || !is_string($data['resource_key'] ?? null)
            || !is_string($data['owner_token'] ?? null)
            || !is_string($data['username'] ?? null)
            || ($data['phase'] ?? null) !== 'READY'
        ) {
            throw new LogicException('Unexpected archive-authorization claim insert');
        }
        self::$claim = [
            'resource_key' => $data['resource_key'],
            'owner_token' => $data['owner_token'],
            'username' => $data['username'],
            'phase' => $data['phase'],
        ];
        self::$claimInserts++;
        return 1;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $sql = self::normalizeSql($sql);
        if ($sql === self::CLAIM_UPDATE_SQL) {
            if (count($params) !== 3
                || !is_string($params[0] ?? null)
                || !is_string($params[1] ?? null)
                || !is_string($params[2] ?? null)
                || !in_array($params[0], ['REMOTE', 'READY'], true)
            ) {
                throw new LogicException('Unexpected archive-authorization claim update parameters');
            }
            [$phase, $key, $token] = $params;
            if (self::$claim === null
                || self::$claim['resource_key'] !== $key
                || self::$claim['owner_token'] !== $token
            ) {
                return 0;
            }
            self::$claim['phase'] = $phase;
            self::$claimPhases[] = $phase;
            return 1;
        }
        if ($sql === self::CLAIM_DELETE_SQL) {
            if (count($params) !== 2
                || !is_string($params[0] ?? null)
                || !is_string($params[1] ?? null)
            ) {
                throw new LogicException('Unexpected archive-authorization claim delete parameters');
            }
            [$key, $token] = $params;
            if (self::$claim === null
                || self::$claim['resource_key'] !== $key
                || self::$claim['owner_token'] !== $token
                || self::$claim['phase'] !== 'READY'
            ) {
                return 0;
            }
            self::$claim = null;
            self::$claimDeletes++;
            return 1;
        }
        throw new LogicException('Unexpected archive-authorization statement: ' . $sql);
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if (self::normalizeSql($query) !== self::ARCHIVE_SELECT_SQL) {
            throw new LogicException('Unexpected archive-authorization query: ' . $query);
        }
        if (count($params) !== 1 || !is_int($params[0] ?? null)) {
            throw new LogicException('Unexpected archive-authorization query parameters');
        }
        if ($params[0] !== self::$archiveId) {
            return false;
        }
        self::$archiveReads++;
        return [
            'archive_generation' => self::$archiveGeneration,
            'archive_version' => self::$archiveVersion,
        ];
    }

    public function close(): void
    {
    }

    private static function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? '';
    }
}

final class ArchiveAuthorizationManager extends EntityManager
{
    /** @var list<object> */
    public array $removed = [];
    public int $flushes = 0;

    public function __construct(\Entities\Archive $archive)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
            $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
        }
        $configuration->setRepositoryFactory(new ArchiveAuthorizationRepositoryFactory(
            new ArchiveAuthorizationRepository($archive),
        ));
        /** @var ArchiveAuthorizationConnection $connection */
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'serverVersion' => '8.0',
            'wrapperClass' => ArchiveAuthorizationConnection::class,
        ], $configuration);
        $connection->reset($archive);
        parent::__construct($connection, $configuration);
    }

    public function remove(object $object): void
    {
        $this->removed[] = $object;
    }

    public function flush(): void
    {
        $this->flushes++;
    }
}

final class ArchiveAuthorizationResources
{
    public function __construct(private readonly ArchiveAuthorizationManager $manager)
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

/** @return array{McpController,ArchiveAuthorizationManager,\Entities\Archive} */
function archiveAuthorizationFixture(
    string $username,
    ?string $allowedDomains,
    ?string $attachedDomain = null,
): array {
    $archive = (new \Entities\Archive())
        ->setUsername($username)
        ->setMaildirFile('/backups/' . str_replace('@', '/', $username));
    archiveAuthorizationSetId($archive);
    if ($attachedDomain !== null) {
        $archive->setDomain((new \Entities\Domain())->setDomain($attachedDomain));
    }
    $manager = new ArchiveAuthorizationManager($archive);
    $session = new ArchiveAuthorizationSession();
    $controller = new McpController(
        new Container(
            new ArchiveAuthorizationResources($manager),
            new Auth($session, static fn (int $id): null => null),
        ),
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
    $token = (new \Entities\McpToken())->setAllowedDomains($allowedDomains);
    (new ReflectionProperty($controller, '_token'))->setValue($controller, $token);
    return [$controller, $manager, $archive];
}

/** @SuppressWarnings("PHPMD.MissingImport") ReflectionProperty is deliberately global in this fixture. */
function archiveAuthorizationSetId(\Entities\Archive $archive): void
{
    (new ReflectionProperty($archive, 'id'))->setValue($archive, 41);
}

/** @param array<string,mixed> $params
 *  @return array<string,mixed> */
function archiveAuthorizationDelete(McpController $controller, array $params): array
{
    $result = (new ReflectionMethod($controller, '_archiveDelete'))->invoke($controller, $params);
    if (!is_array($result)) {
        throw new LogicException('MCP archive delete returned a non-array result');
    }
    return $result;
}

function archiveAuthorizationCheck(string $label, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "ok: {$label}\n";
}

/** @return array{connectAttempts:int,claimInserts:int,claimDeletes:int,archiveReads:int,claimPhases:list<string>,hasClaim:bool} */
function archiveAuthorizationObservations(ArchiveAuthorizationManager $manager): array
{
    return archiveAuthorizationConnection($manager)->observations();
}

function archiveAuthorizationConnection(ArchiveAuthorizationManager $manager): ArchiveAuthorizationConnection
{
    /** @var ArchiveAuthorizationConnection $connection */
    $connection = $manager->getConnection();
    return $connection;
}

/** @SuppressWarnings("PHPMD.MissingImport") The fixture catches the global LogicException diagnostic. */
function archiveAuthorizationRejectsSql(callable $operation): bool
{
    try {
        $operation();
    } catch (LogicException) {
        return true;
    }
    return false;
}

function archiveAuthorizationNoClaimActivity(ArchiveAuthorizationManager $manager): bool
{
    $observations = archiveAuthorizationObservations($manager);
    return $observations === [
        'connectAttempts' => 0,
        'claimInserts' => 0,
        'claimDeletes' => 0,
        'archiveReads' => 0,
        'claimPhases' => [],
        'hasClaim' => false,
    ];
}

function archiveAuthorizationNoEffects(ArchiveAuthorizationManager $manager): bool
{
    return $manager->removed === []
        && $manager->flushes === 0
        && ViMbAdmin_Doveadm::$deletes === 0
        && archiveAuthorizationNoClaimActivity($manager);
}

function archiveAuthorizationCompletedDeleteClaim(ArchiveAuthorizationManager $manager): bool
{
    $observations = archiveAuthorizationObservations($manager);
    return $observations === [
        'connectAttempts' => 0,
        'claimInserts' => 1,
        'claimDeletes' => 1,
        'archiveReads' => 1,
        'claimPhases' => ['REMOTE', 'READY'],
        'hasClaim' => false,
    ];
}

function archiveAuthorizationDenied(
    string $label,
    string $username,
    ?string $allowedDomains,
    string $expectedMessage,
    ?string $attachedDomain = null,
    ?string $requestedUsername = null,
): void {
    ViMbAdmin_Doveadm::$deletes = 0;
    [$controller, $manager] = archiveAuthorizationFixture($username, $allowedDomains, $attachedDomain);
    $message = null;
    try {
        archiveAuthorizationDelete($controller, ['username' => $requestedUsername ?? $username]);
    } catch (ViMbAdmin_Mcp_Exception $error) {
        $message = $error->getMessage();
    }
    if ($message !== $expectedMessage || !archiveAuthorizationNoEffects($manager)) {
        throw new RuntimeException(sprintf(
            '%s (message=%s, removed=%d, flushes=%d, fsDeletes=%d)',
            $label,
            var_export($message, true),
            count($manager->removed),
            $manager->flushes,
            ViMbAdmin_Doveadm::$deletes,
        ));
    }
    archiveAuthorizationCheck(
        $label,
        $message === $expectedMessage
            && archiveAuthorizationNoEffects($manager),
    );
}

ViMbAdmin_Doveadm::$deletes = 0;
[$allowed, $allowedManager, $allowedArchive] = archiveAuthorizationFixture(
    'User@Allowed.Example',
    'allowed.example',
);
$allowedResult = archiveAuthorizationDelete($allowed, ['username' => 'User@Allowed.Example']);
archiveAuthorizationCheck(
    'scoped token deletes an orphan archive using its canonical username domain',
    $allowedResult === ['deleted' => 'user@allowed.example']
        && $allowedManager->removed === [$allowedArchive]
        && $allowedManager->flushes === 1
        && ViMbAdmin_Doveadm::$deletes === 1
        && archiveAuthorizationCompletedDeleteClaim($allowedManager),
);
$allowedConnection = archiveAuthorizationConnection($allowedManager);
archiveAuthorizationCheck(
    'claim phase updates require the exact owner-token predicate',
    archiveAuthorizationRejectsSql(fn (): int|string => $allowedConnection->executeStatement(
        'UPDATE resource_claim SET phase = ? WHERE resource_key = ?',
        ['REMOTE', 'resource-key', 'owner-token'],
    )),
);
archiveAuthorizationCheck(
    'claim phase updates reject surplus parameters',
    archiveAuthorizationRejectsSql(fn (): int|string => $allowedConnection->executeStatement(
        ArchiveAuthorizationConnection::CLAIM_UPDATE_SQL,
        ['REMOTE', 'resource-key', 'owner-token', 'surplus'],
    )),
);
archiveAuthorizationCheck(
    'claim deletion requires the exact owner-token predicate',
    archiveAuthorizationRejectsSql(fn (): int|string => $allowedConnection->executeStatement(
        "DELETE FROM resource_claim WHERE resource_key = ? AND phase = 'READY'",
        ['resource-key', 'owner-token'],
    )),
);
archiveAuthorizationCheck(
    'claim deletion requires the READY predicate',
    archiveAuthorizationRejectsSql(fn (): int|string => $allowedConnection->executeStatement(
        'DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ?',
        ['resource-key', 'owner-token'],
    )),
);
archiveAuthorizationCheck(
    'claim deletion rejects surplus parameters',
    archiveAuthorizationRejectsSql(fn (): int|string => $allowedConnection->executeStatement(
        ArchiveAuthorizationConnection::CLAIM_DELETE_SQL,
        ['resource-key', 'owner-token', 'surplus'],
    )),
);
archiveAuthorizationCheck(
    'archive snapshot reads require the ID predicate',
    archiveAuthorizationRejectsSql(fn (): array|false => $allowedConnection->fetchAssociative(
        'SELECT archive_generation, archive_version FROM archive',
        [41],
    )),
);
archiveAuthorizationCheck(
    'archive snapshot reads reject surplus parameters',
    archiveAuthorizationRejectsSql(fn (): array|false => $allowedConnection->fetchAssociative(
        ArchiveAuthorizationConnection::ARCHIVE_SELECT_SQL,
        [41, 42],
    )),
);
archiveAuthorizationCheck(
    'archive snapshot reads require the exact ID type',
    archiveAuthorizationRejectsSql(fn (): array|false => $allowedConnection->fetchAssociative(
        ArchiveAuthorizationConnection::ARCHIVE_SELECT_SQL,
        ['41'],
    )),
);
archiveAuthorizationCheck(
    'archive snapshot reads reject another archive ID without changing the claim oracle',
    $allowedConnection->fetchAssociative(ArchiveAuthorizationConnection::ARCHIVE_SELECT_SQL, [42]) === false
        && archiveAuthorizationCompletedDeleteClaim($allowedManager),
);

ViMbAdmin_Doveadm::$deletes = 0;
[$unrestricted, $unrestrictedManager, $unrestrictedArchive] = archiveAuthorizationFixture(
    'user@outside.example',
    null,
);
$unrestrictedResult = archiveAuthorizationDelete($unrestricted, ['username' => 'user@outside.example']);
archiveAuthorizationCheck(
    'unrestricted token preserves orphan archive deletion',
    $unrestrictedResult === ['deleted' => 'user@outside.example']
        && $unrestrictedManager->removed === [$unrestrictedArchive]
        && $unrestrictedManager->flushes === 1
        && ViMbAdmin_Doveadm::$deletes === 1
        && archiveAuthorizationCompletedDeleteClaim($unrestrictedManager),
);

archiveAuthorizationDenied(
    'scoped token rejects an orphan archive outside its allowed domain before destructive effects',
    'user@outside.example',
    'allowed.example',
    'unknown domain',
);
archiveAuthorizationDenied(
    'domainless orphan username fails closed before destructive effects',
    'orphan-without-domain',
    null,
    'invalid username: must be local@domain',
);
archiveAuthorizationDenied(
    'malformed orphan username fails closed before destructive effects',
    '@outside.example',
    null,
    'invalid username: must be local@domain',
);
archiveAuthorizationDenied(
    'existing attached-domain authorization remains enforced',
    'user@allowed.example',
    'allowed.example',
    'unknown domain',
    'outside.example',
);
archiveAuthorizationDenied(
    'archive lookup must match the requested canonical username',
    'user@allowed.example',
    'allowed.example',
    'no archive for that username',
    null,
    'other@allowed.example',
);

echo "ALL PASSED (16 checks)\n";
