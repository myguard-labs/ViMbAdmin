<?php

declare(strict_types=1);

final class PhpstanCodemodTestState
{
    public static int $failures = 0;
    public static int $checks = 0;
}

const CODEMOD_FIELDS = [
    'head', 'baseline_sha', 'path', 'line', 'class', 'method', 'property',
    'id', 'count', 'old', 'new', 'status', 'reject_reason', 'file_sha',
];

function codemodCheck(string $name, bool $ok): void
{
    PhpstanCodemodTestState::$checks++;
    echo ($ok ? 'ok ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        PhpstanCodemodTestState::$failures++;
    }
}

/**
 * @param list<string> $command
 * @return array{code:int,out:string}
 */
function runProcess(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start process');
    }
    $output = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => trim($output)];
}

/**
 * @param list<string> $arguments
 * @return array{
 *   code:int,
 *   out:string,
 *   json:array{meta:array{eligible_sites:int},records:list<array<string,int|string>>}|null
 * }
 */
function runCodemod(string $tool, string $repo, array $arguments): array
{
    $command = [PHP_BINARY, $tool, '--repo=' . $repo, ...$arguments];
    $process = runProcess($command);
    $decoded = json_decode($process['out'], true);
    $json = null;
    if (is_array($decoded) && is_array($decoded['meta'] ?? null)
        && is_int($decoded['meta']['eligible_sites'] ?? null)
        && is_array($decoded['records'] ?? null) && array_is_list($decoded['records'])) {
        $records = [];
        foreach ($decoded['records'] as $record) {
            if (!is_array($record) || array_keys($record) !== CODEMOD_FIELDS
                || !is_string($record['status'] ?? null)
                || !is_string($record['reject_reason'] ?? null)) {
                return ['code' => $process['code'], 'out' => $process['out'], 'json' => null];
            }
            $typedRecord = [];
            foreach ($record as $key => $value) {
                if (!is_string($key) || (!is_string($value) && !is_int($value))) {
                    return ['code' => $process['code'], 'out' => $process['out'], 'json' => null];
                }
                $typedRecord[$key] = $value;
            }
            $records[] = $typedRecord;
        }
        $json = ['meta' => ['eligible_sites' => $decoded['meta']['eligible_sites']], 'records' => $records];
    }
    return ['code' => $process['code'], 'out' => $process['out'], 'json' => $json];
}

/** @param list<array{string,string,string,string,int}> $rows */
function fixtureBaseline(array $rows): string
{
    $result = "parameters:\n\tignoreErrors:\n";
    foreach ($rows as [$path, $class, $method, $atom, $count]) {
        $escapedClass = str_replace('\\', '\\\\', $class);
        $result .= "\n\t\t-\n";
        $result .= "\t\t\tmessage: '#^Method {$escapedClass}\\:\\:{$method}\\(\\) should return {$atom} but returns {$atom}\\|null\\.$#'\n";
        $result .= "\t\t\tidentifier: return.type\n\t\t\tcount: {$count}\n\t\t\tpath: {$path}\n";
    }
    return $result;
}

$tool = realpath(__DIR__ . '/../tools/phpstan-codemod.php');
if ($tool === false) {
    throw new RuntimeException('tool missing');
}
$repo = sys_get_temp_dir() . '/vimbadmin-codemod-test-' . bin2hex(random_bytes(6));
mkdir($repo . '/application/Entities', 0777, true);
$source = <<<'PHP'
<?php
namespace Entities;
class Fixture
{
    private ?\DateTime $good = null;
    private ?string $computed = null;
    private ?string $coalesced = null;
    private ?string $native = null;
    private $untyped = null;
    private mixed $mixed = null;
    private ?string $multi = null;
    private ?string $done = null;
    private ?string $counted = null;
    private ?string $wrong = null;

