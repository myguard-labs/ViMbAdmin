<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/maintenance-operation-observer.php';
foreach (['Entities', 'Repositories'] as $directory) {
    foreach (glob(dirname(__DIR__) . '/application/' . $directory . '/*.php') ?: [] as $file) {
        require_once $file;
    }
}
require_once dirname(__DIR__) . '/application/controllers/McpController.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\NativeResources;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;
use ViMbAdmin\Kernel\Session\SessionNamespace;

if (getenv('VIMBADMIN_RUNTIME_FIXTURE') !== '1'
    || preg_match('/^vimbadmin_runtime[a-zA-Z0-9_]*$/D', (string) getenv('DB_NAME')) !== 1) {
    throw new RuntimeException('Disposable vimbadmin_runtime fixture database is required');
}

/** @phpstan-assert true $passed */
function runtimeCheck(string $label, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

function runtimeEnvironment(string $name): string
{
    $value = getenv($name);
    runtimeCheck('required fixture environment ' . $name, is_string($value) && $value !== '');
    return $value;
}

function runtimeDatabasePort(): int
{
    $value = getenv('DB_PORT');
    if ($value === false) {
        return 3306;
    }
    runtimeCheck(
        'DB_PORT must be a decimal TCP port from 1 to 65535',
        strlen($value) <= 5 && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (int) $value <= 65535
    );
    return (int) $value;
}

function runtimeDatabaseHost(): string
{
    $host = runtimeEnvironment('DB_HOST');
    return $host === 'localhost' ? '127.0.0.1' : $host;
}

function runtimeManager(MaintenanceOperationObserver $logger, bool $create = false): EntityManager
{
    $parameters = ['driver' => 'pdo_mysql', 'host' => runtimeDatabaseHost(), 'port' => runtimeDatabasePort(), 'dbname' => runtimeEnvironment('DB_NAME'),
        'user' => runtimeEnvironment('DB_USER'), 'password' => runtimeEnvironment('DB_PASS'), 'charset' => 'utf8mb4'];
    if ($create) {
        $database = $parameters['dbname'];
        unset($parameters['dbname']);
        $bootstrap = DriverManager::getConnection($parameters);
        $bootstrap->executeStatement('CREATE DATABASE IF NOT EXISTS `' . $database . '`');
        $bootstrap->close();
        $parameters['dbname'] = $database;
    }
    if (!Type::hasType(LegacyObjectType::NAME)) {
        Type::addType(LegacyObjectType::NAME, LegacyObjectType::class);
    }
    $configuration = ORMSetup::createAttributeMetadataConfiguration(
        [dirname(__DIR__) . '/application/Entities'],
        true,
        cache: new ArrayAdapter(),
    );
    $configuration->setResultCache(new ArrayAdapter());
    if (PHP_VERSION_ID >= 80400) {
        $configuration->enableNativeLazyObjects(true);
    } else {
        $configuration->setProxyDir(sys_get_temp_dir());
        $configuration->setProxyNamespace('RuntimeBaselineProxies');
    }
    $configuration->setMiddlewares([new Middleware($logger)]);
    return new EntityManager(DriverManager::getConnection($parameters, $configuration), $configuration);
}

function runtimeDomain(string $name): Entities\Domain
{
    return (new Entities\Domain())->setDomain($name)->setQuota(256)->setTransport('virtual')->setBackupmx(false)
        ->setMaxQuota(1024)->setMaxMailboxes(2000)->setMaxAliases(2000)->setMailboxCount(0)->setAliasCount(0)
        ->setActive(true)->setCreated(new DateTime('2026-01-01 00:00:00'))->setModified(new DateTime('2026-01-01 00:00:00'));
}

function runtimeSetup(int $size): void
{
    $em = runtimeManager(new MaintenanceOperationObserver(), true);
    $db = $em->getConnection();
    $db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->createSchemaManager()->listTableNames() as $table) {
        $db->executeStatement('DROP TABLE ' . $db->quoteIdentifier($table));
    }
    $db->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $actor = (new Entities\Admin())->setUsername('runtime-admin@example.test')->setPassword('!')->setSuper(false)
        ->setActive(true)->setCreated(new DateTime('2026-01-01 00:00:00'));
    $revoked = (new Entities\Admin())->setUsername('runtime-revoked@example.test')->setPassword('!')->setSuper(false)
        ->setActive(false)->setCreated(new DateTime('2026-01-01 00:00:00'));
    $em->persist($actor);
    $em->persist($revoked);
    $names = [];
    $primary = null;
    for ($id = 1; $id <= $size; ++$id) {
        $name = sprintf('d%06d.example.test', $id);
        $names[] = $name;
        $domain = runtimeDomain($name);
        $primary ??= $domain;
        $em->persist($domain);
        $actor->addDomain($domain);
    }
    runtimeCheck('fixture contains primary domain', $primary instanceof Entities\Domain);
    $em->persist(runtimeDomain('foreign.example.test'));
    for ($id = 1; $id <= $size; ++$id) {
        $username = sprintf('user%06d@d000001.example.test', $id);
        $mailbox = (new Entities\Mailbox())->setUsername($username)->setLocalPart(sprintf('user%06d', $id))
            ->setName('Name <script>fixture</script>')->setDeletePending(false)->setQuota(128)->setActive(true)->setPassword('!')
            ->setDomain($primary)->setCreated(new DateTime('2026-01-01 00:00:00'));
        $em->persist($mailbox);
        $archive = (new Entities\Archive())->setUsername($username)->setStatus('ARCHIVED')->setDomain($primary)
            ->setArchivedAt(new DateTime('2026-01-01 00:00:00'))->setStatusChangedAt(new DateTime('2026-01-01 00:00:00'))
            ->setData('{}')->setAutoprune(false)->setMaildirSize($id);
        $em->persist($archive);
        $db->insert('dovecot_quota', ['username' => $username, 'bytes' => $id * 10, 'messages' => $id, 'updated_at' => '2026-01-01 00:00:00']);
        $db->insert('dovecot_last_login', ['username' => $username, 'last_login' => 1700000000 + $id]);
    }
    foreach (['read' => 'read', 'write' => 'write', 'revoked' => 'read'] as $label => $scope) {
        $token = (new Entities\McpToken())->setName('runtime-' . $label)->setTokenHash(hash('sha256', 'runtime-fixture-' . $label))
            ->setScope($scope)->setAllowedDomains(implode(',', $names))->setCreated(new DateTime('2026-01-01 00:00:00'))
            ->setLastUsedAt(new DateTime('2100-01-01 00:00:00'))->setRevoked($label === 'revoked');
        $em->persist($token);
    }
    $em->flush();
    // Preserve valid unique generation tokens while freezing otherwise random
    // fixture bytes. This is setup SQL, outside the measured read operations.
    $db->executeStatement('UPDATE archive SET archive_generation = SHA2(CONCAT(?, id), 256)', ['runtime-fixture-']);
    $dataset = [];
    foreach ($db->createSchemaManager()->listTableNames() as $table) {
        $rows = $db->fetchAllAssociative('SELECT * FROM ' . $db->quoteIdentifier($table));
        usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        $dataset[$table] = $rows;
    }
    ksort($dataset);
    echo json_encode(['fixture_rows' => $size, 'row_counts' => ['authorized_domains' => $size, 'foreign_domains' => 1, 'all_domains' => $size + 1, 'mailboxes' => $size, 'archives' => $size], 'backend_normal_items' => min($size, 499) + 1, 'backend_rejection_items' => 501, 'server_settings' => $db->fetchAssociative('SELECT @@innodb_buffer_pool_size AS buffer_pool_bytes, @@query_cache_type AS query_cache_type, @@thread_handling AS thread_handling'), 'dataset_sha256' => hash('sha256', json_encode($dataset, JSON_THROW_ON_ERROR)),
        'mariadb' => $db->fetchOne('SELECT VERSION()'), 'isolation' => $db->fetchOne('SELECT @@tx_isolation')], JSON_THROW_ON_ERROR) . "\n";
}

