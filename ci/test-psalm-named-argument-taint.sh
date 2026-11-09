#!/usr/bin/env bash
# New engine regression only; this does not analyse the application graph.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd -P)
php=${PHP_BINARY:-php}
helper_only=0
if [[ ${1:-} == --helper-only ]]; then
  helper_only=1
  shift
fi
original=$(realpath "${1:?Supply the CI-pinned upstream Psalm PHAR}")
if [[ -n ${PSALM_NAMED_EVIDENCE_DIR:-} ]]; then
  work=$PSALM_NAMED_EVIDENCE_DIR
  mkdir "$work"
else
  work=$(mktemp -d "${TMPDIR:-/tmp}/psalm-named-taint.XXXXXX")
  status=0
  trap 'status=$?; if [[ $status == 0 ]]; then rm -rf -- "${work:?}"; else printf "Engine failure evidence retained: %s\n" "$work" >&2; fi' EXIT
fi

if [[ $helper_only == 1 ]]; then
  "$php" "$root/ci/patch-psalm-named-taint.php" "$original" "$work/patched" >"$work/apply.log" 2>&1
  (cd "$work/patched/engine" && "$work/patched/bin/psalm" --version)
  if "$php" "$root/ci/patch-psalm-named-taint.php" "$original" "$work/patched" >"$work/existing.log" 2>&1; then
    echo 'FAIL: helper accepted an existing output' >&2
    exit 1
  fi
  grep -qF 'Refusing an existing/already patched output' "$work/existing.log"
  printf 'not the pinned PHAR\n' >"$work/unknown.phar"
  if "$php" "$root/ci/patch-psalm-named-taint.php" "$work/unknown.phar" "$work/unknown-output" >"$work/unknown.log" 2>&1; then
    echo 'FAIL: helper accepted an unknown source' >&2
    exit 1
  fi
  grep -qF 'Unknown or modified Psalm PHAR' "$work/unknown.log"
  [[ ! -e $work/unknown-output ]]
  mkdir "$work/tampered-tool"
  cp "$root/ci/patch-psalm-named-taint.php" "$work/tampered-tool/"
  cp "$root/ci/psalm-named-argument-taint.patch" "$work/tampered-tool/"
  printf '\nchanged\n' >>"$work/tampered-tool/psalm-named-argument-taint.patch"
  if "$php" "$work/tampered-tool/patch-psalm-named-taint.php" "$original" "$work/tampered-output" >"$work/tampered.log" 2>&1; then
    echo 'FAIL: helper accepted altered patch bytes' >&2
    exit 1
  fi
  grep -qF 'Patch bytes do not match the checked patch' "$work/tampered.log"
  [[ ! -e $work/tampered-output ]]
  # Derive a private first-stage candidate; never mutate the selected build.
  base=$work/callable-base
  cp -a "$work/patched" "$base"
  "$php" /dev/stdin "$base" <<'PHP'
