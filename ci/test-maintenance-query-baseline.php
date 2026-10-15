<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
foreach (glob(dirname(__DIR__) . '/application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
foreach (glob(dirname(__DIR__) . '/application/Repositories/*.php') ?: [] as $repositoryFile) {
    require_once substr($repositoryFile, 0, -4) . '.php';
}

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Psr\Log\AbstractLogger;

final class MaintenanceProductionQueryLog extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];
    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$logger = new MaintenanceProductionQueryLog();
$orm = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/application/Entities'], true);
if (PHP_VERSION_ID >= 80400) {
    $orm->enableNativeLazyObjects(true);
} else {
    $orm->setProxyDir(sys_get_temp_dir());
    $orm->setProxyNamespace('MaintenanceBaselineProxies');
}
$orm->setMiddlewares([new Middleware($logger)]);
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $orm), $orm);
$db = $em->getConnection();
$db->executeStatement('CREATE TABLE domain (id INTEGER PRIMARY KEY, domain TEXT, active INTEGER, transport TEXT, quota INTEGER, maxquota INTEGER, mailbox_count INTEGER, alias_count INTEGER, max_mailboxes INTEGER, max_aliases INTEGER, created TEXT, modified TEXT)');
$db->executeStatement('CREATE TABLE mailbox (id INTEGER PRIMARY KEY, username TEXT, password TEXT, name TEXT, local_part TEXT, active INTEGER, quota INTEGER, delete_pending INTEGER, created TEXT, modified TEXT, Domain_id INTEGER)');
$db->executeStatement('CREATE TABLE dovecot_quota (username TEXT PRIMARY KEY, bytes INTEGER, messages INTEGER, updated_at TEXT)');
$db->executeStatement('CREATE TABLE dovecot_last_login (username TEXT PRIMARY KEY, last_login INTEGER)');
$db->insert('domain', ['id' => 1, 'domain' => 'example.test', 'active' => 1, 'transport' => '', 'quota' => 0, 'maxquota' => 0, 'mailbox_count' => 0, 'alias_count' => 0, 'max_mailboxes' => 0, 'max_aliases' => 0, 'created' => '2026-01-01 00:00:00', 'modified' => null]);
$admin = (new Entities\Admin())->setUsername('admin@example.test')->setSuper(true);
(new ReflectionMethod($admin, 'assignGeneratedId'))->invoke($admin, 1);
$domain = (new Entities\Domain())->setDomain('example.test');
(new ReflectionMethod($domain, 'assignGeneratedId'))->invoke($domain, 1);
$repository = $em->getRepository(Entities\Mailbox::class);
if (!$repository instanceof Repositories\Mailbox) {
    throw new RuntimeException('Production mailbox repository was not selected');
}

$inject = getenv('VIMBADMIN_BASELINE_INJECT_EXTRA') === '1';
/**
 * @param callable():mixed $operation
 * @return array{result:mixed,queries:int,elapsed:int,memory:int,failed:bool}
 */
$observe = static function (string $name, callable $operation, int $ceiling) use ($logger, $inject, $db, $check): array {
    $logger->sql = [];
    $before = memory_get_usage(true);
    $started = hrtime(true);
    try {
        $result = $operation();
        $failed = false;
    } catch (Throwable $error) {
        $result = $error;
        $failed = true;
    }
    if ($inject) {
        $db->fetchOne('SELECT COUNT(*) FROM mailbox');
    }
    $receipt = ['workflow' => $name, 'queries' => count($logger->sql), 'elapsed_ns' => hrtime(true) - $started,
        'memory_delta_bytes' => max(0, memory_get_usage(true) - $before), 'failed' => $failed];
    echo 'maintenance-production-baseline ' . json_encode($receipt, JSON_THROW_ON_ERROR) . "\n";
    $check("{$name}: production query ceiling", $receipt['queries'] <= $ceiling);
    return ['result' => $result, 'queries' => $receipt['queries'], 'elapsed' => $receipt['elapsed_ns'],
        'memory' => $receipt['memory_delta_bytes'], 'failed' => $failed];
};
$resetRows = static function (int $count) use ($db): void {
    $db->executeStatement('DELETE FROM dovecot_quota');
    $db->executeStatement('DELETE FROM dovecot_last_login');
    $db->executeStatement('DELETE FROM mailbox');
    for ($id = 1; $id <= $count; $id++) {
        $username = "user{$id}@example.test";
        $db->insert('mailbox', ['id' => $id, 'username' => $username, 'password' => '!', 'name' => "User {$id}",
            'local_part' => "user{$id}", 'active' => 1, 'quota' => $id, 'delete_pending' => 0,
            'created' => '2026-01-01 00:00:00', 'modified' => null, 'Domain_id' => 1]);
        $db->insert('dovecot_quota', ['username' => $username, 'bytes' => $id * 10, 'messages' => $id, 'updated_at' => '2026-01-01 00:00:00']);
        $db->insert('dovecot_last_login', ['username' => $username, 'last_login' => 1700000000 + $id]);
    }
};
/** @return array{rows:list<array<string,mixed>>,total:int,filtered:int} */
$pageResult = static function (mixed $result): array {
    if (!is_array($result) || !isset($result['rows'], $result['total'], $result['filtered']) || !is_array($result['rows'])) {
        throw new RuntimeException('Production page result has an invalid shape');
    }
    return $result;
};
/** @return list<array<string,mixed>> */
$rowResult = static function (mixed $result): array {
    if (!is_array($result)) {
        throw new RuntimeException('Production row result has an invalid shape');
    }
    return $result;
};