/** Real Doveadm framing/validation; deterministic test backend at transport boundary. */
final class RuntimeBaselineDoveadm extends ViMbAdmin_Doveadm
{
    public int $calls = 0;
    public string $fault = '';
    public function __construct(private readonly int $size)
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'fixture', 1);
    }
    #[\Override]
    protected function _post($payload)
    {
        ++$this->calls;
        if ($this->fault === 'backend-unauthorized') {
            return [403, 'fixture authorization denied'];
        }
        if ($this->fault === 'backend') {
            throw new RuntimeException('injected backend failure');
        }
        $request = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        runtimeCheck('real client emits request envelope', is_array($request) && isset($request[0]) && is_array($request[0]));
        $envelope = $request[0];
        runtimeCheck('real client emits directory path and id', isset($envelope[1], $envelope[2]) && is_array($envelope[1]) && isset($envelope[1]['path']) && is_string($envelope[1]['path']));
        $path = rtrim($envelope[1]['path'], '/');
        $names = $path === '/mail' ? array_map(static fn (int $id): string => sprintf('user%06d@d000001.example.test', $id), range(1, $this->fault === 'over-cap' ? 500 : min($this->size, 499))) : ['cur'];
        if ($path === '/mail') {
            $names[] = $this->fault === 'unsafe' ? '../outside' : 'orphan@example.test';
        }
        $rows = array_map(static fn (string $name): array => ['path' => $name], $names);
        return [200, json_encode([['doveadmResponse', $rows, $envelope[2]]], JSON_THROW_ON_ERROR)];
    }
}

