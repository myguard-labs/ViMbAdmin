<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;

final class MaintenanceBaselineLog extends AbstractLogger
{
    public int $queries = 0;
    /** @var list<string> */
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($message === 'Executing statement: {sql}' || $message === 'Executing query: {sql}') {
            $this->queries++;
            $this->sql[] = (string) ($context['sql'] ?? '');
        }
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$logger = new MaintenanceBaselineLog();
$configuration = new Configuration();
$configuration->setMiddlewares([new Middleware($logger)]);
$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$db->executeStatement('CREATE TABLE mailbox (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, quota INTEGER NOT NULL)');

$inject = getenv('VIMBADMIN_BASELINE_INJECT_EXTRA') === '1';
$observe = static function (string $workflow, callable $operation, int $maxQueries, int $maxBackend = 0) use ($logger, $inject, $db, $check): mixed {
    $logger->queries = 0;
    $logger->sql = [];
    $backend = 0;
    $started = hrtime(true);
    $before = memory_get_usage(true);
    try {
        $result = $operation(static function () use (&$backend): void { $backend++; });
        $failed = false;
    } catch (Throwable $error) {
        $result = $error;
        $failed = true;
    }
    if ($inject) {
        $db->fetchOne('SELECT COUNT(*) FROM mailbox');
        $backend++;
    }
    $receipt = [
        'workflow' => $workflow,
        'sql_queries' => $logger->queries,
        'backend_calls' => $backend,
        'elapsed_ns' => hrtime(true) - $started,
        'memory_delta_bytes' => max(0, memory_get_usage(true) - $before),
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'failed' => $failed,
    ];
    echo 'maintenance-baseline ' . json_encode($receipt, JSON_THROW_ON_ERROR) . "\n";
    $check("{$workflow}: SQL query ceiling", $logger->queries <= $maxQueries);
    $check("{$workflow}: backend-call ceiling", $backend <= $maxBackend);
    return $result;
};

$sizes = [0, 1, 50, 500];
foreach ($sizes as $size) {
    $db->executeStatement('DELETE FROM mailbox');
    for ($id = 1; $id <= $size; $id++) {
        $db->insert('mailbox', ['id' => $id, 'username' => "user{$id}@example.test", 'quota' => $id]);
    }
    foreach (['cold', 'warm'] as $temperature) {
        $rows = $observe("list-{$size}-{$temperature}", fn () => $db->fetchAllAssociative(
            'SELECT id,username,quota FROM mailbox ORDER BY username LIMIT 501 OFFSET 0'
        ), 1);
        $check("list {$size} {$temperature}: expected cardinality", is_array($rows) && count($rows) === $size);
    }
}

$db->insert('mailbox', ['id' => 501, 'username' => 'user501@example.test', 'quota' => 501]);
$overCap = $observe('list-over-cap', fn () => $db->fetchAllAssociative(
    'SELECT id,username,quota FROM mailbox ORDER BY username LIMIT 501 OFFSET 0'
), 1);
$check('over-cap observer keeps the limit+1 sentinel bounded', is_array($overCap) && count($overCap) <= 501);
$maxPage = $observe('list-max-page', fn () => $db->fetchAllAssociative(
    'SELECT id,username,quota FROM mailbox ORDER BY username LIMIT 201 OFFSET 1000000'
), 1);
$check('max-page observer accepts an empty terminal page', $maxPage === []);
$search = $observe('search', fn () => $db->fetchAllAssociative(
    'SELECT id,username FROM mailbox WHERE username LIKE ? ORDER BY username LIMIT 201',
    ['user%'],
), 1);
$check('search observer returns bounded results', is_array($search) && count($search) <= 201);
$usage = $observe('usage', fn () => $db->fetchAssociative('SELECT COUNT(*) AS mailboxes, COALESCE(SUM(quota),0) AS quota FROM mailbox'), 1);
$check('usage observer returns aggregate counters', is_array($usage) && array_keys($usage) === ['mailboxes', 'quota']);
$check('unauthorized observer performs no SQL or backend work', $observe('unauthorized', fn () => null, 0) === null);
$failure = $observe('failure', fn () => $db->fetchAllAssociative('SELECT missing FROM mailbox'), 1);
$check('failure observer retains the thrown DBAL error', $failure instanceof Throwable);

foreach (['web-create', 'mcp-create', 'web-purge', 'mcp-purge', 'task', 'prune', 'orphan'] as $workflow) {
    $result = $observe($workflow, static function (callable $backend): string {
        $backend();
        return 'ok';
    }, 0, 1);
    $check("{$workflow}: backend observer reaches the production-call seam", $result === 'ok');
}

$plan = $db->fetchAllAssociative('EXPLAIN QUERY PLAN SELECT id,username FROM mailbox WHERE username LIKE ? ORDER BY username LIMIT 201', ['user%']);
$check('SQL plan: bounded search has a readable SQLite plan', $plan !== [] && isset($plan[0]['detail']));
echo 'maintenance-baseline-meta ' . json_encode([
    'source_sha256' => hash_file('sha256', dirname(__DIR__) . '/library/ViMbAdmin/Mcp/ReadOperations.php'),
    'input_sha256' => hash('sha256', json_encode($sizes, JSON_THROW_ON_ERROR)),
    'php' => PHP_VERSION,
], JSON_THROW_ON_ERROR) . "\n";

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
