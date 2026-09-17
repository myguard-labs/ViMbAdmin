<?php

/**
 * Unit test: the native CLI dispatcher and MCP token-list command (WALL #2,
 * docs/ZF1-REMOVAL.md). No application resource or database is booted.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';
require __DIR__ . '/../src/Kernel/Cli/CliCommand.php';

final class CliQueueRunnerState
{
    /** @var list<int|Throwable> */
    public static array $results = [];
    /** @var list<array{int,bool}> */
    public static array $drains = [];
    /** @var array<string,mixed> */
    public static array $options = [];
    public static ?Doctrine\Persistence\ObjectManager $entityManager = null;
}

final class ViMbAdmin_Service_QueueRunner
{
    /** @param array<string,mixed> $options */
    public function __construct(Doctrine\Persistence\ObjectManager $entityManager, array $options)
    {
        CliQueueRunnerState::$entityManager = $entityManager;
        CliQueueRunnerState::$options = $options;
    }

    public function drain(int $max, bool $verbose): int
    {
        CliQueueRunnerState::$drains[] = [$max, $verbose];
        $result = array_shift(CliQueueRunnerState::$results);
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_int($result)) {
            throw new LogicException('Queue command drained beyond the configured test results.');
        }
        return $result;
    }
}

$commandFiles = glob(__DIR__ . '/../src/Kernel/Cli/Command/*.php') ?: [];
foreach ($commandFiles as $cmd) {
    require $cmd;
}
require __DIR__ . '/../src/Kernel/Cli/CliKernel.php';

// CliKernel pulls in Bootstrap via a `use` import only for run(); canHandle()
// touches none of it. Provide the class only if not already autoloaded.
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenGenerateCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenListCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenRevokeCommand;
use ViMbAdmin\Kernel\Cli\Command\ResetTotpCommand;
use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Cli\CliKernel;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class CliTestSession implements SessionStorage
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

/** @implements \Doctrine\Persistence\ObjectRepository<\Entities\McpToken> */
final class CliMcpTokenRepository implements \Doctrine\Persistence\ObjectRepository
{
    /** @var list<\Entities\McpToken> */
    private array $tokens;
    /** @var array<string,mixed>|null */
    public ?array $criteria = null;
    /** @var array<string,string>|null */
    public ?array $orderBy = null;
    public mixed $foundId = null;
    public ?string $foundName = null;
    public ?Throwable $error = null;

    /** @param list<\Entities\McpToken> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function find(mixed $id): ?object
    {
        $this->foundId = $id;
        if ($this->error !== null) {
            throw $this->error;
        }
        foreach ($this->tokens as $token) {
            if ($token->getId() === $id) {
                return $token;
            }
        }
        return null;
    }
    private function findByName(string $name): ?\Entities\McpToken
    {
        $this->foundName = $name;
        if ($this->error !== null) {
            throw $this->error;
        }
        foreach ($this->tokens as $token) {
            if ($token->getName() === $name) {
                return $token;
            }
        }
        return null;
    }
    /** @return list<\Entities\McpToken> */
    public function findAll(): array
    {
        return $this->tokens;
    }
    /** @return list<\Entities\McpToken> */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->criteria = $criteria;
        $this->orderBy = $orderBy;
        $tokens = $this->tokens;
        if ($orderBy === ['id' => 'ASC']) {
            usort($tokens, static fn (\Entities\McpToken $a, \Entities\McpToken $b): int => $a->getId() <=> $b->getId());
        }
        return $tokens;
    }
    public function findOneBy(array $criteria): ?object
    {
        $name = $criteria['name'] ?? null;
        return is_string($name) ? $this->findByName($name) : null;
    }
    public function getClassName(): string
    {
        return \Entities\McpToken::class;
    }
}

