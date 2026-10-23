<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

final class MaintenanceMcpParityState
{
    public int $bodyReads = 0;
    public int $authCalls = 0;
    public int $rateCalls = 0;
    public int $handlerCalls = 0;
    /** @var list<string> */
    public array $events = [];
    public ?ViMbAdmin_Mcp_Exception $authError = null;
    public ?ViMbAdmin_Mcp_Exception $rateError = null;
}

final class MaintenanceMcpParityAssertions
{
    public static int $failures = 0;
}

/** Doctrine fixture used to exercise the real mutation-operation closures. */
/** @extends \Doctrine\ORM\EntityRepository<Entities\Domain> */
final class MaintenanceMcpMutationDomainRepository extends \Doctrine\ORM\EntityRepository
{
    public ?Entities\Domain $existing = null;

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, array|null $orderBy = null): ?object
    {
        unset($orderBy);
        return $this->existing?->requiredDomainName() === ($criteria['domain'] ?? null)
            ? $this->existing
            : null;
    }
}

final class MaintenanceMcpMutationMailboxRepository extends \Repositories\Mailbox
{
    public int $uniqueChecks = 0;

    public function isUnique($email): bool
    {
        $this->uniqueChecks++;
        return $email === 'created@mailbox.test';
    }
}

final class MaintenanceMcpMutationRepositoryFactory implements \Doctrine\ORM\Repository\RepositoryFactory
{
    public ?MaintenanceMcpMutationMailboxRepository $mailboxes = null;

    public function __construct(private readonly Entities\Domain $existingDomain)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $entityName
     * @return \Doctrine\ORM\EntityRepository<T>
     */
    public function getRepository(
        \Doctrine\ORM\EntityManagerInterface $entityManager,
        string $entityName,
    ): \Doctrine\ORM\EntityRepository {
        if (ltrim($entityName, '\\') === Entities\Domain::class) {
            $repository = new MaintenanceMcpMutationDomainRepository(
                $entityManager,
                new \Doctrine\ORM\Mapping\ClassMetadata(Entities\Domain::class),
            );
            $repository->existing = $this->existingDomain;
            return $repository;
        }
        if (ltrim($entityName, '\\') === Entities\Mailbox::class) {
            if ($this->mailboxes === null) {
                $this->mailboxes = new MaintenanceMcpMutationMailboxRepository(
                    $entityManager,
                    new \Doctrine\ORM\Mapping\ClassMetadata(Entities\Mailbox::class),
                );
            }
            return $this->mailboxes;
        }
        throw new LogicException('Unexpected mutation fixture repository ' . $entityName);
    }
}

final class MaintenanceMcpMutationManager extends \Doctrine\ORM\EntityManager
{
    public int $repositoryCalls = 0;
    public int $persists = 0;
    public int $commits = 0;
    public bool $rejectFlush = false;
    public readonly Entities\Domain $existingDomain;
    public readonly MaintenanceMcpMutationRepositoryFactory $repositories;
    public ?Entities\Mailbox $persistedMailbox = null;

    public function __construct()
    {
        $this->existingDomain = (new Entities\Domain())
            ->setDomain('mailbox.test')
            ->setActive(true)
            ->setTransport('virtual')
            ->setQuota(1024)
            ->setMaxQuota(4096)
            ->setMaxMailboxes(10)
            ->setMailboxCount(0)
            ->setAliasCount(0);
        $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([]);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
            $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
        }
        $this->repositories = new MaintenanceMcpMutationRepositoryFactory($this->existingDomain);
        $configuration->setRepositoryFactory($this->repositories);
        parent::__construct(
            \Doctrine\DBAL\DriverManager::getConnection(
                ['driver' => 'pdo_mysql', 'serverVersion' => '8.0'],
                $configuration,
            ),
            $configuration,
        );
    }

    public function getRepository(string $className): \Doctrine\ORM\EntityRepository
    {
        $this->repositoryCalls++;
        return parent::getRepository($className);
    }

    public function persist(object $entity): void
    {
        if ($entity instanceof Entities\Domain) {
            $this->persists++;
            (new ReflectionMethod($entity, 'assignGeneratedId'))->invoke($entity, $this->persists);
            return;
        }
        if ($entity instanceof Entities\Mailbox) {
            $this->persists++;
            $this->persistedMailbox = $entity;
            return;
        }
        throw new LogicException('Unexpected mutation fixture entity');
    }

    public function flush(): void
    {
        if ($this->rejectFlush) {
            throw new ViMbAdmin_Mcp_DomainException('fixture mutation rolled back');
        }
        $this->commits++;
    }
}

function maintenanceMcpParityCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MaintenanceMcpParityAssertions::$failures++;
    }
}

/**
 * @return array<string,array{
 *     handler:\Closure(array<string,mixed>):array<string,mixed>,
 *     scope:'read'|'write',
 *     destructive:bool
 * }>
 */
function maintenanceMcpParityMethods(MaintenanceMcpParityState $state): array
{
    $handler = static function (string $name) use ($state): Closure {
        return static function (array $params) use ($state, $name): array {
            $state->events[] = 'handler:' . $name;
            $state->handlerCalls++;
            if ($name === 'invalid') {
                throw new ViMbAdmin_Mcp_Exception('invalid fixture params');
            }
            if ($name === 'conflict') {
                throw new ViMbAdmin_Mcp_DomainException('fixture state conflict');
            }
            if ($name === 'explode') {
                throw new RuntimeException('fixture exploded');
            }
            return [ 'method' => $name, 'params' => $params ];
        };
    };

    return [
        'ping' => [ 'handler' => $handler('ping'), 'scope' => 'read', 'destructive' => false ],
        'danger' => [ 'handler' => $handler('danger'), 'scope' => 'write', 'destructive' => true ],
        'invalid' => [ 'handler' => $handler('invalid'), 'scope' => 'write', 'destructive' => false ],
        'conflict' => [ 'handler' => $handler('conflict'), 'scope' => 'write', 'destructive' => false ],
        'explode' => [ 'handler' => $handler('explode'), 'scope' => 'read', 'destructive' => false ],
    ];
}

/**
 * @return array<string,array{
 *     handler:\Closure(array<string,mixed>):array<string,mixed>,
 *     scope:'write',
 *     destructive:bool
 * }>
 */
function maintenanceMcpRealMutationMethods(
    MaintenanceMcpParityState $state,
    ViMbAdmin_Mcp_MutationOperations $operations,
): array {
    /**
     * @param \Closure(array<string,mixed>):array<string,mixed> $operation
     * @return \Closure(array<string,mixed>):array<string,mixed>
     */
    $handler = static function (string $name, Closure $operation) use ($state): Closure {
        return static function (array $params) use ($state, $name, $operation): array {
            $state->events[] = 'handler:' . $name;
            $state->handlerCalls++;
            return $operation($params);
        };
    };

    return [
        'domain.create' => [ 'handler' => $handler('domain.create', $operations->domainCreate(...)), 'scope' => 'write', 'destructive' => false ],
        'domain.delete' => [ 'handler' => $handler('domain.delete', $operations->domainDelete(...)), 'scope' => 'write', 'destructive' => true ],
        'mailbox.create' => [ 'handler' => $handler('mailbox.create', $operations->mailboxCreate(...)), 'scope' => 'write', 'destructive' => false ],
        'mailbox.delete' => [ 'handler' => $handler('mailbox.delete', $operations->mailboxDelete(...)), 'scope' => 'write', 'destructive' => true ],
        'alias.create' => [ 'handler' => $handler('alias.create', $operations->aliasCreate(...)), 'scope' => 'write', 'destructive' => false ],
        'alias.delete' => [ 'handler' => $handler('alias.delete', $operations->aliasDelete(...)), 'scope' => 'write', 'destructive' => false ],
        'mailbox.archive' => [ 'handler' => $handler('mailbox.archive', $operations->mailboxArchive(...)), 'scope' => 'write', 'destructive' => true ],
        'archive.restore' => [ 'handler' => $handler('archive.restore', $operations->archiveRestore(...)), 'scope' => 'write', 'destructive' => true ],
        'archive.delete' => [ 'handler' => $handler('archive.delete', $operations->archiveDelete(...)), 'scope' => 'write', 'destructive' => true ],
    ];
}

/**
 * @param array<string,array{
 *     handler:\Closure(array<string,mixed>):array<string,mixed>,
 *     scope:'read'|'write',
 *     destructive:bool
 * }>|null $methods
 */
function maintenanceMcpParityDispatcher(
    MaintenanceMcpParityState $state,
    ?array $methods = null,
): ViMbAdmin_Mcp_Dispatcher {
    $token = (new Entities\McpToken())
        ->setName('dispatcher fixture')
        ->setTokenHash(hash('sha256', 'dispatcher_fixture_token'))
        ->setScope('read write')
        ->setCreated(new DateTime());

    return new ViMbAdmin_Mcp_Dispatcher(
        $methods ?? maintenanceMcpParityMethods($state),
        static function (array $server, string $scope) use ($state, $token): Entities\McpToken {
            unset($server);
            $state->events[] = 'auth:' . $scope;
            $state->authCalls++;
            if ($state->authError !== null) {
                throw $state->authError;
            }
            return $token;
        },
        static function (Entities\McpToken $authenticated) use ($state, $token): void {
            if ($authenticated !== $token) {
                throw new LogicException('rate-limit fixture received the wrong token');
            }
            $state->events[] = 'rate';
            $state->rateCalls++;
            if ($state->rateError !== null) {
                throw $state->rateError;
            }
        },
    );
}

