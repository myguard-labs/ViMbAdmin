<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/** Characterize the three service audit-log constructors and their callers. */

if (getenv('VIMBADMIN_SERVICE_AUDIT_DB') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_SERVICE_AUDIT_DB=1 for the dedicated test database.\n");
    exit(2);
}

$requiredDatabase = 'vimbadmin_service_audit_log';
if (getenv('DB_NAME') !== $requiredDatabase) {
    fwrite(STDERR, "DB_NAME must be the dedicated {$requiredDatabase} database.\n");
    exit(2);
}
foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS'] as $requiredEnvironment) {
    if (getenv($requiredEnvironment) === false) {
        fwrite(STDERR, "{$requiredEnvironment} must be set explicitly.\n");
        exit(2);
    }
}

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../application/Entities/PreferenceScalarAccessors.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/AdminPreference.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/DatabaseVersion.php';
require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/DomainPreference.php';
require_once __DIR__ . '/../application/Entities/LastLogin.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../application/Entities/McpToken.php';
require_once __DIR__ . '/../application/Entities/QueueRunner.php';
require_once __DIR__ . '/../application/Entities/Quota.php';
require_once __DIR__ . '/../application/Entities/RememberMe.php';
require_once __DIR__ . '/../application/Entities/ResourceClaim.php';

require __DIR__ . '/../library/ViMbAdmin/Service/Admin.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Alias.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Mailbox.php';

final class ServiceAuditLogState
{
    public static int $checks = 0;
}

/** @phpstan-assert true $condition */
function serviceAuditCheck(string $label, bool $condition): void
{
    ServiceAuditLogState::$checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'ok: ' . $label . "\n";
}

function serviceAuditDatabaseInteger(mixed $value, string $label): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer !== false) {
            return $integer;
        }
    }
    throw new UnexpectedValueException("MariaDB returned an invalid {$label}");
}

final class ServiceAuditLogManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var list<object> */
    public array $persisted = [];
    /** @var list<string> */
    public array $events = [];
    public int $persistAttempts = 0;
    public int $flushes = 0;
    public ?Throwable $persistFailure = null;

    public function persist(object $object): void
    {
        $this->persistAttempts++;
        $this->events[] = 'persist:' . $object::class;
        if ($object instanceof \Entities\Log && $this->persistFailure !== null) {
            throw $this->persistFailure;
        }
        $this->persisted[] = $object;
    }

    public function remove(object $object): void
    {
        $this->events[] = 'remove:' . $object::class;
    }

    public function flush(): void
    {
        $this->flushes++;
        $this->events[] = 'flush';
    }

    public function find(string $className, mixed $id): ?object
    {
        return null;
    }

    public function clear(): void
    {
    }

    public function detach(object $object): void
    {
    }

    public function refresh(object $object): void
    {
    }

    public function getRepository(string $className): \Doctrine\Persistence\ObjectRepository
    {
        throw new LogicException('repository access is outside this fixture');
    }

    public function getClassMetadata(string $className): \Doctrine\Persistence\Mapping\ClassMetadata
    {
        throw new LogicException('metadata access is outside this fixture');
    }

    public function getMetadataFactory(): \Doctrine\Persistence\Mapping\ClassMetadataFactory
    {
        throw new LogicException('metadata access is outside this fixture');
    }

    public function initializeObject(object $obj): void
    {
    }

    public function isUninitializedObject(mixed $value): bool
    {
        return false;
    }

    public function contains(object $object): bool
    {
        return false;
    }

    public function lastLog(): ?\Entities\Log
    {
        for ($index = count($this->persisted) - 1; $index >= 0; $index--) {
            if ($this->persisted[$index] instanceof \Entities\Log) {
                return $this->persisted[$index];
            }
        }
        return null;
    }
}

/** @param object $service */
function serviceAuditInvokeConstructor(
    object $service,
    \Entities\Admin $actor,
    string $action,
    string $message,
    ?\Entities\Domain $domain,
): void {
    $method = new ReflectionMethod($service, 'log');
    $method->setAccessible(true);
    $method->invoke($service, $actor, $action, $message, $domain);
}

function serviceAuditAdmin(string $username): \Entities\Admin
{
    $admin = new \Entities\Admin();
    $admin->setUsername($username);
    return $admin;
}

function serviceAuditDomain(string $name): \Entities\Domain
{
    $domain = new \Entities\Domain();
    $domain->setDomain($name);
    return $domain;
}

