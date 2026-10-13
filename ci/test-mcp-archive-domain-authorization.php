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
        parent::__construct(
            DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration),
            $configuration,
        );
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
    if ($message !== $expectedMessage
        || $manager->removed !== []
        || $manager->flushes !== 0
        || ViMbAdmin_Doveadm::$deletes !== 0
    ) {
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
            && $manager->removed === []
            && $manager->flushes === 0
            && ViMbAdmin_Doveadm::$deletes === 0,
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
        && ViMbAdmin_Doveadm::$deletes === 1,
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
        && ViMbAdmin_Doveadm::$deletes === 1,
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

echo "ALL PASSED (7 checks)\n";