/** @return array{operation:Closure():mixed,backend:RuntimeBaselineDoveadm,em:EntityManager} */
function runtimeOperation(string $unit, int $size, string $case, MaintenanceOperationObserver $observer): array
{
    $em = runtimeManager($observer);
    $backend = new RuntimeBaselineDoveadm($size);
    $session = new SessionNamespace('RuntimeBaseline');
    $_SESSION = [];
    $storage = new MagicPropertyStorage($session);
    $actorId = $em->getConnection()->fetchOne('SELECT id FROM admin WHERE username = ?', [$case === 'revoked' ? 'runtime-revoked@example.test' : 'runtime-admin@example.test']);
    runtimeCheck('fixture actor id is numeric', is_numeric($actorId));
    $storage->set('identity', ['id' => (int) $actorId]);
    if ($case === 'unauthenticated') {
        $storage->remove('identity');
    }
    if ($case === 'cross-domain') {
        $foreign = $em->getRepository(Entities\Domain::class)->findOneBy(['domain' => 'foreign.example.test']);
        runtimeCheck('foreign domain canary exists', $foreign instanceof Entities\Domain);
        $session->__set('domain', $foreign);
        if (in_array($unit, ['mailbox-http', 'archive-http', 'mailbox-mcp'], true)) {
            // A populated forbidden tenant makes an absent ownership guard fail.
            $canary = (new Entities\Mailbox())->setUsername('outside@foreign.example.test')->setLocalPart('outside')
                ->setName('Forbidden canary')->setDeletePending(false)->setQuota(1)->setActive(true)->setPassword('!')
                ->setDomain($foreign)->setCreated(new DateTime('2026-01-01 00:00:00'));
            $archiveCanary = (new Entities\Archive())->setUsername('outside@foreign.example.test')->setStatus('ARCHIVED')->setDomain($foreign)
                ->setArchivedAt(new DateTime('2026-01-01 00:00:00'))->setStatusChangedAt(new DateTime('2026-01-01 00:00:00'))
                ->setData('{}')->setAutoprune(false);
            $em->persist($canary);
            $em->persist($archiveCanary);
            $em->flush();
            $em->getConnection()->executeStatement('UPDATE archive SET archive_generation = SHA2(?, 256) WHERE username = ?', ['runtime-canary', 'outside@foreign.example.test']);
        }
    }
    $auth = new Auth($storage, static fn (int $id): ?object => $em->find(Entities\Admin::class, $id));
    $resources = new NativeResources(['mcp' => ['enabled' => true]], $em, new stdClass(), $session);
    $container = new Container($resources, $auth);
    $_GET = ['draw' => '42', 'start' => '0', 'length' => '200'];
    $_POST = [];
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    if ($case === 'invalid') {
        $_GET['length'] = [];
    } elseif ($case === 'last-page') {
        $_GET['start'] = '1000000';
    }
    if (str_ends_with($unit, '-http')) {
        $class = match ($unit) {
            'mailbox-http' => ViMbAdmin\Kernel\Controller\MailboxController::class,
            'domain-http' => ViMbAdmin\Kernel\Controller\DomainController::class,
            'archive-http' => ViMbAdmin\Kernel\Controller\ArchiveController::class,
            default => throw new RuntimeException('Unknown HTTP fixture adapter'),
        };
        $controller = new $class($container, new RouteMatch(explode('-', $unit, 2)[0], 'list-data', $class, 'listDataAction', []));
        $operation = $controller->listDataAction(...);
    } elseif (str_ends_with($unit, '-mcp')) {
        $controller = new McpController($container, new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []));
        $dispatcher = (new ReflectionMethod($controller, '_dispatcher'))->invoke($controller);
        runtimeCheck('real controller creates dispatcher', $dispatcher instanceof ViMbAdmin_Mcp_Dispatcher);
        $method = $unit === 'mailbox-mcp' ? 'mailboxes.list' : 'domains.list';
        $params = ['limit' => 200, 'offset' => 0];
        if ($unit === 'mailbox-mcp') {
            $params['domain'] = $case === 'cross-domain' ? 'foreign.example.test' : 'd000001.example.test';
        }
        if ($case === 'invalid') {
            $params['limit'] = 201;
        } elseif ($case === 'last-page') {
            $params['offset'] = 1000000;
        }
        $body = $case === 'malformed' ? '{broken' : json_encode(['jsonrpc' => '2.0', 'id' => 42, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
        $token = in_array($case, ['revoked', 'write'], true) ? $case : 'read';
        $server = ['HTTP_AUTHORIZATION' => 'Bearer runtime-fixture-' . $token, 'REMOTE_ADDR' => '127.0.0.1'];
        $operation = static fn (): Response => $dispatcher->dispatch(true, 'POST', static fn (): string => $body, $server);
    } else {
        $runner = new ViMbAdmin_Service_QueueRunner($em, ['doveadm' => ['maildir_root' => '/mail']]);
        $backend->fault = in_array($case, ['unsafe', 'backend', 'backend-unauthorized', 'over-cap'], true) ? $case : '';
        $operation = static fn (): mixed => (new ReflectionMethod($runner, 'scanOrphans'))->invoke($runner, $backend);
    }
    if ($case === 'failure') {
        $table = $unit === 'domain-http' || $unit === 'domain-mcp' ? 'domain' : 'mailbox';
        $em->getConnection()->executeStatement('RENAME TABLE ' . $table . ' TO runtime_hidden');
    }
    return ['operation' => $operation, 'backend' => $backend, 'em' => $em];
}

/** @return list<array{unit:string,size:int,case:string,phase:string,sql:int,backend:int,elapsed_ns:int,peak_rss_kib:int,backend_items:int,output_sha256:string}> */
function runtimeSample(string $unit, int $size, string $case): array
{
    $observer = new MaintenanceOperationObserver();
    $context = runtimeOperation($unit, $size, $case, $observer);
    $receipts = [];
    try {
        foreach (['cold', 'warm'] as $phase) {
            $after = getenv('VIMBADMIN_RUNTIME_INJECT_EXTRA') === '1'
                ? static function () use ($context): void {
                    $context['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM mailbox');
                } : null;
            $sample = $observer->measure($context['operation'], static fn (): int => $context['backend']->calls, $after);
            $result = $sample['result'];
            $decoded = $result instanceof Response ? json_decode($result->body, true) : $result;
            $denied = in_array($case, ['revoked', 'unauthenticated', 'write'], true);
            if (str_ends_with($unit, '-http')) {
                if ($denied) {
                    runtimeCheck($unit . ' actual Auth denial', $result instanceof Response && $result->status === 401);
                } elseif ($case === 'invalid') {
                    runtimeCheck($unit . ' malformed request', $result instanceof Response && $result->status === 400);
                } elseif ($case === 'failure') {
                    runtimeCheck($unit . ' DB failure visible', $sample['failed'] && $result instanceof Doctrine\DBAL\Exception\TableNotFoundException);
                } else {
                    $rows = $case === 'last-page' || ($case === 'cross-domain' && $unit !== 'domain-http') ? 0 : min($size, 200);
                    runtimeCheck($unit . ' bounded real output', is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) && count($decoded['data']) === $rows);
                    runtimeCheck($unit . ' foreign domain excluded', !str_contains(json_encode($decoded, JSON_THROW_ON_ERROR), 'foreign.example.test'));
                    $total = $case === 'cross-domain' && $unit !== 'domain-http' ? 0 : $size;
                    runtimeCheck($unit . ' protected total/filtered counts', $decoded['recordsTotal'] === $total && $decoded['recordsFiltered'] === $total);
                    runtimeCheck($unit . ' DataTables id/status', $result instanceof Response && $result->status === 200 && $decoded['draw'] === 42);
                }
            } elseif (str_ends_with($unit, '-mcp')) {
                if ($denied) {
                    runtimeCheck($unit . ' actual token scope/revocation denial', $result instanceof Response && $result->status === 403);
                } elseif (in_array($case, ['invalid', 'malformed', 'failure'], true) || ($case === 'cross-domain' && $unit === 'mailbox-mcp')) {
                    runtimeCheck($unit . ' real dispatcher error', is_array($decoded) && isset($decoded['error']));
                    if ($case === 'failure') {
                        runtimeCheck('DB failure is a real dispatcher internal error', is_array($decoded['error']) && $decoded['error']['code'] === -32603);
                    }
                } else {
                    $key = $unit === 'mailbox-mcp' ? 'mailboxes' : 'domains';
                    runtimeCheck($unit . ' bounded real output', is_array($decoded) && isset($decoded['result']) && is_array($decoded['result']) && isset($decoded['result'][$key]) && is_array($decoded['result'][$key]) && count($decoded['result'][$key]) === ($case === 'last-page' ? 0 : min($size, 200)));
                    runtimeCheck($unit . ' domain scope', !str_contains(json_encode($decoded, JSON_THROW_ON_ERROR), 'foreign.example.test'));
                    $more = $case !== 'last-page' && $size > 200;
                    runtimeCheck($unit . ' JSON-RPC id/status', $result instanceof Response && $result->status === 200 && $decoded['id'] === 42);
                    runtimeCheck($unit . ' real continuation contract', $decoded['result']['pagination'] === [
                        'limit' => 200, 'offset' => $case === 'last-page' ? 1000000 : 0,
                        'returned' => $case === 'last-page' ? 0 : min($size, 200), 'has_more' => $more, 'next_offset' => $more ? 200 : null,
                    ]);
                }
            } else {
                runtimeCheck('queue real orphan scan/fault parity', in_array($case, ['backend', 'unsafe', 'failure', 'backend-unauthorized', 'over-cap'], true) ? $sample['failed'] : $result === ['orphan@example.test']);
            }
            if ($denied || $case === 'invalid' || $case === 'malformed') {
                foreach ($sample['sql'] as $sql) {
                    runtimeCheck('denied adapter performs no resource read/effect', preg_match($unit === 'mailbox-mcp' && $case === 'invalid' ? '/\\b(?:mailbox|archive)\\b/i' : '/\\b(?:mailbox|archive|domain)\\b/i', $sql) !== 1);
                }
            }
            if ($unit === 'queue' && in_array($case, ['backend', 'unsafe', 'backend-unauthorized', 'over-cap'], true)) {
                runtimeCheck('queue actual backend failure before SQL', $sample['queries'] === 0 && $sample['backend'] === 1);
                if ($case === 'over-cap') {
                    runtimeCheck('backend501 rejected by production500 bound', $result instanceof Throwable && str_contains($result->getMessage(), 'exceeds 500 entries'));
                }
                if ($case === 'backend-unauthorized') {
                    runtimeCheck('real client rejected HTTP403', $result instanceof Throwable && str_contains($result->getMessage(), '403'));
                }
            }
            if ($unit === 'mailbox-mcp' && $case === 'cross-domain') {
                foreach ($sample['sql'] as $sql) {
                    runtimeCheck('cross-domain MCP reads no mailbox', preg_match('/\\bFROM mailbox\\b/i', $sql) !== 1);
                }
            }
            if ($case === 'positive') {
                if ($unit !== 'queue') {
                    $table = str_starts_with($unit, 'mailbox') ? 'mailbox' : (str_starts_with($unit, 'domain') ? 'domain' : 'archive');
                    foreach ($sample['sql'] as $sql) {
                        if (preg_match('/\\bFROM ' . $table . '\\b/i', $sql) === 1 && !str_contains($sql, 'COUNT(')) {
                            runtimeCheck($unit . ' SQL fetch stays bounded', preg_match('/\\bLIMIT (?:200|201)\\b/', $sql) === 1);
                            if (str_ends_with($unit, '-http')) {
                                runtimeCheck($unit . ' SQL retains tenant ownership join', str_contains($sql, 'JOIN domain_admins'));
                            } elseif ($unit === 'domain-mcp') {
                                runtimeCheck('MCP domains SQL retains token scope', str_contains($sql, 'LOWER(') && str_contains($sql, ' IN ('));
                            }
                        }
                    }
                }
                $ceilings = ['mailbox-http' => [5, 3], 'domain-http' => [4, 1], 'archive-http' => [3, 1], 'mailbox-mcp' => [3, 3], 'domain-mcp' => [2, 2], 'queue' => [1, 1]];
                runtimeCheck($unit . ' operation-local SQL contract ' . $phase . ': observed ' . $sample['queries'], $sample['queries'] === $ceilings[$unit][$phase === 'cold' ? 0 : 1]);
                runtimeCheck($unit . ' operation-local backend contract', $sample['backend'] === ($unit === 'queue' ? 2 : 0));
            }
            $output = $result instanceof Response ? $result->body : ($result instanceof Throwable ? get_class($result) : json_encode($result, JSON_THROW_ON_ERROR));
            $receipts[] = ['unit' => $unit, 'size' => $size, 'case' => $case, 'phase' => $phase,
                'sql' => $sample['queries'], 'backend' => $sample['backend'], 'elapsed_ns' => $sample['elapsed'],
                'peak_rss_kib' => $sample['peak_rss_kib'], 'backend_items' => $unit === 'queue' ? ($case === 'over-cap' ? 501 : min($size, 499) + 1) : 0, 'output_sha256' => hash('sha256', $output)];
        }
    } finally {
        if ($case === 'cross-domain' && in_array($unit, ['mailbox-http', 'archive-http', 'mailbox-mcp'], true)) {
            $context['em']->getConnection()->executeStatement('DELETE FROM archive WHERE username = ?', ['outside@foreign.example.test']);
            $context['em']->getConnection()->executeStatement('DELETE FROM mailbox WHERE username = ?', ['outside@foreign.example.test']);
        }
        if ($case === 'failure') {
            $table = $unit === 'domain-http' || $unit === 'domain-mcp' ? 'domain' : 'mailbox';
            $context['em']->getConnection()->executeStatement('RENAME TABLE runtime_hidden TO ' . $table);
        }
    }
    if ($case === 'positive') {
        runtimeCheck($unit . ' cold/warm output parity', $receipts[0]['output_sha256'] === $receipts[1]['output_sha256']);
    }
    return $receipts;
}