function serviceAuditAssertLog(
    string $label,
    \Entities\Log $log,
    \Entities\Admin $actor,
    string $action,
    string $message,
    ?\Entities\Domain $domain,
    DateTimeInterface $before,
    DateTimeInterface $after,
): void {
    serviceAuditCheck($label . ': exact actor identity', $log->getAdmin() === $actor);
    serviceAuditCheck($label . ': exact action bytes', $log->getAction() === $action);
    serviceAuditCheck($label . ': exact message bytes', $log->getData() === $message);
    serviceAuditCheck($label . ': exact domain identity', $log->getDomain() === $domain);
    $timestamp = $log->getTimestamp();
    serviceAuditCheck(
        $label . ': timestamp is current',
        $timestamp instanceof DateTime
            && $timestamp >= $before
            && $timestamp <= $after,
    );
}

$boundaryActor = serviceAuditAdmin("Åctor \"quoted\" O'Neil@example.test");
$boundaryDomain = serviceAuditDomain("dömäin-\"quoted\"-o'neil.test");
$serviceClasses = [
    'Admin' => ViMbAdmin_Service_Admin::class,
    'Alias' => ViMbAdmin_Service_Alias::class,
    'Mailbox' => ViMbAdmin_Service_Mailbox::class,
];
$constructorCases = [
    'null-empty' => ["ACTION-Ω-\"-'", '', null],
    'present-unicode-quotes' => [
        "ACTIÓN-\"-'雪",
        "Zażółć gęślą jaźń — \"quoted\" and O'Neil 📨",
        $boundaryDomain,
    ],
];

foreach ($serviceClasses as $serviceName => $serviceClass) {
    foreach ($constructorCases as $caseName => [$action, $message, $domain]) {
        $manager = new ServiceAuditLogManager();
        $service = new $serviceClass($manager);
        $before = new DateTimeImmutable();
        serviceAuditInvokeConstructor($service, $boundaryActor, $action, $message, $domain);
        $after = new DateTimeImmutable();
        $log = $manager->lastLog();
        serviceAuditCheck("{$serviceName} {$caseName}: one Log persisted", $log instanceof \Entities\Log);
        serviceAuditCheck("{$serviceName} {$caseName}: one persist total", $manager->persistAttempts === 1);
        serviceAuditCheck("{$serviceName} {$caseName}: persist-only, no flush", $manager->flushes === 0);
        serviceAuditAssertLog(
            "{$serviceName} {$caseName}",
            $log,
            $boundaryActor,
            $action,
            $message,
            $domain,
            $before,
            $after,
        );
    }
}

foreach ($serviceClasses as $serviceName => $serviceClass) {
    $manager = new ServiceAuditLogManager();
    $failure = new RuntimeException("{$serviceName} persist sentinel");
    $manager->persistFailure = $failure;
    $caught = null;
    try {
        serviceAuditInvokeConstructor(
            new $serviceClass($manager),
            $boundaryActor,
            'FAILURE',
            'must not be swallowed',
            $boundaryDomain,
        );
    } catch (Throwable $error) {
        $caught = $error;
    }
    serviceAuditCheck("{$serviceName} persist failure: exact throwable propagates", $caught === $failure);
    serviceAuditCheck("{$serviceName} persist failure: one attempt", $manager->persistAttempts === 1);
    serviceAuditCheck("{$serviceName} persist failure: nothing recorded", $manager->persisted === []);
    serviceAuditCheck("{$serviceName} persist failure: no flush", $manager->flushes === 0);
}

$callerActor = serviceAuditAdmin("Ådmin \"Ops\" O'Neil@example.test");

$adminManager = new ServiceAuditLogManager();
$adminTarget = serviceAuditAdmin("Tårget \"Q\" O'Neil@example.test");
$adminDomain = serviceAuditDomain("münchen-\"quoted\"-o'neil.test");
$before = new DateTimeImmutable();
(new ViMbAdmin_Service_Admin($adminManager))->assignDomain($adminTarget, $adminDomain, $callerActor);
$after = new DateTimeImmutable();
$adminLog = $adminManager->lastLog();
$adminMessage = "Ådmin \"Ops\" O'Neil@example.test added admin Tårget \"Q\" O'Neil@example.test to domain münchen-\"quoted\"-o'neil.test";
serviceAuditCheck('Admin caller: one Log exists', $adminLog instanceof \Entities\Log);
serviceAuditAssertLog(
    'Admin caller',
    $adminLog,
    $callerActor,
    \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD,
    $adminMessage,
    $adminDomain,
    $before,
    $after,
);
serviceAuditCheck(
    'Admin caller: persist then its single flush',
    $adminManager->events === ['persist:Entities\\Log', 'flush'],
);

