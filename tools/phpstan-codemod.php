#!/usr/bin/env php
<?php

declare(strict_types=1);

const FIELDS = [
    'head', 'baseline_sha', 'path', 'line', 'class', 'method', 'property',
    'id', 'count', 'old', 'new', 'status', 'reject_reason', 'file_sha',
];

/** @return never */
function fail(string $message, int $code = 2): void
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @param list<string> $argv
 * @return array{apply:bool,proposal_apply:bool,format:string,family:?string,repo:string,head:?string,sites:?int,diagnostics:?int,allows:list<string>,packet_input:?string,packet_file:?string,proposal_file:?string,packet_allows:list<string>}
 */
function options(array $argv): array
{
    $result = [
        'apply' => false,
        'proposal_apply' => false,
        'format' => 'json',
        'family' => null,
        'repo' => getcwd() ?: '.',
        'head' => null,
        'sites' => null,
        'diagnostics' => null,
        'allows' => [],
        'packet_input' => null,
        'packet_file' => null,
        'proposal_file' => null,
        'packet_allows' => [],
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') {
            $result['apply'] = true;
            continue;
        }
        if ($argument === '--apply-proposals') {
            $result['proposal_apply'] = true;
            continue;
        }
        if ($argument === '--help') {
            echo "usage: tools/phpstan-codemod.php [--apply] --repo=DIR --format=json|tsv\n";
            echo "       --expect-head=SHA --allow=FILE:METHOD@FILE_SHA [--allow=...]\n";
            echo "       --family=test-harness-static-counter --allow=FILE:VARIABLE@FILE_SHA\n";
            echo "       --apply also requires --expect-sites=N --expect-diagnostics=N\n";
            echo "       --packets=PHPSTAN.json --expect-head=SHA\n";
            echo "       --packet-file=PACKETS.json --proposal-file=MODEL.json --allow-packet=ID@HASH\n";
            echo "       proposal validation and --apply-proposals require exact site/diagnostic counts\n";
            exit(0);
        }
        $pairs = [
            '--format=' => 'format', '--repo=' => 'repo', '--expect-head=' => 'head',
            '--family=' => 'family',
            '--expect-sites=' => 'sites', '--expect-diagnostics=' => 'diagnostics',
            '--packets=' => 'packet_input', '--packet-file=' => 'packet_file',
            '--proposal-file=' => 'proposal_file',
        ];
        $matched = false;
        foreach ($pairs as $prefix => $key) {
            if (str_starts_with($argument, $prefix)) {
                $value = substr($argument, strlen($prefix));
                if ($key === 'sites' || $key === 'diagnostics') {
                    if ($value === '' || !ctype_digit($value)) {
                        fail("invalid $prefix value");
                    }
                    $result[$key] = (int) $value;
                } else {
                    $result[$key] = $value;
                }
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        if (str_starts_with($argument, '--allow=')) {
            $result['allows'][] = substr($argument, strlen('--allow='));
            continue;
        }
        if (str_starts_with($argument, '--allow-packet=')) {
            $result['packet_allows'][] = substr($argument, strlen('--allow-packet='));
            continue;
        }
        fail("unknown argument: $argument");
    }
    if (!in_array($result['format'], ['json', 'tsv'], true)) {
        fail('format must be json or tsv');
    }
    if ($result['family'] !== null && $result['family'] !== 'test-harness-static-counter') {
        fail('unknown family');
    }
    if ($result['head'] === null || $result['head'] === '') {
        fail('--expect-head is required');
    }
    $packetMode = $result['packet_input'] !== null;
    $proposalMode = $result['packet_file'] !== null || $result['proposal_file'] !== null;
    if (($packetMode ? 1 : 0) + ($proposalMode ? 1 : 0) + ($result['allows'] === [] ? 0 : 1) !== 1) {
        fail('choose exactly one getter, packet, or proposal mode');
    }
    if (!$packetMode && !$proposalMode && $result['allows'] === []) {
        fail('at least one --allow=FILE:METHOD@FILE_SHA is required');
    }
    if ($result['apply'] && ($result['sites'] === null || $result['diagnostics'] === null)) {
        fail('--apply requires --expect-sites and --expect-diagnostics');
    }
    if ($packetMode && ($result['apply'] || $result['proposal_apply'])) {
        fail('packet generation is dry-run only');
    }
    if ($proposalMode && ($result['packet_file'] === null || $result['proposal_file'] === null
        || $result['packet_allows'] === [] || $result['sites'] === null || $result['diagnostics'] === null
        || $result['apply'])) {
        fail('proposal mode requires packet/proposal files, packet allowlist, and exact counts');
    }
    if (($packetMode || $proposalMode) && $result['format'] !== 'json') {
        fail('packet and proposal modes support JSON only');
    }
    if ($result['family'] !== null && ($packetMode || $proposalMode)) {
        fail('family mode cannot be combined with packet or proposal mode');
    }
    return $result;
}

function gitHead(string $repo): string
{
    $pipes = [];
    $process = proc_open(
        ['git', '-C', $repo, 'rev-parse', 'HEAD'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        fail("cannot start Git for $repo");
    }
    $head = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !preg_match('/^[0-9a-f]{40}$/', $head)) {
        fail("cannot resolve Git HEAD for $repo");
    }
    return $head;
}

/** @return array{path:string,method:string,sha:string} */
function parseAllow(string $allow): array
{
    $at = strrpos($allow, '@');
    $colon = $at === false ? false : strrpos(substr($allow, 0, $at), ':');
    if ($at === false || $colon === false) {
        fail("invalid allowlist entry: $allow");
    }
    $path = substr($allow, 0, $colon);
    $method = substr($allow, $colon + 1, $at - $colon - 1);
    $sha = substr($allow, $at + 1);
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)
        || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
        fail("invalid allowlist entry: $allow");
    }
    return ['path' => $path, 'method' => $method, 'sha' => $sha];
}

/** @return list<array{path:string,id:string,count:int,message:string,class:?string,method:?string,atom:?string}> */
function baselineEntries(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fail("cannot read baseline: $file");
    }
    $entries = [];
    $current = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        foreach (['message', 'identifier', 'count', 'path'] as $key) {
            $prefix = $key . ':';
            if (str_starts_with($trimmed, $prefix)) {
                $current[$key] = trim(substr($trimmed, strlen($prefix)));
            }
        }
        if (isset($current['message'], $current['identifier'], $current['count'], $current['path'])) {
            $message = trim($current['message'], "'\"");
            $plain = str_replace(['#^', '$#'], '', $message);
            $plain = preg_replace('/\\\\([:\\(\\)\\.\\|+\\-=])/', '$1', $plain) ?? $plain;
            $plain = str_replace('\\\\', '\\', $plain);
            $class = $method = $atom = null;
            if (preg_match('/^Method ([A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)\(\) should return ([A-Za-z0-9_\\\\]+) but returns \\3\|null\.$/', $plain, $match)) {
                [, $class, $method, $atom] = $match;
            }
            $entries[] = [
                'path' => $current['path'],
                'id' => $current['identifier'],
                'count' => ctype_digit($current['count']) ? (int) $current['count'] : -1,
                'message' => $plain,
                'class' => $class,
                'method' => $method,
                'atom' => $atom,
            ];
            $current = [];
        }
    }
    return $entries;
}

/** @return list<array{id:int|string|null,text:string,start:int,line:int}> */
function tokenSpans(string $source): array
{
    $result = [];
    $offset = 0;
    $line = 1;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            [$id, $text, $tokenLine] = $token;
        } else {
            $id = null;
            $text = $token;
            $tokenLine = $line;
        }
        $result[] = ['id' => $id, 'text' => $text, 'start' => $offset, 'line' => $tokenLine];
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    return $result;
}

/** @param array{id:int|string|null,text:string,start:int,line:int} $token */
function insignificant(array $token): bool
{
    return $token['id'] === T_WHITESPACE;
}

/**
 * @param list<array{id:int|string|null,text:string,start:int,line:int}> $tokens
 * @return array<string,array{nullable:bool,null:bool,type:string}>
 */
function properties(array $tokens, int $classOpen, int $classClose): array
{
    $result = [];
    $depth = 1;
    for ($i = $classOpen + 1; $i < $classClose; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '{') {
            $depth++;
            continue;
        }
        if ($text === '}') {
            $depth--;
            continue;
        }
        if ($depth !== 1 || !in_array($tokens[$i]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
            continue;
        }
        $end = $i;
        while ($end < $classClose && $tokens[$end]['text'] !== ';' && $tokens[$end]['text'] !== '{') {
            $end++;
        }
        if ($end >= $classClose || $tokens[$end]['text'] !== ';') {
            continue;
        }
        $variable = null;
        $variableIndex = null;
        for ($j = $i; $j < $end; $j++) {
            if ($tokens[$j]['id'] === T_FUNCTION) {
                $variable = null;
                break;
            }
            if ($tokens[$j]['id'] === T_VARIABLE) {
                $variable = substr($tokens[$j]['text'], 1);
                $variableIndex = $j;
                break;
            }
        }
        if ($variable === null || $variableIndex === null) {
            continue;
        }
        $nullable = false;
        $type = '';
        for ($j = $i; $j < $variableIndex; $j++) {
            if ($tokens[$j]['text'] === '?') {
                $nullable = true;
            }
            if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $type .= $tokens[$j]['text'];
            }
        }
        $initializedNull = false;
        for ($j = $variableIndex + 1; $j < $end; $j++) {
            if ($tokens[$j]['text'] !== '=') {
                continue;
            }
            for ($j++; $j < $end && insignificant($tokens[$j]); $j++) {
            }
            $initializedNull = $j < $end && $tokens[$j]['id'] === T_STRING
                && strtolower($tokens[$j]['text']) === 'null';
            break;
        }
        $result[$variable] = ['nullable' => $nullable, 'null' => $initializedNull, 'type' => $type];
        $i = $end;
    }
    return $result;
}