/** @return Closure():(string|false) */
function maintenanceMcpParityBody(MaintenanceMcpParityState $state, string|false $body): Closure
{
    return static function () use ($state, $body): string|false {
        $state->bodyReads++;
        $state->events[] = 'body';
        return $body;
    };
}

/** @param array<string,mixed> $params */
function maintenanceMcpParityRequest(string $method, string|int $id, array $params = []): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => (object) $params,
    ], JSON_THROW_ON_ERROR);
}

/** @return array<string,mixed> */
function maintenanceMcpParityJson(\ViMbAdmin\Kernel\Http\Response $response): array
{
    $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('MCP fixture response is not a JSON object');
    }
    $stringMap = [];
    foreach ($body as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP fixture response has a non-string key');
        }
        $stringMap[$key] = $value;
    }
    return $stringMap;
}

/** @return array{code:int,message:string} */
function maintenanceMcpParityError(\ViMbAdmin\Kernel\Http\Response $response): array
{
    $body = maintenanceMcpParityJson($response);
    $error = $body['error'] ?? null;
    if (!is_array($error) || !is_int($error['code'] ?? null) || !is_string($error['message'] ?? null)) {
        throw new RuntimeException('MCP fixture response has no valid error object');
    }
    return [ 'code' => $error['code'], 'message' => $error['message'] ];
}

final class MaintenanceMcpParityDomain extends Entities\Domain
{
    public function __construct(private readonly int $fixtureId, string $name)
    {
        parent::__construct();
        $this->setDomain($name)
            ->setActive(true)
            ->setTransport('virtual')
            ->setQuota(1024)
            ->setMaxQuota(4096)
            ->setMailboxCount(2)
            ->setAliasCount(3);
    }

    public function requiredId(): int
    {
        return $this->fixtureId;
    }
}

final class MaintenanceMcpParityDomainQuery
{
    private int $offset = 0;
    private ?int $limit = null;
    /** @var list<string>|null */
    private ?array $allowed = null;

    /** @param list<Entities\Domain> $domains */
    public function __construct(private readonly array $domains)
    {
    }

    public function orderBy(string $field, string $direction): self
    {
        if ($field !== 'd.domain' || $direction !== 'ASC') {
            throw new LogicException('Domain fixture requires ascending domain order.');
        }
        return $this;
    }