$aliasManager = new ServiceAuditLogManager();
$alias = new \Entities\Alias();
$aliasAddress = "álïas+\"quote'-雪@example.test";
$alias->setAddress($aliasAddress);
$alias->setGoto('target@example.test');
$aliasEvents = &$aliasManager->events;
$before = new DateTimeImmutable();
(new ViMbAdmin_Service_Alias($aliasManager))->update(
    $alias,
    $callerActor,
    static function () use (&$aliasEvents): void {
        $aliasEvents[] = 'preFlush';
    },
    static function () use (&$aliasEvents): void {
        $aliasEvents[] = 'postFlush';
    },
);
$after = new DateTimeImmutable();
$aliasLog = $aliasManager->lastLog();
serviceAuditCheck('Alias caller: one Log exists', $aliasLog instanceof \Entities\Log);
serviceAuditAssertLog(
    'Alias caller',
    $aliasLog,
    $callerActor,
    \Entities\Log::ACTION_ALIAS_EDIT,
    "Ådmin \"Ops\" O'Neil@example.test edited alias {$aliasAddress}",
    null,
    $before,
    $after,
);
serviceAuditCheck(
    'Alias caller: persist, preFlush, flush, postFlush order',
    $aliasManager->events === ['persist:Entities\\Log', 'preFlush', 'flush', 'postFlush'],
);

$mailboxManager = new ServiceAuditLogManager();
$mailbox = new \Entities\Mailbox();
$mailboxUsername = "máil+\"quote'-雪@example.test";
$mailbox->setUsername($mailboxUsername);
$mailboxEvents = &$mailboxManager->events;
$before = new DateTimeImmutable();
(new ViMbAdmin_Service_Mailbox($mailboxManager))->update(
    $mailbox,
    $callerActor,
    static function () use (&$mailboxEvents): void {
        $mailboxEvents[] = 'preFlush';
    },
    static function () use (&$mailboxEvents): void {
        $mailboxEvents[] = 'postFlush';
    },
);
$after = new DateTimeImmutable();
$mailboxLog = $mailboxManager->lastLog();
serviceAuditCheck('Mailbox caller: one Log exists', $mailboxLog instanceof \Entities\Log);
serviceAuditAssertLog(
    'Mailbox caller',
    $mailboxLog,
    $callerActor,
    \Entities\Log::ACTION_MAILBOX_EDIT,
    "Ådmin \"Ops\" O'Neil@example.test edited mailbox {$mailboxUsername}",
    null,
    $before,
    $after,
);
serviceAuditCheck(
    'Mailbox caller: persist, preFlush, flush, postFlush order',
    $mailboxManager->events === ['persist:Entities\\Log', 'preFlush', 'flush', 'postFlush'],
);

$failingCallers = [
    'Admin' => static function (ServiceAuditLogManager $manager, Throwable $failure): void {
        $manager->persistFailure = $failure;
        (new ViMbAdmin_Service_Admin($manager))->assignDomain(
            serviceAuditAdmin('target@example.test'),
            serviceAuditDomain('domain.example.test'),
            serviceAuditAdmin('actor@example.test'),
        );
    },
    'Alias' => static function (ServiceAuditLogManager $manager, Throwable $failure): void {
        $manager->persistFailure = $failure;
        $alias = new \Entities\Alias();
        $alias->setAddress('alias@example.test');
        $alias->setGoto('target@example.test');
        (new ViMbAdmin_Service_Alias($manager))->update(
            $alias,
            serviceAuditAdmin('actor@example.test'),
            static function (): void {
                throw new RuntimeException('Alias preFlush ran after failed persist');
            },
            static function (): void {
                throw new RuntimeException('Alias postFlush ran after failed persist');
            },
        );
    },
    'Mailbox' => static function (ServiceAuditLogManager $manager, Throwable $failure): void {
        $manager->persistFailure = $failure;
        $mailbox = new \Entities\Mailbox();
        $mailbox->setUsername('mailbox@example.test');
        (new ViMbAdmin_Service_Mailbox($manager))->update(
            $mailbox,
            serviceAuditAdmin('actor@example.test'),
            static function (): void {
                throw new RuntimeException('Mailbox preFlush ran after failed persist');
            },
            static function (): void {
                throw new RuntimeException('Mailbox postFlush ran after failed persist');
            },
        );
    },
];
foreach ($failingCallers as $callerName => $operation) {
    $manager = new ServiceAuditLogManager();
    $failure = new RuntimeException("{$callerName} caller persist sentinel");
    $caught = null;
    try {
        $operation($manager, $failure);
    } catch (Throwable $error) {
        $caught = $error;
    }
    serviceAuditCheck("{$callerName} caller failure: exact throwable", $caught === $failure);
    serviceAuditCheck(
        "{$callerName} caller failure: stops before hook or flush",
        $manager->events === ['persist:Entities\\Log'] && $manager->flushes === 0,
    );
}