/**
 * @param array{id:int|string|null,text:string,start:int,line:int} $doc
 * @return array{atom:string,start:int,end:int}|array{reject:string}
 */
function docReturn(array $doc): array
{
    $text = $doc['text'];
    $positions = [];
    $offset = 0;
    while (($position = strpos($text, '@return', $offset)) !== false) {
        $positions[] = $position;
        $offset = $position + 7;
    }
    if (count($positions) !== 1) {
        return ['reject' => 'doc_return_count'];
    }
    $cursor = $positions[0] + 7;
    while (isset($text[$cursor]) && ($text[$cursor] === ' ' || $text[$cursor] === "\t")) {
        $cursor++;
    }
    $start = $cursor;
    while (isset($text[$cursor]) && !in_array($text[$cursor], [' ', "\t", "\r", "\n", '*'], true)) {
        $cursor++;
    }
    $atom = substr($text, $start, $cursor - $start);
    $editEnd = $cursor;
    while (isset($text[$editEnd]) && ($text[$editEnd] === ' ' || $text[$editEnd] === "\t")) {
        $editEnd++;
    }
    if (!isset($text[$editEnd]) || !in_array($text[$editEnd], ["\r", "\n"], true)) {
        $editEnd = $cursor;
    }
    $restEnd = strpos($text, "\n", $cursor);
    $rest = substr($text, $cursor, ($restEnd === false ? strlen($text) : $restEnd) - $cursor);
    if ($atom === '' || trim($rest, " \t\r*/") !== '') {
        return ['reject' => 'doc_return_not_single_atom'];
    }
    if (str_contains(strtolower($atom), 'null')) {
        return ['reject' => 'already_nullable'];
    }
    return [
        'atom' => $atom,
        'start' => $doc['start'] + $start,
        'end' => $doc['start'] + $editEnd,
    ];
}

function normalizeAtom(string $atom): string
{
    $atom = strtolower(ltrim($atom, '\\'));
    return ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'][$atom] ?? $atom;
}

/**
 * @return array{reject:string}|array{line:int,class:string,property:string,old:string,new:string,start:int,end:int}
 */
function inspectMethod(string $source, string $method, string $expectedClass, string $expectedAtom): array
{
    try {
        $tokens = tokenSpans($source);
    } catch (ParseError) {
        return ['reject' => 'source_parse_error'];
    }
    $namespace = '';
    for ($i = 0; $i < count($tokens); $i++) {
        if ($tokens[$i]['id'] !== T_NAMESPACE) {
            continue;
        }
        for ($j = $i + 1; $j < count($tokens) && !in_array($tokens[$j]['text'], [';', '{'], true); $j++) {
            if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)
                || $tokens[$j]['id'] === T_NS_SEPARATOR) {
                $namespace .= $tokens[$j]['text'];
            }
        }
        break;
    }
    $className = null;
    $classOpen = $classClose = null;
    for ($i = 0; $i < count($tokens); $i++) {
        if ($tokens[$i]['id'] !== T_CLASS) {
            continue;
        }
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j]['id'] === T_STRING) {
                $className = $tokens[$j]['text'];
            }
            if ($tokens[$j]['text'] === '{') {
                $classOpen = $j;
                break;
            }
        }
        if ($classOpen !== null) {
            $depth = 1;
            for ($j = $classOpen + 1; $j < count($tokens); $j++) {
                if ($tokens[$j]['text'] === '{') {
                    $depth++;
                }
                if ($tokens[$j]['text'] === '}' && --$depth === 0) {
                    $classClose = $j;
                    break;
                }
            }
            break;
        }
    }
    $qualifiedClass = $className === null ? null
        : ($namespace === '' ? $className : $namespace . '\\' . $className);
    if ($className === null || $classOpen === null || $classClose === null
        || ltrim($expectedClass, '\\') !== $qualifiedClass) {
        return ['reject' => 'class_mismatch'];
    }
    $propertyMap = properties($tokens, $classOpen, $classClose);
    $depth = 1;
    for ($i = $classOpen + 1; $i < $classClose; $i++) {
        if ($tokens[$i]['text'] === '{') {
            $depth++;
            continue;
        }
        if ($tokens[$i]['text'] === '}') {
            $depth--;
            continue;
        }
        if ($depth !== 1 || $tokens[$i]['id'] !== T_FUNCTION) {
            continue;
        }
        $nameIndex = null;
        for ($j = $i + 1; $j < $classClose; $j++) {
            if ($tokens[$j]['id'] === T_STRING) {
                $nameIndex = $j;
                break;
            }
            if ($tokens[$j]['text'] === '(') {
                break;
            }
        }
        if ($nameIndex === null || $tokens[$nameIndex]['text'] !== $method) {
            continue;
        }
        $openParen = $nameIndex + 1;
        while ($tokens[$openParen]['text'] !== '(') {
            $openParen++;
        }
        $parenDepth = 1;
        for ($closeParen = $openParen + 1; $closeParen < $classClose; $closeParen++) {
            if ($tokens[$closeParen]['text'] === '(') {
                $parenDepth++;
            }
            if ($tokens[$closeParen]['text'] === ')' && --$parenDepth === 0) {
                break;
            }
        }
        for ($j = $openParen + 1; $j < $closeParen; $j++) {
            if (!insignificant($tokens[$j])) {
                return ['reject' => 'method_has_parameters'];
            }
        }
        $bodyOpen = $closeParen + 1;
        $native = [];
        while ($bodyOpen < $classClose && $tokens[$bodyOpen]['text'] !== '{') {
            if (!insignificant($tokens[$bodyOpen])) {
                $native[] = $tokens[$bodyOpen];
            }
            $bodyOpen++;
        }
        if ($native !== [] && $native[0]['text'] === ':' && !in_array('?', array_column($native, 'text'), true)
            && !in_array('null', array_map('strtolower', array_column($native, 'text')), true)) {
            return ['reject' => 'native_non_nullable_return'];
        }
        $bodyDepth = 1;
        for ($bodyClose = $bodyOpen + 1; $bodyClose < $classClose; $bodyClose++) {
            if ($tokens[$bodyClose]['text'] === '{') {
                $bodyDepth++;
            }
            if ($tokens[$bodyClose]['text'] === '}' && --$bodyDepth === 0) {
                break;
            }
        }
        $meaningful = [];
        for ($j = $bodyOpen + 1; $j < $bodyClose; $j++) {
            if (!insignificant($tokens[$j])) {
                $meaningful[] = $tokens[$j];
            }
        }
        $exact = count($meaningful) === 5
            && $meaningful[0]['id'] === T_RETURN
            && $meaningful[1]['id'] === T_VARIABLE && $meaningful[1]['text'] === '$this'
            && $meaningful[2]['id'] === T_OBJECT_OPERATOR
            && $meaningful[3]['id'] === T_STRING
            && $meaningful[4]['text'] === ';';
        if (!$exact) {
            return ['reject' => 'getter_body_not_exact'];
        }
        $property = $meaningful[3]['text'];
        if (!isset($propertyMap[$property]) || !$propertyMap[$property]['nullable']
            || !$propertyMap[$property]['null'] || strtolower($propertyMap[$property]['type']) === 'mixed') {
            return ['reject' => 'property_not_explicit_nullable_null'];
        }
        $doc = null;
        for ($j = $i - 1; $j > $classOpen; $j--) {
            if ($tokens[$j]['id'] === T_DOC_COMMENT) {
                $doc = $tokens[$j];
                break;
            }
            if (in_array($tokens[$j]['text'], [';', '}'], true)) {
                break;
            }
        }
        if ($doc === null) {
            return ['reject' => 'missing_method_doc'];
        }
        $return = docReturn($doc);
        if (isset($return['reject'])) {
            return $return;
        }
        if (normalizeAtom($return['atom']) !== normalizeAtom($expectedAtom)) {
            return ['reject' => 'doc_atom_diagnostic_mismatch'];
        }
        return [
            'line' => $tokens[$i]['line'], 'class' => $expectedClass, 'property' => $property,
            'old' => $return['atom'], 'new' => $return['atom'] . '|null',
            'start' => $return['start'], 'end' => $return['end'],
        ];
    }
    return ['reject' => 'method_not_found'];
}

/**
 * @param list<array<string,int|string>> $records
 * @param array<string,int|string> $meta
 */
