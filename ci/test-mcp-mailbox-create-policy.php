<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class McpMailboxPolicySession implements SessionStorage
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
final class McpMailboxPolicyDomainRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, ClassMetadata $class, private readonly \Entities\Domain $domain)
    {
        parent::__construct($em, $class);
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): object
    {
        return $this->domain;
    }
}

final class McpMailboxPolicyMailboxRepository extends \Repositories\Mailbox
{
    public function isUnique($username, $id = false)
    {
        return true;
    }
}

/** @extends EntityRepository<\Entities\Mailbox> */
final class McpMailboxPolicyAliasRepository extends EntityRepository
{
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return null;
    }
}

final class McpMailboxPolicyRepositoryFactory implements RepositoryFactory
{
    public function __construct(private readonly \Entities\Domain $domain)
    {
    }

    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        $entityName = ltrim($entityName, '\\');
        // These repositories are pure fixture seams: none performs ORM queries.
        // Avoid loading real mapped metadata here, because doing so makes newer
        // Doctrine releases validate every unrelated association target.
        $metadata = new ClassMetadata($entityName);
        if ($entityName === \Entities\Domain::class) {
            return new McpMailboxPolicyDomainRepository($manager, $metadata, $this->domain);
        }
        if ($entityName === \Entities\Mailbox::class) {
            return new McpMailboxPolicyMailboxRepository($manager, $metadata);
        }
        if ($entityName === \Entities\Alias::class) {
            return new McpMailboxPolicyAliasRepository($manager, $metadata);
        }
        throw new LogicException('Unexpected repository: ' . $entityName);
    }
}

final class McpMailboxPolicyManager extends EntityManager
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;

    public function __construct(\Entities\Domain $domain)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../application/Entities']);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setRepositoryFactory(new McpMailboxPolicyRepositoryFactory($domain));
        parent::__construct(
            DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration),
            $configuration,
        );
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

final class McpMailboxPolicyResources
{
    public function __construct(
        private readonly McpMailboxPolicyManager $manager,
        private readonly bool $mailboxAliases,
    ) {
    }
    public function getResource(string $name): object
    {
        return $this->manager;
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [
            'mailboxAliases' => $this->mailboxAliases,
            'defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']],
        ];
    }
}