    public function setFirstResult(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function setMaxResults(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function andWhere(string $expression): self
    {
        if ($expression !== 'LOWER(d.domain) IN (:allowed)') {
            throw new LogicException('Unexpected domain fixture filter.');
        }
        return $this;
    }

    /** @param list<string> $allowed */
    public function setParameter(string $name, array $allowed): self
    {
        if ($name !== 'allowed') {
            throw new LogicException('Unexpected domain fixture parameter.');
        }
        $this->allowed = $allowed;
        return $this;
    }

    public function getQuery(): self
    {
        return $this;
    }

    /** @return list<Entities\Domain> */
    public function getResult(): array
    {
        $domains = $this->domains;
        usort($domains, static fn (Entities\Domain $left, Entities\Domain $right): int =>
            strcmp($left->requiredDomainName(), $right->requiredDomainName()));
        if ($this->allowed !== null) {
            $domains = array_values(array_filter(
                $domains,
                fn (Entities\Domain $domain): bool => in_array(
                    ViMbAdmin_Identity::canonical($domain->requiredDomainName()),
                    $this->allowed,
                    true,
                ),
            ));
        }
        return array_slice($domains, $this->offset, $this->limit);
    }
}

final class MaintenanceMcpParityDomainRepository
{
    /** @param list<Entities\Domain> $domains */
    public function __construct(private readonly array $domains)
    {
    }

    public function createQueryBuilder(string $alias): MaintenanceMcpParityDomainQuery
    {
        if ($alias !== 'd') {
            throw new LogicException('Unexpected domain fixture alias.');
        }
        return new MaintenanceMcpParityDomainQuery($this->domains);
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria): ?Entities\Domain
    {
        foreach ($this->domains as $domain) {
            if ($domain->requiredDomainName() === ($criteria['domain'] ?? null)) {
                return $domain;
            }
        }
        return null;
    }
}

final class MaintenanceMcpParityListRepository
{
    public int $findCalls = 0;

    /** @param list<Entities\Mailbox>|list<Entities\Alias> $rows */
    public function __construct(private readonly array $rows, private readonly string $orderField)
    {
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string> $orderBy
     * @return list<Entities\Mailbox>|list<Entities\Alias>
     */
    public function findBy(array $criteria, array $orderBy, int $limit, int $offset): array
    {
        $this->findCalls++;
        if (!($criteria['Domain'] ?? null) instanceof Entities\Domain
            || $orderBy !== [$this->orderField => 'ASC']) {
            throw new LogicException('Unexpected list fixture query.');
        }
        $rows = $this->rows;
        usort($rows, function (object $left, object $right): int {
            $leftValue = $left instanceof Entities\Mailbox ? $left->requiredUsername() : $left->getAddress();
            $rightValue = $right instanceof Entities\Mailbox ? $right->requiredUsername() : $right->getAddress();
            return strcmp((string) $leftValue, (string) $rightValue);
        });
        return array_slice($rows, $offset, $limit);
    }
}

final class MaintenanceMcpParityEntityManager
{
    public function __construct(
        public readonly MaintenanceMcpParityDomainRepository $domains,
        public readonly MaintenanceMcpParityListRepository $mailboxes,
        public readonly MaintenanceMcpParityListRepository $aliases,
    ) {
    }

    public function getRepository(string $class): object
    {
        return match (ltrim($class, '\\')) {
            Entities\Domain::class => $this->domains,
            Entities\Mailbox::class => $this->mailboxes,
            Entities\Alias::class => $this->aliases,
            default => throw new LogicException('Unexpected fixture repository ' . $class),
        };
    }
}

function maintenanceMcpParityMailbox(Entities\Domain $domain, string $localPart, bool $active, int $quota): Entities\Mailbox
{
    return (new Entities\Mailbox())
        ->setDomain($domain)
        ->setUsername($localPart . '@' . $domain->requiredDomainName())
        ->setLocalPart($localPart)
        ->setName(ucfirst($localPart))
        ->setActive($active)
        ->setQuota($quota);
}

function maintenanceMcpParityAlias(Entities\Domain $domain, string $localPart, string $goto, bool $active): Entities\Alias
{
    return (new Entities\Alias())
        ->setDomain($domain)
        ->setAddress($localPart . '@' . $domain->requiredDomainName())
        ->setGoto($goto)
        ->setActive($active);
}

echo "== maintenance MCP request dispatcher parity ==\n";

$state = new MaintenanceMcpParityState();
$dispatcher = maintenanceMcpParityDispatcher($state);
$request = maintenanceMcpParityRequest('ping', 'request-1');

$disabled = $dispatcher->dispatch(false, 'POST', maintenanceMcpParityBody($state, $request), []);
$disabledBody = maintenanceMcpParityJson($disabled);
maintenanceMcpParityCheck(
    'disabled MCP returns the existing 404 transport error without reading a body',
    $disabled->status === 404
        && array_key_exists('id', $disabledBody)
        && $disabledBody['id'] === null
        && maintenanceMcpParityError($disabled) === [ 'code' => -32000, 'message' => 'mcp disabled' ]
        && $state->bodyReads === 0
        && $state->authCalls === 0
        && $state->handlerCalls === 0,
);

$methodRejected = $dispatcher->dispatch(true, ['POST'], maintenanceMcpParityBody($state, $request), []);
maintenanceMcpParityCheck(
    'non-string HTTP methods retain the 405 response before body parsing',
    $methodRejected->status === 405
        && maintenanceMcpParityError($methodRejected) === [ 'code' => -32000, 'message' => 'POST required' ]
        && $state->bodyReads === 0,
);

$parseRejected = $dispatcher->dispatch(true, 'POST', maintenanceMcpParityBody($state, '{'), []);
$parseBody = maintenanceMcpParityJson($parseRejected);
maintenanceMcpParityCheck(
    'malformed JSON retains parse-error mapping and cannot reach authentication',
    $parseRejected->status === 200
        && array_key_exists('id', $parseBody)
        && $parseBody['id'] === null
        && maintenanceMcpParityError($parseRejected) === [ 'code' => -32700, 'message' => 'parse error' ]
        && $state->authCalls === 0,
);

$unknown = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('unknown', 'unknown-id')),
    [],
);
$unknownBody = maintenanceMcpParityJson($unknown);
maintenanceMcpParityCheck(
    'unknown RPC methods preserve their request id and fail before authentication',
    $unknown->status === 200
        && ($unknownBody['id'] ?? null) === 'unknown-id'
        && maintenanceMcpParityError($unknown) === [ 'code' => -32601, 'message' => "unknown method 'unknown'" ]
        && $state->authCalls === 0,
);

$eventOffset = count($state->events);
$success = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('ping', 7, ['value' => 'ok'])),
    [],
);
$successBody = maintenanceMcpParityJson($success);
maintenanceMcpParityCheck(
    'successful non-destructive requests authenticate then dispatch with the same id',
    $success->status === 200
        && ($successBody['id'] ?? null) === 7
        && ($successBody['result'] ?? null) === [ 'method' => 'ping', 'params' => ['value' => 'ok'] ]
        && array_slice($state->events, $eventOffset) === ['body', 'auth:read', 'handler:ping']
        && $state->rateCalls === 0,
);

