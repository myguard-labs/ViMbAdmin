<?php

declare(strict_types=1);

final class ResidualPacketTestState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function residualCheck(string $name, bool $ok): void
{
    ResidualPacketTestState::$checks++;
    echo ($ok ? 'ok ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        ResidualPacketTestState::$failures++;
    }
}

/**
 * @param list<string> $command
 * @return array{code:int,out:string,err:string}
 */
function residualRun(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start process');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => trim($out), 'err' => trim($err)];
}

/** @param array<string,mixed> $value */
function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/** @return array<string,mixed> */
function decodedMap(string $value): array
{
    $result = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($result) || array_is_list($result)) {
        throw new RuntimeException('JSON result is not an object');
    }
    $mapped = [];
    foreach ($result as $key => $item) {
        if (!is_string($key)) {
            throw new RuntimeException('JSON object key is not a string');
        }
        $mapped[$key] = $item;
    }
    return $mapped;
}

/** @return list<array{path:string,file_sha:string,line?:int}> */
function decodedEvidence(mixed $values): array
{
    if (!is_array($values) || !array_is_list($values)) {
        throw new RuntimeException('malformed evidence list');
    }
    $result = [];
    foreach ($values as $item) {
        if (!is_array($item) || !is_string($item['path'] ?? null) || !is_string($item['file_sha'] ?? null)
            || (isset($item['line']) && !is_int($item['line']))) {
            throw new RuntimeException('malformed evidence');
        }
        $row = isset($item['line'])
            ? ['path' => $item['path'], 'line' => $item['line'], 'file_sha' => $item['file_sha']]
            : ['path' => $item['path'], 'file_sha' => $item['file_sha']];
        $result[] = $row;
    }
    return $result;
}

/** @return array{schema_version:string,meta:array{head:string,baseline_sha:string,packet_count:int,diagnostics:int,analysis_contexts:list<string>},packets:list<array{identity:string,packet_hash:string,head:string,baseline_sha:string,path:string,analysis_context:?string,line:int,class:?string,method:?string,symbol_start:?int,symbol_end:?int,id:string,message:string,count:int,context:list<string>,file_sha:string,callers:list<array{path:string,file_sha:string,line?:int}>,tests:list<array{path:string,file_sha:string,line?:int}>,discovery_complete:bool,rule:string,reject_reason:string,security_contract_sensitive:bool}>} */
function decodedPacketDocument(string $value): array
{
    $document = decodedMap($value);
    $meta = $document['meta'] ?? null;
    $packetValues = $document['packets'] ?? null;
    if (!is_string($document['schema_version'] ?? null) || !is_array($meta) || array_is_list($meta)
        || !is_string($meta['head'] ?? null) || !is_string($meta['baseline_sha'] ?? null)
        || !is_int($meta['packet_count'] ?? null) || !is_int($meta['diagnostics'] ?? null)
        || !is_array($meta['analysis_contexts'] ?? null) || !array_is_list($meta['analysis_contexts'])
        || array_filter($meta['analysis_contexts'], static fn (mixed $context): bool => !is_string($context)) !== []
        || !is_array($packetValues) || !array_is_list($packetValues)) {
        throw new RuntimeException('malformed packet document');
    }
    $packets = [];
    foreach ($packetValues as $packet) {
        if (!is_array($packet) || array_is_list($packet)
            || !is_string($packet['identity'] ?? null) || !is_string($packet['packet_hash'] ?? null)
            || !is_string($packet['head'] ?? null) || !is_string($packet['baseline_sha'] ?? null)
            || !is_string($packet['path'] ?? null)
            || (!is_string($packet['analysis_context'] ?? null) && ($packet['analysis_context'] ?? null) !== null)
            || !is_int($packet['line'] ?? null)
            || (!is_string($packet['class'] ?? null) && ($packet['class'] ?? null) !== null)
            || (!is_string($packet['method'] ?? null) && ($packet['method'] ?? null) !== null)
            || ((!is_int($packet['symbol_start'] ?? null) || !is_int($packet['symbol_end'] ?? null))
                && (($packet['symbol_start'] ?? null) !== null || ($packet['symbol_end'] ?? null) !== null))
            || !is_string($packet['id'] ?? null) || !is_string($packet['message'] ?? null)
            || !is_int($packet['count'] ?? null) || !is_array($packet['context'] ?? null)
            || !array_is_list($packet['context']) || array_filter($packet['context'], static fn (mixed $line): bool => !is_string($line)) !== []
            || !is_string($packet['file_sha'] ?? null) || !is_array($packet['callers'] ?? null)
            || !array_is_list($packet['callers']) || !is_array($packet['tests'] ?? null) || !array_is_list($packet['tests'])
            || !is_bool($packet['discovery_complete'] ?? null) || !is_string($packet['rule'] ?? null)
            || !is_string($packet['reject_reason'] ?? null) || !is_bool($packet['security_contract_sensitive'] ?? null)) {
            throw new RuntimeException('malformed packet');
        }
        $context = [];
        foreach ($packet['context'] as $contextLine) {
            if (!is_string($contextLine)) {
                throw new RuntimeException('malformed context');
            }
            $context[] = $contextLine;
        }
        $class = is_string($packet['class']) ? $packet['class'] : null;
        $method = is_string($packet['method']) ? $packet['method'] : null;
        $analysisContext = is_string($packet['analysis_context']) ? $packet['analysis_context'] : null;
        $symbolStart = is_int($packet['symbol_start']) ? $packet['symbol_start'] : null;
        $symbolEnd = is_int($packet['symbol_end']) ? $packet['symbol_end'] : null;
        $packets[] = [
            'identity' => $packet['identity'], 'packet_hash' => $packet['packet_hash'], 'head' => $packet['head'],
            'baseline_sha' => $packet['baseline_sha'], 'path' => $packet['path'], 'analysis_context' => $analysisContext,
            'line' => $packet['line'],
            'class' => $class, 'method' => $method, 'symbol_start' => $symbolStart,
            'symbol_end' => $symbolEnd, 'id' => $packet['id'], 'message' => $packet['message'],
            'count' => $packet['count'], 'context' => $context, 'file_sha' => $packet['file_sha'],
            'callers' => decodedEvidence($packet['callers']), 'tests' => decodedEvidence($packet['tests']), 'discovery_complete' => $packet['discovery_complete'],
            'rule' => $packet['rule'], 'reject_reason' => $packet['reject_reason'],
            'security_contract_sensitive' => $packet['security_contract_sensitive'],
        ];
    }
    $analysisContexts = [];
    foreach ($meta['analysis_contexts'] as $analysisContext) {
        if (!is_string($analysisContext)) {
            throw new RuntimeException('malformed analysis context');
        }
        $analysisContexts[] = $analysisContext;
    }
    return ['schema_version' => $document['schema_version'], 'meta' => [
        'head' => $meta['head'], 'baseline_sha' => $meta['baseline_sha'], 'packet_count' => $meta['packet_count'],
        'diagnostics' => $meta['diagnostics'], 'analysis_contexts' => $analysisContexts,
    ], 'packets' => $packets];
}