/** @param list<string> $arguments */
function runtimeProcess(array $arguments, string $label, string $directory): string
{
    $stdout = $directory . '/' . $label . '.stdout';
    $stderr = $directory . '/' . $label . '.stderr';
    $process = proc_open(
        [PHP_BINARY, '-d', 'apc.enable_cli=1', '-d', 'opcache.enable_cli=0', ...$arguments],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
        $pipes
    );
    runtimeCheck('start owned fixture child', is_resource($process));
    $exit = proc_close($process);
    runtimeCheck($label . ' exits zero; evidence ' . $stdout . ' and ' . $stderr, $exit === 0);
    $output = file_get_contents($stdout);
    runtimeCheck('read child receipt', is_string($output));
    return $output;
}

/** @return non-empty-list<array{elapsed_ns:int,peak_rss_kib:int,sql:int,backend:int,backend_items:int,output_sha256:string}> */
function runtimePair(string $json): array
{
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    runtimeCheck('paired child receipt', is_array($decoded) && array_is_list($decoded) && count($decoded) === 2);
    $pair = [];
    foreach ($decoded as $row) {
        runtimeCheck('child metric record', is_array($row) && isset($row['elapsed_ns'], $row['peak_rss_kib'], $row['sql'], $row['backend'], $row['backend_items'], $row['output_sha256'])
            && is_int($row['elapsed_ns']) && is_int($row['peak_rss_kib']) && is_int($row['sql']) && is_int($row['backend']) && is_int($row['backend_items']) && is_string($row['output_sha256']));
        $pair[] = ['elapsed_ns' => $row['elapsed_ns'], 'peak_rss_kib' => $row['peak_rss_kib'], 'sql' => $row['sql'],
            'backend' => $row['backend'], 'backend_items' => $row['backend_items'], 'output_sha256' => $row['output_sha256']];
    }
    return $pair;
}