$eventOffset = count($state->events);
$beforeHandlers = $state->handlerCalls;
$state->authError = new ViMbAdmin_Mcp_Exception('fixture bearer rejected', 401);
$authRejected = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('ping', 'auth-id')),
    [],
);
$authBody = maintenanceMcpParityJson($authRejected);
maintenanceMcpParityCheck(
    'authentication rejection preserves HTTP status, challenge, message and request id',
    $authRejected->status === 401
        && $authRejected->headers === [ 'WWW-Authenticate' => 'Bearer' ]
        && ($authBody['id'] ?? null) === 'auth-id'
        && maintenanceMcpParityError($authRejected) === [ 'code' => -32000, 'message' => 'fixture bearer rejected' ]
        && array_slice($state->events, $eventOffset) === ['body', 'auth:read']
        && $state->handlerCalls === $beforeHandlers,
);
$state->authError = null;

$eventOffset = count($state->events);
$beforeHandlers = $state->handlerCalls;
$state->rateError = new ViMbAdmin_Mcp_Exception('fixture rate rejected', 429);
$rateRejected = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('danger', 'rate-id')),
    [],
);
$rateBody = maintenanceMcpParityJson($rateRejected);
maintenanceMcpParityCheck(
    'rate rejection preserves HTTP status, message and request id',
    $rateRejected->status === 429
        && ($rateBody['id'] ?? null) === 'rate-id'
        && maintenanceMcpParityError($rateRejected) === [ 'code' => -32000, 'message' => 'fixture rate rejected' ],
);
maintenanceMcpParityCheck(
    'handler cannot run before authentication and rate guards',
    array_slice($state->events, $eventOffset) === ['body', 'auth:write', 'rate']
        && $state->handlerCalls === $beforeHandlers,
);
$state->rateError = null;

$invalid = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('invalid', 'invalid-id')),
    [],
);
$invalidBody = maintenanceMcpParityJson($invalid);
maintenanceMcpParityCheck(
    'handler input failures retain invalid-params mapping and request id',
    $invalid->status === 200
        && ($invalidBody['id'] ?? null) === 'invalid-id'
        && maintenanceMcpParityError($invalid) === [ 'code' => -32602, 'message' => 'invalid fixture params' ],
);

$conflict = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('conflict', 'conflict-id')),
    [],
);
$conflictBody = maintenanceMcpParityJson($conflict);
maintenanceMcpParityCheck(
    'domain conflicts retain server-error mapping and request id',
    $conflict->status === 200
        && ($conflictBody['id'] ?? null) === 'conflict-id'
        && maintenanceMcpParityError($conflict) === [ 'code' => -32010, 'message' => 'fixture state conflict' ],
);

$internal = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('explode', 'internal-id')),
    [],
);
$internalBody = maintenanceMcpParityJson($internal);
maintenanceMcpParityCheck(
    'unexpected handler failures retain internal-error mapping and request id',
    $internal->status === 200
        && ($internalBody['id'] ?? null) === 'internal-id'
        && maintenanceMcpParityError($internal) === [ 'code' => -32603, 'message' => 'internal error' ],
);