/** @param array<string,mixed> $value */
function residualCanonicalHash(array $value): string
{
    return hash('sha256', json_encode(residualCanonicalValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function residualCanonicalValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('residualCanonicalValue', $value);
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new RuntimeException('non-string canonical key');
        }
        $result[$key] = residualCanonicalValue($item);
    }
    ksort($result, SORT_STRING);
    return $result;
}

/**
 * @param array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>} $proposal
 * @return array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>}
 */
function signedProposal(array $proposal): array
{
    unset($proposal['proposal_hash']);
    $proposal['proposal_hash'] = residualCanonicalHash(['schema_version' => 'phpstan-residual-proposals/v1', ...$proposal]);
    return $proposal;
}

/**
 * @param array{schema_version:string,proposals:list<array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>}>} $document
 * @return array{schema_version:string,proposals:list<array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>}>}
 */
function signedProposalDocument(array $document): array
{
    foreach ($document['proposals'] as &$proposal) {
        $proposal = signedProposal($proposal);
    }
    unset($proposal);
    return $document;
}

/** @param array{code:int,out:string,err:string} $run */
function rejection(array $run): string
{
    if ($run['out'] === '') {
        return '';
    }
    $result = decodedMap($run['out']);
    $records = $result['records'] ?? null;
    if (!is_array($records) || !array_is_list($records) || !is_array($records[0] ?? null)
        || !is_string($records[0]['reject_reason'] ?? null)) {
        return '';
    }
    return $records[0]['reject_reason'];
}

function eligibleSites(string $output): int
{
    $result = decodedMap($output);
    $meta = $result['meta'] ?? null;
    if (!is_array($meta) || !is_int($meta['eligible_sites'] ?? null)) {
        return -1;
    }
    return $meta['eligible_sites'];
}

/**
 * @param array{identity:string,packet_hash:string,head:string,baseline_sha:string,path:string,analysis_context:?string,line:int,class:?string,method:?string,symbol_start:?int,symbol_end:?int,id:string,message:string,count:int,context:list<string>,file_sha:string,callers:list<array{path:string,file_sha:string,line?:int}>,tests:list<array{path:string,file_sha:string,line?:int}>,discovery_complete:bool,rule:string,reject_reason:string,security_contract_sensitive:bool} $packet
 * @return array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>}
 */