function emit(array $records, array $meta, string $format): void
{
    if ($format === 'json') {
        echo json_encode(['meta' => $meta, 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }
    echo implode("\t", FIELDS) . "\n";
    foreach ($records as $record) {
        $values = [];
        foreach (FIELDS as $field) {
            $values[] = str_replace(["\t", "\r", "\n"], ' ', (string) ($record[$field] ?? ''));
        }
        echo implode("\t", $values) . "\n";
    }
}

/**
 * @param array<string,string> $contents
 * @param array<string,string> $expectedHashes
 */
function atomicWrite(array $contents, array $expectedHashes = []): void
{
    $temps = $backups = [];
    try {
        foreach ($contents as $file => $content) {
            $temp = tempnam(dirname($file), '.phpstan-codemod.');
            if ($temp === false || file_put_contents($temp, $content) === false) {
                throw new RuntimeException("cannot stage $file");
            }
            $permissions = fileperms($file);
            if ($permissions === false || !chmod($temp, $permissions & 0777)) {
                throw new RuntimeException("cannot preserve mode for $file");
            }
            $temps[$file] = $temp;
            $original = file_get_contents($file);
            if ($original === false) {
                throw new RuntimeException("cannot back up $file");
            }
            $backups[$file] = $original;
        }
        foreach ($expectedHashes as $file => $expectedSha) {
            $actualSha = hash_file('sha256', $file);
            if ($actualSha === false || !hash_equals($expectedSha, $actualSha)) {
                throw new RuntimeException("file changed before atomic replace: $file");
            }
        }
        $written = [];
        foreach ($temps as $file => $temp) {
            if (!rename($temp, $file)) {
                throw new RuntimeException("cannot replace $file");
            }
            $written[] = $file;
        }
    } catch (Throwable $error) {
        foreach ($backups as $file => $content) {
            if (isset($written) && in_array($file, $written, true)) {
                file_put_contents($file, $content);
            }
        }
        foreach ($temps as $temp) {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        fail('atomic write failed: ' . $error->getMessage(), 1);
    }
}

const PACKET_SCHEMA = 'phpstan-residual-packets/v1';
const PROPOSAL_SCHEMA = 'phpstan-residual-proposals/v1';
const PROPOSAL_CLASSIFICATIONS = ['mechanical_safe', 'manual_review', 'reject'];

final class PhpstanCodemodSymbolScope
{
    public function __construct(public ?string $className, public ?string $methodName)
    {
    }
}

/** @return array<string,mixed> */
function jsonObject(string $file, string $label): array
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        fail("cannot read $label: $file");
    }
    try {
        $value = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail("malformed $label JSON");
    }
    if (!is_array($value) || array_is_list($value)) {
        fail("$label must be a JSON object");
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            fail("$label object keys must be strings");
        }
        $result[$key] = $item;
    }
    return $result;
}

/** @return array<string,mixed> */
function stringMap(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        fail("$label must be an object");
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            fail("$label keys must be strings");
        }
        $result[$key] = $item;
    }
    return $result;
}

function relativeFile(string $repo, string $path): ?string
{
    $normalized = str_replace('\\', '/', $path);
    $repoNormalized = rtrim(str_replace('\\', '/', $repo), '/');
    if (str_starts_with($normalized, '/workspace/')) {
        $normalized = substr($normalized, 11);
    } elseif (str_starts_with($normalized, $repoNormalized . '/')) {
        $normalized = substr($normalized, strlen($repoNormalized) + 1);
    }
    if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, '../')) {
        return null;
    }
    return $normalized;
}

/** @return array{path:string,analysis_context:?string} */
function diagnosticPath(string $repo, string $rawPath): array
{
    $analysisContext = null;
    $physicalPath = $rawPath;
    $marker = ' (in context of class ';
    if (str_contains($rawPath, $marker)) {
        if (!preg_match('/^(.*) \(in context of class ([A-Za-z_][A-Za-z0-9_\\\\]*)\)$/', $rawPath, $match)) {
            fail('malformed PHPStan analysis-context path');
        }
        $physicalPath = $match[1];
        $analysisContext = $match[2];
    }
    $path = relativeFile($repo, $physicalPath);
    if ($path === null) {
        fail('invalid PHPStan diagnostic path');
    }
    return ['path' => $path, 'analysis_context' => $analysisContext];
}

/** @return array{class:?string,method:?string,symbol_start:?int,symbol_end:?int} */
function diagnosticSymbol(string $source, int $line, string $message): array
{
    $messageClass = $messageMethod = null;
    if (preg_match('/(?:Method|Call to method) ([A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)/', $message, $match)) {
        $messageClass = $match[1];
        $messageMethod = $match[2];
    }
    try {
        $tokens = tokenSpans($source);
    } catch (ParseError) {
        return ['class' => $messageClass, 'method' => $messageMethod, 'symbol_start' => null, 'symbol_end' => null];
    }
    $namespace = '';
    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_NAMESPACE) {
            continue;
        }
        for ($i = $index + 1; isset($tokens[$i]) && !in_array($tokens[$i]['text'], [';', '{'], true); $i++) {
            if (in_array($tokens[$i]['id'], [T_STRING, T_NAME_QUALIFIED], true) || $tokens[$i]['id'] === T_NS_SEPARATOR) {
                $namespace .= $tokens[$i]['text'];
            }
        }
        break;
    }
    $class = null;
    $method = null;
    $pendingClass = null;
    $pendingMethod = null;
    $stack = [];
    foreach ($tokens as $index => $token) {
        if ($token['line'] > $line) {
            break;
        }
        if ($token['id'] === T_CLASS) {
            for ($i = $index + 1; isset($tokens[$i]); $i++) {
                if ($tokens[$i]['id'] === T_STRING) {
                    $pendingClass = $namespace === '' ? $tokens[$i]['text'] : $namespace . '\\' . $tokens[$i]['text'];
                    break;
                }
                if ($tokens[$i]['text'] === '{') {
                    break;
                }
            }
        }
        if ($token['id'] === T_FUNCTION) {
            for ($i = $index + 1; isset($tokens[$i]); $i++) {
                if ($tokens[$i]['id'] === T_STRING) {
                    $pendingMethod = $tokens[$i]['text'];
                    break;
                }
                if ($tokens[$i]['text'] === '(') {
                    break;
                }
            }
        }
        if ($token['text'] === '{') {
            $stack[] = new PhpstanCodemodSymbolScope($class, $method);
            if ($pendingClass !== null) {
                $class = $pendingClass;
                $pendingClass = null;
                $method = null;
            } elseif ($pendingMethod !== null) {
                $method = $pendingMethod;
                $pendingMethod = null;
            }
        } elseif ($token['text'] === '}' && $stack !== []) {
            $scope = array_pop($stack);
            $class = $scope->className;
            $method = $scope->methodName;
        }
    }
    $resolvedClass = $messageClass ?? $class;
    $resolvedMethod = $messageMethod ?? $method;
    $symbolStart = $symbolEnd = null;
    if ($resolvedMethod !== null) {
        $searchClass = $pendingSearchClass = null;
        $searchStack = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_CLASS) {
                for ($i = $index + 1; isset($tokens[$i]); $i++) {
                    if ($tokens[$i]['id'] === T_STRING) {
                        $pendingSearchClass = $namespace === '' ? $tokens[$i]['text'] : $namespace . '\\' . $tokens[$i]['text'];
                        break;
                    }
                    if ($tokens[$i]['text'] === '{') {
                        break;
                    }
                }
            }
            if ($token['text'] === '{') {
                $searchStack[] = new PhpstanCodemodSymbolScope($searchClass, null);
                if ($pendingSearchClass !== null) {
                    $searchClass = $pendingSearchClass;
                    $pendingSearchClass = null;
                }
            } elseif ($token['text'] === '}' && $searchStack !== []) {
                $searchClass = array_pop($searchStack)->className;
            }
            if ($token['id'] !== T_FUNCTION) {
                continue;
            }
            if ($resolvedClass !== null && ltrim($resolvedClass, '\\') !== $searchClass) {
                continue;
            }
            $nameIndex = null;
            for ($i = $index + 1; isset($tokens[$i]); $i++) {
                if ($tokens[$i]['id'] === T_STRING) {
                    $nameIndex = $i;
                    break;
                }
                if ($tokens[$i]['text'] === '(') {
                    break;
                }
            }
            if ($nameIndex === null || $tokens[$nameIndex]['text'] !== $resolvedMethod) {
                continue;
            }
            $symbolStart = $token['start'];
            for ($i = $index - 1; $i >= 0; $i--) {
                if ($tokens[$i]['id'] === T_DOC_COMMENT) {
                    $symbolStart = $tokens[$i]['start'];
                    break;
                }
                if (!insignificant($tokens[$i]) && !in_array($tokens[$i]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                    break;
                }
            }
            $open = null;
            for ($i = $nameIndex; isset($tokens[$i]); $i++) {
                if ($tokens[$i]['text'] === '{') {
                    $open = $i;
                    break;
                }
            }
            if ($open !== null) {
                $depth = 1;
                for ($i = $open + 1; isset($tokens[$i]); $i++) {
                    if ($tokens[$i]['text'] === '{') {
                        $depth++;
                    } elseif ($tokens[$i]['text'] === '}' && --$depth === 0) {
                        $symbolEnd = $tokens[$i]['start'] + 1;
                        break;
                    }
                }
            }
            break;
        }
    }
    return ['class' => $resolvedClass, 'method' => $resolvedMethod, 'symbol_start' => $symbolStart, 'symbol_end' => $symbolEnd];
}