echo "\n== maintenance MCP mutation dispatch parity ==\n";
$mutationState = new MaintenanceMcpParityState();
$mutationManager = new MaintenanceMcpMutationManager();
$mutationOperations = new ViMbAdmin_Mcp_MutationOperations(
    $mutationManager,
    [
        'virtual' => ['defaults' => ['domain' => ['transport' => 'virtual']]],
        'defaults' => [
            'domain' => ['quota' => 0, 'maxquota' => 0, 'mailboxes' => 0, 'aliases' => 0],
            'mailbox' => ['password_scheme' => 'crypt:sha512'],
            'quota' => ['multiplier' => OSS_Filter_FileSize::SIZE_KILOBYTES],
        ],
        'mailboxAliases' => false,
    ],
    null,
);
$mutationDefinitions = [
    'domain.create' => false,
    'domain.delete' => true,
    'mailbox.create' => false,
    'mailbox.delete' => true,
    'alias.create' => false,
    'alias.delete' => false,
    'mailbox.archive' => true,
    'archive.restore' => true,
    'archive.delete' => true,
];
$mutationMethods = maintenanceMcpRealMutationMethods($mutationState, $mutationOperations);
$mutationDispatcher = maintenanceMcpParityDispatcher($mutationState, $mutationMethods);

$eventOffset = count($mutationState->events);
$domainCreated = $mutationDispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody(
        $mutationState,
        maintenanceMcpParityRequest('domain.create', 'domain.create-id', ['domain' => 'created.test']),
    ),
    [],
);
$domainCreatedBody = maintenanceMcpParityJson($domainCreated);
maintenanceMcpParityCheck(
    'real domain.create mutation persists and preserves result/id mapping',
    $domainCreated->status === 200
        && ($domainCreatedBody['id'] ?? null) === 'domain.create-id'
        && ($domainCreatedBody['result'] ?? null) === [
            'created' => true,
            'domain' => 'created.test',
            'id' => 1,
        ]
        && array_slice($mutationState->events, $eventOffset) === [
            'body', 'auth:write', 'handler:domain.create',
        ]
        && $mutationManager->persists === 1
        && $mutationManager->commits === 1,
);
maintenanceMcpParityCheck(
    'negative control: a real mutation regression cannot pass the routing fixture',
    $mutationManager->repositoryCalls === 1
        && $mutationManager->persists === 1
        && $mutationManager->commits === 1,
);

$eventOffset = count($mutationState->events);
$persistOffset = $mutationManager->persists;
$commitOffset = $mutationManager->commits;
$mailboxRepository = $mutationManager->getRepository(Entities\Mailbox::class);
$uniqueCheckOffset = $mailboxRepository instanceof MaintenanceMcpMutationMailboxRepository
    ? $mailboxRepository->uniqueChecks
    : -1;
$mailboxCreated = $mutationDispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody(
        $mutationState,
        maintenanceMcpParityRequest('mailbox.create', 'mailbox.create-id', [
            'domain' => 'mailbox.test',
            'local_part' => 'created',
            'password' => 'exact mailbox password',
            'active' => false,
            'name' => 'Created mailbox',
            'quota' => 512,
        ]),
    ),
    [],
);
$mailboxCreatedBody = maintenanceMcpParityJson($mailboxCreated);
$persistedMailbox = $mutationManager->persistedMailbox;
maintenanceMcpParityCheck(
    'real mailbox.create mutation dispatches, applies policy, persists and preserves result/id mapping',
    $mailboxCreated->status === 200
        && ($mailboxCreatedBody['id'] ?? null) === 'mailbox.create-id'
        && ($mailboxCreatedBody['result'] ?? null) === [
            'created' => true,
            'username' => 'created@mailbox.test',
        ]
        && array_slice($mutationState->events, $eventOffset) === [
            'body', 'auth:write', 'handler:mailbox.create',
        ]
        && $persistedMailbox instanceof Entities\Mailbox
        && $persistedMailbox->getDomain() === $mutationManager->existingDomain
        && $persistedMailbox->requiredUsername() === 'created@mailbox.test'
        && $persistedMailbox->getName() === 'Created mailbox'
        && $persistedMailbox->getQuota() === 512
        && $persistedMailbox->getActive() === false
        && OSS_Auth_Password::verify(
            'exact mailbox password',
            $persistedMailbox->requiredPassword(),
            ['pwhash' => 'crypt:sha512'],
        )
        && $mutationManager->existingDomain->getMailboxCount() === 1
        && $mutationManager->repositories->mailboxes === $mailboxRepository
        && $mailboxRepository->uniqueChecks === $uniqueCheckOffset + 1
        && $mutationManager->persists === $persistOffset + 1
        && $mutationManager->commits === $commitOffset + 1,
);
$refetchedMailboxRepository = $mutationManager->getRepository(Entities\Mailbox::class);
maintenanceMcpParityCheck(
    'negative control: repeated mailbox repository fetches preserve the observed uniqueness check',
    $refetchedMailboxRepository === $mailboxRepository
        && $mailboxRepository->uniqueChecks === $uniqueCheckOffset + 1,
);