/** @param list<int> $values */
function runtimePercentile(array $values, float $percentile): int
{
    sort($values);
    return $values[max(0, (int) ceil(count($values) * $percentile) - 1)];
}

function runtimeAll(): void
{
    $directory = (getenv('VIMBADMIN_RUNTIME_REPORT_DIR') ?: sys_get_temp_dir() . '/vimbadmin-runtime') . '/php-' . PHP_VERSION;
    runtimeCheck('new evidence directory', !is_dir($directory) && mkdir($directory, 0700, true));
    runtimeProcess(['ci/test-maintenance-query-baseline.php'], 'existing-observer-contracts', $directory);
    $units = ['mailbox-http', 'domain-http', 'archive-http', 'mailbox-mcp', 'domain-mcp', 'queue'];
    $report = ['php' => PHP_VERSION, 'boundary' => 'In-process real action/dispatcher or queue scan; process/bootstrap/Container construction excluded from timings; no HTTP network latency claim.',
        'cache' => 'Fresh process/EM/ArrayAdapter for each paired trial; same EM/result cache/Auth within cold-warm pair. CLI opcache off. Fixed future last-used token suppresses best-effort touch writes.',
        'database_cache' => 'Owned MariaDB buffer pool is prewarmed by fixture creation and retained across pairs/minors; not a cold database benchmark. Serial PHP CLI child processes, one operation at a time.',
        'rss' => 'Linux getrusage ru_maxrss process-lifetime KiB high-water, including bootstrap and previous cold result; not per-operation allocation delta.',
        'fixtures' => [], 'measurements' => [], 'cases' => []];
    foreach ([1, 50, 500, 1000] as $size) {
        $report['fixtures'][$size] = json_decode(runtimeProcess([__FILE__, '--setup', (string) $size], 'setup-' . $size, $directory), true, 512, JSON_THROW_ON_ERROR);
        foreach ($units as $unit) {
            $trials = [];
            for ($trial = 0; $trial < 7; ++$trial) {
                $trials[] = runtimePair(runtimeProcess([__FILE__, '--sample', $unit, (string) $size, 'positive'], $unit . '-' . $size . '-' . $trial, $directory));
            }
            foreach ([0 => 'cold', 1 => 'warm'] as $index => $phase) {
                $samples = array_column($trials, $index);
                $times = array_column($samples, 'elapsed_ns');
                $p50 = runtimePercentile($times, 0.50);
                $p95 = runtimePercentile($times, 0.95);
                runtimeCheck($unit . ' repeated output parity', count(array_unique(array_column($samples, 'output_sha256'))) === 1);
                $report['measurements'][] = ['unit' => $unit, 'authorized_rows' => $size, 'database_resource_rows' => $size + ($unit === 'domain-http' || $unit === 'domain-mcp' ? 1 : 0), 'backend_items' => $samples[0]['backend_items'],
                    'phase' => $phase, 'paired_trials' => 7, 'p50_ns' => $p50, 'p95_ns' => $p95,
                    'observed_spread_ratio' => ($p95 - $p50) / max(1, $p50), 'peak_rss_kib' => max([0, ...array_column($samples, 'peak_rss_kib')]),
                    'sql_counts' => array_values(array_unique(array_column($samples, 'sql'))),
                    'backend_counts' => array_values(array_unique(array_column($samples, 'backend'))), 'output_sha256' => $samples[0]['output_sha256']];
            }
            $cases = str_ends_with($unit, '-http') ? ['invalid', 'unauthenticated', 'revoked', 'cross-domain', 'last-page', 'failure']
                : ($unit === 'queue' ? ['backend', 'backend-unauthorized', 'unsafe', 'over-cap', 'failure']
                    : ['invalid', 'malformed', 'revoked', 'write', 'cross-domain', 'last-page', 'failure']);
            foreach ($cases as $case) {
                $report['cases'][] = json_decode(runtimeProcess([__FILE__, '--sample', $unit, (string) $size, $case], $unit . '-' . $size . '-' . $case, $directory), true, 512, JSON_THROW_ON_ERROR);
            }
        }
    }
    $report['fixture_source_sha256'] = hash_file('sha256', __FILE__);
    $report['observer_source_sha256'] = hash_file('sha256', __DIR__ . '/maintenance-operation-observer.php');
    file_put_contents($directory . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo 'runtime-baseline report=' . $directory . '/report.json' . "\nALL PASSED\n";
}

$mode = $argv[1] ?? '';
$size = (int) ($argv[3] ?? $argv[2] ?? 0);
if ($mode === '--check-port') {
    echo runtimeManager(new MaintenanceOperationObserver())->getConnection()->getParams()['port'] . "\n";
} elseif ($mode === '--check-host') {
    echo (runtimeManager(new MaintenanceOperationObserver())->getConnection()->getParams()['host'] ?? '') . "\n";
} elseif ($mode === '--setup') {
    runtimeCheck('fixed dataset size', in_array($size, [1, 50, 500, 1000], true));
    runtimeSetup($size);
} elseif ($mode === '--sample') {
    $unit = $argv[2] ?? '';
    runtimeCheck('known live runtime unit', in_array($unit, ['mailbox-http', 'domain-http', 'archive-http', 'mailbox-mcp', 'domain-mcp', 'queue'], true));
    runtimeCheck('fixed dataset size', in_array($size, [1, 50, 500, 1000], true));
    $case = $argv[4] ?? 'positive';
    echo json_encode(runtimeSample($unit, $size, $case), JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === '') {
    runtimeAll();
} else {
    throw new RuntimeException('Use no arguments, --check-port, --setup ROWS or --sample UNIT ROWS CASE inside the fixture runner');
}
