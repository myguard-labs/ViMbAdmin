<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Entities/Domain.php';
require __DIR__ . '/../application/Repositories/Admin.php';
require __DIR__ . '/../application/Repositories/Domain.php';
require __DIR__ . '/../application/Repositories/Log.php';

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\LogController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class LogControllerTestStorage implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

final class LogControllerTestNamespace
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function __get(string $key): mixed { return $this->data[$key] ?? null; }
    public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function __isset(string $key): bool { return isset($this->data[$key]); }
    public function __unset(string $key): void { unset($this->data[$key]); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
}

final class LogControllerTestView
{
    /** @var array<string,mixed> */
    public array $assigned = [];
    public function __set(string $key, mixed $value): void { $this->assigned[$key] = $value; }
    public function render(string $script): string { return $script; }
}

final class LogControllerTestResources
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly mixed $entityManager,
        private readonly LogControllerTestNamespace $session,
        private readonly LogControllerTestView $view,
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

final class LogControllerTestAdmin extends \Entities\Admin
{
    public function __construct(
        private readonly int $testId,
        bool $super,
        private readonly ?\Entities\Domain $manageableDomain = null,
    ) {
        parent::__construct();
        $this->setSuper($super);
        $this->setActive(true);
    }

    public function getId(): int { return $this->testId; }
    public function isSuper(): bool { return (bool) $this->getSuper(); }
    public function canManageDomain(mixed $domain): bool { return $domain === $this->manageableDomain; }
}

final class LogControllerTestDomain extends \Entities\Domain
{
    public function __construct(private readonly int $testId)
    {
        parent::__construct();
    }

    public function getId(): int { return $this->testId; }
}

final class LogControllerTestAdminRepository extends \Repositories\Admin
{
    public function __construct(private readonly ?\Entities\Admin $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(
        mixed $id,
        LockMode|int|null $lockMode = null,
        ?int $lockVersion = null,
    ): ?object {
        return $this->result;
    }
}

final class LogControllerTestDomainRepository extends \Repositories\Domain
{
    public function __construct(private readonly ?\Entities\Domain $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(
        mixed $id,
        LockMode|int|null $lockMode = null,
        ?int $lockVersion = null,
    ): ?object {
        return $this->result;
    }
}

final class LogControllerTestLogRepository extends \Repositories\Log
{
    /** @var list<array{0:\Entities\Admin|null,1:\Entities\Domain|null}> */
    public array $loadCalls = [];
    /** @var list<array{0:\Entities\Admin|null,1:\Entities\Domain|null,2:string,3:bool,4:string,5:string,6:int,7:int}> */
    public array $pageCalls = [];

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{rows:list<array<string,mixed>>,total:int,filtered:int}|null $page
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?array $page = null,
        private readonly ?Throwable $error = null,
    ) {}

    /**
     * @param \Entities\Admin|null $admin
     * @param \Entities\Domain|null $domain
     * @return list<array<string,mixed>>
     */
    public function loadForLogList($admin, $domain = null): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        $this->loadCalls[] = [$admin, $domain];
        return $this->rows;
    }

    /**
     * @param \Entities\Admin|null $admin
     * @param \Entities\Domain|null $domain
     * @return array{rows:list<array<string,mixed>>,total:int,filtered:int}
     */
    public function pagedForLogList(
        $admin,
        $domain,
        string $search,
        bool $contains,
        string $sortField,
        string $sortDir,
        int $start,
        int $length,
    ): array {
        $this->pageCalls[] = [$admin, $domain, $search, $contains, $sortField, $sortDir, $start, $length];
        return $this->page ?? ['rows' => [], 'total' => 0, 'filtered' => 0];
    }
}

/** @extends EntityRepository<object> */
final class LogControllerWrongRepository extends EntityRepository
{
    public function __construct() {}
}

final class LogControllerTestRepositoryFactory implements RepositoryFactory
{
    /** @var array<string,EntityRepository<covariant object>> */
    private array $repositories;

    /** @param array<string,EntityRepository<covariant object>> $repositories */
    public function __construct(array $repositories) { $this->repositories = $repositories; }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return EntityRepository<T>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @SuppressWarnings("PHPMD.MissingImport")
     */
    public function getRepository(EntityManagerInterface $entityManager, string $className): EntityRepository
    {
        $repository = $this->repositories[ltrim($className, '\\')]
            ?? throw new LogicException('Unexpected repository ' . $className);
        /** @var EntityRepository<T> $repository */
        return $repository;
    }
}

/**
 * @param callable(int):?object $loader
 * @param array<string,string|null> $params
 * @param array<string,mixed> $options
 * @SuppressWarnings("PHPMD.MissingImport")
 */