    /** @return \DateTime */
    public function getGood() { return $this->good; }
    /** @return string */
    public function getComputed() { return strtoupper((string) $this->computed); }
    /** @return string */
    public function getCoalesced() { return $this->coalesced ?? ''; }
    /** @return string */
    public function getNative(): string { return $this->native; }
    /** @return string */
    public function getUntyped() { return $this->untyped; }
    /** @return mixed */
    public function getMixed() { return $this->mixed; }
    /** @return string */
    public function getMulti() { if (rand(0, 1)) { return $this->multi; } return $this->multi; }
    /** @return string|null */
    public function getDone() { return $this->done; }
    /** @return string */
    public function getCounted() { return $this->counted; }
    /** @return string */
    public function getWrong() { return $this->wrong; }
}
PHP;
$path = 'application/Entities/Fixture.php';
file_put_contents($repo . '/' . $path, $source);
$rows = [];
foreach ([
    'getGood' => '\\DateTime', 'getComputed' => 'string', 'getCoalesced' => 'string',
    'getNative' => 'string', 'getUntyped' => 'string', 'getMixed' => 'mixed',
    'getMulti' => 'string', 'getDone' => 'string', 'getCounted' => 'string',
] as $method => $atom) {
    $rows[] = [$path, 'Entities\\Fixture', $method, $atom, $method === 'getCounted' ? 2 : 1];
}
// Similar-looking unmatched arm: wrong return atom must never authorize getWrong.
$rows[] = [$path, 'Entities\\Fixture', 'getWrong', 'int', 1];
file_put_contents($repo . '/phpstan-baseline.neon', fixtureBaseline($rows));
runProcess(['git', '-C', $repo, 'init', '-q']);
runProcess(['git', '-C', $repo, 'config', 'user.email', 'test@example.invalid']);
runProcess(['git', '-C', $repo, 'config', 'user.name', 'test']);
runProcess(['git', '-C', $repo, 'add', '.']);
runProcess(['git', '-C', $repo, 'commit', '-qm', 'fixture']);
$head = runProcess(['git', '-C', $repo, 'rev-parse', 'HEAD'])['out'];
$sha = hash_file('sha256', $repo . '/' . $path);
$common = ['--expect-head=' . $head];
$allow = static fn (string $method, ?string $hash = null): string => '--allow=' . $path . ':' . $method . '@' . ($hash ?? $sha);

$expected = [
    'getGood' => ['', 'eligible'],
    'getComputed' => ['getter_body_not_exact', 'rejected'],
    'getCoalesced' => ['getter_body_not_exact', 'rejected'],
    'getNative' => ['native_non_nullable_return', 'rejected'],
    'getUntyped' => ['property_not_explicit_nullable_null', 'rejected'],
    'getMixed' => ['property_not_explicit_nullable_null', 'rejected'],
    'getMulti' => ['getter_body_not_exact', 'rejected'],
    'getDone' => ['already_nullable', 'rejected'],
    'getCounted' => ['baseline_count_not_one', 'rejected'],
    'getWrong' => ['doc_atom_diagnostic_mismatch', 'rejected'],
];
$schemaRun = runCodemod($tool, $repo, [...$common, $allow('getGood')]);
codemodCheck(
    'JSON output has exact full schema',
    array_keys($schemaRun['json']['records'][0] ?? []) === CODEMOD_FIELDS
);
foreach ($expected as $method => [$reason, $status]) {
    $run = runCodemod($tool, $repo, [...$common, $allow($method)]);
    $record = $run['json']['records'][0] ?? [];
    $ok = $run['code'] === 0 && ($record['status'] ?? '') === $status
        && ($record['reject_reason'] ?? '') === $reason;
    if (!$ok) {
        echo '  observed: ' . json_encode($record) . "\n";
    }
    codemodCheck($method . ' matcher arm', $ok);
}

$drift = runCodemod($tool, $repo, [...$common, $allow('getGood', str_repeat('0', 64))]);
codemodCheck('file hash drift rejected', ($drift['json']['records'][0]['reject_reason'] ?? '') === 'file_hash_drift');
$headDrift = runCodemod($tool, $repo, ['--expect-head=' . str_repeat('0', 40), $allow('getGood')]);
codemodCheck('HEAD drift rejected', ($headDrift['json']['records'][0]['reject_reason'] ?? '') === 'head_drift');