function makeResidualProposal(array $packet, string $path, string $sha, int $start, int $end): array
{
    return signedProposal([
        'proposal_hash' => '', 'packet_identity' => $packet['identity'], 'packet_hash' => $packet['packet_hash'],
        'classification' => 'mechanical_safe', 'path' => $path, 'file_sha' => $sha,
        'confidence' => 1.0, 'rationale' => 'Exact documentation atom matches the nullable property return.',
        'required_tests' => ['tests/test-fixture.php'],
        'edits' => [['edit_kind' => 'doc_return_atom', 'start' => $start, 'end' => $end, 'old' => 'string', 'new' => 'string|null']],
    ]);
}

/**
 * @param array{identity:string,packet_hash:string,head:string,baseline_sha:string,path:string,analysis_context:?string,line:int,class:?string,method:?string,symbol_start:?int,symbol_end:?int,id:string,message:string,count:int,context:list<string>,file_sha:string,callers:list<array{path:string,file_sha:string,line?:int}>,tests:list<array{path:string,file_sha:string,line?:int}>,discovery_complete:bool,rule:string,reject_reason:string,security_contract_sensitive:bool} $safe
 * @param array{schema_version:string,proposals:list<array{proposal_hash:string,packet_identity:string,packet_hash:string,classification:string,path:string,file_sha:string,confidence:float,rationale:string,required_tests:list<string>,edits:list<array{edit_kind:string,start:int,end:int,old:string,new:string}>}>} $document
 * @param list<string> $extra
 * @return array{code:int,out:string,err:string}
 */
function runProposalFixture(
    string $tool,
    string $repo,
    string $head,
    string $packetFile,
    string $proposalFile,
    array $safe,
    array $document,
    array $extra = [],
): array {
    writeJson($proposalFile, $document);
    return residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
        '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
        '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'],
        '--expect-sites=1', '--expect-diagnostics=2', ...$extra]);
}

/** @return array{code:int,out:string,err:string,signal:string} */
function runToctouControl(
    string $tool,
    string $repo,
    string $head,
    string $packetFile,
    string $proposalFile,
    string $allow,
    string $sourceFile,
): array {
    $signal = sys_get_temp_dir() . '/vimbadmin-codemod-' . bin2hex(random_bytes(8));
    putenv('PHPSTAN_CODEMOD_TEST_PAUSE=' . $signal);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
        '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile, '--allow-packet=' . $allow,
        '--expect-sites=1', '--expect-diagnostics=2', '--apply-proposals'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    putenv('PHPSTAN_CODEMOD_TEST_PAUSE');
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start TOCTOU control');
    }
    $deadline = microtime(true) + 5.0;
    while (!is_file($signal . '.ready') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($signal . '.ready')) {
        throw new RuntimeException('TOCTOU ready signal missing');
    }
    file_put_contents($sourceFile, "\n// external drift\n", FILE_APPEND);
    file_put_contents($signal . '.continue', "continue\n");
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'out' => trim($out), 'err' => trim($err), 'signal' => $signal];
}

$tool = realpath(__DIR__ . '/../tools/phpstan-codemod.php');
if ($tool === false) {
    throw new RuntimeException('tool missing');
}
$repo = sys_get_temp_dir() . '/vimbadmin-residual-test-' . bin2hex(random_bytes(6));
mkdir($repo . '/application/Entities', 0777, true);
mkdir($repo . '/application/Traits', 0777, true);
mkdir($repo . '/tests', 0777, true);
$fixture = <<<'PHP'
<?php
namespace Entities;
class Fixture
{
    private ?string $value = null;
    private ?string $password = null;
    private ?string $label = null;
    private mixed $photo = null;
    private ?string $display = null;
    private ?bool $super = null;

