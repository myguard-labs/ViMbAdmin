<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../application/Repositories/Alias.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AliasController;
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ControllerAliasIdentitySession implements SessionStorage
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

final class ControllerAliasIdentityView
{
    public int $renders = 0;
    public function __set(string $key, mixed $value): void {}
    public function render(string $script): string
    {
        $this->renders++;
        return $script;
    }
}

final class ControllerAliasIdentityResources
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControllerAliasIdentitySession $session,
        private readonly ControllerAliasIdentityView $view,
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

final class ControllerAliasIdentityAdmin extends \Entities\Admin
{
    public function __construct()
    {
        parent::__construct();
        // Set the backing field too, not just the getter override: audit-row
        // formatting goes through requiredUsername(), so a fixture without it
        // turns any mutation that reaches logAlias() into a fatal instead of a
        // named assertion failure.
        $this->setUsername('admin@example.test');
    }

    public function getId(): int { return 1; }
    public function getUsername(): string { return 'admin@example.test'; }
    public function getSuper(): bool { return true; }
    public function getActive(): bool { return true; }
    public function isSuper(): bool { return true; }
}

/**
 * A domain admin (not super) who administers exactly the domains handed to it.
 * Used to prove `mid`/`alid` scope checks, which are no-ops for a super admin.
 */
final class ControllerAliasIdentityScopedAdmin extends \Entities\Admin
{
    /** @param list<\Entities\Domain> $managed */
    public function __construct(private readonly array $managed = [])
    {
        parent::__construct();
        $this->setUsername('domainadmin@example.test');
    }

    public function getId(): int { return 2; }
    public function getUsername(): string { return 'domainadmin@example.test'; }
    public function getSuper(): bool { return false; }
    public function getActive(): bool { return true; }
    public function isSuper(): bool { return false; }

    /** @param \Entities\Domain $domain */
    public function canManageDomain($domain): bool
    {
        foreach ($this->managed as $d) {
            if ($d->requiredId() === $domain->requiredId()) {
                return true;
            }
        }

        return false;
    }
}

final class ControllerAliasIdentityAliasRepository extends \Repositories\Alias
{
    public int $identityLookups = 0;

    /** @param list<\Entities\Alias> $aliases */
    public function __construct(private readonly array $aliases) {}

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->aliases[0] ?? null;
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<\Entities\Alias>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->aliases;
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $this->identityLookups++;
        return $this->aliases[0] ?? null;
    }
}

final class ControllerAliasIdentityMailboxRepository extends \Repositories\Mailbox
{
    public bool $purged = false;
    public int $listLookups = 0;

    public function __construct(private readonly ?\Entities\Mailbox $mailbox) {}

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->mailbox;
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<\Entities\Mailbox>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->listLookups++;
        return $this->mailbox === null ? [] : [$this->mailbox];
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->mailbox;
    }

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function purgeMailbox($mailbox, $admin, $removeMailbox = true)
    {
        $domain = $mailbox->getDomain();
        if ($domain === null) {
            throw new LogicException('Mailbox domain cannot be null.');
        }

        $this->purged = true;
        $domain->decreaseMailboxCount();
        return true;
    }
}

final class ControllerAliasIdentityPagedMailboxRepository extends \Repositories\Mailbox
{
    /** @param list<mixed> $mailboxes */
    public function __construct(private readonly array $mailboxes) {}

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<mixed>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if ($orderBy !== ['username' => 'ASC']) {
            throw new LogicException('Expected mailbox username ascending order.');
        }
        $rows = $this->mailboxes;
        usort($rows, static function(mixed $left, mixed $right): int {
            if (!$left instanceof \Entities\Mailbox || !$right instanceof \Entities\Mailbox) { return 0; }
            return strcmp($left->requiredUsername(), $right->requiredUsername());
        });
        return array_slice($rows, $offset ?? 0, $limit);
    }
}

final class ControllerAliasIdentityDomainRepository extends \Repositories\Domain
{
    public function __construct(private readonly ?\Entities\Domain $domain) {}

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->domain;
    }
}

final class ControllerAliasIdentityRepositoryFactory implements RepositoryFactory
{
    /** @var array<string,EntityRepository<covariant object>> */
    private array $repositories;

    /** @param array<string,EntityRepository<covariant object>> $repositories */
    public function __construct(array $repositories) { $this->repositories = $repositories; }

    /**
     * @template T of object
     * @param class-string<T> $entityName
     * @return EntityRepository<T>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $repository = $this->repositories[ltrim($entityName, '\\')]
            ?? throw new LogicException('Unexpected repository ' . $entityName);
        /** @var EntityRepository<T> $repository */
        return $repository;
    }
}