if (!\Doctrine\DBAL\Types\Type::hasType(\ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME)) {
    \Doctrine\DBAL\Types\Type::addType(
        \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME,
        \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::class,
    );
}

function serviceAuditEntityManager(): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration(
        [__DIR__ . '/../application/Entities'],
        true,
    );
    if (PHP_VERSION_ID >= 80400) {
        $configuration->enableNativeLazyObjects(true);
    } else {
        $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
        $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'ServiceAuditLogProxies');
    }
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => (string) getenv('DB_HOST'),
        'port' => (int) getenv('DB_PORT'),
        'dbname' => (string) getenv('DB_NAME'),
        'user' => (string) getenv('DB_USER'),
        'password' => (string) getenv('DB_PASS'),
        'charset' => 'utf8mb4',
    ], $configuration);
    return new EntityManager($connection, $configuration);
}

final class ServiceAuditLogPersistFailure
{
    public int $attempts = 0;

    public function __construct(
        private Connection $connection,
        private Throwable $failure,
    ) {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        if (!$event->getObject() instanceof \Entities\Log) {
            return;
        }
        $this->attempts++;
        $this->connection->insert('audit_log_rollback_probe', ['marker' => 'persist-entered']);
        throw $this->failure;
    }
}

$setupManager = serviceAuditEntityManager();
$database = $setupManager->getConnection();
$schemaTool = new SchemaTool($setupManager);
$probeManager = null;
$verificationManager = null;
$listener = null;