    /** @return string */
    public function getValue() { return $this->value; }
    /** @return string */
    public function getPassword() { return $this->password; }
    /** @return string */
    public function getStatusLabel() { return strtoupper((string) $this->label); }
    /** @return string */
    public function getJpegPhoto() { return $this->photo; }
    /** @return string */
    public function getDisplayName() { return strtoupper((string) $this->display); }
    /** @return bool */
    public function getSuper() { return $this->super; }
}
class Shadow
{
    private mixed $photo = null;
    /** @return string */
    public function getJpegPhoto() { return $this->photo; }
}
PHP;
$other = <<<'PHP'
<?php
namespace Entities;
class Other
{
    private ?string $value = null;
    /** @return string */
    public function getValue() { return $this->value; }
}
PHP;
$sharedTrait = <<<'PHP'
<?php
namespace Fixture\Traits;
trait Shared
{
    private ?string $value = null;
    /** @return string */
    public function getValue() { return $this->value; }
}
PHP;
file_put_contents($repo . '/application/Entities/Fixture.php', $fixture);
file_put_contents($repo . '/application/Entities/Other.php', $other);
file_put_contents($repo . '/application/Traits/Shared.php', $sharedTrait);
file_put_contents($repo . '/application/bootstrap.php', "<?php\n\$flag = true;\n");
file_put_contents($repo . '/tests/test-fixture.php', "<?php\n\$fixture->getValue();\n// Entities\\Fixture getStatusLabel getJpegPhoto\n");
file_put_contents($repo . '/phpstan-baseline.neon', "parameters:\n\tignoreErrors: []\n");
residualRun(['git', '-C', $repo, 'init', '-q']);
residualRun(['git', '-C', $repo, 'config', 'user.email', 'test@example.invalid']);
residualRun(['git', '-C', $repo, 'config', 'user.name', 'test']);
residualRun(['git', '-C', $repo, 'add', '.']);
residualRun(['git', '-C', $repo, 'commit', '-qm', 'fixture']);
$head = residualRun(['git', '-C', $repo, 'rev-parse', 'HEAD'])['out'];
$fixturePath = $repo . '/application/Entities/Fixture.php';
$otherPath = $repo . '/application/Entities/Other.php';

$phpstan = ['totals' => ['errors' => 0, 'file_errors' => 12], 'files' => [
    $fixturePath => ['errors' => 8, 'messages' => [
        ['message' => 'Method Entities\\Fixture::getValue() should return string but returns string|null.', 'line' => 13, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getValue() should return string but returns string|null.', 'line' => 13, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getPassword() should return string but returns string|null.', 'line' => 15, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getStatusLabel() should return string but returns string|null.', 'line' => 17, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getJpegPhoto() should return string but returns mixed.', 'line' => 19, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getDisplayName() should return string but returns string|null.', 'line' => 21, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Fixture::getSuper() should return bool but returns bool|null.', 'line' => 23, 'ignorable' => true, 'identifier' => 'return.type'],
        ['message' => 'Method Entities\\Shadow::getJpegPhoto() should return string but returns mixed.', 'line' => 29, 'ignorable' => true, 'identifier' => 'return.type'],
    ]],
    $otherPath => ['errors' => 1, 'messages' => [
        ['message' => 'Method Entities\\Other::getValue() should return string but returns string|null.', 'line' => 7, 'ignorable' => true, 'identifier' => 'return.type'],
    ]],
    $repo . '/application/bootstrap.php' => ['errors' => 1, 'messages' => [
        ['message' => 'Variable $missing might not be defined.', 'line' => 2, 'ignorable' => true, 'identifier' => 'variable.undefined'],
    ]],
    $repo . '/application/Traits/Shared.php (in context of class Entities\\ConsumerA)' => ['errors' => 1, 'messages' => [
        ['message' => 'Method Entities\\ConsumerA::getValue() should return string but returns string|null.', 'line' => 7, 'ignorable' => true, 'identifier' => 'return.type'],
    ]],
    $repo . '/application/Traits/Shared.php (in context of class Entities\\ConsumerB)' => ['errors' => 1, 'messages' => [
        ['message' => 'Method Entities\\ConsumerB::getValue() should return string but returns string|null.', 'line' => 7, 'ignorable' => true, 'identifier' => 'return.type'],
    ]],
]];
$phpstanFile = $repo . '/phpstan.json';
writeJson($phpstanFile, $phpstan);
$packetRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head, '--packets=' . $phpstanFile]);
$packetDocument = decodedPacketDocument($packetRun['out']);
$packets = $packetDocument['packets'] ?? [];
residualCheck('packet generation succeeds', $packetRun['code'] === 0);
$packetHeadDrift = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . str_repeat('0', 40), '--packets=' . $phpstanFile]);
residualCheck('packet generation rejects HEAD drift', $packetHeadDrift['code'] !== 0 && str_contains($packetHeadDrift['err'], 'head_drift'));
$packetTsv = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head, '--format=tsv', '--packets=' . $phpstanFile]);
residualCheck('packet mode rejects TSV output', $packetTsv['code'] !== 0);
residualCheck('packet document has exact schema and counts', ($packetDocument['schema_version'] ?? '') === 'phpstan-residual-packets/v1'
    && ($packetDocument['meta']['packet_count'] ?? -1) === 11 && ($packetDocument['meta']['diagnostics'] ?? -1) === 12
    && $packetDocument['meta']['analysis_contexts'] === ['Entities\\ConsumerA', 'Entities\\ConsumerB']);