$symlinkPath = 'application/Entities/Linked.php';
symlink($repo . '/' . $path, $repo . '/' . $symlinkPath);
$linked = runCodemod($tool, $repo, [
    ...$common, '--allow=' . $symlinkPath . ':getGood@' . $sha,
]);
codemodCheck('symlink allowlist target rejected', ($linked['json']['records'][0]['reject_reason'] ?? '') === 'file_hash_drift');
runProcess(['rm', '--', $repo . '/' . $symlinkPath]);

$before = hash_file('sha256', $repo . '/' . $path);
foreach ([['--expect-sites=2', '--expect-diagnostics=1'], ['--expect-sites=1', '--expect-diagnostics=2']] as $badCounts) {
    $run = runCodemod($tool, $repo, ['--apply', ...$common, $allow('getGood'), ...$badCounts]);
    codemodCheck('apply count precondition leaves hash stable', $run['code'] !== 0
        && hash_file('sha256', $repo . '/' . $path) === $before);
}
$badHeadApply = runCodemod($tool, $repo, [
    '--apply', '--expect-head=' . str_repeat('0', 40), $allow('getGood'),
    '--expect-sites=1', '--expect-diagnostics=1',
]);
codemodCheck('apply HEAD drift leaves hash stable', $badHeadApply['code'] !== 0
    && hash_file('sha256', $repo . '/' . $path) === $before);
$badHashApply = runCodemod($tool, $repo, [
    '--apply', ...$common, $allow('getGood', str_repeat('0', 64)),
    '--expect-sites=1', '--expect-diagnostics=1',
]);
codemodCheck('apply file drift leaves hash stable', $badHashApply['code'] !== 0
    && hash_file('sha256', $repo . '/' . $path) === $before);
$mixedApply = runCodemod($tool, $repo, [
    '--apply', ...$common, $allow('getGood'), $allow('getComputed'),
    '--expect-sites=1', '--expect-diagnostics=1',
]);
codemodCheck('mixed eligible/rejected batch is all-or-nothing', $mixedApply['code'] !== 0
    && hash_file('sha256', $repo . '/' . $path) === $before);
$duplicateApply = runCodemod($tool, $repo, [
    '--apply', ...$common, $allow('getGood'), $allow('getGood'),
    '--expect-sites=2', '--expect-diagnostics=2',
]);
codemodCheck('duplicate allowlist site rejected without writes', $duplicateApply['code'] !== 0
    && hash_file('sha256', $repo . '/' . $path) === $before);
$apply = runCodemod($tool, $repo, [
    '--apply', ...$common, $allow('getGood'), '--expect-sites=1', '--expect-diagnostics=1',
]);
$after = hash_file('sha256', $repo . '/' . $path);
codemodCheck('apply changes exact doc atom', $apply['code'] === 0 && $after !== $before
    && str_contains((string) file_get_contents($repo . '/' . $path), '@return \DateTime|null'));
$second = runCodemod($tool, $repo, [
    '--expect-head=' . $head, '--allow=' . $path . ':getGood@' . $after,
]);
codemodCheck('second dry-run has zero eligible sites', ($second['json']['meta']['eligible_sites'] ?? -1) === 0
    && ($second['json']['records'][0]['reject_reason'] ?? '') === 'already_nullable'
    && hash_file('sha256', $repo . '/' . $path) === $after);
$tsv = runCodemod($tool, $repo, [
    '--format=tsv', '--expect-head=' . $head, '--allow=' . $path . ':getGood@' . $after,
]);
codemodCheck('TSV output has exact full schema', $tsv['code'] === 0
    && strtok($tsv['out'], "\n") === implode("\t", CODEMOD_FIELDS));

codemodCheck('fixed pre-invariant assertion count', PhpstanCodemodTestState::$checks === 23);

runProcess(['rm', '-rf', '--', $repo]);
echo PhpstanCodemodTestState::$failures === 0 ? "ALL PASSED\n" : PhpstanCodemodTestState::$failures . " FAILED\n";
exit(PhpstanCodemodTestState::$failures === 0 ? 0 : 1);