final class CliMcpObjectManager
{
    public int $flushes = 0;
    /** @var list<string> */
    public array $operations = [];
    /** @var list<object> */
    public array $persisted = [];
    /** @var list<object> */
    public array $removed = [];
    public ?Throwable $writeError = null;
    public function __construct(public CliMcpTokenRepository $repository)
    {
    }
    public function getRepository(string $className): CliMcpTokenRepository
    {
        if ($className !== '\\Entities\\McpToken') {
            throw new RuntimeException("unexpected repository {$className}");
        }
        return $this->repository;
    }
    public function persist(object $object): void
    {
        $this->operations[] = 'persist';
        if ($this->writeError !== null) {
            throw $this->writeError;
        }
        $this->persisted[] = $object;
    }
    public function remove(object $object): void
    {
        $this->operations[] = 'remove';
        if ($this->writeError !== null) {
            throw $this->writeError;
        }
        $this->removed[] = $object;
    }
    public function flush(): void
    {
        $this->operations[] = 'flush';
        if ($this->writeError !== null) {
            throw $this->writeError;
        }
        $this->flushes++;
    }
}

final class CliTotpAdmin
{
    /** @param array<string,mixed> $preferences */
    public function __construct(private string $username, public array $preferences)
    {
    }
    public function getUsername(): string
    {
        return $this->username;
    }
    public function getPreference(string $key): mixed
    {
        return $this->preferences[$key] ?? null;
    }
    public function deletePreference(string $key): void
    {
        unset($this->preferences[$key]);
    }
}

/** @implements \Doctrine\Persistence\ObjectRepository<object> */
final class CliTotpAdminRepository implements \Doctrine\Persistence\ObjectRepository
{
    /** @var list<object> */
    public array $admins;
    /** @var array<string,mixed>|null */
    public ?array $criteria = null;
    public bool $findAllCalled = false;
    public ?Throwable $error = null;
    /** @param list<object> $admins */
    public function __construct(array $admins)
    {
        $this->admins = $admins;
    }
    public function find(mixed $id): ?object
    {
        return null;
    }
    /** @return list<object> */
    public function findAll(): array
    {
        $this->findAllCalled = true;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->admins;
    }
    /** @return list<object> */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->criteria = $criteria;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->admins;
    }
    public function findOneBy(array $criteria): ?object
    {
        return null;
    }
    public function getClassName(): string
    {
        return \Entities\Admin::class;
    }
}