/** @return array{McpController,McpMailboxPolicyManager,\Entities\Domain} */
function mcpMailboxPolicyFixture(
    int $count,
    int $maximum,
    int $quota,
    int $maximumQuota,
    bool $mailboxAliases = false,
    int $aliasCount = 0,
    int $maxAliases = 0,
): array {
    $domain = (new \Entities\Domain())
        ->setDomain('example.test')
        ->setMailboxCount($count)
        ->setAliasCount($aliasCount)
        ->setMaxAliases($maxAliases)
        ->setMaxMailboxes($maximum)
        ->setQuota($quota)
        ->setMaxQuota($maximumQuota);
    $manager = new McpMailboxPolicyManager($domain);
    $session = new McpMailboxPolicySession();
    $controller = new McpController(
        new Container(
            new McpMailboxPolicyResources($manager, $mailboxAliases),
            new Auth($session, static fn (int $id): null => null),
        ),
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
    return [$controller, $manager, $domain];
}

/** @param array<string,mixed> $overrides
 *  @return array<string,mixed> */
function mcpMailboxCreate(McpController $controller, array $overrides = []): array
{
    $params = array_replace([
        'domain' => 'example.test',
        'local_part' => 'new-user',
        'password' => 'correct horse battery staple',
        'quota' => 500,
    ], $overrides);
    $result = (new ReflectionMethod($controller, '_mailboxCreate'))->invoke($controller, $params);
    if (!is_array($result)) {
        throw new LogicException('MCP mailbox create returned a non-array result');
    }
    return $result;
}

function mcpMailboxCheck(string $name, bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "ok: {$name}\n";
}

/** @return \Entities\Mailbox|null */
function persistedMailbox(McpMailboxPolicyManager $manager): ?\Entities\Mailbox
{
    foreach ($manager->persisted as $entity) {
        if ($entity instanceof \Entities\Mailbox) {
            return $entity;
        }
    }
    return null;
}

/** @return \Entities\Alias|null */
function persistedAlias(McpMailboxPolicyManager $manager): ?\Entities\Alias
{
    foreach ($manager->persisted as $entity) {
        if ($entity instanceof \Entities\Alias) {
            return $entity;
        }
    }
    return null;
}

[$allowed, $allowedManager, $allowedDomain] = mcpMailboxPolicyFixture(1, 2, 256, 1000);
$created = mcpMailboxCreate($allowed);
$allowedMailbox = persistedMailbox($allowedManager);
mcpMailboxCheck(
    'ordinary MCP password reaches the mailbox hash unchanged',
    $allowedMailbox instanceof \Entities\Mailbox
        && OSS_Auth_Password::verify(
            'correct horse battery staple',
            $allowedMailbox->requiredPassword(),
            ['pwhash' => 'crypt:sha512'],
        ),
);
mcpMailboxCheck(
    'below-limit MCP creation preserves an allowed quota',
    $created['created'] === true && $allowedMailbox?->getQuota() === 500
        && $allowedDomain->getMailboxCount() === 2 && $allowedManager->flushes === 1,
);

$boundaryBytes = " \tleading and trailing\r\n";
[$passwordBoundary, $passwordBoundaryManager] = mcpMailboxPolicyFixture(0, 2, 256, 1000);
mcpMailboxCreate($passwordBoundary, ['password' => $boundaryBytes]);
$passwordBoundaryHash = persistedMailbox($passwordBoundaryManager)?->requiredPassword();
mcpMailboxCheck(
    'MCP password preserves exact leading and trailing whitespace bytes through hashing',
    is_string($passwordBoundaryHash)
        && OSS_Auth_Password::verify($boundaryBytes, $passwordBoundaryHash, ['pwhash' => 'crypt:sha512'])
        && !OSS_Auth_Password::verify(trim($boundaryBytes), $passwordBoundaryHash, ['pwhash' => 'crypt:sha512']),
);

foreach ([['password' => []], ['password' => '']] as $invalidPassword) {
    [$invalid, $invalidManager, $invalidDomain] = mcpMailboxPolicyFixture(0, 2, 256, 1000);
    $invalidError = null;
    try {
        mcpMailboxCreate($invalid, $invalidPassword);
    } catch (ViMbAdmin_Mcp_Exception $exception) {
        $invalidError = $exception->getMessage();
    }
    $expectedError = is_array($invalidPassword['password'])
        ? 'param "password" must be a string'
        : 'param "password" required';
    mcpMailboxCheck(
        $expectedError . ' is reported before mailbox state changes',
        $invalidError === $expectedError
            && $invalidManager->persisted === [] && $invalidManager->flushes === 0
            && $invalidDomain->getMailboxCount() === 0,
    );
}

[$boundary, $boundaryManager] = mcpMailboxPolicyFixture(0, 2, 256, 1000);
mcpMailboxCreate($boundary, ['quota' => 1000]);
mcpMailboxCheck(
    'exact domain maximum MCP quota is preserved',
    persistedMailbox($boundaryManager)?->getQuota() === 1000 && $boundaryManager->flushes === 1,
);

[$full, $fullManager, $fullDomain] = mcpMailboxPolicyFixture(2, 2, 256, 1000);
$fullError = null;
try {
    mcpMailboxCreate($full);
} catch (ViMbAdmin_Mcp_DomainException $exception) {
    $fullError = $exception->getMessage();
}
mcpMailboxCheck(
    'at-limit MCP creation is rejected before mailbox state changes',
    $fullError === 'You have used all of your allocated mailboxes.'
        && $fullManager->persisted === [] && $fullManager->flushes === 0
        && $fullDomain->getMailboxCount() === 2,
);

foreach ([0, 1001] as $requestedQuota) {
    [$clamped, $clampedManager] = mcpMailboxPolicyFixture(0, 2, 256, 1000);
    mcpMailboxCreate($clamped, ['quota' => $requestedQuota]);
    mcpMailboxCheck(
        "MCP quota {$requestedQuota} is normalized to the domain quota",
        persistedMailbox($clampedManager)?->getQuota() === 256 && $clampedManager->flushes === 1,
    );
}

foreach ([0, 1001] as $requestedQuota) {
    [$unsafeDefault, $unsafeDefaultManager] = mcpMailboxPolicyFixture(0, 2, 2000, 1000);
    mcpMailboxCreate($unsafeDefault, ['quota' => $requestedQuota]);
    mcpMailboxCheck(
        "MCP quota {$requestedQuota} cannot persist above the domain maximum when the default is over-limit",
        persistedMailbox($unsafeDefaultManager)?->getQuota() === 1000
            && $unsafeDefaultManager->flushes === 1,
    );
}

foreach ([0, 1001] as $requestedQuota) {
    [$unlimitedDefault, $unlimitedDefaultManager] = mcpMailboxPolicyFixture(0, 2, 0, 1000);
    mcpMailboxCreate($unlimitedDefault, ['quota' => $requestedQuota]);
    mcpMailboxCheck(
        "MCP quota {$requestedQuota} is capped when the unlimited default has a finite domain maximum",
        persistedMailbox($unlimitedDefaultManager)?->getQuota() === 1000
            && $unlimitedDefaultManager->flushes === 1,
    );
}

[$malformed, $malformedManager, $malformedDomain] = mcpMailboxPolicyFixture(0, 2, 256, 1000);
$malformedError = null;
try {
    mcpMailboxCreate($malformed, ['quota' => -1]);
} catch (ViMbAdmin_Mcp_Exception $exception) {
    $malformedError = $exception->getMessage();
}
mcpMailboxCheck(
    'negative MCP quota is rejected before policy or persistence',
    $malformedError === 'param "quota" must be a non-negative integer'
        && $malformedManager->persisted === [] && $malformedManager->flushes === 0
        && $malformedDomain->getMailboxCount() === 0,
);

[$unlimited, $unlimitedManager, $unlimitedDomain] = mcpMailboxPolicyFixture(99, 0, 256, 0);
mcpMailboxCreate($unlimited, ['quota' => 0]);
mcpMailboxCheck(
    'unlimited domain keeps zero quota and permits MCP creation',
    persistedMailbox($unlimitedManager)?->getQuota() === 0
        && $unlimitedDomain->getMailboxCount() === 100 && $unlimitedManager->flushes === 1,
);

[$automaticAlias, $automaticAliasManager, $automaticAliasDomain] = mcpMailboxPolicyFixture(
    0,
    2,
    256,
    1000,
    true,
    2,
    2,
);
mcpMailboxCreate($automaticAlias);
$createdAlias = persistedAlias($automaticAliasManager);
mcpMailboxCheck(
    'mailbox auto self-alias persists at capacity without consuming alias capacity',
    $createdAlias instanceof \Entities\Alias
        && $createdAlias->getAddress() === 'new-user@example.test'
        && $createdAlias->getGoto() === 'new-user@example.test'
        && $automaticAliasDomain->getAliasCount() === 2
        && $automaticAliasDomain->getMailboxCount() === 1
        && $automaticAliasManager->flushes === 1,
);

echo "ALL PASSED (16 checks)\n";
