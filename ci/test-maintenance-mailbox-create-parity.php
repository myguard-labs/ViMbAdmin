<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/Alias.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;

/** @extends EntityRepository<\Entities\Alias> */
final class MailboxCreateParityAliasRepository extends EntityRepository
{
    public ?\Entities\Alias $existing = null;

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        if (($criteria['address'] ?? null) === null) {
            throw new LogicException('Alias fixture requires an address lookup.');
        }
        return $this->existing;
    }
}

final class MailboxCreateParityRepositoryFactory implements RepositoryFactory
{
    public ?\Entities\Alias $existing = null;

    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        if (ltrim($entityName, '\\') !== \Entities\Alias::class) {
            throw new LogicException('Unexpected fixture repository ' . $entityName);
        }
        $aliases = new MailboxCreateParityAliasRepository($manager, new ClassMetadata(\Entities\Alias::class));
        $aliases->existing = $this->existing;
        /** @var EntityRepository<T> $repository */
        $repository = $aliases;
        return $repository;
    }
}

final class MailboxCreateParityManager extends EntityManager
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;
    public ?Throwable $flushError = null;
    public readonly MailboxCreateParityRepositoryFactory $repositories;

    public function __construct()
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
            $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
        }
        $this->repositories = new MailboxCreateParityRepositoryFactory();
        $configuration->setRepositoryFactory($this->repositories);
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration);
        parent::__construct($connection, $configuration);
    }

    public function persist(object $entity): void
    {
        $this->persisted[] = $entity;
    }

    public function flush(): void
    {
        $this->flushes++;
        if ($this->flushError !== null) {
            throw $this->flushError;
        }
    }

    public function countPersisted(string $class): int
    {
        return count(array_filter($this->persisted, static fn (object $entity): bool => $entity instanceof $class));
    }
}

final class MailboxCreateParityAssertions
{
    public static int $failures = 0;
}

function mailboxCreateParityCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MailboxCreateParityAssertions::$failures++;
    }
}

function mailboxCreateParityDomain(int $count = 0, int $maximum = 10): \Entities\Domain
{
    return (new \Entities\Domain())
        ->setDomain('example.test')
        ->setMailboxCount($count)
        ->setAliasCount(0)
        ->setMaxMailboxes($maximum)
        ->setQuota(256)
        ->setMaxQuota(1024);
}

function mailboxCreateParityMailbox(string $password, bool $active = false): \Entities\Mailbox
{
    return (new \Entities\Mailbox())
        ->setUsername('user@example.test')
        ->setLocalPart('user')
        ->setName('MCP Name')
        ->setQuota(512)
        ->setActive($active)
        ->setPassword($password);
}

echo "== maintenance shared mailbox creation parity ==\n";

$exactPassword = " \tleading and trailing\r\n";
$manager = new MailboxCreateParityManager();
$domain = mailboxCreateParityDomain();
$mailbox = mailboxCreateParityMailbox($exactPassword, false);
$created = (new ViMbAdmin_Service_Mailbox($manager))->createWithPolicy(
    $mailbox,
    $domain,
    'crypt:sha512',
    true,
    ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
);
$storedPassword = $mailbox->requiredPassword();
mailboxCreateParityCheck(
    'MCP policy preserves active=false, name and byte quota',
    $created === $mailbox && $mailbox->getActive() === false
        && $mailbox->getName() === 'MCP Name' && $mailbox->getQuota() === 512,
);
mailboxCreateParityCheck(
    'exact password bytes are hashed once and domain count increments once',
    OSS_Auth_Password::verify($exactPassword, $storedPassword, ['pwhash' => 'crypt:sha512'])
        && !OSS_Auth_Password::verify(trim($exactPassword), $storedPassword, ['pwhash' => 'crypt:sha512'])
        && $domain->getMailboxCount() === 1
        && $manager->countPersisted(\Entities\Mailbox::class) === 1
        && $manager->countPersisted(\Entities\Log::class) === 0
        && $manager->flushes === 1,
);
$selfAlias = array_values(array_filter(
    $manager->persisted,
    static fn (object $entity): bool => $entity instanceof \Entities\Alias,
))[0] ?? null;
mailboxCreateParityCheck(
    'MCP self-alias remains active and does not consume alias capacity',
    $selfAlias instanceof \Entities\Alias
        && $selfAlias->getAddress() === 'user@example.test'
        && $selfAlias->getGoto() === 'user@example.test'
        && $selfAlias->getActive() === true
        && $domain->getAliasCount() === 0,
);