/** @return list<string> */
function phpFiles(string $repo): array
{
    $files = [];
    foreach (['application', 'library', 'src', 'tests'] as $root) {
        $directory = $repo . '/' . $root;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink() && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($repo) + 1);
                if (preg_match('#(?:^|/)(?:vendor|cache|generated|proxies?)(?:/|$)#i', $relative)) {
                    continue;
                }
                $files[] = $relative;
            }
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/** @return array{callers:list<array{path:string,line:int,file_sha:string}>,tests:list<array{path:string,file_sha:string}>,complete:bool} */
function relevantLocations(string $repo, ?string $class, ?string $method, string $ownPath): array
{
    if ($method === null) {
        return ['callers' => [], 'tests' => [], 'complete' => false];
    }
    $callers = $tests = [];
    foreach (phpFiles($repo) as $path) {
        if ($path === $ownPath) {
            continue;
        }
        $source = file_get_contents($repo . '/' . $path);
        if ($source === false || (!str_contains($source, $method) && ($class === null || !str_contains($source, basename(str_replace('\\', '/', $class)))))) {
            continue;
        }
        $lines = explode("\n", $source);
        $sha = hash_file('sha256', $repo . '/' . $path);
        foreach ($lines as $number => $text) {
            $methodCall = preg_match('/(?:->|::)\s*' . preg_quote($method, '/') . '\s*\(/', $text) === 1;
            if ($methodCall && count($callers) < 20 && is_string($sha)) {
                $callers[] = ['path' => $path, 'line' => $number + 1, 'file_sha' => $sha];
            }
            if (str_starts_with($path, 'tests/') && (str_contains($text, $method)
                || ($class !== null && str_contains($text, basename(str_replace('\\', '/', $class)))))) {
                if (is_string($sha)) {
                    $tests[$path] = ['path' => $path, 'file_sha' => $sha];
                }
            }
        }
    }
    $uniqueCallers = [];
    foreach ($callers as $caller) {
        $uniqueCallers[$caller['path'] . ':' . $caller['line']] = $caller;
    }
    return ['callers' => array_values($uniqueCallers), 'tests' => array_slice(array_values($tests), 0, 20), 'complete' => false];
}

function strictDocCandidate(string $source, string $identifier, string $message, ?string $class, ?string $method): bool
{
    if ($identifier !== 'return.type' || $class === null || $method === null
        || !preg_match('/^Method [A-Za-z0-9_\\\\]+::[A-Za-z_][A-Za-z0-9_]*\(\) should return ([A-Za-z0-9_\\\\]+) but returns \1\|null\.$/', $message, $match)) {
        return false;
    }
    return !isset(inspectMethod($source, $method, $class, $match[1])['reject']);
}

/** @return array{rule:string,reject_reason:string,sensitive:bool} */
function packetRule(string $path, string $identifier, string $message, ?string $method, bool $strictDoc): array
{
    $needle = strtolower($path . ' ' . $message . ' ' . ($method ?? ''));
    $security = preg_match('/(?:admin|auth|password|secret|token|permission|privilege|super|getsuper|role|account|mcp|access[_ ]control|session|crypt|csrf|nonce|credential|signingkey|privatekey|apikey|encryptionkey)/', $needle) === 1;
    if ($security) {
        return ['rule' => 'security_contract', 'reject_reason' => 'security_contract_sensitive', 'sensitive' => true];
    }
    if ($method !== null && preg_match('/(?:status|type)label$/i', $method)) {
        return ['rule' => 'computed_getter', 'reject_reason' => 'computed_runtime_contract', 'sensitive' => true];
    }
    if (str_contains(strtolower($message), 'mixed') || str_contains(strtolower($method ?? ''), 'jpegphoto')) {
        return ['rule' => 'mixed_contract', 'reject_reason' => 'mixed_runtime_contract', 'sensitive' => true];
    }
    if ($strictDoc) {
        return ['rule' => 'doc_comment_candidate', 'reject_reason' => '', 'sensitive' => false];
    }
    return ['rule' => 'semantic_review', 'reject_reason' => 'manual_semantic_review', 'sensitive' => true];
}