/** @param array<string,EntityRepository<covariant object>> $repositories */
function controllerAliasIdentityEntityManager(array $repositories): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
    $configuration->enableNativeLazyObjects(true);
    $configuration->setRepositoryFactory(new ControllerAliasIdentityRepositoryFactory($repositories));
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    return new EntityManager($connection, $configuration);
}

/** @param array<string,mixed> $options */
function controllerAliasIdentityContainer(
    EntityManager $entityManager,
    ControllerAliasIdentitySession $session,
    ControllerAliasIdentityView $view,
    array $options = [],
): Container {
    $admin = new ControllerAliasIdentityAdmin();
    return new Container(
        new ControllerAliasIdentityResources($entityManager, $session, $view, $options),
        new Auth($session, static fn(int $id): object => $admin),
    );
}

/**
 * Entity manager that records persists and skips the real flush, so a
 * successful controller path can be asserted without ORM mapping metadata.
 */
final class ControllerAliasIdentityRecordingEntityManager extends EntityManagerDecorator
{
    /** @var list<object> */
    public array $persisted = [];
    /** @var list<object> */
    public array $removed = [];
    public int $flushes = 0;

    /** @param array<string,EntityRepository<covariant object>> $repositories */
    public function __construct(array $repositories)
    {
        parent::__construct(controllerAliasIdentityEntityManager($repositories));
    }

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
}

/** Container bound to an arbitrary admin identity (default: the super admin). */
function controllerAliasIdentityContainerFor(
    EntityManagerInterface $entityManager,
    ControllerAliasIdentitySession $session,
    ControllerAliasIdentityView $view,
    \Entities\Admin $admin,
): Container {
    return new Container(
        new ControllerAliasIdentityResources($entityManager, $session, $view, []),
        new Auth($session, static fn(int $id): object => $admin),
    );
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpAliases(McpController $controller, array $params): array
{
    $method = new ReflectionMethod($controller, '_aliasesList');
    $result = $method->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP aliases result is not an array');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP aliases result has a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpMailboxes(McpController $controller, array $params): array
{
    $method = new ReflectionMethod($controller, '_mailboxesList');
    $result = $method->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP mailboxes result is not an array');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP mailboxes result has a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpDelete(McpController $controller, array $params): array
{
    $result = (new ReflectionMethod($controller, '_mailboxDelete'))->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP mailbox delete result is not an array');
    }

    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP mailbox delete result has a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

/** @param array<string,mixed> $params */
function controllerAliasIdentityMcpInvoke(McpController $controller, string $method, array $params): mixed
{
    return (new ReflectionMethod($controller, $method))->invoke($controller, $params);
}

final class ControllerAliasIdentityState
{
    public static int $failures = 0;
}

function controllerAliasIdentityCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { ControllerAliasIdentityState::$failures++; }
}

echo "== controller alias identity boundaries ==\n";

$domain = (new \Entities\Domain())->setDomain('example.test')->setAliasCount(4);
$gotoMissing = (new \Entities\Alias())->setAddress('sales@example.test')->setDomain($domain);
$aliasSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$aliasView = new ControllerAliasIdentityView();
$aliasEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$gotoMissing]),
]);
$aliasController = new AliasController(
    controllerAliasIdentityContainer($aliasEntityManager, $aliasSession, $aliasView),
    new RouteMatch('alias', 'edit', AliasController::class, 'editAction', ['alid' => '7']),
);
$storedDestination = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
$parsedDestinations = (new ReflectionMethod(AliasController::class, 'parseGotos'))
    ->invoke($aliasController, $storedDestination);
controllerAliasIdentityCheck(
    'alias form accepts the exact RFC-valid markup-like destination',
    $parsedDestinations === [[$storedDestination], null],
);
$canonicalDestinations = (new ReflectionMethod(AliasController::class, 'parseGotos'))
    ->invoke($aliasController, " User@Example.TEST \n ADMIN@Example.TEST ");
controllerAliasIdentityCheck(
    'alias web form preserves lowercase and surrounding-whitespace normalization',
    $canonicalDestinations === [['user@example.test', 'admin@example.test'], null],
);
$aliasError = null;
try {
    $aliasController->editAction();
} catch (LogicException $e) {
    $aliasError = $e->getMessage();
}
controllerAliasIdentityCheck('alias edit rejects missing goto before rendering',
    $aliasError === 'Alias goto cannot be null.' && $aliasView->renders === 0);
controllerAliasIdentityCheck('alias edit identity failure leaves state and flash queue untouched',
    $gotoMissing->getGoto() === null && $aliasSession->values() === ['identity' => ['id' => 1]]);

