<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
foreach (glob(dirname(__DIR__) . '/application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
foreach (glob(dirname(__DIR__) . '/application/Repositories/*.php') ?: [] as $repositoryFile) {
    require_once $repositoryFile;
}
require_once dirname(__DIR__) . '/library/ViMbAdmin/Doveadm.php';
require_once dirname(__DIR__) . '/library/ViMbAdmin/Service/QueueRunner.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\AbstractLogger;

final class MaintenanceWorkflowSqlLog extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class MaintenanceWorkflowDoveadm extends ViMbAdmin_Doveadm
{
    public int $calls = 0;

    public function __construct()
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'test-key', 1);
    }

    protected function _post($payload)
    {
        ++$this->calls;
        $request = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !isset($request[0]) || !is_array($request[0])) {
            throw new RuntimeException('Doveadm request envelope has an invalid shape');
        }
        $envelope = $request[0];
        $parameters = $envelope[1] ?? [];
        $command = $envelope[0] ?? null;
        $path = is_array($parameters) ? ($parameters['path'] ?? '') : '';
        $tag = $envelope[2] ?? '';
        $rows = $command === 'fsIterDirs' && $path === '/mail/'
            ? [['path' => 'known@example.test'], ['path' => 'orphan@example.test']]
            : [['path' => 'cur']];
        return [200, json_encode([['doveadmResponse', $rows, $tag]], JSON_THROW_ON_ERROR)];
    }

    public function fsListDirs($path, $filter = 'posix')
    {
        if ($path === '/mail/orphan@example.test') {
            ++$this->calls;
            return ['cur'];
        }
        return parent::fsListDirs($path, $filter);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$inject = getenv('VIMBADMIN_BASELINE_INJECT_EXTRA') === '1';
$injectOrphanBackend = $inject || getenv('VIMBADMIN_BASELINE_INJECT_ORPHAN_BACKEND') === '1';
$discardOrphans = getenv('VIMBADMIN_BASELINE_INJECT_ORPHAN_DISCARD') === '1';
$logger = new MaintenanceWorkflowSqlLog();
$orm = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/application/Entities'], true);
if (PHP_VERSION_ID >= 80400) {
    $orm->enableNativeLazyObjects(true);
} else {
    $orm->setProxyDir(sys_get_temp_dir());
    $orm->setProxyNamespace('MaintenanceWorkflowProxies');
}
$orm->setMiddlewares([new Middleware($logger)]);
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $orm), $orm);
$schemaEntities = [
    Entities\Domain::class, Entities\Mailbox::class, Entities\Alias::class, Entities\Log::class,
    Entities\MailboxPreference::class, Entities\AliasPreference::class, Entities\Admin::class,
];
(new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), $schemaEntities));
$db = $em->getConnection();
$db->executeStatement("CREATE TABLE mailbox_task (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(32) NOT NULL, username VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, task_version BIGINT DEFAULT 1 NOT NULL, abandoned BOOLEAN DEFAULT 0 NOT NULL, priority INTEGER NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME, finished_at DATETIME, log CLOB, data CLOB, Domain_id BIGINT, Admin_id BIGINT, QueueRunner_id BIGINT)");

/** @return Entities\Domain */
$newDomain = static function (string $name): Entities\Domain {
    return (new Entities\Domain())->setDomain($name)->setQuota(256)->setTransport('virtual')->setBackupmx(false)->setMaxQuota(1024)
        ->setMaxMailboxes(1000)->setMaxAliases(1000)->setMailboxCount(0)->setAliasCount(0)
        ->setActive(true)->setCreated(new DateTime());
};
$newMailbox = static function (string $username): Entities\Mailbox {
    return (new Entities\Mailbox())->setUsername($username)->setLocalPart(strstr($username, '@', true) ?: $username)
        ->setName($username)->setQuota(128)->setActive(false)->setPassword('password');
};

/** @param callable():mixed $operation */
$observe = static function (
    string $workflow,
    callable $operation,
    int $sqlCeiling,
    int $backendCeiling,
    ?MaintenanceWorkflowDoveadm $backend = null,
) use ($logger, $db, $inject, $injectOrphanBackend, $check): mixed {
    $logger->sql = [];
    $backendBefore = $backend === null ? 0 : $backend->calls;
    $memoryBefore = memory_get_usage(true);
    $started = hrtime(true);
    $result = $operation();
    if ($backend !== null) {
        if ($injectOrphanBackend) {
            $backend->fsListDirs('/mail/injected');
        }
    } elseif ($inject) {
        $db->fetchOne('SELECT COUNT(*) FROM mailbox');
    }
    $receipt = [
        'workflow' => $workflow,
        'sql' => count($logger->sql),
        'backend' => ($backend === null ? 0 : $backend->calls) - $backendBefore,
        'elapsed_ns' => hrtime(true) - $started,
        'memory_delta_bytes' => max(0, memory_get_usage(true) - $memoryBefore),
    ];
    echo 'maintenance-production-workflow ' . json_encode($receipt, JSON_THROW_ON_ERROR) . "\n";
    $check("{$workflow}: operation-local SQL ceiling", $receipt['sql'] <= $sqlCeiling);
    $check("{$workflow}: operation-local backend ceiling", $receipt['backend'] <= $backendCeiling);
    return $result;
};