residualCheck('duplicate diagnostic is count-bound', ($packets[0]['count'] ?? -1) === 2);
residualCheck('packet carries bounded symbol/context/hash', ($packets[0]['class'] ?? '') === 'Entities\\Fixture'
    && ($packets[0]['method'] ?? '') === 'getValue' && count($packets[0]['context'] ?? []) <= 5
    && preg_match('/^[a-f0-9]{64}$/', (string) ($packets[0]['packet_hash'] ?? '')) === 1);
residualCheck('callers and tests are deterministic and hash-bound', ($packets[0]['callers'][0]['path'] ?? '') === 'tests/test-fixture.php'
    && ($packets[0]['callers'][0]['line'] ?? -1) === 2 && preg_match('/^[a-f0-9]{64}$/', (string) ($packets[0]['callers'][0]['file_sha'] ?? '')) === 1
    && ($packets[0]['tests'][0]['path'] ?? '') === 'tests/test-fixture.php' && ($packets[0]['discovery_complete'] ?? true) === false);
$byMethod = [];
foreach ($packets as $packet) {
    $byMethod[(string) ($packet['class'] ?? '') . '::' . (string) ($packet['method'] ?? '')] = $packet;
}
$safe = $byMethod['Entities\\Fixture::getValue'];
$otherSafe = $byMethod['Entities\\Other::getValue'];
residualCheck('safe doc diagnostic is proposal-eligible', $safe['rule'] === 'doc_comment_candidate'
    && $safe['reject_reason'] === '' && $safe['security_contract_sensitive'] === false);
residualCheck('security diagnostic is marked sensitive', $byMethod['Entities\\Fixture::getPassword']['reject_reason'] === 'security_contract_sensitive');
residualCheck('computed getter is contract-sensitive', $byMethod['Entities\\Fixture::getStatusLabel']['reject_reason'] === 'computed_runtime_contract');
residualCheck('mixed getter is contract-sensitive', $byMethod['Entities\\Fixture::getJpegPhoto']['reject_reason'] === 'mixed_runtime_contract');
residualCheck('computed non-label getter is not mechanically safe', $byMethod['Entities\\Fixture::getDisplayName']['reject_reason'] === 'manual_semantic_review'
    && $byMethod['Entities\\Fixture::getDisplayName']['security_contract_sensitive'] === true);
residualCheck('Admin getSuper shape is security-sensitive', $byMethod['Entities\\Fixture::getSuper']['reject_reason'] === 'security_contract_sensitive');
residualCheck('duplicate method name binds the qualified class', $byMethod['Entities\\Shadow::getJpegPhoto']['class'] === 'Entities\\Shadow'
    && $byMethod['Entities\\Shadow::getJpegPhoto']['symbol_start'] !== $byMethod['Entities\\Fixture::getJpegPhoto']['symbol_start']);
$nonMethodPacket = $byMethod['::'];
residualCheck('non-method packet round-trips as manual', $nonMethodPacket['symbol_start'] === null && $nonMethodPacket['symbol_end'] === null
    && $nonMethodPacket['reject_reason'] === 'manual_semantic_review');
$traitA = $byMethod['Entities\\ConsumerA::getValue'];
$traitB = $byMethod['Entities\\ConsumerB::getValue'];
residualCheck('trait contexts produce distinct deterministic identities', $traitA['analysis_context'] === 'Entities\\ConsumerA'
    && $traitB['analysis_context'] === 'Entities\\ConsumerB' && $traitA['identity'] !== $traitB['identity']);
residualCheck('trait contexts bind the same physical hash fail-closed', $traitA['path'] === 'application/Traits/Shared.php'
    && $traitA['file_sha'] === $traitB['file_sha'] && $traitA['symbol_start'] === null && $traitB['symbol_start'] === null
    && $traitA['reject_reason'] === 'manual_semantic_review' && $traitB['security_contract_sensitive'] === true);