final class CliTotpObjectManager implements Doctrine\Persistence\ObjectManager
{
    /** @var list<string> */
    public array $operations = [];
    public ?Throwable $flushError = null;
    public function __construct(public CliTotpAdminRepository $repository)
    {
    }
    public function getRepository(string $className): CliTotpAdminRepository
    {
        $this->operations[] = 'getRepository';
        if ($className !== '\\Entities\\Admin') {
            throw new RuntimeException("unexpected repository {$className}");
        }
        return $this->repository;
    }
    public function flush(): void
    {
        $this->operations[] = 'flush';
        if ($this->flushError !== null) {
            throw $this->flushError;
        }
    }
    public function find(string $className, mixed $id): ?object
    {
        return null;
    }
    public function persist(object $object): void
    {
    }
    public function remove(object $object): void
    {
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
    public function getClassMetadata(string $className): Doctrine\Persistence\Mapping\ClassMetadata
    {
        throw new LogicException('TOTP reset test does not query metadata.');
    }
    public function getMetadataFactory(): Doctrine\Persistence\Mapping\ClassMetadataFactory
    {
        throw new LogicException('TOTP reset test does not query metadata.');
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
}

final class CliTestResources
{
    /** @param array<string,mixed> $options */
    public function __construct(private object $entityManager, private array $options = [])
    {
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
    public function getResource(string $name): object
    {
        if ($name !== 'doctrine2') {
            throw new RuntimeException("unexpected resource {$name}");
        }
        return $this->entityManager;
    }
}

/** @param array<string,mixed> $options */
function cliContainer(object $entityManager, array $options = []): Container
{
    return new Container(
        new CliTestResources($entityManager, $options),
        new Auth(new CliTestSession(), static fn (int $id): null => null),
    );
}

final class CliQueueObjectManager implements Doctrine\Persistence\ObjectManager
{
    public function find(string $className, mixed $id): ?object
    {
        return null;
    }
    public function persist(object $object): void
    {
    }
    public function remove(object $object): void
    {
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
    public function flush(): void
    {
    }
    public function getRepository(string $className): Doctrine\Persistence\ObjectRepository
    {
        throw new LogicException('Queue command test does not query repositories directly.');
    }
    public function getClassMetadata(string $className): Doctrine\Persistence\Mapping\ClassMetadata
    {
        throw new LogicException('Queue command test does not query metadata.');
    }
    public function getMetadataFactory(): Doctrine\Persistence\Mapping\ClassMetadataFactory
    {
        throw new LogicException('Queue command test does not query metadata.');
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
}

/** @param list<int|Throwable> $results
 *  @param array<string,mixed> $options
 *  @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runQueueCommand(array $results, array $options = [], array $args = []): array
{
    CliQueueRunnerState::$results = $results;
    CliQueueRunnerState::$drains = [];
    CliQueueRunnerState::$options = [];
    CliQueueRunnerState::$entityManager = null;

    ob_start();
    try {
        $status = (new QueueRunCommand())->run(cliContainer(new CliQueueObjectManager(), $options), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function cliMcpToken(int $id, string $name): \Entities\McpToken
{
    $token = (new \Entities\McpToken())
        ->setName($name)
        ->setScope('read');
    $idProperty = new ReflectionProperty($token, 'id');
    $idProperty->setValue($token, $id);
    return $token;
}

/** @return array{int,string} */
function runMcpTokenList(object $entityManager): array
{
    ob_start();
    try {
        $status = (new McpTokenListCommand())->run(cliContainer($entityManager), []);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runMcpTokenRevoke(object $entityManager, array $args): array
{
    ob_start();
    try {
        $status = (new McpTokenRevokeCommand())->run(cliContainer($entityManager), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runMcpTokenGenerate(object $entityManager, array $args): array
{
    ob_start();
    try {
        $status = (new McpTokenGenerateCommand())->run(cliContainer($entityManager), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runResetTotp(object $entityManager, array $args): array
{
    ob_start();
    try {
        $status = (new ResetTotpCommand())->run(cliContainer($entityManager, ['securitysalt' => 'test-salt']), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

final class TestKernelCliHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelCliHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestKernelCliHarnessState::$count++;
    }
}

echo "== native CLI dispatcher ==\n";

$kernel = new CliKernel('/nonexistent', 'testing');

$expected = [
    'queue.cli-run',
    'admin.cli-reset-totp',
    'maintenance.cli-schema-update',
    'maintenance.cli-precompile-templates',
    'mcp.cli-token-generate',
    'mcp.cli-token-list',
    'mcp.cli-token-revoke',
];
foreach ($expected as $name) {
    check("canHandle({$name}) true", $kernel->canHandle($name));
}
check('canHandle(unknown) false', !$kernel->canHandle('foo.bar'));
check('canHandle(empty) false', !$kernel->canHandle(''));

$got = $kernel->commands();
sort($got);
$want = $expected;
sort($want);
check('commands() == registered set', $got === $want);

$instructionFiles = [
    __DIR__ . '/../README.md',
    __DIR__ . '/../UPDATING',
    __DIR__ . '/../application/configs/application.ini.dist',
    __DIR__ . '/../bin/crons/vimbadmin',
    __DIR__ . '/../contrib/cron/README.md',
    __DIR__ . '/../contrib/cron/crontab.example',
    __DIR__ . '/../docs/MIGRATION.md',
    __DIR__ . '/../docs/ORM3-UPGRADE.md',
    __DIR__ . '/../docs/ZF1-REMOVAL.md',
    __DIR__ . '/../docs/mcp-auth.md',
];
$documentedActions = [];
foreach ($instructionFiles as $file) {
    $contents = file_get_contents($file);
    if (!is_string($contents)) {
        throw new RuntimeException("Could not read CLI instruction file: {$file}");
    }
    preg_match_all('/vimbtool\.php\s+-a\s+([a-z][a-z0-9-]*\.[a-z][a-z0-9-]*)/', $contents, $matches);
    foreach ($matches[1] as $action) {
        if ($action !== 'controller.action') {
            $documentedActions[$action] = true;
        }
    }
}
check('CLI docs and cron examples name at least one concrete action', $documentedActions !== []);
foreach (array_keys($documentedActions) as $action) {
    check("documented CLI action {$action} is registered", $kernel->canHandle($action));
}

$cmd = new QueueRunCommand();
check('QueueRunCommand name', $cmd->name() === 'queue.cli-run');
$implemented = class_implements($cmd) ?: [];
check('CliCommand contract', in_array(CliCommand::class, $implemented, true));

echo "== queue run command ==\n";

[$status, $output] = runQueueCommand([2, 1, 0]);
check('queue drains until empty and succeeds', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[5, false], [5, false], [5, false]]);
check('queue passes the validated object manager to the runner', CliQueueRunnerState::$entityManager instanceof CliQueueObjectManager);

$queueOptions = ['queue' => ['runner' => ['max_per_run' => 7]]];
[$status, $output] = runQueueCommand([3, 0], $queueOptions);
check('queue uses configured max_per_run and forwards options', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[7, false], [7, false]] && CliQueueRunnerState::$options === $queueOptions);

[$status, $output] = runQueueCommand([4, 3], [], ['once' => true]);
check('--once drains exactly one batch', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[5, false]]);

[$status, $output] = runQueueCommand([2, 1, 0], [], ['verbose' => true]);
check('verbose mode forwards the flag and prints the processed total', $status === 0 && $output === "Processed 3 task(s).\n" && CliQueueRunnerState::$drains === [[5, true], [5, true], [5, true]]);

[$status, $output] = runQueueCommand([-1], [], ['v' => true]);
check('lease throttling stops the loop and reports zero processed', $status === 0 && $output === "Processed 0 task(s).\n" && CliQueueRunnerState::$drains === [[5, true]]);

foreach ([['queue' => null], ['queue' => ['runner' => ['max_per_run' => 0]]]] as $badQueueOptions) {
    $rejected = false;
    try {
        runQueueCommand([1], $badQueueOptions);
    } catch (TypeError) {
        $rejected = true;
    }
    check('malformed queue command options fail before draining', $rejected && CliQueueRunnerState::$drains === []);
}

$queueErrorPropagated = false;
try {
    runQueueCommand([new RuntimeException('queue drain failed')]);
} catch (RuntimeException $e) {
    $queueErrorPropagated = $e->getMessage() === 'queue drain failed';
}
check('queue drain errors propagate', $queueErrorPropagated);

$queueBoundaryRejected = false;
try {
    (new QueueRunCommand())->run(cliContainer(new stdClass()), []);
} catch (LogicException $e) {
    $queueBoundaryRejected = $e->getMessage() === 'Queue command requires a Doctrine object manager.';
}
check('queue rejects non-Doctrine entity-manager resources locally', $queueBoundaryRejected);

echo "== reset TOTP command ==\n";

$enabled = new CliTotpAdmin('enabled@example.com', [
    ViMbAdmin_TwoFactor::PREF_SECRET => 'secret',
    ViMbAdmin_TwoFactor::PREF_BACKUP => 'backup',
    ViMbAdmin_TwoFactor::PREF_LASTTS => 123,
]);
$repository = new CliTotpAdminRepository([$enabled]);
$entityManager = new CliTotpObjectManager($repository);
[$status, $output] = runResetTotp($entityManager, ['username' => 'enabled@example.com']);
check('username reset queries the exact account', $repository->criteria === ['username' => 'enabled@example.com'] && !$repository->findAllCalled);
check('enabled account reset clears all TOTP state before one flush', $status === 0 && $enabled->preferences === [] && $entityManager->operations === ['getRepository', 'flush']);
check('username reset retains stable success output', $output === "2FA reset for: enabled@example.com\nDone. 1 admin(s) had 2FA disabled.\n");

$disabled = new CliTotpAdmin('disabled@example.com', []);
$enabled = new CliTotpAdmin('all@example.com', [ViMbAdmin_TwoFactor::PREF_SECRET => 'secret']);
$repository = new CliTotpAdminRepository([$disabled, $enabled]);
$entityManager = new CliTotpObjectManager($repository);
[$status, $output] = runResetTotp($entityManager, ['all' => false, 'username' => 'ignored@example.com']);
check('--all presence takes precedence and loads every account', $status === 0 && $repository->findAllCalled && $repository->criteria === null);
check('--all resets only enabled accounts but still flushes once', $disabled->preferences === [] && $enabled->preferences === [] && $entityManager->operations === ['getRepository', 'flush']);
check('--all counts only changed accounts', $output === "2FA reset for: all@example.com\nDone. 1 admin(s) had 2FA disabled.\n");

$repository = new CliTotpAdminRepository([]);
$entityManager = new CliTotpObjectManager($repository);
[$status, $output] = runResetTotp($entityManager, ['username' => 'missing@example.com']);
check('missing account fails without flushing', $status === 1 && $entityManager->operations === ['getRepository'] && $output === "No matching admin(s) found.\n");

$repository = new CliTotpAdminRepository([]);
$entityManager = new CliTotpObjectManager($repository);
[$status, $output] = runResetTotp($entityManager, ['username' => ['invalid']]);
check('invalid username input prints usage before repository access', $status === 1 && $entityManager->operations === [] && $output === "Usage: vimbtool.php -a admin.cli-reset-totp --username=<email> | --all\n");

$repository = new CliTotpAdminRepository([]);
$repository->error = new RuntimeException('admin lookup failed');
$lookupErrorPropagated = false;
try {
    runResetTotp(new CliTotpObjectManager($repository), ['all' => true]);
} catch (RuntimeException $e) {
    $lookupErrorPropagated = $e->getMessage() === 'admin lookup failed';
}
check('admin lookup errors propagate without flushing', $lookupErrorPropagated);

$enabled = new CliTotpAdmin('flush@example.com', [ViMbAdmin_TwoFactor::PREF_SECRET => 'secret']);
$entityManager = new CliTotpObjectManager(new CliTotpAdminRepository([$enabled]));
$entityManager->flushError = new RuntimeException('admin flush failed');
$flushErrorPropagated = false;
try {
    runResetTotp($entityManager, ['all' => true]);
} catch (RuntimeException $e) {
    $flushErrorPropagated = $e->getMessage() === 'admin flush failed';
}
check('flush errors propagate after the reset mutation', $flushErrorPropagated && $enabled->preferences === [] && $entityManager->operations === ['getRepository', 'flush']);

$resetBoundaryRejected = false;
try {
    runResetTotp(new stdClass(), ['all' => true]);
} catch (LogicException $e) {
    $resetBoundaryRejected = $e->getMessage() === 'TOTP reset requires a Doctrine object manager.';
}
check('TOTP reset rejects non-Doctrine entity-manager resources locally', $resetBoundaryRejected);

echo "== MCP token list command ==\n";

$emptyRepository = new CliMcpTokenRepository([]);
[$status, $output] = runMcpTokenList(new CliMcpObjectManager($emptyRepository));
check('empty list succeeds', $status === 0);
check('empty list keeps its concise output', $output === "No MCP tokens.\n");
check('empty list queries all tokens in ascending id order', $emptyRepository->criteria === [] && $emptyRepository->orderBy === ['id' => 'ASC']);

$active = cliMcpToken(2, 'active-token');
$active->setAllowedIps('192.0.2.1')->setAllowedDomains('example.com')->setLastUsedAt(new DateTime('2026-08-31 12:34:56'));
$revoked = cliMcpToken(1, 'revoked-token')->setRevoked(true);
$expired = cliMcpToken(3, 'expired-token')->setExpiresAt(new DateTime('2000-01-01 00:00:00'));
$repository = new CliMcpTokenRepository([$expired, $active, $revoked]);
[$status, $output] = runMcpTokenList(new CliMcpObjectManager($repository));
check('populated list succeeds', $status === 0);
check('populated list retains its headings', str_starts_with($output, 'ID   NAME'));
check('tokens are emitted in ascending id order', strpos($output, 'revoked-token') < strpos($output, 'active-token') && strpos($output, 'active-token') < strpos($output, 'expired-token'));
check('revoked state wins and empty restrictions keep defaults', preg_match('/revoked-token\s+read\s+any\s+all\s+revoked\s+-/', $output) === 1);
check('active state and explicit restrictions are rendered', preg_match('/active-token\s+read\s+192\.0\.2\.1\s+example\.com\s+active\s+2026-08-31 12:34:56/', $output) === 1);
check('expired state is rendered', preg_match('/expired-token\s+read\s+any\s+all\s+expired\s+-/', $output) === 1);

$boundaryRejected = false;
try {
    runMcpTokenList(new stdClass());
} catch (LogicException $e) {
    $boundaryRejected = $e->getMessage() === 'MCP token listing requires a Doctrine object manager.';
}
check('non-Doctrine entity-manager resources fail at the local boundary', $boundaryRejected);

echo "== MCP token revoke command ==\n";

$byId = cliMcpToken(7, 'by-id');
$repository = new CliMcpTokenRepository([$byId, cliMcpToken(8, 'by-name')]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['id' => '7', 'name' => 'by-name']);
check('id lookup takes precedence over name and succeeds', $status === 0 && $repository->foundId === 7 && $repository->foundName === null);
check('id revocation is persisted once with stable output', $byId->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'by-id' (id 7).\n");

$byName = cliMcpToken(9, 'named-token');
$repository = new CliMcpTokenRepository([$byName]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['id' => '', 'name' => 'named-token']);
check('empty id falls back to name lookup', $status === 0 && $repository->foundId === null && $repository->foundName === 'named-token');
check('name revocation is persisted with stable output', $byName->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'named-token' (id 9).\n");

$alreadyRevoked = cliMcpToken(10, 'already-revoked')->setRevoked(true);
$repository = new CliMcpTokenRepository([$alreadyRevoked]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['name' => 'already-revoked']);
check('already-revoked tokens retain the successful persistence contract', $status === 0 && $alreadyRevoked->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'already-revoked' (id 10).\n");

$repository = new CliMcpTokenRepository([]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['name' => 'missing']);
check('missing token returns the documented error without flushing', $status === 1 && $entityManager->flushes === 0 && $output === "ERROR: token not found (use --name or --id; see mcp.cli-token-list)\n");

$repository = new CliMcpTokenRepository([]);
$repository->error = new RuntimeException('token lookup failed');
$lookupErrorPropagated = false;
try {
    runMcpTokenRevoke(new CliMcpObjectManager($repository), ['id' => '1']);
} catch (RuntimeException $e) {
    $lookupErrorPropagated = $e->getMessage() === 'token lookup failed';
}
check('repository lookup errors propagate', $lookupErrorPropagated);

$revokeBoundaryRejected = false;
try {
    runMcpTokenRevoke(new stdClass(), ['id' => '1']);
} catch (LogicException $e) {
    $revokeBoundaryRejected = $e->getMessage() === 'MCP token revocation requires a Doctrine object manager.';
}
check('revocation rejects non-Doctrine entity-manager resources locally', $revokeBoundaryRejected);

echo "== MCP token generate command ==\n";

$repository = new CliMcpTokenRepository([]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenGenerate($entityManager, [
    'name' => 'new-token',
    'scope' => 'read write',
    'ip' => '192.0.2.1',
    'domains' => 'example.com',
    'days' => '2',
]);
$created = $entityManager->persisted[0] ?? null;
preg_match('/TOKEN \(shown once, store it now\):\n\n    ([a-f0-9]{64})\n/', $output, $rawMatches);
$raw = $rawMatches[1] ?? null;
check('token creation persists then flushes exactly once', $status === 0 && $created instanceof \Entities\McpToken && $entityManager->operations === ['persist', 'flush']);
check('token creation retains name, scope and restriction options', $created instanceof \Entities\McpToken && $created->getName() === 'new-token' && $created->getScope() === 'read write' && $created->getAllowedIps() === '192.0.2.1' && $created->getAllowedDomains() === 'example.com');
check('raw token is 32 random bytes shown twice while only its SHA-256 is stored', is_string($raw) && substr_count($output, $raw) === 2 && $created instanceof \Entities\McpToken && $created->getTokenHash() === hash('sha256', $raw));
check('positive validity creates a future expiry and stable summary', $created instanceof \Entities\McpToken && $created->getCreated() instanceof DateTime && $created->getExpiresAt() instanceof DateTime && str_contains($output, "MCP token 'new-token' created. Scope: read write. IPs: 192.0.2.1. Domains: example.com. Expires: "));

$active = cliMcpToken(11, 'collision');
$repository = new CliMcpTokenRepository([$active]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenGenerate($entityManager, ['name' => 'collision']);
check('active-name collision returns an error without partial writes', $status === 1 && $repository->foundName === 'collision' && $entityManager->operations === [] && $output === "ERROR: an active token named 'collision' already exists (revoke it first)\n");

$revoked = cliMcpToken(12, 'reusable')->setRevoked(true);
$repository = new CliMcpTokenRepository([$revoked]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenGenerate($entityManager, ['name' => 'reusable', 'days' => '0']);
$replacement = $entityManager->persisted[0] ?? null;
check('revoked-name replacement removes and flushes before creating', $status === 0 && $entityManager->removed === [$revoked] && $entityManager->operations === ['remove', 'flush', 'persist', 'flush']);
check('zero-day boundary keeps defaults and no expiry', $replacement instanceof \Entities\McpToken && $replacement->getScope() === 'read' && $replacement->getAllowedIps() === null && $replacement->getAllowedDomains() === null && $replacement->getExpiresAt() === null && str_contains($output, 'Scope: read. IPs: any. Domains: all. No expiry.'));

$repository = new CliMcpTokenRepository([]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenGenerate($entityManager, ['name' => 123, 'scope' => ['write']]);
check('invalid required input fails before repository or persistence work', $status === 1 && $repository->foundName === null && $entityManager->operations === [] && $output === "ERROR: --name is required\n");

$repository = new CliMcpTokenRepository([]);
$entityManager = new CliMcpObjectManager($repository);
$entityManager->writeError = new RuntimeException('token persist failed');
$writeErrorPropagated = false;
try {
    runMcpTokenGenerate($entityManager, ['name' => 'write-error']);
} catch (RuntimeException $e) {
    $writeErrorPropagated = $e->getMessage() === 'token persist failed';
}
check('persistence errors propagate without a flush', $writeErrorPropagated && $entityManager->operations === ['persist'] && $entityManager->flushes === 0);

$generateBoundaryRejected = false;
try {
    runMcpTokenGenerate(new stdClass(), ['name' => 'boundary']);
} catch (LogicException $e) {
    $generateBoundaryRejected = $e->getMessage() === 'MCP token generation requires a Doctrine object manager.';
}
check('generation rejects non-Doctrine entity-manager resources locally', $generateBoundaryRejected);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