function logController(
    mixed $entityManager,
    LogControllerTestNamespace $namespace,
    LogControllerTestView $view,
    LogControllerTestStorage $authStorage,
    callable $loader,
    array $params = [],
    array $options = [],
): LogController {
    return new LogController(
        new Container(
            new LogControllerTestResources($entityManager, $namespace, $view, $options),
            new Auth($authStorage, $loader),
        ),
        new RouteMatch('log', 'list', LogController::class, 'listAction', $params),
    );
}

/** @return array{defaults:array{server_side:array{pagination:array{log:array{enable:bool}}}}} */
function logClientSideOptions(): array
{
    return ['defaults' => ['server_side' => ['pagination' => ['log' => ['enable' => false]]]]];
}

/**
 * @param array<string,EntityRepository<covariant object>> $repositories
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.MissingImport")
 */
function logEntityManager(array $repositories): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
    $configuration->enableNativeLazyObjects(true);
    $configuration->setRepositoryFactory(new LogControllerTestRepositoryFactory($repositories));
    $connection = DriverManager::getConnection(['driver' => 'pdo_mysql'], $configuration);
    return new EntityManager($connection, $configuration);
}

function redirectEndsWith(\ViMbAdmin\Kernel\Http\Response $response, string $path): bool
{
    return $response->status === 302 && str_ends_with($response->headers['Location'] ?? '', $path);
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};

echo "== native log controller boundaries ==\n";

$emptyLoader = static fn(int $id): ?object => null;
$anonymous = logController(
    new stdClass(),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(),
    $emptyLoader,
);
$check('anonymous list redirects to login without touching Doctrine',
    redirectEndsWith($anonymous->listAction(), '/auth/login'));
$check('anonymous list-data returns ko without touching Doctrine', $anonymous->listDataAction()->body === 'ko');

$self = new LogControllerTestAdmin(7, false);
$selfLog = new LogControllerTestLogRepository([['action' => 'login']]);
$selfView = new LogControllerTestView();
$selfController = logController(
    logEntityManager(['Entities\\Log' => $selfLog]),
    new LogControllerTestNamespace(),
    $selfView,
    new LogControllerTestStorage(['identity' => ['id' => 7]]),
    static fn(int $id): object => $self,
    [],
    logClientSideOptions(),
);
$selfResponse = $selfController->listAction();
$check('non-super list remains scoped to the authenticated admin',
    $selfResponse->body === 'log/list.phtml' && $selfLog->loadCalls === [[$self, null]]);
$check('inline list rows reach the view unchanged',
    $selfView->assigned['logs'] === [['action' => 'login']]);

$target = new LogControllerTestAdmin(8, false);
$super = new LogControllerTestAdmin(1, true);
$targetAdminRepository = new LogControllerTestAdminRepository($target);
$targetLog = new LogControllerTestLogRepository();
$superController = logController(
    logEntityManager(['Entities\\Admin' => $targetAdminRepository, 'Entities\\Log' => $targetLog]),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    ['aid' => '8'],
    logClientSideOptions(),
);
$superController->listAction();
$check('super admin may select another admin scope', $targetLog->loadCalls === [[$target, null]]);

$deniedController = logController(
    logEntityManager(['Entities\\Admin' => new LogControllerTestAdminRepository($target)]),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 7]]),
    static fn(int $id): object => $self,
    ['aid' => '8'],
);
$check('non-super admin cannot select another admin scope',
    redirectEndsWith($deniedController->listAction(), '/auth/login'));

$missingAdminController = logController(
    logEntityManager(['Entities\\Admin' => new LogControllerTestAdminRepository(null)]),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    ['aid' => '999'],
);
$check('missing target admin redirects to the admin list',
    redirectEndsWith($missingAdminController->listAction(), '/admin/list'));

$domain = new LogControllerTestDomain(4);
$domainAdmin = new LogControllerTestAdmin(7, false, $domain);
$domainRepository = new LogControllerTestDomainRepository($domain);
$domainLog = new LogControllerTestLogRepository();
$domainNamespace = new LogControllerTestNamespace();
$domainController = logController(
    logEntityManager(['Entities\\Domain' => $domainRepository, 'Entities\\Log' => $domainLog]),
    $domainNamespace,
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 7]]),
    static fn(int $id): object => $domainAdmin,
    ['did' => '4'],
    logClientSideOptions(),
);
$domainController->listAction();
$check('authorised domain is applied and remembered',
    $domainLog->loadCalls === [[$domainAdmin, $domain]] && $domainNamespace->get('domain') === $domain);

$unauthorisedDomain = new LogControllerTestDomain(5);
$unauthorisedController = logController(
    logEntityManager([
        'Entities\\Domain' => new LogControllerTestDomainRepository($unauthorisedDomain),
        'Entities\\Log' => new LogControllerTestLogRepository(),
    ]),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 7]]),
    static fn(int $id): object => $domainAdmin,
    ['did' => '5'],
);
$check('non-super admin cannot select an unauthorised domain',
    redirectEndsWith($unauthorisedController->listAction(), '/auth/login'));