$packetFile = $repo . '/packets.json';
writeJson($packetFile, $packetDocument);
$source = (string) file_get_contents($fixturePath);
$docStart = strpos($source, '/** @return string */');
if ($docStart === false) {
    throw new RuntimeException('doc fixture missing');
}
$start = $docStart + strlen('/** @return ');
$end = $start + strlen('string');
$safeProposal = makeResidualProposal($safe, 'application/Entities/Fixture.php', $safe['file_sha'], $start, $end);
$proposalFile = $repo . '/proposal.json';
$proposalDocument = ['schema_version' => 'phpstan-residual-proposals/v1', 'proposals' => [$safeProposal]];
$validated = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalDocument);
$validated['code'] === 0 || fwrite(STDERR, "proposal validation observed: {$validated['out']} {$validated['err']} bounds=" . json_encode([$safe['symbol_start'], $safe['symbol_end'], $start, $end]) . "\n");
residualCheck('valid proposal dry-run is eligible', $validated['code'] === 0
    && eligibleSites($validated['out']) === 1);
$shuffledPacketDocument = $packetDocument;
$caller = $shuffledPacketDocument['packets'][0]['callers'][0];
$callerLine = $caller['line'] ?? null;
if (!is_int($callerLine)) {
    throw new RuntimeException('caller line missing');
}
$shuffledPacketDocument['packets'][0]['callers'][0] = ['file_sha' => $caller['file_sha'], 'line' => $callerLine, 'path' => $caller['path']];
writeJson($repo . '/shuffled-packets.json', $shuffledPacketDocument);
writeJson($proposalFile, $proposalDocument);
$shuffledRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $repo . '/shuffled-packets.json', '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('canonical packet hash ignores nested object key order', $shuffledRun['code'] === 0);
$proposalTsv = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head, '--format=tsv',
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('proposal mode rejects TSV output', $proposalTsv['code'] !== 0);
$before = hash_file('sha256', $fixturePath);

$malformedFile = $repo . '/malformed.json';
file_put_contents($malformedFile, '{');
$malformed = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head, '--packets=' . $malformedFile]);
residualCheck('malformed PHPStan JSON rejected', $malformed['code'] !== 0);
$malformedContext = ['files' => [
    $fixturePath . ' (in context of class bad!)' => ['errors' => 1, 'messages' => [[
        'message' => 'Method bad::getValue() should return string but returns string|null.',
        'line' => 13, 'identifier' => 'return.type',
    ]]],
]];
writeJson($repo . '/malformed-context.json', $malformedContext);
$malformedContextRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packets=' . $repo . '/malformed-context.json']);
residualCheck('malformed analysis-context suffix rejected', $malformedContextRun['code'] !== 0
    && str_contains($malformedContextRun['err'], 'malformed PHPStan analysis-context path'));
$badSchema = $proposalDocument;
$badSchema['proposals'][0]['unexpected'] = true;
residualCheck('malformed proposal schema rejected without writes', runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $badSchema)['code'] !== 0 && hash_file('sha256', $fixturePath) === $before);
$badPacket = $packetDocument;
$badPacket['packets'][0]['packet_hash'] = str_repeat('0', 64);
writeJson($repo . '/bad-packets.json', $badPacket);
$badPacketRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $repo . '/bad-packets.json', '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('packet hash drift rejected', $badPacketRun['code'] !== 0);
$proposalHash = $proposalDocument;
$proposalHash['proposals'][0]['proposal_hash'] = str_repeat('0', 64);
$proposalHashRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalHash);
residualCheck('proposal hash drift rejected', $proposalHashRun['code'] !== 0 && rejection($proposalHashRun) === 'proposal_hash_drift');
$headRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . str_repeat('0', 40),
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('HEAD drift rejected', $headRun['code'] !== 0);
$badCount = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalDocument, ['--expect-diagnostics=3']);
residualCheck('diagnostic count mismatch rejects without writes', $badCount['code'] !== 0 && hash_file('sha256', $fixturePath) === $before);
$nonallow = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $otherSafe['identity'] . '@' . $otherSafe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('nonallowlisted packet rejected', $nonallow['code'] !== 0);
$low = $proposalDocument;
$low['proposals'][0]['confidence'] = 0.5;
$low = signedProposalDocument($low);
$lowRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $low);
residualCheck('low-confidence proposal rejected', $lowRun['code'] !== 0 && rejection($lowRun) === 'low_confidence');
$noTests = $proposalDocument;
$noTests['proposals'][0]['required_tests'] = [];
$noTests = signedProposalDocument($noTests);
$noTestsRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $noTests);
residualCheck('empty required-test evidence rejected', $noTestsRun['code'] !== 0 && rejection($noTestsRun) === 'malformed_proposal_schema');
$badTestPath = $proposalDocument;
$badTestPath['proposals'][0]['required_tests'] = ['application/Entities/Fixture.php'];
$badTestPath = signedProposalDocument($badTestPath);
$badTestPathRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $badTestPath);
residualCheck('required test must be existing repo test PHP', $badTestPathRun['code'] !== 0
    && rejection($badTestPathRun) === 'required_test_path_rejected');