/** @param array<string,mixed> $value */
function canonicalHash(array $value): string
{
    return hash('sha256', json_encode(canonicalValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function canonicalValue(mixed $value): mixed
{
    if (!is_array($value)) {
        if (is_object($value) || is_resource($value)) {
            fail('non-JSON value cannot be hashed');
        }
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalValue', $value);
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            fail('canonical object keys must be strings');
        }
        $result[$key] = canonicalValue($item);
    }
    ksort($result, SORT_STRING);
    return $result;
}

/** @return never */
function emitPackets(string $repo, string $head, string $baselineSha, string $input): void
{
    $phpstan = jsonObject($input, 'PHPStan');
    if (!isset($phpstan['files']) || !is_array($phpstan['files']) || array_is_list($phpstan['files'])) {
        fail('PHPStan JSON files must be an object');
    }
    $grouped = [];
    foreach ($phpstan['files'] as $rawPath => $file) {
        if (!is_string($rawPath)) {
            fail('malformed PHPStan file path');
        }
        $pathInfo = diagnosticPath($repo, $rawPath);
        $path = $pathInfo['path'];
        if (!is_array($file) || !is_array($file['messages'] ?? null)) {
            fail('malformed PHPStan file record');
        }
        foreach ($file['messages'] as $message) {
            if (!is_array($message) || !is_int($message['line'] ?? null) || ($message['line'] ?? 0) < 1
                || !is_string($message['identifier'] ?? null) || !is_string($message['message'] ?? null)) {
                fail('malformed PHPStan diagnostic');
            }
            $key = implode("\0", [$path, $pathInfo['analysis_context'] ?? '', (string) $message['line'], $message['identifier'], $message['message']]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['path' => $path, 'analysis_context' => $pathInfo['analysis_context'], 'line' => $message['line'], 'id' => $message['identifier'], 'message' => $message['message'], 'count' => 0];
            }
            $grouped[$key]['count']++;
        }
    }
    ksort($grouped, SORT_STRING);
    $packets = [];
    foreach ($grouped as $diagnostic) {
        $candidate = $repo . '/' . $diagnostic['path'];
        $resolved = realpath($candidate);
        if ($resolved === false || !str_starts_with($resolved, $repo . DIRECTORY_SEPARATOR) || is_link($candidate) || !is_file($resolved)) {
            fail('diagnostic path is not a regular repository file: ' . $diagnostic['path']);
        }
        $source = file_get_contents($resolved);
        $fileSha = hash_file('sha256', $resolved);
        if ($source === false || $fileSha === false) {
            fail('cannot read diagnostic source');
        }
        $symbol = diagnosticSymbol($source, $diagnostic['line'], $diagnostic['message']);
        $locations = relevantLocations($repo, $symbol['class'], $symbol['method'], $diagnostic['path']);
        $sourceLines = explode("\n", $source);
        $first = max(1, $diagnostic['line'] - 2);
        $last = min(count($sourceLines), $diagnostic['line'] + 2);
        $context = [];
        for ($line = $first; $line <= $last; $line++) {
            $context[] = $line . ':' . $sourceLines[$line - 1];
        }
        $strictDoc = strictDocCandidate($source, $diagnostic['id'], $diagnostic['message'], $symbol['class'], $symbol['method']);
        $rule = packetRule($diagnostic['path'], $diagnostic['id'], $diagnostic['message'], $symbol['method'], $strictDoc);
        $identityData = [
            'schema_version' => PACKET_SCHEMA, 'head' => $head, 'baseline_sha' => $baselineSha, 'path' => $diagnostic['path'],
            'analysis_context' => $diagnostic['analysis_context'],
            'line' => $diagnostic['line'], 'id' => $diagnostic['id'], 'message' => $diagnostic['message'],
            'count' => $diagnostic['count'], 'file_sha' => $fileSha, 'class' => $symbol['class'],
            'method' => $symbol['method'], 'symbol_start' => $symbol['symbol_start'],
            'symbol_end' => $symbol['symbol_end'], 'context' => $context,
        ];
        $identity = canonicalHash($identityData);
        $packet = [
            'identity' => $identity, 'head' => $head, 'baseline_sha' => $baselineSha,
            'path' => $diagnostic['path'], 'analysis_context' => $diagnostic['analysis_context'],
            'line' => $diagnostic['line'], 'class' => $symbol['class'],
            'method' => $symbol['method'], 'symbol_start' => $symbol['symbol_start'], 'symbol_end' => $symbol['symbol_end'],
            'id' => $diagnostic['id'], 'message' => $diagnostic['message'],
            'count' => $diagnostic['count'], 'context' => $context, 'file_sha' => $fileSha,
            'callers' => $locations['callers'], 'tests' => $locations['tests'], 'discovery_complete' => $locations['complete'], 'rule' => $rule['rule'],
            'reject_reason' => $rule['reject_reason'], 'security_contract_sensitive' => $rule['sensitive'],
        ];
        $packet['packet_hash'] = canonicalHash(['schema_version' => PACKET_SCHEMA, ...$packet]);
        $packets[] = $packet;
    }
    $diagnostics = array_sum(array_column($packets, 'count'));
    $contexts = [];
    foreach ($packets as $packet) {
        if (is_string($packet['analysis_context'])) {
            $contexts[$packet['analysis_context']] = true;
        }
    }
    $analysisContexts = array_keys($contexts);
    sort($analysisContexts, SORT_STRING);
    echo json_encode([
        'schema_version' => PACKET_SCHEMA,
        'meta' => ['head' => $head, 'baseline_sha' => $baselineSha, 'packet_count' => count($packets),
            'diagnostics' => $diagnostics, 'analysis_contexts' => $analysisContexts],
        'packets' => $packets,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

/** @return array{identity:string,hash:string} */
function parsePacketAllow(string $allow): array
{
    $at = strrpos($allow, '@');
    if ($at === false) {
        fail('invalid packet allowlist entry');
    }
    $identity = substr($allow, 0, $at);
    $hash = substr($allow, $at + 1);
    if (!preg_match('/^[a-f0-9]{64}$/', $identity) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        fail('invalid packet allowlist entry');
    }
    return ['identity' => $identity, 'hash' => $hash];
}

/**
 * @param array<string,mixed> $proposal
 * @param array<string,mixed> $packet
 * @return array{reject:string}|array{file:string,start:int,end:int,old:string,new:string,expected_sha:string}
 */
function validateProposal(string $repo, array $proposal, array $packet): array
{
    $fields = ['proposal_hash', 'packet_identity', 'packet_hash', 'classification', 'path', 'file_sha', 'confidence', 'rationale', 'required_tests', 'edits'];
    $keys = array_keys($proposal);
    sort($keys);
    $expected = $fields;
    sort($expected);
    if ($keys !== $expected) {
        return ['reject' => 'malformed_proposal_schema'];
    }
    if (!is_string($proposal['proposal_hash']) || !preg_match('/^[a-f0-9]{64}$/', $proposal['proposal_hash'])
        || !is_string($proposal['packet_identity']) || !is_string($proposal['packet_hash'])
        || !is_string($proposal['classification']) || !in_array($proposal['classification'], PROPOSAL_CLASSIFICATIONS, true)
        || !is_string($proposal['path']) || !is_string($proposal['file_sha'])
        || (!is_float($proposal['confidence']) && !is_int($proposal['confidence']))
        || !is_string($proposal['rationale']) || trim($proposal['rationale']) === ''
        || !is_array($proposal['required_tests']) || $proposal['required_tests'] === [] || !array_is_list($proposal['required_tests'])
        || array_filter($proposal['required_tests'], static fn (mixed $test): bool => !is_string($test) || trim($test) === '') !== []
        || !is_array($proposal['edits']) || !array_is_list($proposal['edits'])) {
        return ['reject' => 'malformed_proposal_schema'];
    }
    $proposalCopy = $proposal;
    unset($proposalCopy['proposal_hash']);
    if (!hash_equals($proposal['proposal_hash'], canonicalHash(['schema_version' => PROPOSAL_SCHEMA, ...$proposalCopy]))) {
        return ['reject' => 'proposal_hash_drift'];
    }
    if ($proposal['classification'] !== 'mechanical_safe') {
        return ['reject' => 'classification_not_applicable'];
    }
    if ((float) $proposal['confidence'] !== 1.0) {
        return ['reject' => 'low_confidence'];
    }
    foreach ($proposal['required_tests'] as $test) {
        if (!is_string($test)) {
            return ['reject' => 'malformed_proposal_schema'];
        }
        $testPath = $repo . '/' . $test;
        $resolvedTest = realpath($testPath);
        if (!str_starts_with($test, 'tests/') || !str_ends_with($test, '.php') || str_contains($test, '..')
            || $resolvedTest === false || !str_starts_with($resolvedTest, $repo . DIRECTORY_SEPARATOR)
            || is_link($testPath) || !is_file($resolvedTest)) {
            return ['reject' => 'required_test_path_rejected'];
        }
    }
    if (($packet['security_contract_sensitive'] ?? true) !== false || ($packet['reject_reason'] ?? '') !== '') {
        return ['reject' => 'contract_or_security_sensitive'];
    }
    if (($proposal['packet_identity'] ?? '') !== ($packet['identity'] ?? '') || ($proposal['packet_hash'] ?? '') !== ($packet['packet_hash'] ?? '')) {
        return ['reject' => 'packet_identity_drift'];
    }
    if ($proposal['path'] !== ($packet['path'] ?? '') || $proposal['file_sha'] !== ($packet['file_sha'] ?? '')) {
        return ['reject' => 'file_hash_drift'];
    }
    if (count($proposal['edits']) !== 1 || !is_array($proposal['edits'][0]) || array_is_list($proposal['edits'][0])) {
        return ['reject' => 'ambiguous_or_multiedit'];
    }
    $edit = stringMap($proposal['edits'][0], 'edit');
    $editFields = ['edit_kind', 'start', 'end', 'old', 'new'];
    $editKeys = array_keys($edit);
    sort($editKeys);
    sort($editFields);
    if ($editKeys !== $editFields || !is_int($edit['start']) || !is_int($edit['end']) || $edit['start'] < 0 || $edit['end'] <= $edit['start']
        || $edit['edit_kind'] !== 'doc_return_atom' || !is_string($edit['old']) || $edit['old'] === ''
        || !is_string($edit['new']) || $edit['new'] !== $edit['old'] . '|null' || str_contains($edit['new'], "\0")) {
        return ['reject' => 'malformed_edit'];
    }
    $candidate = $repo . '/' . $proposal['path'];
    $resolved = realpath($candidate);
    if ($resolved === false || !str_starts_with($resolved, $repo . DIRECTORY_SEPARATOR) || is_link($candidate)) {
        return ['reject' => 'path_rejected'];
    }
    $sha = hash_file('sha256', $resolved);
    $source = file_get_contents($resolved);
    if ($sha === false || $source === false || !hash_equals($proposal['file_sha'], $sha)) {
        return ['reject' => 'file_hash_drift'];
    }
    if (substr($source, $edit['start'], $edit['end'] - $edit['start']) !== $edit['old']) {
        return ['reject' => 'span_drift'];
    }
    try {
        $tokens = tokenSpans($source);
    } catch (ParseError) {
        return ['reject' => 'source_parse_error'];
    }
    $insideDoc = false;
    foreach ($tokens as $token) {
        $end = $token['start'] + strlen($token['text']);
        if ($token['id'] === T_DOC_COMMENT && $edit['start'] >= $token['start'] && $edit['end'] <= $end) {
            $docReturn = docReturn($token);
            $insideDoc = !isset($docReturn['reject']) && $docReturn['start'] === $edit['start']
                && $docReturn['end'] === $edit['end'] && $docReturn['atom'] === $edit['old'];
            break;
        }
    }
    if (!$insideDoc) {
        return ['reject' => 'runtime_control_edit'];
    }
    if (($packet['id'] ?? '') !== 'return.type'
        || !is_string($packet['message'] ?? null)
        || !preg_match('/ should return ([A-Za-z0-9_\\\\]+) but returns \1\|null\.$/', $packet['message'], $returnMatch)
        || normalizeAtom($returnMatch[1]) !== normalizeAtom($edit['old'])) {
        return ['reject' => 'packet_edit_mismatch'];
    }
    if (!is_int($packet['symbol_start'] ?? null) || !is_int($packet['symbol_end'] ?? null)
        || $edit['start'] < $packet['symbol_start'] || $edit['end'] > $packet['symbol_end']) {
        return ['reject' => 'symbol_bounds_drift'];
    }
    return ['file' => $resolved, 'start' => $edit['start'], 'end' => $edit['end'], 'old' => $edit['old'],
        'new' => $edit['new'], 'expected_sha' => $proposal['file_sha']];
}

function pauseBeforeProposalWrite(): void
{
    $base = getenv('PHPSTAN_CODEMOD_TEST_PAUSE');
    if ($base === false || $base === '') {
        return;
    }
    $directory = realpath(dirname($base));
    if ($directory !== sys_get_temp_dir() || !str_starts_with(basename($base), 'vimbadmin-codemod-')) {
        fail('invalid test pause path');
    }
    $ready = $base . '.ready';
    $handle = @fopen($ready, 'x');
    if ($handle === false) {
        fail('cannot create test pause signal');
    }
    fclose($handle);
    $deadline = microtime(true) + 5.0;
    while (!is_file($base . '.continue')) {
        if (microtime(true) >= $deadline) {
            fail('test pause timed out');
        }
        usleep(10_000);
    }
}

/**
 * @param array{apply:bool,proposal_apply:bool,format:string,repo:string,head:?string,sites:?int,diagnostics:?int,allows:list<string>,packet_input:?string,packet_file:?string,proposal_file:?string,packet_allows:list<string>} $options
 * @return never
 */
function processProposals(string $repo, string $head, string $baselineSha, array $options): void
{
    if ($options['packet_file'] === null || $options['proposal_file'] === null) {
        fail('proposal files required');
    }
    $packetDocument = jsonObject($options['packet_file'], 'packet');
    $proposalDocument = jsonObject($options['proposal_file'], 'proposal');
    $packetDocumentKeys = array_keys($packetDocument);
    sort($packetDocumentKeys);
    $proposalDocumentKeys = array_keys($proposalDocument);
    sort($proposalDocumentKeys);
    if ($packetDocumentKeys !== ['meta', 'packets', 'schema_version']
        || ($packetDocument['schema_version'] ?? '') !== PACKET_SCHEMA || !is_array($packetDocument['meta'] ?? null)
        || !is_array($packetDocument['packets'] ?? null) || !array_is_list($packetDocument['packets'])
        || ($proposalDocument['schema_version'] ?? '') !== PROPOSAL_SCHEMA
        || $proposalDocumentKeys !== ['proposals', 'schema_version']
        || !is_array($proposalDocument['proposals']) || !array_is_list($proposalDocument['proposals'])) {
        fail('malformed packet or proposal document');
    }
    $meta = stringMap($packetDocument['meta'], 'packet meta');
    $metaKeys = array_keys($meta);
    sort($metaKeys);
    if ($metaKeys !== ['analysis_contexts', 'baseline_sha', 'diagnostics', 'head', 'packet_count']
        || !is_int($meta['packet_count']) || !is_int($meta['diagnostics'])
        || !is_array($meta['analysis_contexts']) || !array_is_list($meta['analysis_contexts'])
        || array_filter($meta['analysis_contexts'], static fn (mixed $context): bool => !is_string($context)) !== []
        || $meta['packet_count'] !== count($packetDocument['packets'])
        || ($meta['head'] ?? '') !== $head || ($meta['baseline_sha'] ?? '') !== $baselineSha
        || $head !== $options['head']) {
        fail('head or baseline drift');
    }
    $packetFields = ['identity', 'head', 'baseline_sha', 'path', 'analysis_context', 'line', 'class', 'method', 'symbol_start', 'symbol_end',
        'id', 'message', 'count', 'context', 'file_sha', 'callers', 'tests', 'discovery_complete', 'rule',
        'reject_reason', 'security_contract_sensitive', 'packet_hash'];
    sort($packetFields);
    $packets = [];
    foreach ($packetDocument['packets'] as $packetValue) {
        $packet = stringMap($packetValue, 'packet');
        $keys = array_keys($packet);
        sort($keys);
        if ($keys !== $packetFields || !is_string($packet['identity'])) {
            fail('malformed packet schema');
        }
        if (isset($packets[$packet['identity']])) {
            fail('ambiguous packet identity');
        }
        if (!is_string($packet['head']) || !is_string($packet['baseline_sha']) || !is_string($packet['path'])
            || (!is_string($packet['analysis_context']) && $packet['analysis_context'] !== null)
            || !is_int($packet['line']) || $packet['line'] < 1 || (!is_string($packet['class']) && $packet['class'] !== null)
            || (!is_string($packet['method']) && $packet['method'] !== null)
            || ((!is_int($packet['symbol_start']) || !is_int($packet['symbol_end']))
                && ($packet['symbol_start'] !== null || $packet['symbol_end'] !== null))
            || !is_string($packet['id']) || !is_string($packet['message']) || !is_int($packet['count']) || $packet['count'] < 1
            || !is_array($packet['context']) || !array_is_list($packet['context']) || !is_string($packet['file_sha'])
            || !is_array($packet['callers']) || !is_array($packet['tests']) || $packet['discovery_complete'] !== false
            || !is_string($packet['rule']) || !is_string($packet['reject_reason']) || !is_bool($packet['security_contract_sensitive'])) {
            fail('malformed packet schema');
        }
        $hash = $packet['packet_hash'] ?? null;
        $copy = $packet;
        unset($copy['packet_hash']);
        if (!is_string($hash) || !hash_equals($hash, canonicalHash(['schema_version' => PACKET_SCHEMA, ...$copy]))) {
            fail('packet hash drift');
        }
        if ($packet['head'] !== $head || $packet['baseline_sha'] !== $baselineSha) {
            fail('packet head or baseline drift');
        }
        $identityData = [
            'schema_version' => PACKET_SCHEMA, 'head' => $packet['head'], 'baseline_sha' => $packet['baseline_sha'],
            'path' => $packet['path'], 'analysis_context' => $packet['analysis_context'],
            'line' => $packet['line'], 'id' => $packet['id'], 'message' => $packet['message'],
            'count' => $packet['count'], 'file_sha' => $packet['file_sha'], 'class' => $packet['class'],
            'method' => $packet['method'], 'symbol_start' => $packet['symbol_start'], 'symbol_end' => $packet['symbol_end'],
            'context' => $packet['context'],
        ];
        if (!hash_equals($packet['identity'], canonicalHash($identityData))) {
            fail('packet identity drift');
        }
        $candidate = $repo . '/' . $packet['path'];
        $resolved = realpath($candidate);
        $source = $resolved === false ? false : file_get_contents($resolved);
        $sha = $resolved === false ? false : hash_file('sha256', $resolved);
        if ($resolved === false || !str_starts_with($resolved, $repo . DIRECTORY_SEPARATOR) || is_link($candidate)
            || $source === false || $sha === false || !hash_equals($packet['file_sha'], $sha)) {
            fail('packet file hash drift');
        }
        $symbol = diagnosticSymbol($source, $packet['line'], $packet['message']);
        if ($symbol['class'] !== $packet['class'] || $symbol['method'] !== $packet['method']
            || $symbol['symbol_start'] !== $packet['symbol_start'] || $symbol['symbol_end'] !== $packet['symbol_end']) {
            fail('packet symbol drift');
        }
        $lines = explode("\n", $source);
        $context = [];
        for ($line = max(1, $packet['line'] - 2); $line <= min(count($lines), $packet['line'] + 2); $line++) {
            $context[] = $line . ':' . $lines[$line - 1];
        }
        if ($context !== $packet['context']) {
            fail('packet context drift');
        }
        $strictDoc = strictDocCandidate($source, $packet['id'], $packet['message'], $packet['class'], $packet['method']);
        $rule = packetRule($packet['path'], $packet['id'], $packet['message'], $packet['method'], $strictDoc);
        if ($rule['rule'] !== $packet['rule'] || $rule['reject_reason'] !== $packet['reject_reason']
            || $rule['sensitive'] !== $packet['security_contract_sensitive']) {
            fail('packet policy drift');
        }
        foreach (array_merge($packet['callers'], $packet['tests']) as $location) {
            if (!is_array($location) || !is_string($location['path'] ?? null) || !is_string($location['file_sha'] ?? null)
                || hash_file('sha256', $repo . '/' . $location['path']) !== $location['file_sha']) {
                fail('packet evidence hash drift');
            }
        }
        $packets[$packet['identity']] = $packet;
    }
    $packetDiagnostics = 0;
    $packetContexts = [];
    foreach ($packets as $packet) {
        if (!is_int($packet['count'] ?? null)) {
            fail('packet count drift');
        }
        $packetDiagnostics += $packet['count'];
        if (is_string($packet['analysis_context'] ?? null)) {
            $packetContexts[$packet['analysis_context']] = true;
        }
    }
    $expectedContexts = array_keys($packetContexts);
    sort($expectedContexts, SORT_STRING);
    if ($meta['diagnostics'] !== $packetDiagnostics || $meta['analysis_contexts'] !== $expectedContexts) {
        fail('packet diagnostic or context count drift');
    }
    $allows = [];
    foreach ($options['packet_allows'] as $raw) {
        $allow = parsePacketAllow($raw);
        if (isset($allows[$allow['identity']])) {
            fail('duplicate packet allowlist identity');
        }
        $allows[$allow['identity']] = $allow['hash'];
    }
    $records = [];
    $writes = [];
    $diagnostics = 0;
    $files = [];
    foreach ($proposalDocument['proposals'] as $proposalValue) {
        $proposal = is_array($proposalValue) && !array_is_list($proposalValue) ? stringMap($proposalValue, 'proposal') : [];
        $identity = is_string($proposal['packet_identity'] ?? null) ? $proposal['packet_identity'] : '';
        $packet = $packets[$identity] ?? null;
        $reject = '';
        if ($packet === null) {
            $reject = 'unmatched_packet';
        } elseif (!is_string($packet['packet_hash'] ?? null) || !is_int($packet['count'] ?? null)) {
            fail('stored packet shape drift');
        } elseif (!isset($allows[$identity]) || !hash_equals($allows[$identity], $packet['packet_hash'])) {
            $reject = 'nonallowlisted_packet';
        } else {
            $validated = validateProposal($repo, $proposal, $packet);
            if (isset($validated['reject'])) {
                $reject = $validated['reject'];
            } else {
                $writes[] = $validated;
                $files[$validated['file']] = true;
                $diagnostics += $packet['count'];
            }
        }
        $records[] = ['packet_identity' => $identity, 'status' => $reject === '' ? ($options['proposal_apply'] ? 'applied' : 'eligible') : 'rejected', 'reject_reason' => $reject];
    }
    $eligible = count($writes);
    $rejected = count($records) - $eligible;
    if (count($writes) > 1) {
        $rejected = count($records);
        $eligible = 0;
        foreach ($records as &$record) {
            $record['status'] = 'rejected';
            $record['reject_reason'] = 'multi_proposal_batch';
        } unset($record);
    }
    if (count($files) > 1) {
        $rejected = count($records);
        $eligible = 0;
        foreach ($records as &$record) {
            $record['status'] = 'rejected';
            $record['reject_reason'] = 'multifile_batch';
        } unset($record);
    }
    $ok = $rejected === 0 && $eligible === $options['sites'] && $diagnostics === $options['diagnostics'];
    $result = ['schema_version' => PROPOSAL_SCHEMA, 'meta' => ['head' => $head, 'baseline_sha' => $baselineSha, 'mode' => $options['proposal_apply'] ? 'apply' : 'dry-run', 'eligible_sites' => $eligible, 'diagnostics' => $diagnostics, 'rejected' => $rejected], 'records' => $records];
    if (!$ok) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        fail('proposal preconditions failed; no files changed', 1);
    }
    if ($options['proposal_apply']) {
        if (gitHead($repo) !== $options['head']) {
            fail('HEAD changed before proposal write');
        }
        pauseBeforeProposalWrite();
        $write = $writes[0];
        $source = file_get_contents($write['file']);
        $actualSha = hash_file('sha256', $write['file']);
        if ($source === false || $actualSha === false || !hash_equals($write['expected_sha'], $actualSha)
            || substr($source, $write['start'], $write['end'] - $write['start']) !== $write['old']) {
            fail('proposal source changed before write');
        }
        $contents = [$write['file'] => substr($source, 0, $write['start']) . $write['new'] . substr($source, $write['end'])];
        atomicWrite($contents, [$write['file'] => $write['expected_sha']]);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

/** @param list<array{id:int|string|null,text:string,start:int,line:int}> $tokens */
function nextSignificantIndex(array $tokens, int $index): ?int
{
    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        if (!insignificant($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

/** @param list<array{id:int|string|null,text:string,start:int,line:int}> $tokens */
function previousSignificantIndex(array $tokens, int $index): ?int
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (!insignificant($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

function harnessStateClass(string $path): string
{
    $stem = preg_replace('/\.php$/', '', basename($path)) ?? '';
    $parts = preg_split('/[^A-Za-z0-9]+/', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $class = implode('', array_map(static fn (string $part): string => ucfirst(strtolower($part)), $parts));
    return $class . 'HarnessState';
}

/**
 * @return array{line:int,old:string,new:string,source:string,increments:int}|array{reject:string}
 */
function inspectHarnessCounter(string $source, string $variable, string $stateClass): array
{
    if (str_contains($source, $stateClass)) {
        return ['reject' => 'state_class_collision'];
    }
    $tokens = tokenSpans($source);
    $depths = [];
    $functionScopes = [];
    $depth = 0;
    $pendingFunction = false;
    $functionDepths = [];
    foreach ($tokens as $index => $token) {
        $depths[$index] = $depth;
        $functionScopes[$index] = $functionDepths !== [];
        if ($token['id'] === T_FUNCTION) {
            $pendingFunction = true;
        }
        if (($token['id'] === null && $token['text'] === '{')
            || $token['id'] === T_CURLY_OPEN || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            if ($pendingFunction && $token['id'] === null) {
                $functionDepths[] = $depth;
                $pendingFunction = false;
            }
        }
        if ($token['id'] === null && $token['text'] === ';' && $pendingFunction) {
            $pendingFunction = false;
        }
        if ($token['id'] === null && $token['text'] === '}') {
            if ($functionDepths !== [] && end($functionDepths) === $depth) {
                array_pop($functionDepths);
            }
            $depth--;
        }
    }
    if ($depth !== 0) {
        return ['reject' => 'unbalanced_source'];
    }

    $name = '$' . $variable;
    $initializers = [];
    $globalVariables = [];
    $edits = [];
    $increments = 0;
    $assignmentIds = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL,
        T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL,
        T_SR_EQUAL, T_COALESCE_EQUAL,
    ];

    foreach ($tokens as $index => $token) {
        if ($token['id'] === T_GLOBAL) {
            $variableIndex = nextSignificantIndex($tokens, $index);
            $semicolon = $variableIndex === null ? null : nextSignificantIndex($tokens, $variableIndex);
            if ($variableIndex !== null && $tokens[$variableIndex]['id'] === T_VARIABLE
                && $tokens[$variableIndex]['text'] === $name
                && $semicolon !== null && $tokens[$semicolon]['text'] === ';') {
                $lineStart = strrpos(substr($source, 0, $token['start']), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $editStart = trim(substr($source, $lineStart, $token['start'] - $lineStart)) === ''
                    ? $lineStart : $token['start'];
                $globalVariables[$variableIndex] = true;
                $edits[] = [
                    'start' => $editStart,
                    'end' => $tokens[$semicolon]['start'] + 1,
                    'new' => '',
                ];
            } elseif ($variableIndex !== null && $tokens[$variableIndex]['id'] === T_VARIABLE
                && $tokens[$variableIndex]['text'] === $name) {
                return ['reject' => 'counter_global_not_exact'];
            }
        }
    }

    foreach ($tokens as $index => $token) {
        if ($token['id'] === T_VARIABLE && $token['text'] === '$GLOBALS') {
            $open = nextSignificantIndex($tokens, $index);
            $key = $open === null ? null : nextSignificantIndex($tokens, $open);
            $close = $key === null ? null : nextSignificantIndex($tokens, $key);
            $increment = $close === null ? null : nextSignificantIndex($tokens, $close);
            $literal = $key === null ? '' : trim($tokens[$key]['text'], "'\"");
            if ($open !== null && $tokens[$open]['text'] === '[' && $literal === $variable
                && $close !== null && $tokens[$close]['text'] === ']') {
                if ($increment === null || $tokens[$increment]['id'] !== T_INC) {
                    return ['reject' => 'counter_globals_use_not_increment'];
                }
                $edits[] = [
                    'start' => $token['start'],
                    'end' => $tokens[$increment]['start'] + strlen($tokens[$increment]['text']),
                    'new' => $stateClass . '::$count++',
                ];
                $increments++;
            }
        }
    }

    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_VARIABLE || $token['text'] !== $name || isset($globalVariables[$index])) {
            continue;
        }
        $previous = previousSignificantIndex($tokens, $index);
        $next = nextSignificantIndex($tokens, $index);
        $afterNext = $next === null ? null : nextSignificantIndex($tokens, $next);
        if (($depths[$index] ?? -1) === 0 && $next !== null && $tokens[$next]['text'] === '='
            && $afterNext !== null && $tokens[$afterNext]['id'] === T_LNUMBER
            && $tokens[$afterNext]['text'] === '0') {
            $semicolon = nextSignificantIndex($tokens, $afterNext);
            if ($semicolon === null || $tokens[$semicolon]['text'] !== ';') {
                return ['reject' => 'counter_initializer_not_exact'];
            }
            $initializers[] = [$index, $semicolon];
            continue;
        }
        if ($next !== null && $tokens[$next]['id'] === T_INC) {
            $edits[] = [
                'start' => $token['start'],
                'end' => $tokens[$next]['start'] + strlen($tokens[$next]['text']),
                'new' => $stateClass . '::$count++',
            ];
            $increments++;
            continue;
        }
        $previousToken = $previous === null ? null : $tokens[$previous];
        $nextToken = $next === null ? null : $tokens[$next];
        if (($previousToken !== null && ($previousToken['id'] === T_INC || $previousToken['id'] === T_DEC
                || $previousToken['text'] === '&'))
            || ($nextToken !== null && ($nextToken['text'] === '=' || $nextToken['text'] === '&'
                || $nextToken['id'] === T_DEC || in_array($nextToken['id'], $assignmentIds, true)))) {
            return ['reject' => 'counter_write_not_exact'];
        }
        if ($functionScopes[$index] ?? false) {
            return ['reject' => 'counter_function_read_not_exact'];
        }
    }

    if (count($initializers) !== 1) {
        return ['reject' => 'counter_initializer_not_exact'];
    }
    if ($increments === 0) {
        return ['reject' => 'counter_increment_missing'];
    }
    [$startIndex, $endIndex] = $initializers[0];
    $old = substr(
        $source,
        $tokens[$startIndex]['start'],
        $tokens[$endIndex]['start'] + 1 - $tokens[$startIndex]['start'],
    );
    $new = "final class {$stateClass}\n{\n    public static int \$count = 0;\n}\n\n"
        . $name . " =& {$stateClass}::\$count;";
    $edits[] = [
        'start' => $tokens[$startIndex]['start'],
        'end' => $tokens[$endIndex]['start'] + 1,
        'new' => $new,
    ];
    usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
    $transformed = $source;
    $lastStart = strlen($source) + 1;
    foreach ($edits as $edit) {
        if ($edit['end'] > $lastStart) {
            return ['reject' => 'counter_edit_overlap'];
        }
        $transformed = substr($transformed, 0, $edit['start']) . $edit['new'] . substr($transformed, $edit['end']);
        $lastStart = $edit['start'];
    }
    return [
        'line' => $tokens[$startIndex]['line'],
        'old' => $old,
        'new' => $new,
        'source' => $transformed,
        'increments' => $increments,
    ];
}

/** @param array{path:string,id:string,count:int,message:string,class:?string,method:?string,atom:?string} $entry */
function harnessDiagnostic(array $entry): bool
{
    return ($entry['id'] === 'postInc.type' && $entry['message'] === 'Cannot use ++ on mixed.')
        || ($entry['id'] === 'identical.alwaysTrue'
            && $entry['message'] === 'Strict comparison using === between 0 and 0 will always evaluate to true.')
        || ($entry['id'] === 'deadCode.unreachable'
            && $entry['message'] === 'Unreachable statement - code above always terminates.');
}

/**
 * @param array{apply:bool,proposal_apply:bool,format:string,family:?string,repo:string,head:?string,sites:?int,diagnostics:?int,allows:list<string>,packet_input:?string,packet_file:?string,proposal_file:?string,packet_allows:list<string>} $options
 * @param list<array{path:string,id:string,count:int,message:string,class:?string,method:?string,atom:?string}> $entries
 */
function processHarnessCounters(string $repo, string $head, string $baselineSha, array $entries, array $options): void
{
    $parsedAllows = array_map('parseAllow', $options['allows']);
    $siteKeys = array_map(static fn (array $allow): string => $allow['path'] . ':' . $allow['method'], $parsedAllows);
    if (count($siteKeys) !== count(array_unique($siteKeys))) {
        fail('duplicate allowlist site');
    }
    $records = [];
    $writes = [];
    $diagnosticCount = 0;
    foreach ($parsedAllows as $allow) {
        $path = $allow['path'];
        $variable = $allow['method'];
        $candidate = $repo . '/' . $path;
        $resolved = realpath($candidate);
        $inside = $resolved !== false && str_starts_with($resolved, $repo . DIRECTORY_SEPARATOR);
        $file = $inside ? $resolved : $candidate;
        $fileSha = $inside && !is_link($candidate) && is_file($file) ? hash_file('sha256', $file) : false;
        if ($fileSha === false) {
            $fileSha = '';
        }
        $record = array_fill_keys(FIELDS, '');
        $record = array_merge($record, [
            'head' => $head, 'baseline_sha' => $baselineSha, 'path' => $path,
            'method' => $variable, 'property' => 'count', 'id' => 'test-harness-static-counter',
            'count' => 0, 'status' => 'rejected', 'file_sha' => $fileSha,
        ]);
        if ($head !== $options['head']) {
            $record['reject_reason'] = 'head_drift';
            $records[] = $record;
            continue;
        }
        if (!str_starts_with($path, 'tests/') || $fileSha === '' || !hash_equals($allow['sha'], $fileSha)) {
            $record['reject_reason'] = $fileSha === '' || !hash_equals($allow['sha'], $fileSha)
                ? 'file_hash_drift' : 'counter_path_not_test';
            $records[] = $record;
            continue;
        }
        $matches = array_values(array_filter($entries, static fn (array $entry): bool =>
            $entry['path'] === $path && harnessDiagnostic($entry)));
        $postIncrementCount = array_sum(array_map(static fn (array $entry): int =>
            $entry['id'] === 'postInc.type' ? $entry['count'] : 0, $matches));
        $count = array_sum(array_column($matches, 'count'));
        $record['count'] = $count;
        if ($count <= 0 || $postIncrementCount <= 0) {
            $record['reject_reason'] = 'counter_baseline_not_exact';
            $records[] = $record;
            continue;
        }
        $source = file_get_contents($file);
        if ($source === false) {
            $record['reject_reason'] = 'file_unreadable';
            $records[] = $record;
            continue;
        }
        $stateClass = harnessStateClass($path);
        $inspection = inspectHarnessCounter($source, $variable, $stateClass);
        if (isset($inspection['reject'])) {
            $record['reject_reason'] = $inspection['reject'];
            $records[] = $record;
            continue;
        }
        if ($inspection['increments'] !== $postIncrementCount) {
            $record['reject_reason'] = 'counter_increment_diagnostic_mismatch';
            $records[] = $record;
            continue;
        }
        $record['line'] = $inspection['line'];
        $record['class'] = $stateClass;
        $record['old'] = $inspection['old'];
        $record['new'] = $inspection['new'];
        $record['status'] = $options['apply'] ? 'applied' : 'eligible';
        $record['reject_reason'] = '';
        $records[] = $record;
        $writes[$file] = ['source' => $inspection['source'], 'sha' => $allow['sha']];
        $diagnosticCount += $count;
    }
    $eligible = count($writes);
    $rejected = count($records) - $eligible;
    $meta = [
        'head' => $head, 'baseline_sha' => $baselineSha,
        'mode' => $options['apply'] ? 'apply' : 'dry-run', 'family' => 'test-harness-static-counter',
        'eligible_sites' => $eligible, 'diagnostics' => $diagnosticCount, 'rejected' => $rejected,
    ];
    if ($options['apply']) {
        if ($rejected !== 0 || $eligible !== $options['sites'] || $diagnosticCount !== $options['diagnostics']) {
            foreach ($records as &$record) {
                if ($record['status'] === 'applied') {
                    $record['status'] = 'eligible';
                }
            }
            unset($record);
            emit($records, $meta, $options['format']);
            fail('apply preconditions failed; no files changed');
        }
        if (gitHead($repo) !== $options['head']) {
            fail('HEAD changed before write');
        }
        $contents = [];
        $expected = [];
        foreach ($writes as $file => $write) {
            $contents[$file] = $write['source'];
            $expected[$file] = $write['sha'];
        }
        atomicWrite($contents, $expected);
    }
    emit($records, $meta, $options['format']);
    exit(0);
}

$options = options($argv);
$repo = realpath($options['repo']);
if ($repo === false) {
    fail('repository does not exist');
}
$head = gitHead($repo);
$baseline = $repo . '/phpstan-baseline.neon';
$baselineSha = is_file($baseline) ? hash_file('sha256', $baseline) : false;
if ($baselineSha === false) {
    fail('phpstan-baseline.neon is required');
}
if ($options['packet_input'] !== null) {
    if ($head !== $options['head']) {
        fail('head_drift');
    }
    emitPackets($repo, $head, $baselineSha, $options['packet_input']);
}
if ($options['packet_file'] !== null) {
    processProposals($repo, $head, $baselineSha, $options);
}
$entries = baselineEntries($baseline);
if ($options['family'] === 'test-harness-static-counter') {
    processHarnessCounters($repo, $head, $baselineSha, $entries, $options);
}
$records = [];
/** @var array<string,list<array{start:int,end:int,new:string}>> $edits */
$edits = [];
$diagnosticCount = 0;
$parsedAllows = array_map('parseAllow', $options['allows']);
$siteKeys = array_map(static fn (array $allow): string => $allow['path'] . ':' . $allow['method'], $parsedAllows);
if (count($siteKeys) !== count(array_unique($siteKeys))) {
    fail('duplicate allowlist site');
}
foreach ($parsedAllows as $allow) {
    $candidateFile = $repo . '/' . $allow['path'];
    $resolvedFile = realpath($candidateFile);
    $insideRepo = $resolvedFile !== false && str_starts_with($resolvedFile, $repo . DIRECTORY_SEPARATOR);
    $file = $insideRepo ? $resolvedFile : $candidateFile;
    $fileSha = $insideRepo && !is_link($candidateFile) && is_file($file) ? hash_file('sha256', $file) : false;
    if ($fileSha === false) {
        $fileSha = '';
    }
    $record = array_fill_keys(FIELDS, '');
    $record = array_merge($record, [
        'head' => $head, 'baseline_sha' => $baselineSha, 'path' => $allow['path'],
        'method' => $allow['method'], 'id' => 'return.type', 'count' => 0,
        'status' => 'rejected', 'file_sha' => $fileSha,
    ]);
    if ($head !== $options['head']) {
        $record['reject_reason'] = 'head_drift';
        $records[] = $record;
        continue;
    }
    if ($fileSha === '' || !hash_equals($allow['sha'], $fileSha)) {
        $record['reject_reason'] = 'file_hash_drift';
        $records[] = $record;
        continue;
    }
    $matches = array_values(array_filter($entries, static fn (array $entry): bool =>
        $entry['path'] === $allow['path'] && $entry['id'] === 'return.type'
        && $entry['method'] === $allow['method']));
    if (count($matches) !== 1) {
        $record['reject_reason'] = 'baseline_no_exact_return';
        $records[] = $record;
        continue;
    }
    $entry = $matches[0];
    $record['count'] = $entry['count'];
    $record['class'] = $entry['class'] ?? '';
    if ($entry['count'] !== 1) {
        $record['reject_reason'] = 'baseline_count_not_one';
        $records[] = $record;
        continue;
    }
    if ($entry['class'] === null || $entry['atom'] === null) {
        $record['reject_reason'] = 'baseline_no_exact_return';
        $records[] = $record;
        continue;
    }
    $source = file_get_contents($file);
    if ($source === false) {
        $record['reject_reason'] = 'file_unreadable';
        $records[] = $record;
        continue;
    }
    $inspection = inspectMethod($source, $allow['method'], $entry['class'], $entry['atom']);
    if (isset($inspection['reject'])) {
        $record['reject_reason'] = $inspection['reject'];
        $records[] = $record;
        continue;
    }
    $record = array_merge($record, $inspection);
    unset($record['start'], $record['end']);
    $record['status'] = $options['apply'] ? 'applied' : 'eligible';
    $record['reject_reason'] = '';
    $records[] = $record;
    $edits[$file][] = [
        'start' => $inspection['start'], 'end' => $inspection['end'], 'new' => $inspection['new'],
    ];
    $diagnosticCount += $entry['count'];
}
$eligible = array_sum(array_map(static fn (array $record): int => in_array($record['status'], ['eligible', 'applied'], true) ? 1 : 0, $records));
$rejected = count($records) - $eligible;
$meta = [
    'head' => $head, 'baseline_sha' => $baselineSha, 'mode' => $options['apply'] ? 'apply' : 'dry-run',
    'eligible_sites' => $eligible, 'diagnostics' => $diagnosticCount, 'rejected' => $rejected,
];
if ($options['apply']) {
    if ($rejected !== 0 || $eligible !== $options['sites'] || $diagnosticCount !== $options['diagnostics']) {
        foreach ($records as &$record) {
            if ($record['status'] === 'applied') {
                $record['status'] = 'eligible';
            }
        }
        unset($record);
        emit($records, $meta, $options['format']);
        fail('apply preconditions failed; no files changed');
    }
    if (gitHead($repo) !== $options['head']) {
        fail('HEAD changed before write');
    }
    $contents = [];
    foreach ($edits as $file => $fileEdits) {
        $expected = null;
        foreach ($parsedAllows as $allow) {
            if ($repo . '/' . $allow['path'] === $file) {
                $expected = $allow['sha'];
                break;
            }
        }
        $currentSha = hash_file('sha256', $file);
        if ($expected === null || $currentSha === false || !hash_equals($expected, $currentSha)) {
            fail('file changed before write: ' . substr($file, strlen($repo) + 1));
        }
        $content = file_get_contents($file);
        if ($content === false) {
            fail("cannot reread $file");
        }
        usort($fileEdits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($fileEdits as $edit) {
            $content = substr($content, 0, $edit['start']) . $edit['new'] . substr($content, $edit['end']);
        }
        $contents[$file] = $content;
    }
    atomicWrite($contents);
}
emit($records, $meta, $options['format']);