foreach ($mutationDefinitions as $mutationMethod => $destructive) {
    $eventOffset = count($mutationState->events);
    $rateOffset = $mutationState->rateCalls;
    $mutationResponse = $mutationDispatcher->dispatch(
        true,
        'POST',
        maintenanceMcpParityBody(
            $mutationState,
            maintenanceMcpParityRequest($mutationMethod, $mutationMethod . '-error-id'),
        ),
        [],
    );
    $mutationBody = maintenanceMcpParityJson($mutationResponse);
    $expectedEvents = ['body', 'auth:write'];
    if ($destructive) {
        $expectedEvents[] = 'rate';
    }
    $expectedEvents[] = 'handler:' . $mutationMethod;
    maintenanceMcpParityCheck(
        $mutationMethod . ' routes to its real operation after guards and preserves validation errors',
        $mutationResponse->status === 200
            && ($mutationBody['id'] ?? null) === $mutationMethod . '-error-id'
            && (maintenanceMcpParityError($mutationResponse)['code'] ?? null) === -32602
            && array_slice($mutationState->events, $eventOffset) === $expectedEvents
            && $mutationState->rateCalls === $rateOffset + ($destructive ? 1 : 0),
    );
}

$mutationManager->rejectFlush = true;
$commitOffset = $mutationManager->commits;
$rollbackError = $mutationDispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody(
        $mutationState,
        maintenanceMcpParityRequest('domain.create', 'rollback-id', ['domain' => 'rollback.test']),
    ),
    [],
);
$rollbackBody = maintenanceMcpParityJson($rollbackError);
maintenanceMcpParityCheck(
    'real mutation flush failures retain rollback error/id mapping without a commit',
    $rollbackError->status === 200
        && ($rollbackBody['id'] ?? null) === 'rollback-id'
        && maintenanceMcpParityError($rollbackError) === [
            'code' => -32010,
            'message' => 'fixture mutation rolled back',
        ]
        && $mutationManager->commits === $commitOffset,
);
$mutationManager->rejectFlush = false;

$unauthorizedMutationState = new MaintenanceMcpParityState();
$unauthorizedMutationState->authError = new ViMbAdmin_Mcp_Exception('mutation authorization rejected', 403);
$unauthorizedMutationDispatcher = maintenanceMcpParityDispatcher(
    $unauthorizedMutationState,
    maintenanceMcpRealMutationMethods($unauthorizedMutationState, $mutationOperations),
);
$repositoryOffset = $mutationManager->repositoryCalls;
$unauthorizedMutation = $unauthorizedMutationDispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody(
        $unauthorizedMutationState,
        maintenanceMcpParityRequest('mailbox.delete', 'unauthorized-mutation-id', ['username' => 'hidden@example.test']),
    ),
    [],
);
$unauthorizedMutationBody = maintenanceMcpParityJson($unauthorizedMutation);
maintenanceMcpParityCheck(
    'unauthorized mutation cannot reach repository service or backend handlers',
    $unauthorizedMutation->status === 403
        && ($unauthorizedMutationBody['id'] ?? null) === 'unauthorized-mutation-id'
        && maintenanceMcpParityError($unauthorizedMutation) === [
            'code' => -32000,
            'message' => 'mutation authorization rejected',
        ]
        && $unauthorizedMutationState->events === ['body', 'auth:write']
        && $unauthorizedMutationState->handlerCalls === 0
        && $unauthorizedMutationState->rateCalls === 0
        && $mutationManager->repositoryCalls === $repositoryOffset,
);

echo "\n== maintenance MCP read-operation parity ==\n";
$allowedDomain = new MaintenanceMcpParityDomain(2, 'allowed.test');
$hiddenDomain = new MaintenanceMcpParityDomain(3, 'hidden.test');
$alphaDomain = new MaintenanceMcpParityDomain(1, 'alpha.test');
$mailboxes = new MaintenanceMcpParityListRepository([
    maintenanceMcpParityMailbox($allowedDomain, 'zoe', true, 300),
    maintenanceMcpParityMailbox($allowedDomain, 'alice', true, 100),
    maintenanceMcpParityMailbox($allowedDomain, 'bob', false, 200),
], 'username');
$aliases = new MaintenanceMcpParityListRepository([
    maintenanceMcpParityAlias($allowedDomain, 'sales', 'alice@allowed.test', true),
    maintenanceMcpParityAlias($allowedDomain, 'abuse', 'bob@allowed.test', false),
    maintenanceMcpParityAlias($allowedDomain, 'postmaster', 'zoe@allowed.test', true),
], 'address');
$manager = new MaintenanceMcpParityEntityManager(
    new MaintenanceMcpParityDomainRepository([$hiddenDomain, $allowedDomain, $alphaDomain]),
    $mailboxes,
    $aliases,
);
$reads = new ViMbAdmin_Mcp_ReadOperations();
$allDomainsToken = (new Entities\McpToken())->setAllowedDomains(null);
$allowedToken = (new Entities\McpToken())->setAllowedDomains('ALLOWED.test');