$mcpDomain = $newDomain('mcp.example');
$em->persist($mcpDomain);
$em->flush();
$mcpMailbox = $newMailbox('user@mcp.example');
$observe('mcp-create', static fn () => (new ViMbAdmin_Service_Mailbox($em))->createWithPolicy(
    $mcpMailbox,
    $mcpDomain,
    'crypt:sha512',
    false,
    ViMbAdmin_Service_MailboxCreationPolicy::mcp()
), 3, 0);
$check('mcp-create: production row persisted', $db->fetchOne('SELECT COUNT(*) FROM mailbox WHERE username = ?', ['user@mcp.example']) === 1);

$webDomain = $newDomain('web.example');
$actor = (new Entities\Admin())->setUsername('admin@example.test')->setPassword('!')->setSuper(true)->setActive(true)->setCreated(new DateTime());
$em->persist($webDomain);
$em->persist($actor);
$em->flush();
$webMailbox = $newMailbox('user@web.example');
$observe('web-create', static fn () => (new ViMbAdmin_Service_Mailbox($em))->create(
    $webMailbox,
    $webDomain,
    $actor,
    ['defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']], 'mailboxAliases' => false]
), 4, 0);
$check('web-create: production row and audit persisted', $db->fetchOne('SELECT COUNT(*) FROM mailbox WHERE username = ?', ['user@web.example']) === 1
    && $db->fetchOne('SELECT COUNT(*) FROM log') === 1);

$purgeDomain = $newDomain('purge.example')->setMailboxCount(1)->setAliasCount(1);
$purgeMailbox = $newMailbox('user@purge.example')->setDomain($purgeDomain)->setCreated(new DateTime());
$purgeAlias = (new Entities\Alias())->setAddress('forward@purge.example')->setGoto('user@purge.example')
    ->setDomain($purgeDomain)->setActive(true)->setCreated(new DateTime());
$em->persist($purgeDomain);
$em->persist($purgeMailbox);
$em->persist($purgeAlias);
$em->flush();
$db->beginTransaction();
$observe('web-and-mcp-purge', static function () use ($em, $purgeMailbox): void {
    $repository = $em->getRepository(Entities\Mailbox::class);
    if (!$repository instanceof Repositories\Mailbox || !$repository->purgeMailbox($purgeMailbox, null, true)) {
        throw new RuntimeException('Production mailbox purge failed');
    }
    $em->flush();
}, 9, 0);
$db->commit();
$check('web-and-mcp-purge: production mailbox and alias removed', $db->fetchOne('SELECT COUNT(*) FROM mailbox WHERE username = ?', ['user@purge.example']) === 0
    && $db->fetchOne('SELECT COUNT(*) FROM alias WHERE address = ?', ['forward@purge.example']) === 0);

$runner = new ViMbAdmin_Service_QueueRunner($em, ['doveadm' => ['maildir_root' => '/mail']]);
$openPrune = new ReflectionMethod($runner, 'openPruneUsernames');
foreach ([5 => 1, 500 => 1, 501 => 2] as $size => $ceiling) {
    $users = array_map(static fn (int $id): string => "prune{$id}@example.test", range(1, $size));
    $result = $observe("task-prune-{$size}", static fn () => $openPrune->invoke($runner, $users), $ceiling, 0);
    $check("task-prune-{$size}: real production query returned an array", is_array($result));
}

$known = $newMailbox('known@example.test')->setDomain($mcpDomain)->setCreated(new DateTime());
$em->persist($known);
$em->flush();
$doveadm = new MaintenanceWorkflowDoveadm();
$scanOrphans = new ReflectionMethod($runner, 'scanOrphans');
$orphans = $observe('orphan-scan', static fn () => $scanOrphans->invoke($runner, $doveadm), 1, 2, $doveadm);
$orphans = $discardOrphans ? [] : $orphans;
$check('orphan-scan: production SQL/backend correlation returns only the orphan', $orphans === ['orphan@example.test']);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