$sensitivePacket = $byMethod['Entities\\Fixture::getPassword'];
$sensitive = makeResidualProposal($sensitivePacket, 'application/Entities/Fixture.php', $sensitivePacket['file_sha'], $start, $end);
writeJson($proposalFile, ['schema_version' => 'phpstan-residual-proposals/v1', 'proposals' => [$sensitive]]);
$sensitiveRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $sensitivePacket['identity'] . '@' . $sensitivePacket['packet_hash'], '--expect-sites=1', '--expect-diagnostics=1']);
residualCheck('security-contract proposal rejected', $sensitiveRun['code'] !== 0);
$policyPacketDocument = $packetDocument;
foreach ($policyPacketDocument['packets'] as &$policyPacket) {
    if ($policyPacket['method'] !== 'getPassword') {
        continue;
    }
    $policyPacket['rule'] = 'doc_comment_candidate';
    $policyPacket['reject_reason'] = '';
    $policyPacket['security_contract_sensitive'] = false;
    $policyHashInput = $policyPacket;
    unset($policyHashInput['packet_hash']);
    $policyPacket['packet_hash'] = residualCanonicalHash(['schema_version' => 'phpstan-residual-packets/v1', ...$policyHashInput]);
}
unset($policyPacket);
writeJson($repo . '/policy-override-packets.json', $policyPacketDocument);
$policyRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $repo . '/policy-override-packets.json', '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $sensitivePacket['identity'] . '@' . $policyPacketDocument['packets'][1]['packet_hash'],
    '--expect-sites=1', '--expect-diagnostics=1']);
residualCheck('model cannot override tool-derived security policy', $policyRun['code'] !== 0 && str_contains($policyRun['err'], 'packet policy drift'));
$evidencePacketDocument = $packetDocument;
$evidencePacketDocument['packets'][0]['callers'][0]['file_sha'] = str_repeat('0', 64);
$evidenceHashInput = $evidencePacketDocument['packets'][0];
unset($evidenceHashInput['packet_hash']);
$evidencePacketDocument['packets'][0]['packet_hash'] = residualCanonicalHash(['schema_version' => 'phpstan-residual-packets/v1', ...$evidenceHashInput]);
writeJson($repo . '/evidence-drift-packets.json', $evidencePacketDocument);
writeJson($proposalFile, $proposalDocument);
$evidenceRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $repo . '/evidence-drift-packets.json', '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $evidencePacketDocument['packets'][0]['packet_hash'],
    '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('caller evidence hash drift rejected', $evidenceRun['code'] !== 0 && str_contains($evidenceRun['err'], 'packet evidence hash drift'));
$ambiguousPackets = $packetDocument;
$ambiguousPackets['packets'][] = $safe;
writeJson($repo . '/ambiguous-packets.json', $ambiguousPackets);
$ambiguousRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $repo . '/ambiguous-packets.json', '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=1', '--expect-diagnostics=2']);
residualCheck('ambiguous packet identity rejected', $ambiguousRun['code'] !== 0);
$span = $proposalDocument;
$spanEdit = $span['proposals'][0]['edits'][0];
$span['proposals'][0]['edits'][0] = ['edit_kind' => $spanEdit['edit_kind'], 'start' => $spanEdit['start'],
    'end' => $spanEdit['end'], 'old' => 'wrong', 'new' => 'wrong|null'];