$missingDomainLog = new LogControllerTestLogRepository();
$missingDomainNamespace = new LogControllerTestNamespace();
$missingDomainController = logController(
    logEntityManager([
        'Entities\\Domain' => new LogControllerTestDomainRepository(null),
        'Entities\\Log' => $missingDomainLog,
    ]),
    $missingDomainNamespace,
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    ['did' => '404'],
    logClientSideOptions(),
);
$missingDomainController->listAction();
$check('missing domain leaves the list unfiltered and is not remembered',
    $missingDomainLog->loadCalls === [[null, null]] && !$missingDomainNamespace->has('domain'));

$rememberedLog = new LogControllerTestLogRepository();
$rememberedController = logController(
    logEntityManager(['Entities\\Log' => $rememberedLog]),
    new LogControllerTestNamespace(['domain' => $domain]),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    [],
    logClientSideOptions(),
);
$rememberedController->listAction();
$check('remembered session domain is reused without a repository lookup',
    $rememberedLog->loadCalls === [[null, $domain]]);

$unsetNamespace = new LogControllerTestNamespace(['domain' => $domain]);
$unsetLog = new LogControllerTestLogRepository();
$unsetController = logController(
    logEntityManager(['Entities\\Log' => $unsetLog]),
    $unsetNamespace,
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    ['unset' => '1'],
    logClientSideOptions(),
);
$unsetController->listAction();
$check('unset clears the remembered domain before loading',
    !$unsetNamespace->has('domain') && $unsetLog->loadCalls === [[null, null]]);

$pagedLog = new LogControllerTestLogRepository([], [
    'rows' => [['timestamp' => new DateTimeImmutable('2026-08-31 12:34:56'), 'action' => 'edit']],
    'total' => 9,
    'filtered' => 1,
]);
$_GET = [
    'draw' => '3',
    'start' => '5',
    'length' => '25',
    'search' => ['value' => ' edit '],
    'order' => [['column' => '3', 'dir' => 'desc']],
];
$pagedController = logController(
    logEntityManager(['Entities\\Log' => $pagedLog]),
    new LogControllerTestNamespace(['domain' => $domain]),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
);
$pagedResponse = $pagedController->listDataAction();
$pagedBody = json_decode($pagedResponse->body, true);
$pagedRows = is_array($pagedBody) && isset($pagedBody['data']) && is_array($pagedBody['data'])
    ? $pagedBody['data']
    : [];
$firstPagedRow = $pagedRows[0] ?? null;
// This request is domain-scoped, so log/list.phtml does NOT render the Domain
// column and order[0][column] = 3 is the "Occurred At" column -- which is also the
// index log/js/list.js sets as the initial order in exactly this case. The map
// must therefore answer 'timestamp' here, not 'domain'.
$check('list-data preserves scoped pagination and sorting arguments',
    $pagedLog->pageCalls === [[null, $domain, 'edit', false, 'timestamp', 'DESC', 5, 25]]);
$check('list-data returns counts and formats timestamps',
    is_array($pagedBody)
        && $pagedBody['draw'] === 3
        && $pagedBody['recordsTotal'] === 9
        && is_array($firstPagedRow)
        && ($firstPagedRow['timestamp'] ?? null) === '2026-08-31 12:34:56');

// A leading '*' is the contains toggle: the controller must forward the STRIPPED
// term plus the flag. Passing $q->search here instead of $q->searchTerm would bind
// '%*acme%' and silently match nothing, and every non-starred case in this file
// cannot tell the two properties apart.
$starredLog = new LogControllerTestLogRepository([], ['rows' => [], 'total' => 9, 'filtered' => 0]);
$_GET['search']['value'] = ' *acme ';
$starredController = logController(
    logEntityManager(['Entities\\Log' => $starredLog]),
    new LogControllerTestNamespace(['domain' => $domain]),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
);
$starredController->listDataAction();
$check('list-data forwards the stripped term and the contains flag for a starred search',
    $starredLog->pageCalls === [[null, $domain, 'acme', true, 'timestamp', 'DESC', 5, 25]]);

$_GET['search']['value'] = 'abc';
foreach ([
    'list-specific override' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 2,
        'log' => ['enable' => true, 'min_search_str' => 4],
    ]]]],
    'shared fallback' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 4,
        'log' => ['enable' => true],
    ]]]],
    'default fallback' => [],
] as $case => $options) {
    $shortSearchLog = new LogControllerTestLogRepository();
    $response = logController(
        logEntityManager(['Entities\\Log' => $shortSearchLog]),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
        [],
        $options,
    )->listDataAction();
    $expectedStatus = $case === 'default fallback' ? 200 : 400;
    $check("list-data enforces {$case} search minimum argument",
        $response->status === $expectedStatus
            && ($expectedStatus === 200 || $response->body === 'Search must be empty or at least 4 characters')
            && ($expectedStatus === 400 ? $shortSearchLog->pageCalls === [] : $shortSearchLog->pageCalls !== []));
}