// mailbox delete-alias is POST-only with a body CSRF token (VIM-D05).
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf'] = 'test-token';

$addressMissing = (new \Entities\Alias())->setGoto('user@example.test')->setDomain($domain);
$mailbox = (new \Entities\Mailbox())->setUsername('user@example.test')->setDomain($domain);
$mailboxSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$mailboxView = new ControllerAliasIdentityView();
$mailboxEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$addressMissing]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$mailboxController = new MailboxController(
    controllerAliasIdentityContainer($mailboxEntityManager, $mailboxSession, $mailboxView),
    new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
        'mid' => '3',
        'alid' => '7',
    ]),
);
$mailboxError = null;
try {
    $mailboxController->deleteAliasAction();
} catch (Throwable $e) {
    $mailboxError = $e->getMessage();
}
controllerAliasIdentityCheck('mailbox delete rejects missing address before mutation',
    $mailboxError === 'Alias address cannot be null.'
        && $addressMissing->getGoto() === 'user@example.test'
        && $domain->getAliasCount() === 4
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $mailboxSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

$invalidMidController = new MailboxController(
    controllerAliasIdentityContainer($mailboxEntityManager, $mailboxSession, $mailboxView),
    new RouteMatch('mailbox', 'add', MailboxController::class, 'addAction', ['mid' => '7junk']),
);
$invalidMidResponse = $invalidMidController->addAction();
controllerAliasIdentityCheck('malformed add-with-mid fails closed before auth, lookup, or mutation',
    $invalidMidResponse->status === 302
        && ($invalidMidResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $mailboxSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

$orphanAlias = (new \Entities\Alias())
    ->setAddress('orphan@example.test')
    ->setGoto('user@example.test');
$orphanSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$orphanEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$orphanAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$orphanController = new MailboxController(
    controllerAliasIdentityContainer($orphanEntityManager, $orphanSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
        'mid' => '3',
        'alid' => '7',
    ]),
);
$orphanResponse = $orphanController->deleteAliasAction();
controllerAliasIdentityCheck('mailbox delete fails closed on a missing alias domain before mutation',
    $orphanResponse->status === 302
        && ($orphanResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $orphanAlias->getGoto() === 'user@example.test'
        && $domain->getAliasCount() === 4
        && $orphanEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $orphanEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $orphanSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

// A persisted mailbox without its required domain must never reach
// authorisation context construction or mutation.
$orphanMailbox = (new \Entities\Mailbox())->setUsername('orphan@example.test');
$wrongSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$wrongView = new ControllerAliasIdentityView();
$wrongMailboxRepository = new ControllerAliasIdentityMailboxRepository($orphanMailbox);
$wrongEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => $wrongMailboxRepository,
]);
$wrongContainer = controllerAliasIdentityContainer($wrongEntityManager, $wrongSession, $wrongView);
$wrongActions = [
    'ajax-toggle-active' => ['ajaxToggleActiveAction', [], 'ko'],
    'purge' => ['purgeAction', ['csrf' => 'test-token'], '/mailbox/list'],
    'password' => ['passwordAction', [], '/mailbox/list'],
    'queue-repair' => ['queueRepairAction', ['csrf' => 'test-token'], '/mailbox/list'],
    'email-settings' => ['emailSettingsAction', [], 'error'],
];
foreach ($wrongActions as $route => [$method, $extra, $expected]) {
    $controller = new MailboxController(
        $wrongContainer,
        new RouteMatch('mailbox', $route, MailboxController::class, $method, ['mid' => '3'] + $extra),
    );
    $response = $controller->{$method}();
    $actual = $response->status === 302 ? ($response->headers['Location'] ?? null) : $response->body;
    controllerAliasIdentityCheck("{$route} rejects an orphan mailbox without writes",
        $actual === $expected
            && $wrongEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
            && $wrongEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);
}

$rememberedSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'domain' => new stdClass(),
]);
$rememberedEntityManager = controllerAliasIdentityEntityManager([]);
$rememberedController = new MailboxController(
    controllerAliasIdentityContainer($rememberedEntityManager, $rememberedSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'list', MailboxController::class, 'listAction', []),
);
$rememberedResponse = $rememberedController->listAction();
controllerAliasIdentityCheck('list removes malformed remembered domain before fail-closed redirect',
    $rememberedResponse->status === 302
        && ($rememberedResponse->headers['Location'] ?? null) === '/auth/login'
        && !$rememberedSession->has('domain'));

$invalidDidSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$invalidDidEntityManager = controllerAliasIdentityEntityManager([]);
$invalidDidController = new MailboxController(
    controllerAliasIdentityContainer($invalidDidEntityManager, $invalidDidSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'list', MailboxController::class, 'listAction', ['did' => '7junk']),
);
$invalidDidResponse = $invalidDidController->listAction();
controllerAliasIdentityCheck('present malformed list domain cannot widen into an unfiltered list',
    $invalidDidResponse->status === 302
        && ($invalidDidResponse->headers['Location'] ?? null) === '/auth/login'
        && $invalidDidEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []);

$oldGet = $_GET;
$_GET = ['draw' => ['2']];
$listDataController = new MailboxController(
    $wrongContainer,
    new RouteMatch('mailbox', 'list-data', MailboxController::class, 'listDataAction', []),
);
$listLookupsBefore = $wrongMailboxRepository->listLookups;
$listDataResponse = $listDataController->listDataAction();
$_GET = $oldGet;
controllerAliasIdentityCheck('DataTables container input returns 400 before repository access',
    $listDataResponse->status === 400
        && $listDataResponse->body === 'Invalid DataTables request'
        && $wrongMailboxRepository->listLookups === $listLookupsBefore
        && $wrongEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []);

$_GET = ['search' => ['value' => 'abc']];
$searchFloorOptions = ['defaults' => ['server_side' => ['pagination' => ['min_search_str' => 4]]]];
foreach ([
    'alias' => new AliasController(
        controllerAliasIdentityContainer($wrongEntityManager, new ControllerAliasIdentitySession(['identity' => ['id' => 1]]), new ControllerAliasIdentityView(), $searchFloorOptions),
        new RouteMatch('alias', 'list-data', AliasController::class, 'listDataAction', []),
    ),
    'mailbox' => new MailboxController(
        controllerAliasIdentityContainer($wrongEntityManager, new ControllerAliasIdentitySession(['identity' => ['id' => 1]]), new ControllerAliasIdentityView(), $searchFloorOptions),
        new RouteMatch('mailbox', 'list-data', MailboxController::class, 'listDataAction', []),
    ),
] as $name => $searchFloorController) {
    $response = $searchFloorController->listDataAction();
    controllerAliasIdentityCheck("{$name} list-data enforces the shared search minimum argument",
        $response->status === 400
            && $response->body === 'Search must be empty or at least 4 characters');
}
$_GET = $oldGet;

