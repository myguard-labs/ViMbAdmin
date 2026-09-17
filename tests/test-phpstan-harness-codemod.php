<?php

declare(strict_types=1);

final class PhpstanHarnessCodemodTestState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function harnessCodemodCheck(string $name, bool $ok): void
{
    PhpstanHarnessCodemodTestState::$checks++;
    echo ($ok ? 'ok ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        PhpstanHarnessCodemodTestState::$failures++;
    }
}

/**
 * @param list<string> $command
 * @return array{code:int,out:string}
 */
function harnessProcess(array $command): array
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
 *   json:array{
 *     meta:array{eligible_sites:int,diagnostics:int,rejected:int},
 *     records:list<array{reject_reason:string}>
 *   }|null
 * }
 */
function harnessCodemod(string $tool, string $repo, array $arguments): array
{
    $run = harnessProcess([PHP_BINARY, $tool, '--repo=' . $repo,
        '--family=test-harness-static-counter', ...$arguments]);
    $decoded = json_decode($run['out'], true);
    if (!is_array($decoded) || !is_array($decoded['meta'] ?? null)
        || !is_int($decoded['meta']['eligible_sites'] ?? null)
        || !is_int($decoded['meta']['diagnostics'] ?? null)
        || !is_int($decoded['meta']['rejected'] ?? null)
        || !is_array($decoded['records'] ?? null) || !array_is_list($decoded['records'])) {
        return ['code' => $run['code'], 'json' => null];
    }
    $records = [];
    foreach ($decoded['records'] as $record) {
        if (!is_array($record) || !is_string($record['reject_reason'] ?? null)) {
            return ['code' => $run['code'], 'json' => null];
        }
        $records[] = ['reject_reason' => $record['reject_reason']];
    }
    return [
        'code' => $run['code'],
        'json' => [
            'meta' => [
                'eligible_sites' => $decoded['meta']['eligible_sites'],
                'diagnostics' => $decoded['meta']['diagnostics'],
                'rejected' => $decoded['meta']['rejected'],
            ],
            'records' => $records,
        ],
    ];
}

/** @param list<array{string,string,int}> $rows */
function harnessBaseline(array $rows): string
{
    $messages = [
        'postInc.type' => '#^Cannot use \\+\\+ on mixed\\.$#',
        'identical.alwaysTrue' => '#^Strict comparison using \\=\\=\\= between 0 and 0 will always evaluate to true\\.$#',
        'deadCode.unreachable' => '#^Unreachable statement \\- code above always terminates\\.$#',
    ];
    $result = "parameters:\n\tignoreErrors:\n";
    foreach ($rows as [$path, $identifier, $count]) {
        $result .= "\n\t\t-\n\t\t\tmessage: '{$messages[$identifier]}'\n";
        $result .= "\t\t\tidentifier: {$identifier}\n\t\t\tcount: {$count}\n\t\t\tpath: {$path}\n";
    }
    return $result;
}

$tool = realpath(__DIR__ . '/../tools/phpstan-codemod.php');
if ($tool === false) {
    throw new RuntimeException('tool missing');
}
$repo = sys_get_temp_dir() . '/vimbadmin-harness-codemod-' . bin2hex(random_bytes(6));
mkdir($repo . '/tests', 0777, true);
$positivePath = 'tests/harness-positive.php';
$positive = <<<'PHP'
<?php
declare(strict_types=1);
$failures = 0;
function checkHarness(bool $ok): void
{
    global $failures;
    if (!$ok) { $failures++; }
}
function checkHarnessGlobal(bool $ok): void
{
    if (!$ok) { $GLOBALS['failures']++; }
}
function sameHarness(int $actual, int $expected): bool
{
    return $actual === $expected;
}
checkHarness(true);
checkHarnessGlobal(true);
if (!sameHarness($failures, 0)) { exit(2); }
exit($failures === 0 ? 0 : 1);
PHP;
file_put_contents($repo . '/' . $positivePath, $positive);

$negativePath = 'tests/harness-negative.php';
$negative = <<<'PHP'
<?php
declare(strict_types=1);
$read = 0;
function readCounter(bool $ok): void
{
    global $read;
    if (!$ok) { $read++; }
    echo $read;
}
$assigned = 0;
function assignCounter(bool $ok): void
{
    global $assigned;
    if (!$ok) { $assigned++; }
    $assigned = 4;
}
$globalRead = 0;
function globalReadCounter(bool $ok): void
{
    if (!$ok) { $GLOBALS['globalRead']++; }
    echo $GLOBALS['globalRead'];
}
$computed = 0 + 0;
function computedCounter(bool $ok): void
{
    global $computed;
    if (!$ok) { $computed++; }
}
PHP;
file_put_contents($repo . '/' . $negativePath, $negative);
file_put_contents($repo . '/phpstan-baseline.neon', harnessBaseline([
    [$positivePath, 'postInc.type', 2],
    [$positivePath, 'identical.alwaysTrue', 2],
    [$positivePath, 'deadCode.unreachable', 1],
    [$negativePath, 'postInc.type', 4],
]));
harnessProcess(['git', '-C', $repo, 'init', '-q']);
harnessProcess(['git', '-C', $repo, 'config', 'user.email', 'test@example.invalid']);
harnessProcess(['git', '-C', $repo, 'config', 'user.name', 'test']);
harnessProcess(['git', '-C', $repo, 'add', '.']);
harnessProcess(['git', '-C', $repo, 'commit', '-qm', 'fixture']);
$head = harnessProcess(['git', '-C', $repo, 'rev-parse', 'HEAD'])['out'];
$positiveSha = (string) hash_file('sha256', $repo . '/' . $positivePath);
$negativeSha = (string) hash_file('sha256', $repo . '/' . $negativePath);
$common = ['--expect-head=' . $head];
$allow = static fn (string $path, string $variable, string $sha): string =>
    '--allow=' . $path . ':' . $variable . '@' . $sha;