$zeroMinimumLog = new LogControllerTestLogRepository();
$zeroResponse = logController(
    logEntityManager(['Entities\\Log' => $zeroMinimumLog]),
    new LogControllerTestNamespace(),
    new LogControllerTestView(),
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    [],
    ['defaults' => ['server_side' => ['pagination' => ['min_search_str' => 0]]]],
)->listDataAction();
$check('list-data zero minimum explicitly permits short searches',
    $zeroResponse->status === 200 && ($zeroMinimumLog->pageCalls[0][2] ?? null) === 'abc');

$malformedMinimumRejected = false;
try {
    logController(
        logEntityManager(['Entities\\Log' => new LogControllerTestLogRepository()]),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
        [],
        ['defaults' => ['server_side' => ['pagination' => ['min_search_str' => ['4']]]]],
    )->listDataAction();
} catch (\TypeError $e) {
    $malformedMinimumRejected = $e->getMessage() === 'min_search_str must be a non-negative integer';
}
$check('list-data malformed minimum configuration fails closed before repository access',
    $malformedMinimumRejected);
$_GET = [];

$serverSideLog = new LogControllerTestLogRepository([['action' => 'must-not-load']]);
$serverSideView = new LogControllerTestView();
logController(
    logEntityManager(['Entities\\Log' => $serverSideLog]),
    new LogControllerTestNamespace(),
    $serverSideView,
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
    [],
    ['defaults' => ['server_side' => ['pagination' => ['log' => ['enable' => true]]]]],
)->listAction();
$check('server-side list renders without materialising all log rows',
    $serverSideLog->loadCalls === [] && $serverSideView->assigned['logs'] === []);

$missingPaginationConfigLog = new LogControllerTestLogRepository([['action' => 'must-not-load']]);
$missingPaginationConfigView = new LogControllerTestView();
logController(
    logEntityManager(['Entities\\Log' => $missingPaginationConfigLog]),
    new LogControllerTestNamespace(),
    $missingPaginationConfigView,
    new LogControllerTestStorage(['identity' => ['id' => 1]]),
    static fn(int $id): object => $super,
)->listAction();
$check('missing log pagination config defaults to server-side list',
    $missingPaginationConfigLog->loadCalls === []
    && ($missingPaginationConfigView->assigned['logs'] ?? null) === []);

$malformedDomainRejected = false;
try {
    logController(
        logEntityManager([]),
        new LogControllerTestNamespace(['domain' => 'invalid']),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
    )->listAction();
} catch (LogicException $e) {
    $malformedDomainRejected = $e->getMessage() === 'Stored domain has an invalid type';
}
$check('malformed remembered domain fails closed', $malformedDomainRejected);

$invalidAdminRejected = false;
try {
    logController(
        new stdClass(),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 7]]),
        static fn(int $id): object => new stdClass(),
        [],
        logClientSideOptions(),
    )->listAction();
} catch (LogicException $e) {
    $invalidAdminRejected = $e->getMessage() === 'Authenticated admin has an invalid type';
}
$check('malformed authenticated admin fails closed', $invalidAdminRejected);

$invalidManagerRejected = false;
try {
    logController(
        new stdClass(),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
        [],
        logClientSideOptions(),
    )->listAction();
} catch (LogicException $e) {
    $invalidManagerRejected = $e->getMessage() === 'Doctrine entity manager resource has an invalid type';
}
$check('malformed entity manager fails closed', $invalidManagerRejected);

$wrongRepositoryRejected = false;
try {
    logController(
        logEntityManager(['Entities\\Log' => new LogControllerWrongRepository()]),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
        [],
        logClientSideOptions(),
    )->listAction();
} catch (LogicException $e) {
    $wrongRepositoryRejected = $e->getMessage() === 'Log repository has an invalid type';
}
$check('unexpected log repository fails closed', $wrongRepositoryRejected);

$repositoryError = new RuntimeException('log lookup failed');
$errorPropagated = false;
try {
    logController(
        logEntityManager(['Entities\\Log' => new LogControllerTestLogRepository([], null, $repositoryError)]),
        new LogControllerTestNamespace(),
        new LogControllerTestView(),
        new LogControllerTestStorage(['identity' => ['id' => 1]]),
        static fn(int $id): object => $super,
        [],
        logClientSideOptions(),
    )->listAction();
} catch (RuntimeException $e) {
    $errorPropagated = $e === $repositoryError;
}
$check('repository errors propagate unchanged', $errorPropagated);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all log controller assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