$mailbox->setAltEmail(null);
$formSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$formEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$oldServer = $_SERVER;
$oldPost = $_POST;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'test-token', 'type' => ['username'], 'email' => ['attacker@example.test']];
$formController = new MailboxController(
    controllerAliasIdentityContainer($formEntityManager, $formSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'email-settings', MailboxController::class, 'emailSettingsAction', [
        'mid' => '3',
        'send' => '1',
    ]),
);
$formResponse = $formController->emailSettingsAction();
$_SERVER = $oldServer;
$_POST = $oldPost;
controllerAliasIdentityCheck('email settings container input safely re-renders without Array disclosure or writes',
    $formResponse->status === 200
        && !str_contains($formResponse->body, 'Array')
        && $formEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $formEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$badQueueSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$badQueueEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$badQueueController = new MailboxController(
    controllerAliasIdentityContainer(
        $badQueueEntityManager,
        $badQueueSession,
        new ControllerAliasIdentityView(),
        ['queue' => ['runner' => ['key' => ['bad']]]],
    ),
    new RouteMatch('mailbox', 'queue-repair', MailboxController::class, 'queueRepairAction', [
        'mid' => '3',
        'csrf' => 'test-token',
    ]),
);
$badQueueResponse = $badQueueController->queueRepairAction();
controllerAliasIdentityCheck('malformed present queue key is rejected before enqueue or flush',
    $badQueueResponse->status === 302
        && ($badQueueResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $badQueueEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $badQueueEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$validAlias = (new \Entities\Alias())
    ->setAddress('sales@example.test')
    ->setGoto('user@example.test')
    ->setActive(true)
    ->setDomain($domain);
$mcpSession = new ControllerAliasIdentitySession();
$mcpView = new ControllerAliasIdentityView();
$mcpEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$validAlias]),
]);
$mcpController = new McpController(
    new Container(
        new ControllerAliasIdentityResources($mcpEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$mcpResult = controllerAliasIdentityMcpAliases($mcpController, ['domain' => 'example.test']);
controllerAliasIdentityCheck('MCP alias list emits required string identities',
    $mcpResult === [
        'domain' => 'example.test',
        'aliases' => [[
            'address' => 'sales@example.test',
            'goto' => 'user@example.test',
            'active' => true,
        ]],
    ]);

$pagedMailboxes = [];
foreach (['z@example.test', 'a@example.test', 'm@example.test'] as $username) {
    $pagedMailboxes[] = (new \Entities\Mailbox())->setUsername($username)->setDomain($domain)->setActive(true);
}
$pagedMcpEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Mailbox' => new ControllerAliasIdentityPagedMailboxRepository($pagedMailboxes),
]);
$pagedMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($pagedMcpEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$pageOne = controllerAliasIdentityMcpMailboxes(
    $pagedMcp,
    ['domain' => 'example.test', 'limit' => 2],
);
$pageTwo = controllerAliasIdentityMcpMailboxes(
    $pagedMcp,
    ['domain' => 'example.test', 'limit' => 2, 'offset' => 2],
);
$pageOneRows = $pageOne['mailboxes'] ?? null;
$pageTwoRows = $pageTwo['mailboxes'] ?? null;
if (!is_array($pageOneRows) || !is_array($pageTwoRows)) {
    throw new RuntimeException('MCP mailbox page rows have an invalid shape');
}
$pagedUsernames = array_column(array_merge($pageOneRows, $pageTwoRows), 'username');
controllerAliasIdentityCheck('MCP mailbox pages are bounded, complete and deterministically ordered',
    count($pageOneRows) === 2
        && count($pageTwoRows) === 1
        && $pagedUsernames === ['a@example.test', 'm@example.test', 'z@example.test']);

foreach ([
    ['limit' => 0],
    ['limit' => 201],
    ['offset' => 10001],
    ['limit' => '1.5'],
] as $invalidBounds) {
    $boundsRejected = false;
    try {
        controllerAliasIdentityMcpInvoke($pagedMcp, '_listBounds', $invalidBounds);
    } catch (ViMbAdmin_Mcp_Exception|LogicException $e) {
        $boundsRejected = true;
    }
    controllerAliasIdentityCheck('MCP rejects invalid or oversized list bounds ' . json_encode($invalidBounds), $boundsRejected);
}

$malformedPageEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Mailbox' => new ControllerAliasIdentityPagedMailboxRepository([new stdClass()]),
]);
$malformedPageMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($malformedPageEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$malformedHydrationRejected = false;
try {
    controllerAliasIdentityMcpMailboxes($malformedPageMcp, ['domain' => 'example.test']);
} catch (UnexpectedValueException $e) {
    $malformedHydrationRejected = str_contains($e->getMessage(), 'MCP mailbox list query');
}
controllerAliasIdentityCheck('MCP list hydration rejects non-mailbox repository rows', $malformedHydrationRejected);

$crossDomainAliasRepository = new ControllerAliasIdentityAliasRepository([]);
$crossDomainAliasManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Alias' => $crossDomainAliasRepository,
]);
$crossDomainAliasMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($crossDomainAliasManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$crossDomainAliasError = null;
try {
    controllerAliasIdentityMcpInvoke($crossDomainAliasMcp, '_aliasCreate', [
        'domain' => 'example.test',
        'address' => 'sales@other.test',
        'goto' => 'user@example.test',
    ]);
} catch (ViMbAdmin_Mcp_Exception $e) {
    $crossDomainAliasError = $e->getMessage();
}
controllerAliasIdentityCheck('MCP alias create rejects a cross-domain address before lookup or mutation',
    $crossDomainAliasError === 'address domain must match the authorized domain'
        && $crossDomainAliasRepository->identityLookups === 0
        && $crossDomainAliasManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $crossDomainAliasManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$orphanAlias = (new \Entities\Alias())
    ->setAddress('orphan@other.test')
    ->setGoto('user@other.test');
$orphanAliasRepository = new ControllerAliasIdentityAliasRepository([$orphanAlias]);
$orphanAliasManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => $orphanAliasRepository,
]);
$orphanAliasMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($orphanAliasManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$orphanAliasError = null;
try {
    controllerAliasIdentityMcpInvoke($orphanAliasMcp, '_aliasDelete', ['address' => 'orphan@other.test']);
} catch (ViMbAdmin_Mcp_Exception $e) {
    $orphanAliasError = $e->getMessage();
}
controllerAliasIdentityCheck('MCP alias delete fails closed on an orphan relation before mutation',
    $orphanAliasError === 'unknown alias'
        && $orphanAliasRepository->identityLookups === 1
        && $orphanAliasManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $orphanAliasManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$deleteDomain = (new \Entities\Domain())->setDomain('delete.example.test')->setMailboxCount(3);
$deleteMailbox = (new \Entities\Mailbox())
    ->setUsername('user@delete.example.test')
    ->setDomain($deleteDomain);
$deleteRepository = new ControllerAliasIdentityMailboxRepository($deleteMailbox);
$deleteEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => $deleteRepository,
]);
$deleteMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($deleteEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$deleteResult = controllerAliasIdentityMcpDelete($deleteMcp, ['username' => 'user@delete.example.test']);
controllerAliasIdentityCheck('MCP mailbox delete delegates the counter exactly once',
    $deleteResult === ['deleted' => true, 'username' => 'user@delete.example.test']
        && $deleteRepository->purged
        && $deleteDomain->getMailboxCount() === 2);

$orphanMailbox = (new \Entities\Mailbox())->setUsername('orphan@other.test');
$orphanMailboxRepository = new ControllerAliasIdentityMailboxRepository($orphanMailbox);
$orphanMailboxManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => $orphanMailboxRepository,
]);
$orphanMailboxMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($orphanMailboxManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$orphanMailboxError = null;
try {
    controllerAliasIdentityMcpDelete($orphanMailboxMcp, ['username' => 'orphan@other.test']);
} catch (ViMbAdmin_Mcp_Exception $e) {
    $orphanMailboxError = $e->getMessage();
}
controllerAliasIdentityCheck('MCP mailbox delete fails closed on an orphan relation before purge',
    $orphanMailboxError === 'unknown mailbox'
        && !$orphanMailboxRepository->purged
        && $orphanMailboxManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $orphanMailboxManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$malformedMcpAlias = (new \Entities\Alias())->setAddress('broken@example.test')->setDomain($domain);
$malformedMcpEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$malformedMcpAlias]),
]);
$malformedMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($malformedMcpEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$mcpError = null;
ob_start();
try {
    controllerAliasIdentityMcpAliases($malformedMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $mcpError = $e->getMessage();
}
$mcpOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects missing goto without partial output',
    $mcpError === 'Alias goto cannot be null.' && $mcpOutput === '');

$unnamedDomain = new \Entities\Domain();
$unnamedDomainEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($unnamedDomain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([]),
]);
$unnamedDomainMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($unnamedDomainEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$unnamedDomainError = null;
ob_start();
try {
    controllerAliasIdentityMcpAliases($unnamedDomainMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $unnamedDomainError = $e->getMessage();
}
$unnamedDomainOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects a null domain name without output or mutation',
    $unnamedDomainError === 'Domain name cannot be null.'
        && $unnamedDomainOutput === ''
        && $unnamedDomainEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $unnamedDomainEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$unnamedMailbox = (new \Entities\Mailbox())->setDomain($domain);
$unnamedMailboxEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($unnamedMailbox),
]);
$unnamedMailboxMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($unnamedMailboxEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$unnamedMailboxError = null;
ob_start();
try {
    controllerAliasIdentityMcpMailboxes($unnamedMailboxMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $unnamedMailboxError = $e->getMessage();
}
$unnamedMailboxOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects a null mailbox username without output or mutation',
    $unnamedMailboxError === 'Mailbox username cannot be null.'
        && $unnamedMailboxOutput === ''
        && $unnamedMailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $unnamedMailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$emailSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$emailView = new ControllerAliasIdentityView();
$emailEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($unnamedMailbox),
]);
$emailController = new MailboxController(
    controllerAliasIdentityContainer($emailEntityManager, $emailSession, $emailView),
    new RouteMatch('mailbox', 'email-settings', MailboxController::class, 'emailSettingsAction', ['mid' => '3']),
);
$emailError = null;
try {
    $emailController->emailSettingsAction();
} catch (LogicException $e) {
    $emailError = $e->getMessage();
}
controllerAliasIdentityCheck('mail settings reject a null username before render or mutation',
    $emailError === 'Mailbox username cannot be null.'
        && $emailView->renders === 0
        && $emailEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $emailEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

// VIM-D03: `deleteAliasAction` scope-checks BOTH ids. A domain admin passing a
// `mid` for a mailbox outside their domains must be indistinguishable from
// "no such mailbox" and must not mutate the alias or write an audit row.
$scopeIdent = static function(\Entities\Domain $domain, int $id): \Entities\Domain {
    (new ReflectionProperty(\Entities\Domain::class, 'id'))->setValue($domain, $id);
    return $domain;
};

$ownedDomain   = $scopeIdent((new \Entities\Domain())->setDomain('owned.test')->setAliasCount(4), 10);
$foreignDomain = $scopeIdent((new \Entities\Domain())->setDomain('foreign.test')->setAliasCount(9), 20);
$scopedAdmin   = new ControllerAliasIdentityScopedAdmin([$ownedDomain]);

$scopeBaseSession = ['identity' => ['id' => 2], 'csrfToken' => 'test-token'];
$scopeRoute = new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
    'mid' => '3',
    'alid' => '7',
    'csrf' => 'test-token',
]);