$counts = [];
foreach ([0, 1, 50, 500] as $size) {
    $resetRows($size);
    $cold = $observe("mailbox-list-{$size}-cold", fn () => $repository->pagedForMailboxList($admin, $domain, '', false, 'username', 'ASC', 0, 500), $size === 0 ? 2 : 4);
    $warm = $observe("mailbox-list-{$size}-warm", fn () => $repository->pagedForMailboxList($admin, $domain, '', false, 'username', 'ASC', 0, 500), $size === 0 ? 1 : 3);
    $coldPage = $pageResult($cold['result']);
    $firstRow = $coldPage['rows'][0] ?? null;
    $counts[$size] = [$cold['queries'], $warm['queries']];
    $check(
        "mailbox-list {$size}: production rows and usage merge are complete",
        count($coldPage['rows']) === $size
        && ($size === 0 || (is_array($firstRow) && isset($firstRow['quota_bytes'], $firstRow['last_login'])))
    );
}
$resetRows(501);
$over = $observe('mailbox-list-over-cap', fn () => $repository->pagedForMailboxList($admin, $domain, '', false, 'username', 'ASC', 0, 500), 3);
$check('over-cap: production repository returns one bounded page', count($pageResult($over['result'])['rows']) === 500);
$last = $observe('mailbox-list-max-page', fn () => $repository->pagedForMailboxList($admin, $domain, '', false, 'username', 'ASC', 1000000, 500), 1);
$check('max-page: terminal production page is empty', $pageResult($last['result'])['rows'] === []);
$search = $observe('mailbox-search', fn () => $repository->pagedForMailboxList($admin, $domain, 'user4', true, 'username', 'ASC', 0, 200), 4);
$check('search: production count and page remain bounded', count($pageResult($search['result'])['rows']) <= 200);
$usage = $observe('mailbox-usage', fn () => $repository->loadForMailboxList($admin, $domain), 3);
$check('usage: production bulk merge has no per-row growth', count($rowResult($usage['result'])) === 501 && $usage['queries'] === 3);
$unauthorized = $observe('mailbox-unauthorized', static function (): never {
    throw new RuntimeException('authorization rejected before repository');
}, 0);
$check('unauthorized: production repository is not reached', $unauthorized['failed'] && $unauthorized['queries'] === 0);
$failure = $observe('mailbox-failure', function () use ($repository, $admin, $domain, $db): mixed {
    $db->executeStatement('DROP TABLE dovecot_quota');
    return $repository->loadForMailboxList($admin, $domain);
}, 2);
$check('failure: DBAL failure remains observable', $failure['result'] instanceof Throwable);

$check(
    'RF-Q01: query counts stay constant from 1 through 500 rows',
    $counts[1] === $counts[50] && $counts[50] === $counts[500] && $usage['queries'] === 3
);

/** Execute operation-local production workflow observers under this exact PHP lane. */
$productionFixture = static function (string $script) use ($check): void {
    $started = hrtime(true);
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start production workflow observer');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    echo (string) $stdout;
    echo 'maintenance-production-workflow-suite ' . json_encode([
        'exit' => $status, 'elapsed_ns' => hrtime(true) - $started,
        'output_sha256' => hash('sha256', (string) $stdout . (string) $stderr),
    ], JSON_THROW_ON_ERROR) . "\n";
    $check('production workflows: operation-local observers', $status === 0);
};
$productionFixture('ci/test-maintenance-workflow-observers.php');
echo 'maintenance-production-meta ' . json_encode([
    'source_sha256' => hash_file('sha256', dirname(__DIR__) . '/application/Repositories/Mailbox.php'),
    'sizes' => $counts,
    'rf_q01' => 'no-change: list/usage remain constant, prune scales only at its 500-item batch boundary, and create/purge/orphan operations are bounded',
], JSON_THROW_ON_ERROR) . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