try {
    serviceAuditCheck(
        'MariaDB preflight: selected dedicated database',
        $database->fetchOne('SELECT DATABASE()') === $requiredDatabase,
    );
    $metadata = $setupManager->getMetadataFactory()->getAllMetadata();
    serviceAuditCheck('Doctrine preflight: entity metadata discovered', $metadata !== []);
    $schemaTool->dropDatabase();
    $schemaTool->createSchema($metadata);
    $database->executeStatement(
        'CREATE TABLE audit_log_rollback_probe (marker VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB',
    );

    $database->insert('admin', [
        'username' => "db-Åctor-\"quoted\"-o'neil@example.test",
        'password' => 'unused',
        'super' => 1,
        'active' => 1,
        'created' => '2026-01-01 00:00:00',
    ]);
    $actorId = (int) $database->lastInsertId();
    $database->insert('admin', [
        'username' => "db-Tårget-\"quoted\"-o'neil@example.test",
        'password' => 'unused',
        'super' => 0,
        'active' => 1,
        'created' => '2026-01-01 00:00:00',
    ]);
    $targetId = (int) $database->lastInsertId();

    $actor = $setupManager->find(\Entities\Admin::class, $actorId);
    $target = $setupManager->find(\Entities\Admin::class, $targetId);
    serviceAuditCheck('Doctrine success fixture: managed admins loaded', $actor instanceof \Entities\Admin && $target instanceof \Entities\Admin);

    $probeManager = serviceAuditEntityManager();
    $before = new DateTimeImmutable('-1 second');
    $database->beginTransaction();
    $result = (new ViMbAdmin_Service_Admin($setupManager))->toggleActive($target, $actor);
    $after = new DateTimeImmutable('+1 second');
    serviceAuditCheck('Doctrine success: service returns changed state', $result === false);
    serviceAuditCheck('Doctrine success: caller transaction remains active after flush', $database->getTransactionNestingLevel() === 1);
    serviceAuditCheck(
        'Doctrine success: current transaction sees one log',
        serviceAuditDatabaseInteger($database->fetchOne('SELECT COUNT(*) FROM log'), 'log count') === 1,
    );
    serviceAuditCheck(
        'Doctrine success: other connection cannot see uncommitted log',
        serviceAuditDatabaseInteger(
            $probeManager->getConnection()->fetchOne('SELECT COUNT(*) FROM log'),
            'uncommitted log count',
        ) === 0,
    );
    $database->commit();
    serviceAuditCheck(
        'Doctrine success: caller commit publishes log',
        serviceAuditDatabaseInteger(
            $probeManager->getConnection()->fetchOne('SELECT COUNT(*) FROM log'),
            'committed log count',
        ) === 1,
    );
    $row = $probeManager->getConnection()->fetchAssociative(
        'SELECT action, data, Admin_id, Domain_id, timestamp FROM log',
    );
    serviceAuditCheck('Doctrine success: stored Log row is readable', is_array($row));
    $expectedMessage = "db-Åctor-\"quoted\"-o'neil@example.test deactivated admin db-Tårget-\"quoted\"-o'neil@example.test";
    serviceAuditCheck('Doctrine success: exact action bytes stored', ($row['action'] ?? null) === \Entities\Log::ACTION_ADMIN_DEACTIVATE);
    serviceAuditCheck('Doctrine success: exact message bytes stored', ($row['data'] ?? null) === $expectedMessage);
    serviceAuditCheck(
        'Doctrine success: exact actor stored',
        serviceAuditDatabaseInteger($row['Admin_id'] ?? null, 'stored actor id') === $actorId,
    );
    serviceAuditCheck(
        'Doctrine success: null domain stored',
        array_key_exists('Domain_id', $row) && $row['Domain_id'] === null,
    );
    $storedTimestamp = isset($row['timestamp']) && is_string($row['timestamp'])
        ? new DateTimeImmutable($row['timestamp'])
        : null;
    serviceAuditCheck(
        'Doctrine success: current timestamp stored',
        $storedTimestamp instanceof DateTimeImmutable
            && $storedTimestamp >= $before
            && $storedTimestamp <= $after,
    );

    $database->executeStatement('DELETE FROM log');
    $database->executeStatement('DELETE FROM audit_log_rollback_probe');
    $database->update('admin', ['active' => 1], ['id' => $targetId]);
    $setupManager->clear();
    $actor = $setupManager->find(\Entities\Admin::class, $actorId);
    $target = $setupManager->find(\Entities\Admin::class, $targetId);
    serviceAuditCheck('Doctrine failure fixture: managed admins reloaded', $actor instanceof \Entities\Admin && $target instanceof \Entities\Admin);

    $failure = new RuntimeException('real Doctrine persist failure sentinel');
    $listener = new ServiceAuditLogPersistFailure($database, $failure);
    $setupManager->getEventManager()->addEventListener([Events::prePersist], $listener);
    $caught = null;
    $database->beginTransaction();
    try {
        (new ViMbAdmin_Service_Admin($setupManager))->toggleActive($target, $actor);
    } catch (Throwable $error) {
        $caught = $error;
    }
    serviceAuditCheck('Doctrine failure: exact persist throwable propagates', $caught === $failure);
    serviceAuditCheck('Doctrine failure: Log persist reached once', $listener->attempts === 1);
    serviceAuditCheck('Doctrine failure: caller transaction remains active', $database->getTransactionNestingLevel() === 1);
    serviceAuditCheck(
        'Doctrine failure: transaction contains the failure marker before rollback',
        serviceAuditDatabaseInteger(
            $database->fetchOne('SELECT COUNT(*) FROM audit_log_rollback_probe'),
            'transaction marker count',
        ) === 1,
    );
    $database->rollBack();

    $verificationManager = serviceAuditEntityManager();
    $verification = $verificationManager->getConnection();
    serviceAuditCheck(
        'Doctrine rollback: persist marker rolled back',
        serviceAuditDatabaseInteger(
            $verification->fetchOne('SELECT COUNT(*) FROM audit_log_rollback_probe'),
            'rolled-back marker count',
        ) === 0,
    );
    serviceAuditCheck(
        'Doctrine rollback: no Log row escaped',
        serviceAuditDatabaseInteger($verification->fetchOne('SELECT COUNT(*) FROM log'), 'rolled-back log count') === 0,
    );
    serviceAuditCheck(
        'Doctrine rollback: target row stayed active',
        serviceAuditDatabaseInteger(
            $verification->fetchOne('SELECT active FROM admin WHERE id = ?', [$targetId]),
            'target active state',
        ) === 1,
    );
} finally {
    if ($database->isTransactionActive()) {
        $database->rollBack();
    }
    if ($listener instanceof ServiceAuditLogPersistFailure) {
        $setupManager->getEventManager()->removeEventListener([Events::prePersist], $listener);
    }
    $schemaTool->dropDatabase();
    $setupManager->getConnection()->close();
    if ($probeManager instanceof EntityManager) {
        $probeManager->getConnection()->close();
    }
    if ($verificationManager instanceof EntityManager) {
        $verificationManager->getConnection()->close();
    }
}

echo 'ALL PASSED (' . ServiceAuditLogState::$checks . " checks)\n";