$reuseManager = new MailboxCreateParityManager();
$reuseManager->repositories->existing = (new \Entities\Alias())
    ->setAddress('user@example.test')->setGoto('legacy@example.test');
$reuseDomain = mailboxCreateParityDomain();
(new ViMbAdmin_Service_Mailbox($reuseManager))->createWithPolicy(
    mailboxCreateParityMailbox('reuse password'),
    $reuseDomain,
    'crypt:sha512',
    true,
    ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
);
mailboxCreateParityCheck(
    'an existing self-alias is reused without duplicate persistence',
    $reuseManager->countPersisted(\Entities\Alias::class) === 0
        && $reuseManager->countPersisted(\Entities\Mailbox::class) === 1
        && $reuseDomain->getMailboxCount() === 1,
);

$fullManager = new MailboxCreateParityManager();
$fullDomain = mailboxCreateParityDomain(1, 1);
$capacityRejected = false;
try {
    ViMbAdmin_Service_Mailbox::applyCreationPolicy(
        mailboxCreateParityMailbox('capacity password'),
        $fullDomain,
    );
} catch (ViMbAdmin_Service_Exception $error) {
    $capacityRejected = $error->getMessage() === 'You have used all of your allocated mailboxes.';
}
mailboxCreateParityCheck(
    'domain-capacity contention rejects before hashing, count or persistence',
    $capacityRejected && $fullDomain->getMailboxCount() === 1
        && $fullManager->persisted === [] && $fullManager->flushes === 0,
);

$vetoManager = new MailboxCreateParityManager();
$vetoDomain = mailboxCreateParityDomain();
$vetoMailbox = mailboxCreateParityMailbox('veto password');
$vetoCalls = 0;
$vetoed = (new ViMbAdmin_Service_Mailbox($vetoManager))->createWithPolicy(
    $vetoMailbox,
    $vetoDomain,
    'crypt:sha512',
    true,
    ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
    static function () use (&$vetoCalls): bool {
        $vetoCalls++;
        return false;
    },
);
mailboxCreateParityCheck(
    'plugin veto runs before hashing, persistence and count mutation',
    $vetoed === null && $vetoCalls === 1 && $vetoMailbox->getPassword() === 'veto password'
        && $vetoDomain->getMailboxCount() === 0
        && $vetoManager->persisted === [] && $vetoManager->flushes === 0,
);

$conflictManager = new MailboxCreateParityManager();
$conflictManager->flushError = new RuntimeException('optimistic capacity conflict');
$conflictDomain = mailboxCreateParityDomain();
$postFlushCalls = 0;
$conflictPropagated = false;
try {
    (new ViMbAdmin_Service_Mailbox($conflictManager))->createWithPolicy(
        mailboxCreateParityMailbox('conflict password'),
        $conflictDomain,
        'crypt:sha512',
        false,
        ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
        null,
        null,
        static function () use (&$postFlushCalls): void {
            $postFlushCalls++;
        },
    );
} catch (RuntimeException $error) {
    $conflictPropagated = $error === $conflictManager->flushError;
}
mailboxCreateParityCheck(
    'flush failure propagates for ORM rollback and skips post-flush publication',
    $conflictPropagated && $conflictManager->flushes === 1 && $postFlushCalls === 0
        && $conflictManager->countPersisted(\Entities\Mailbox::class) === 1
        && $conflictDomain->getMailboxCount() === 1,
);

$failures = MailboxCreateParityAssertions::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