$span = signedProposalDocument($span);
$spanRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $span);
residualCheck('span drift rejected', $spanRun['code'] !== 0 && rejection($spanRun) === 'span_drift');
$multiedit = $proposalDocument;
$multiedit['proposals'][0]['edits'][] = $multiedit['proposals'][0]['edits'][0];
$multiedit = signedProposalDocument($multiedit);
$multieditRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $multiedit);
residualCheck('multiple edits rejected', $multieditRun['code'] !== 0 && rejection($multieditRun) === 'ambiguous_or_multiedit');
$runtime = $proposalDocument;
$runtimeStart = strpos($source, 'return $this->value');
if ($runtimeStart === false) {
    throw new RuntimeException('runtime fixture missing');
}
$runtime['proposals'][0]['edits'] = [['edit_kind' => 'doc_return_atom', 'start' => $runtimeStart, 'end' => $runtimeStart + 6, 'old' => 'return', 'new' => 'return|null']];
$runtime = signedProposalDocument($runtime);
$runtimeRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $runtime);
residualCheck('high-confidence malicious runtime replacement rejected', $runtimeRun['code'] !== 0 && rejection($runtimeRun) === 'runtime_control_edit');
$unmatched = $proposalDocument;
$unmatched['proposals'][0]['packet_identity'] = str_repeat('f', 64);
$unmatched = signedProposalDocument($unmatched);
$unmatchedRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $unmatched);
residualCheck('unmatched packet rejected', $unmatchedRun['code'] !== 0 && rejection($unmatchedRun) === 'unmatched_packet');
$manual = $proposalDocument;
$manual['proposals'][0]['classification'] = 'manual_review';
$manual = signedProposalDocument($manual);
$manualRun = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $manual);
residualCheck('non-safe classification rejected', $manualRun['code'] !== 0 && rejection($manualRun) === 'classification_not_applicable');
$extra = $proposalDocument;
$extra['proposals'][] = $unmatched['proposals'][0];
residualCheck('one-extra proposal rejects atomic batch', runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $extra)['code'] !== 0 && hash_file('sha256', $fixturePath) === $before);
$duplicateProposalDocument = ['schema_version' => 'phpstan-residual-proposals/v1', 'proposals' => [$safeProposal, $safeProposal]];
writeJson($proposalFile, $duplicateProposalDocument);
$duplicateProposalRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'], '--expect-sites=2', '--expect-diagnostics=4']);
residualCheck('multiple same-file proposals rejected atomically', $duplicateProposalRun['code'] !== 0
    && hash_file('sha256', $fixturePath) === $before && str_contains($duplicateProposalRun['out'], 'multi_proposal_batch'));

$otherSource = (string) file_get_contents($otherPath);
$otherDoc = strpos($otherSource, '/** @return string */');
if ($otherDoc === false) {
    throw new RuntimeException('other doc missing');
}
$otherProposal = makeResidualProposal($otherSafe, 'application/Entities/Other.php', $otherSafe['file_sha'], $otherDoc + 12, $otherDoc + 18);
$multiFileDoc = ['schema_version' => 'phpstan-residual-proposals/v1', 'proposals' => [$safeProposal, $otherProposal]];
writeJson($proposalFile, $multiFileDoc);
$multiFileRun = residualRun([PHP_BINARY, $tool, '--repo=' . $repo, '--expect-head=' . $head,
    '--packet-file=' . $packetFile, '--proposal-file=' . $proposalFile,
    '--allow-packet=' . $safe['identity'] . '@' . $safe['packet_hash'],
    '--allow-packet=' . $otherSafe['identity'] . '@' . $otherSafe['packet_hash'], '--expect-sites=2', '--expect-diagnostics=3']);
residualCheck('multifile batch rejected atomically', $multiFileRun['code'] !== 0
    && hash_file('sha256', $fixturePath) === $before && hash_file('sha256', $otherPath) === $otherSafe['file_sha']);

writeJson($proposalFile, $proposalDocument);
$toctou = runToctouControl(
    $tool,
    $repo,
    $head,
    $packetFile,
    $proposalFile,
    $safe['identity'] . '@' . $safe['packet_hash'],
    $fixturePath
);
residualCheck('TOCTOU mutation is observed red before atomic replace', $toctou['code'] !== 0
    && str_contains($toctou['err'], 'proposal source changed before write')
    && !str_contains((string) file_get_contents($fixturePath), '/** @return string|null */'));
file_put_contents($fixturePath, $source);
residualRun(['rm', '--', $toctou['signal'] . '.ready', $toctou['signal'] . '.continue']);
$toctouRestored = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalDocument);
residualCheck('TOCTOU source restoration returns validation green', $toctouRestored['code'] === 0
    && hash_file('sha256', $fixturePath) === $before);

$applied = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalDocument, ['--apply-proposals']);
$applied['code'] === 0 || fwrite(STDERR, "proposal apply observed: {$applied['out']} {$applied['err']}\n");
$after = hash_file('sha256', $fixturePath);
residualCheck('guarded proposal applies exact doc span', $applied['code'] === 0 && $after !== $before
    && str_contains((string) file_get_contents($fixturePath), '/** @return string|null */'));
$second = runProposalFixture($tool, $repo, $head, $packetFile, $proposalFile, $safe, $proposalDocument, ['--apply-proposals']);
residualCheck('second application is idempotent and rejects stale hash', $second['code'] !== 0
    && hash_file('sha256', $fixturePath) === $after);

residualCheck('fixed pre-invariant assertion count', ResidualPacketTestState::$checks === 47);
residualRun(['rm', '-rf', '--', $repo]);
echo ResidualPacketTestState::$failures === 0 ? "ALL PASSED\n" : ResidualPacketTestState::$failures . " FAILED\n";
exit(ResidualPacketTestState::$failures === 0 ? 0 : 1);