<?php
    $root = $argv[1];
    $m = json_decode(file_get_contents($root . "/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    if (($m["callable_inverse_byte_conservation"] ?? false) !== true) throw new RuntimeException("Callable stage did not run");
    foreach ($m["callable_members"] as $member => $pins) {
      if (!copy($root . "/callable-original/" . basename($member), $root . "/engine/" . $member)) throw new RuntimeException("Cannot prepare first-stage member");
    }
    unset($m["callable_patch_sha256"], $m["callable_inverse_byte_conservation"], $m["callable_members"], $m["members"]["src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"]);
    $m["members"]["src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php"]["patched"] = "12e2cf9e7564b2a6866d10794a03e9f83fddd93026b6f4de06a0e3a3c3c4474c";
    file_put_contents($root . "/manifest.json", json_encode($m, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    unlink($root . "/original/FunctionLikeAnalyzer.php");
PHP
  rm -rf -- "$base/callable-original" "$base/callable-inverse"
  cp "$base/manifest.json" "$work/first-stage-manifest.json"
  cp "$base/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php" "$work/first-stage-member.php"
  callable_guard() {
    local name=$1 diagnostic=$2 helper=${3:-$root/ci/patch-psalm-callable-taint.php} candidate=${4:-$base}
    if "$php" "$helper" "$candidate" >"$work/callable-$name.log" 2>&1; then
      echo "FAIL: callable guard accepted $name" >&2
      exit 1
    fi
    grep -qF "$diagnostic" "$work/callable-$name.log"
    echo "PASS: callable guard rejects $name"
  }
  "$php" /dev/stdin "$base/manifest.json" <<'PHP'
<?php
$p=$argv[1];$m=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);$m["upstream_version"]="unknown";file_put_contents($p,json_encode($m,JSON_THROW_ON_ERROR));
PHP
  callable_guard unknown-version 'Unknown Psalm version or PHAR identity'
  cp "$work/first-stage-manifest.json" "$base/manifest.json"
  mv "$base/manifest.json" "$work/held-manifest.json"
  callable_guard missing-manifest 'Cannot read named patch manifest'
  mv "$work/held-manifest.json" "$base/manifest.json"
  printf '\n// changed\n' >>"$base/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"
  callable_guard unknown-member 'Unknown callable member'
  cp "$work/first-stage-member.php" "$base/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"
  mv "$base/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php" "$work/held-member.php"
  callable_guard missing-member 'Missing callable member'
  mv "$work/held-member.php" "$base/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"
  mkdir "$work/callable-tampered" "$work/callable-missing"
  cp "$root/ci/patch-psalm-callable-taint.php" "$work/callable-tampered/"
  cp "$root/ci/psalm-callable-return-taint.patch" "$work/callable-tampered/"
  printf '\nchanged\n' >>"$work/callable-tampered/psalm-callable-return-taint.patch"
  callable_guard tampered-patch 'Unknown callable patch bytes' "$work/callable-tampered/patch-psalm-callable-taint.php"
  cp "$root/ci/patch-psalm-callable-taint.php" "$work/callable-missing/"
  callable_guard missing-patch 'Unknown callable patch bytes' "$work/callable-missing/patch-psalm-callable-taint.php"
  callable_guard reapply 'Refusing an already callable-patched tool' "$root/ci/patch-psalm-callable-taint.php" "$work/patched"
  "$php" "$root/ci/patch-psalm-callable-taint.php" "$base" >"$work/callable-restored.log" 2>&1
  "$php" /dev/stdin "$base/manifest.json" <<'PHP'
<?php
$m=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if($m["callable_inverse_byte_conservation"]!==true || count($m["members"])!==3)throw new RuntimeException("Missing final composed tool");
PHP
  echo 'PASS: callable version/member/missing/tampered/reapply guards; restored composed tool and both inverse stages'
  echo 'PASS: exact PHAR/member guards, forward/inverse conservation, existing/unknown/tampered refusal'
  exit 0
fi

patched=$(realpath "${2:?Supply the isolated patched engine/psalm entrypoint}")
"$php" /dev/stdin "$original" "$patched" "$work" "$root/src/Kernel/Http/Response.php" <<'PHP'
<?php
declare(strict_types=1);

[$script, $original, $patched, $work, $response] = $argv;
if (hash_file('sha256', $original) !== '7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00') {
    throw new RuntimeException('Unpatched control is not the pinned official PHAR');
}
// Compare identical source-engine formats; never bypass or edit the PHAR's Box manifest.
$originalArchive = $work . '/upstream.phar';
if (!copy($original, $originalArchive) || hash_file('sha256', $originalArchive) !== hash_file('sha256', $original)) {
    throw new RuntimeException('Cannot preserve the verified upstream PHAR for extraction');
}
$archive = new Phar($originalArchive);
$originalDirectory = $work . '/original-engine';
$archive->extractTo($originalDirectory);
$memberHashes = [];
foreach (new RecursiveIteratorIterator($archive) as $member) {
    if (!$member->isFile()) continue;
    $relative = substr($member->getPathname(), strlen('phar://' . $originalArchive . '/'));
    $expected = hash('sha256', $member->getContent());
    if (hash_file('sha256', $originalDirectory . '/' . $relative) !== $expected) {
        throw new RuntimeException('Untouched original engine member mismatch: ' . $relative);
    }
    $memberHashes[$relative] = $expected;
}
file_put_contents($work . '/original-engine-members.json', json_encode($memberHashes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo 'PASS: untouched upstream engine member conservation ', count($memberHashes), ' members ', hash('sha256', json_encode($memberHashes, JSON_THROW_ON_ERROR)), "\n";
$original = $originalDirectory . '/psalm';
$manifest = json_decode(file_get_contents(dirname($patched, 2) . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['inverse_byte_conservation'] !== true) throw new RuntimeException('Missing checked inverse byte conservation');
foreach ($manifest['members'] as $path => $pins) {
    if (hash_file('sha256', $originalDirectory . '/' . $path) !== $pins['original']) throw new RuntimeException('Original member differs from checked inverse');
    if (hash_file('sha256', dirname($patched) . '/' . $path) !== $pins['patched']) {
        throw new RuntimeException('Patched engine differs from build manifest');
    }
}
$header = ['TaintedHeader'];
$cases = [
    'positional' => ["\$r = new Response('', 200, \$_GET['type']); header(\$r->contentType);", $header],
    'ordered_named' => ["\$r = new Response(body: '', status: 200, contentType: \$_GET['type']); header(\$r->contentType);", $header],
    'skip_status' => ["\$r = new Response('', contentType: \$_GET['type']); header(\$r->contentType);", $header],
    'explicit_status' => ["\$r = new Response('', status: 200, contentType: \$_GET['type']); header(\$r->contentType);", $header],
    'reordered_named' => ["\$r = new Response(contentType: \$_GET['type'], body: ''); header(\$r->contentType);", $header],
    'no_false_body' => ["\$r = new Response(contentType: \$_GET['type'], body: ''); echo \$r->body;", []],
    'literal_safe' => ["\$r = new Response(contentType: 'text/plain', body: ''); header(\$r->contentType); echo \$r->body;", []],
    'empty_safe' => ["\$r = new Response(''); header(\$r->contentType); echo \$r->body;", []],
    'named_header_array' => ["\$r = new Response(body: '', headers: ['X-Test' => \$_GET['value']]); foreach (\$r->headers as \$k => \$v) header(\$k . ': ' . \$v);", $header],
    'named_function' => ["function content(string \$body = '', int \$status = 200, string \$type = 'text/plain'): string { return \$type; } header(content(type: \$_GET['type']));", $header],
    // This fails if argument edges are fixed but named sink nodes retain their old ordinal.
    'named_sink' => ["/** @psalm-taint-sink header \$value */ function sink(string \$prefix = 'fixed', string \$value = 'safe'): void {} sink(value: \$_GET['value']);", $header],
    'named_sink_safe' => ["/** @psalm-taint-sink header \$value */ function sink(string \$prefix = 'fixed', string \$value = 'safe'): void {} sink(value: 'safe', prefix: \$_GET['other']);", []],
];
// These paths are deliberately outside the named nonvariadic correction.
// Compare every reported diagnostic, including known upstream limitations.
$unchanged = [
    'positional_unpack' => ["\$r = new Response(...['', 200, \$_GET['type']]); header(\$r->contentType);", false],
    'named_unpack' => ["\$r = new Response(...['contentType' => \$_GET['type'], 'body' => '']); header(\$r->contentType); echo \$r->body;", false],
    'variadic' => ["function emit(string ...\$items): void { foreach (\$items as \$item) header(\$item); } emit(\$_GET['value']);", false],
    'named_variadic' => ["function emit(string ...\$items): void { foreach (\$items as \$item) header(\$item); } emit(extra: \$_GET['value']);", false],
    'unknown_name' => ["new Response('', content_type: 'safe');", true],
    'duplicate_name' => ["new Response(body: '', body: 'again');", true],
    'wrong_type' => ["new Response('', status: []);", true],
    'invalid_unpack' => ["new Response(...42);", true],
    'malformed_source' => ['function {', true],
];

/** @return list<array{type:string,line:int,file:string,message:string}> */
function analyse(string $engine, string $label, string $name, string $code, bool $types): array
{
    global $work, $response;
    $dir = $work . '/' . $label . '/' . $name;
    mkdir($dir . '/src', 0700, true);
    mkdir($dir . '/vendor', 0700);
    copy($response, $dir . '/src/Response.php');
    file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
    file_put_contents($dir . '/src/Probe.php', "<?php\nuse ViMbAdmin\\Kernel\\Http\\Response;\n" . $code . "\n");
    file_put_contents($dir . '/psalm.xml', '<?xml version="1.0"?><psalm errorLevel="1" findUnusedCode="false" resolveFromConfigFile="true" xmlns="https://getpsalm.org/schema/config"><projectFiles><directory name="src" /></projectFiles></psalm>');
    $command = [PHP_BINARY, $engine, '--config=psalm.xml', '--no-cache', '--threads=1', '--no-progress', $types ? '--show-info=false' : '--taint-analysis', '--output-format=json'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/result.json', 'w'], 2 => ['file', $dir . '/stderr.log', 'w']], $pipes, $dir);
    if (!is_resource($process)) throw new RuntimeException('Cannot start actual Psalm');
    fclose($pipes[0]);
    $status = proc_close($process);
    file_put_contents($dir . '/exit.txt', $status . "\n");
    if (!in_array($status, [0, 2], true)) throw new RuntimeException('Psalm runtime failure exit ' . $status . ': ' . $dir . "\n" . substr(file_get_contents($dir . '/stderr.log'), -6000));
    $issues = json_decode(file_get_contents($dir . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
    $result = [];
    foreach ($issues as $issue) $result[] = ['type' => $issue['type'], 'line' => $issue['line_from'], 'file' => $issue['file_name'], 'message' => $issue['message']];
    sort($result);
    return $result;
}

$summary = [];
$observedRed = [];
foreach ($cases as $name => [$code, $expected]) {
    $before = analyse($original, 'original', $name, $code, false);
    $after = analyse($patched, 'patched', $name, $code, false);
    $beforeTypes = array_column($before, 'type'); sort($beforeTypes);
    $afterTypes = array_column($after, 'type'); sort($afterTypes); sort($expected);
    if ($afterTypes !== $expected) throw new RuntimeException('Patched oracle failed: ' . $name . ': ' . json_encode([$expected, $after]));
    if ($beforeTypes !== $expected) $observedRed[] = $name;
    $summary[$name] = ['original' => $before, 'patched' => $after, 'original_oracle_red' => $beforeTypes !== $expected];
    echo 'PASS: ', $name, ' original=', json_encode($beforeTypes), ' patched=', json_encode($afterTypes), "\n";
}
foreach (['skip_status', 'reordered_named', 'no_false_body', 'named_header_array'] as $requiredRed) {
    if (!in_array($requiredRed, $observedRed, true)) throw new RuntimeException('Unpatched negative control did not fail: ' . $requiredRed);
}
foreach ($unchanged as $name => [$code, $types]) {
    $before = analyse($original, 'original', $name, $code, $types);
    $after = analyse($patched, 'patched', $name, $code, $types);
    if ($before !== $after) throw new RuntimeException('Changed out-of-scope diagnostic behavior: ' . $name . ': ' . json_encode([$before, $after]));
    if ($types && $before === []) throw new RuntimeException('Malformed/type near-miss produced no diagnostic: ' . $name);
    $summary[$name] = ['original' => $before, 'patched' => $after, 'identical' => true];
    echo 'PASS: unchanged ', $name, ' ', json_encode(array_column($after, 'type')), "\n";
}
file_put_contents($work . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo 'PASS: actual pinned-engine red/patched-green controls; no application graph qualification', "\n";
PHP
