<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../library/ViMbAdmin/Service/QueueRunner.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$root = getenv('PERFORMANCE_CONTRACT_ROOT') ?: dirname(__DIR__);
$source = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) {
        throw new RuntimeException("Cannot read {$path}");
    }
    return $value;
};

echo "== bounded query and filesystem contracts ==\n";

final class PerformanceOpenTaskQuery
{
    /** @var array<string,mixed> */
    private array $parameters = [];
    public function setParameter(string $name, mixed $value): self
    {
        $this->parameters[$name] = $value;
        return $this;
    }
    /** @return list<array{username:string}> */
    public function getArrayResult(): array
    {
        $users = $this->parameters['users'] ?? [];
        if (!is_array($users) || $users === []) {
            return [];
        }
        $last = end($users);
        return is_string($last) ? [['username' => strtoupper($last)]] : [];
    }
}

final class PerformanceOpenTaskEntityManager
{
    public int $queries = 0;
    public function createQuery(string $dql): PerformanceOpenTaskQuery
    {
        if (!str_contains($dql, 'LOWER(t.username) IN (:users)')) {
            throw new RuntimeException('Unexpected open-task query');
        }
        $this->queries++;
        return new PerformanceOpenTaskQuery();
    }
}

$openTaskRunner = (new ReflectionClass(ViMbAdmin_Service_QueueRunner::class))->newInstanceWithoutConstructor();
$openTaskEntityManager = new PerformanceOpenTaskEntityManager();
(new ReflectionProperty(ViMbAdmin_Service_QueueRunner::class, 'em'))->setValue($openTaskRunner, $openTaskEntityManager);
$openTaskMethod = new ReflectionMethod($openTaskRunner, 'openPruneUsernames');
foreach ([5 => 1, 400 => 1, 501 => 2] as $candidateCount => $expectedQueries) {
    $openTaskEntityManager->queries = 0;
    $users = array_map(static fn (int $id): string => "user{$id}@example.test", range(1, $candidateCount));
    $open = $openTaskMethod->invoke($openTaskRunner, $users);
    $queryCount = (new ReflectionProperty($openTaskEntityManager, 'queries'))->getValue($openTaskEntityManager);
    $check(
        "autoprune open-task lookup uses {$expectedQueries} query batch(es) for {$candidateCount} candidates",
        $queryCount === $expectedQueries
            && is_array($open)
            && isset($open["user{$candidateCount}@example.test"])
            && ($candidateCount < 501 || isset($open['user500@example.test']))
    );
}

$captcha = $source('library/OSS/Captcha/Image.php');
$check(
    'captcha cleanup stats each candidate at most once before sorting',
    // VIM-D11: the throttled expiry scan and the eviction fill-in each stat
    // a file at most once; the fill-in is guarded by isset() so it never
    // re-stats a file the expiry scan already priced.
    substr_count($captcha, '@filemtime($file)') === 2
    && substr_count($captcha, 'isset($mtimes[$file])') === 1
    && !str_contains($captcha, '@filemtime($a)')
    && str_contains($captcha, '$mtimes[$a] <=> $mtimes[$b]')
);

$domain = $source('application/Repositories/Domain.php');
$check(
    'domain choices use scalar hydration',
    str_contains($domain, "SELECT d.id AS id, d.domain AS domain")
    && str_contains($domain, '$query->getArrayResult()')
);

$mcp = $source('application/controllers/McpController.php');
$check(
    'all MCP list abilities use validated bounded repository queries',
    substr_count($mcp, '$this->_listBounds($params)') === 3
    && preg_match_all('/\],\s*\$limit,\s*\$offset/', $mcp) === 2
    && str_contains($mcp, '->setFirstResult($offset)->setMaxResults($limit)')
    && str_contains($mcp, 'LOWER(d.domain) IN (:allowed)')
    && str_contains($mcp, 'LIST_MAX_OFFSET = 10000')
);

$aliases = $source('application/plugins/MailboxAutomaticAliases.php');
$check(
    'automatic aliases flush once per created group',
    substr_count($aliases, 'if ($created)') === 2
    && substr_count($aliases, '$controller->getD2EM()->flush();') === 2
);

$runner = $source('library/ViMbAdmin/Service/QueueRunner.php');
$sweepStart = strpos($runner, 'private function autopruneSweep()');
$sweepEnd = strpos($runner, 'private function initializedAutopruneArchives');
if ($sweepStart === false || $sweepEnd === false || $sweepEnd <= $sweepStart) {
    throw new RuntimeException('Cannot isolate the autoprune sweep source.');
}
$sweep = substr($runner, $sweepStart, $sweepEnd - $sweepStart);
$check(
    'autoprune detects open tasks with one set-based query',
    str_contains($sweep, 'LOWER(t.username) IN (:users)')
    && str_contains($sweep, 'array_chunk($users, 500)')
    && !str_contains($sweep, 'SELECT COUNT(t.id)')
);

$maintenance = $source('src/Kernel/Controller/MaintenanceController.php');
$check(
    'schema confirmation reuses its already-computed pending count',
    str_contains($maintenance, "['schemaSql' => \$sql, 'dbPending' => count(\$sql)]")
    && str_contains($maintenance, "array_key_exists('dbPending', \$extra)")
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