$ping = $reads->ping(['ignored' => true]);
maintenanceMcpParityCheck(
    'ping retains its result shape and UTC timestamp',
    $ping['pong'] === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $ping['time']) === 1,
);

$domainPage = $reads->domainsList($manager, $allDomainsToken, ['limit' => 2, 'offset' => 0]);
maintenanceMcpParityCheck(
    'domains.list retains ordering, fields and look-ahead pagination',
    $domainPage === [
        'domains' => [
            [ 'id' => 2, 'domain' => 'allowed.test', 'active' => true, 'transport' => 'virtual', 'quota' => 1024,
                'maxquota' => 4096, 'mailboxes' => 2, 'aliases' => 3 ],
            [ 'id' => 1, 'domain' => 'alpha.test', 'active' => true, 'transport' => 'virtual', 'quota' => 1024,
                'maxquota' => 4096, 'mailboxes' => 2, 'aliases' => 3 ],
        ],
        'pagination' => [ 'limit' => 2, 'offset' => 0, 'returned' => 2, 'has_more' => true, 'next_offset' => 2 ],
    ],
);
$restrictedDomains = $reads->domainsList($manager, $allowedToken, []);
maintenanceMcpParityCheck(
    'domains.list applies the token domain allowlist before returning data',
    array_column($restrictedDomains['domains'], 'domain') === ['allowed.test']
        && $restrictedDomains['pagination']['returned'] === 1,
);

$mailboxPage = $reads->mailboxesList($manager, $allowedToken, [
    'domain' => 'allowed.test', 'limit' => 2, 'offset' => 0,
]);
maintenanceMcpParityCheck(
    'mailboxes.list retains data shape, ordering and pagination',
    $mailboxPage === [
        'domain' => 'allowed.test',
        'mailboxes' => [
            [ 'username' => 'alice@allowed.test', 'name' => 'Alice', 'active' => true, 'quota' => 100, 'local_part' => 'alice' ],
            [ 'username' => 'bob@allowed.test', 'name' => 'Bob', 'active' => false, 'quota' => 200, 'local_part' => 'bob' ],
        ],
        'pagination' => [ 'limit' => 2, 'offset' => 0, 'returned' => 2, 'has_more' => true, 'next_offset' => 2 ],
    ],
);

$aliasPage = $reads->aliasesList($manager, $allowedToken, [
    'domain' => 'allowed.test', 'limit' => 1, 'offset' => 1,
]);
maintenanceMcpParityCheck(
    'aliases.list retains data shape, ordering, offset and pagination',
    $aliasPage === [
        'domain' => 'allowed.test',
        'aliases' => [
            [ 'address' => 'postmaster@allowed.test', 'goto' => 'zoe@allowed.test', 'active' => true ],
        ],
        'pagination' => [ 'limit' => 1, 'offset' => 1, 'returned' => 1, 'has_more' => true, 'next_offset' => 2 ],
    ],
);

$beforeUnauthorizedRead = $mailboxes->findCalls;
$unauthorizedRejected = false;
try {
    $reads->mailboxesList($manager, $allowedToken, ['domain' => 'hidden.test']);
} catch (ViMbAdmin_Mcp_DomainException $error) {
    $unauthorizedRejected = $error->getMessage() === 'unknown domain';
}
maintenanceMcpParityCheck(
    'unauthorized domain cannot reach read repositories',
    $unauthorizedRejected && $mailboxes->findCalls === $beforeUnauthorizedRead,
);

$unknownRejected = false;
try {
    $reads->aliasesList($manager, $allDomainsToken, ['domain' => 'missing.test']);
} catch (ViMbAdmin_Mcp_DomainException $error) {
    $unknownRejected = $error->getMessage() === 'unknown domain';
}
maintenanceMcpParityCheck('unknown domains retain their domain-error message', $unknownRejected);

$limitRejected = false;
try {
    $reads->domainsList($manager, $allDomainsToken, ['limit' => 201]);
} catch (ViMbAdmin_Mcp_Exception $error) {
    $limitRejected = $error->getMessage() === 'param "limit" must be between 1 and 200';
}
maintenanceMcpParityCheck('read methods retain invalid pagination errors', $limitRejected);

$failures = MaintenanceMcpParityAssertions::$failures;
echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