// (a) The VIM-D03 attack, and the case where the two guards diverge: the alias
// sits in a domain the admin DOES administer (so the pre-fix alias-only
// authorisation passed), while `mid` points at a mailbox in a domain they do
// not. Pre-fix, this trimmed the foreign mailbox's address out of the alias and
// wrote an ALIAS_DELETE audit row for a destination that was never on it.
//
// This is what pins `$domain = $mailbox->getDomain()` specifically: reverting
// that line alone re-authorises against the alias's (owned) domain, and because
// the alias-domain guard then compares that same object against itself it also
// passes, so the foreign mailbox is trimmed. Note the mirror shape — mailbox
// and alias BOTH in the foreign domain — cannot discriminate the two guards,
// since `canManageDomain` rejects it either way.
$foreignMailbox = (new \Entities\Mailbox())->setUsername('victim@foreign.test')->setDomain($foreignDomain);
$foreignAlias   = (new \Entities\Alias())
    ->setAddress('team@owned.test')
    ->setGoto('victim@foreign.test,other@owned.test')
    ->setDomain($ownedDomain);
$foreignSession = new ControllerAliasIdentitySession($scopeBaseSession);
$foreignEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$foreignAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($foreignMailbox),
]);
$foreignResponse = (new MailboxController(
    controllerAliasIdentityContainerFor($foreignEm, $foreignSession, new ControllerAliasIdentityView(), $scopedAdmin),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias refuses a mid outside the admin\'s domains without mutation or audit row',
    $foreignResponse->status === 302
        && ($foreignResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $foreignAlias->getGoto() === 'victim@foreign.test,other@owned.test'
        && $ownedDomain->getAliasCount() === 4
        && $foreignEm->persisted === []
        && $foreignEm->removed === []
        && $foreignEm->flushes === 0
        && $foreignSession->values() === $scopeBaseSession);

// (b) The alias-side guard: the mailbox is in a domain the admin manages, but
// the alias is not. This is what the alias authorisation defends — an admin
// must not detach a mailbox from an alias whose domain is outside their scope.
$ownedMailbox = (new \Entities\Mailbox())->setUsername('user@owned.test')->setDomain($ownedDomain);
$crossAlias   = (new \Entities\Alias())
    ->setAddress('team@foreign.test')
    ->setGoto('user@owned.test,other@foreign.test')
    ->setDomain($foreignDomain);
$crossSession = new ControllerAliasIdentitySession($scopeBaseSession);
$crossEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$crossAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($ownedMailbox),
]);
$crossResponse = (new MailboxController(
    controllerAliasIdentityContainerFor($crossEm, $crossSession, new ControllerAliasIdentityView(), $scopedAdmin),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias refuses an alias whose domain the admin does not manage',
    $crossResponse->status === 302
        && ($crossResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $crossAlias->getGoto() === 'user@owned.test,other@foreign.test'
        && $crossEm->persisted === []
        && $crossEm->removed === []
        && $crossEm->flushes === 0
        && $crossSession->values() === $scopeBaseSession);

// (b2) The supported cross-domain flow, and the regression this guard must not
// break: an admin who manages BOTH domains detaches a mailbox in one from an
// alias in the other. `Repositories\Alias::loadWithMailbox()` lists exactly
// this row and application/views/mailbox/aliases.phtml renders a delete link
// for it, so it must succeed — trimming the destination and writing the audit
// row. Requiring alias.Domain == mailbox.Domain would silently no-op it.
$multiDomainAdmin = new ControllerAliasIdentityScopedAdmin([$ownedDomain, $foreignDomain]);
$crossOkMailbox = (new \Entities\Mailbox())->setUsername('user@foreign.test')->setDomain($foreignDomain);
$crossOkAlias   = (new \Entities\Alias())
    ->setAddress('team@owned.test')
    ->setGoto('user@foreign.test,other@owned.test')
    ->setDomain($ownedDomain);
$crossOkSession = new ControllerAliasIdentitySession($scopeBaseSession);
$crossOkEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$crossOkAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($crossOkMailbox),
]);
$crossOkResponse = (new MailboxController(
    controllerAliasIdentityContainerFor(
        $crossOkEm,
        $crossOkSession,
        new ControllerAliasIdentityView(),
        $multiDomainAdmin,
    ),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias still detaches a mailbox from a cross-domain alias it manages',
    $crossOkResponse->status === 302
        && str_starts_with((string) ($crossOkResponse->headers['Location'] ?? ''), '/mailbox/aliases/mid/')
        && $crossOkAlias->getGoto() === 'other@owned.test'
        && count($crossOkEm->persisted) === 1
        && $crossOkEm->persisted[0] instanceof \Entities\Log
        && $crossOkEm->removed === []
        && $crossOkEm->flushes === 1);

// (b3) Whole-alias removal across domains: the alias's ONLY goto is this
// mailbox, so it is deleted outright and a domain's alias counter is
// decremented. The counter belongs to the ALIAS's domain — decrementing the
// mailbox's domain instead leaves the alias's domain overcounted and silently
// corrupts an unrelated domain's total. Case (b2) cannot catch this: its alias
// has two gotos, so it takes the trim branch and touches no counter.
// Fresh domains per case: this is the only block that really decrements a
// counter, so sharing $ownedDomain with the earlier cases would make case (a)'s
// hard-coded count assertion fail if anyone reorders these blocks -- a failure
// that points at the wrong line.
$countOwned   = $scopeIdent((new \Entities\Domain())->setDomain('owned.test')->setAliasCount(4), 10);
$countForeign = $scopeIdent((new \Entities\Domain())->setDomain('foreign.test')->setAliasCount(9), 20);
$countAdmin = new ControllerAliasIdentityScopedAdmin([$countOwned, $countForeign]);
$countMailbox = (new \Entities\Mailbox())->setUsername('solo@foreign.test')->setDomain($countForeign);
$countAlias   = (new \Entities\Alias())
    ->setAddress('solo-alias@owned.test')
    ->setGoto('solo@foreign.test')
    ->setDomain($countOwned);
$ownedCountBefore   = $countOwned->getAliasCount();
$foreignCountBefore = $countForeign->getAliasCount();
$countSession = new ControllerAliasIdentitySession($scopeBaseSession);
$countEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$countAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($countMailbox),
]);
$countResponse = (new MailboxController(
    controllerAliasIdentityContainerFor(
        $countEm,
        $countSession,
        new ControllerAliasIdentityView(),
        $countAdmin,
    ),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias decrements the alias domain, not the mailbox domain',
    $countResponse->status === 302
        && str_starts_with((string) ($countResponse->headers['Location'] ?? ''), '/mailbox/aliases/mid/')
        && $countEm->removed === [$countAlias]
        && $countOwned->getAliasCount() === $ownedCountBefore - 1
        && $countForeign->getAliasCount() === $foreignCountBefore
        && count($countEm->persisted) === 1
        && $countEm->persisted[0] instanceof \Entities\Log
        && $countEm->flushes === 1);

// (c) The rejection for a foreign mid is byte-identical to the rejection for a
// mailbox that does not exist at all: probing `mid` leaks nothing.
$missingSession = new ControllerAliasIdentitySession($scopeBaseSession);
$missingEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$foreignAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository(null),
]);
$missingResponse = (new MailboxController(
    controllerAliasIdentityContainerFor($missingEm, $missingSession, new ControllerAliasIdentityView(), $scopedAdmin),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias rejection is indistinguishable from a non-existent mailbox',
    $missingResponse->status === $foreignResponse->status
        && $missingResponse->headers === $foreignResponse->headers
        && $missingResponse->body === $foreignResponse->body
        && $missingSession->values() === $foreignSession->values());

// (d) In-scope deletion is unchanged: the destination is trimmed, an audit row
// is written, and the redirect goes back to the mailbox's alias list.
$inScopeAlias = (new \Entities\Alias())
    ->setAddress('team@owned.test')
    ->setGoto('user@owned.test,other@owned.test')
    ->setDomain($ownedDomain);
$inScopeSession = new ControllerAliasIdentitySession($scopeBaseSession);
$inScopeEm = new ControllerAliasIdentityRecordingEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$inScopeAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($ownedMailbox),
]);
$inScopeResponse = (new MailboxController(
    controllerAliasIdentityContainerFor($inScopeEm, $inScopeSession, new ControllerAliasIdentityView(), $scopedAdmin),
    $scopeRoute,
))->deleteAliasAction();
controllerAliasIdentityCheck('delete-alias still trims a destination and audits it for an in-scope mailbox',
    $inScopeResponse->status === 302
        && str_starts_with((string) ($inScopeResponse->headers['Location'] ?? ''), '/mailbox/aliases/mid/')
        && $inScopeAlias->getGoto() === 'other@owned.test'
        && count($inScopeEm->persisted) === 1
        && $inScopeEm->persisted[0] instanceof \Entities\Log
        && $inScopeEm->removed === []
        && $inScopeEm->flushes === 1);

echo ControllerAliasIdentityState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . ControllerAliasIdentityState::$failures . " FAILED\n";
exit(ControllerAliasIdentityState::$failures === 0 ? 0 : 1);