$dry = harnessCodemod($tool, $repo, [...$common, $allow($positivePath, 'failures', $positiveSha)]);
harnessCodemodCheck('dry-run exact 1 site and 5 diagnostics', $dry['code'] === 0
    && ($dry['json']['meta']['eligible_sites'] ?? null) === 1
    && ($dry['json']['meta']['diagnostics'] ?? null) === 5
    && ($dry['json']['meta']['rejected'] ?? null) === 0);
harnessCodemodCheck('dry-run makes no source change', hash_file('sha256', $repo . '/' . $positivePath) === $positiveSha);

$rejections = [
    'read' => 'counter_function_read_not_exact',
    'assigned' => 'counter_write_not_exact',
    'globalRead' => 'counter_globals_use_not_increment',
    'computed' => 'counter_initializer_not_exact',
];
foreach ($rejections as $variable => $reason) {
    $run = harnessCodemod($tool, $repo, [...$common, $allow($negativePath, $variable, $negativeSha)]);
    harnessCodemodCheck($variable . ' negative arm', $run['code'] === 0
        && ($run['json']['records'][0]['reject_reason'] ?? null) === $reason);
}

$before = hash_file('sha256', $repo . '/' . $positivePath);
foreach ([['--expect-sites=2', '--expect-diagnostics=5'], ['--expect-sites=1', '--expect-diagnostics=4']] as $counts) {
    $run = harnessCodemod($tool, $repo, [
        '--apply', ...$common, $allow($positivePath, 'failures', $positiveSha), ...$counts,
    ]);
    harnessCodemodCheck('count drift is atomic', $run['code'] !== 0
        && hash_file('sha256', $repo . '/' . $positivePath) === $before);
}
$mixed = harnessCodemod($tool, $repo, [
    '--apply', ...$common,
    $allow($positivePath, 'failures', $positiveSha),
    $allow($negativePath, 'read', $negativeSha),
    '--expect-sites=1', '--expect-diagnostics=5',
]);
harnessCodemodCheck('mixed eligible and rejected batch is atomic', $mixed['code'] !== 0
    && hash_file('sha256', $repo . '/' . $positivePath) === $before);
$stale = harnessCodemod($tool, $repo, [...$common,
    $allow($positivePath, 'failures', str_repeat('0', 64))]);
harnessCodemodCheck('hash drift rejected', ($stale['json']['records'][0]['reject_reason'] ?? null) === 'file_hash_drift');

$apply = harnessCodemod($tool, $repo, [
    '--apply', ...$common, $allow($positivePath, 'failures', $positiveSha),
    '--expect-sites=1', '--expect-diagnostics=5',
]);
$transformed = (string) file_get_contents($repo . '/' . $positivePath);
$after = (string) hash_file('sha256', $repo . '/' . $positivePath);
harnessCodemodCheck('apply performs exact token-aware rewrite', $apply['code'] === 0
    && $after !== $positiveSha
    && str_contains($transformed, 'final class HarnessPositiveHarnessState')
    && str_contains($transformed, '$failures =& HarnessPositiveHarnessState::$count;')
    && substr_count($transformed, 'HarnessPositiveHarnessState::$count++') === 2
    && !str_contains($transformed, 'global $failures;')
    && preg_match('/^[ \t]+$/m', $transformed) !== 1);
harnessCodemodCheck(
    'callable and terminal assertions remain unchanged',
    str_contains($transformed, 'sameHarness($failures, 0)')
    && str_contains($transformed, '$failures === 0')
);
harnessCodemodCheck(
    'transformed positive arm remains green',
    harnessProcess([PHP_BINARY, $repo . '/' . $positivePath])['code'] === 0
);

$mutant = str_replace('checkHarness(true);', 'checkHarness(false);', $transformed, $replacements);
file_put_contents($repo . '/' . $positivePath, $mutant);
harnessCodemodCheck('false assertion mutant remains observable red', $replacements === 1
    && harnessProcess([PHP_BINARY, $repo . '/' . $positivePath])['code'] !== 0);
file_put_contents($repo . '/' . $positivePath, $transformed);
$second = harnessCodemod($tool, $repo, [...$common, $allow($positivePath, 'failures', $after)]);
harnessCodemodCheck('second dry-run is idempotent and hash-stable', $second['code'] === 0
    && ($second['json']['meta']['eligible_sites'] ?? null) === 0
    && ($second['json']['records'][0]['reject_reason'] ?? null) === 'state_class_collision'
    && hash_file('sha256', $repo . '/' . $positivePath) === $after);

harnessCodemodCheck('fixed assertion count', PhpstanHarnessCodemodTestState::$checks === 15);
harnessProcess(['rm', '-rf', '--', $repo]);
echo PhpstanHarnessCodemodTestState::$failures === 0 ? "ALL PASSED\n" : PhpstanHarnessCodemodTestState::$failures . " FAILED\n";
exit(PhpstanHarnessCodemodTestState::$failures === 0 ? 0 : 1);
