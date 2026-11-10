#!/usr/bin/env bash
# Observe constructor/array taint boundaries only; the Smarty renderer is outside this test.
set -euo pipefail
repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
mode=${1:-all}
if [[ $# -gt 1 || ($mode != all && $mode != --runtime-only) ]]; then
  echo 'Usage: test-psalm-flash-propagation.sh [--runtime-only]' >&2
  exit 2
fi
if [[ $mode == all ]]; then
  : "${PSALM_BIN:?Select the checked, patched Psalm 6.16.1 launcher with PSALM_BIN}"
  engine=$(command -v -- "$PSALM_BIN")
  bash "$repo_root/ci/test-psalm-flash-cleanup.sh"
else
  engine=''
fi
if [[ -n ${FLASH_PROOF_DIR:-} ]]; then
  mkdir -m 700 -- "$FLASH_PROOF_DIR"
  proof_dir=$FLASH_PROOF_DIR
else
  proof_parent=$(cd -- "${TMPDIR:-/tmp}" && pwd -P)
  proof_dir=$(mktemp -d "$proof_parent/vimbadmin-flash-propagation.XXXXXX")
  [[ -d $proof_dir && ! -L $proof_dir && $(cd -- "$proof_dir" && pwd -P) == "$proof_dir" ]] || exit 1
  case $proof_dir in
    "$proof_parent"/vimbadmin-flash-propagation.*) ;;
    *) exit 1 ;;
  esac
  cleanup() {
    local status=$?
    if [[ $status == 0 && -n $proof_dir && -d $proof_dir && ! -L $proof_dir && $(cd -- "$proof_dir" && pwd -P) == "$proof_dir" ]]; then
      rm -rf -- "${proof_dir:?}"
    fi
    return "$status"
  }
  trap cleanup EXIT
fi
# Caller-owned evidence is always retained; default evidence is retained on failure.
printf 'Flash propagation evidence: %s\n' "$proof_dir"
php /dev/stdin "$repo_root" "$proof_dir" "$mode" "$engine" <<'PHP'
<?php
declare(strict_types=1);

use ViMbAdmin\Kernel\Flash\FlashMessage;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Session\SessionStorage;

[$unused, $repo, $proof, $mode, $engine] = $argv;
function must(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$files = [
    'src/Kernel/Session/SessionStorage.php',
    'src/Kernel/Flash/FlashMessages.php',
    'src/Kernel/Flash/FlashMessage.php',
];
$hashes = [];
foreach ($files as $file) {
    $hashes[$file] = hash_file('sha256', "$repo/$file");
    require_once "$repo/$file";
}
file_put_contents("$proof/source-hashes.json", json_encode($hashes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
final class FlashFixtureSession implements SessionStorage
{
    public array $data = [];
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}
$checks = 0;
function check(bool $ok, string $name): void
{
    global $checks;
    must($ok, 'runtime: ' . $name);
    ++$checks;
}
function rejects(callable $action, string $class, string $name): void
{
    try {
        $action();
    } catch (Throwable $error) {
        check($error instanceof $class, $name);
        return;
    }
    check(false, $name);
}
$text = '<b title="x">&\'payload</b>';
foreach ([true, false] as $html) {
    $session = new FlashFixtureSession();
    $flash = new FlashMessages($session);
    $flash->add($text, 'warning', $html);
    check($session->data['flashMessages'][0] === ['text' => $text, 'level' => 'warning', 'isHtml' => $html], 'add/toArray fields');
    check($flash->peek()[0]->toArray() === ['text' => $text, 'level' => 'warning', 'isHtml' => $html], 'peek/fromArray fields');
    check(count($flash->drain()) === 1 && !$session->has('flashMessages'), 'private drain');
}
check((new FlashMessage(''))->toArray() === ['text' => '', 'level' => 'success', 'isHtml' => false], 'empty/default constructor');
check(FlashMessage::fromArray([])->toArray() === ['text' => '', 'level' => 'success', 'isHtml' => false], 'missing array keys');
check((new FlashMessage(isHtml: true, text: $text))->toArray() === ['text' => $text, 'level' => 'success', 'isHtml' => true], 'named reorder/omitted level');
check((new FlashMessage(isHtml: false, level: 'info', text: $text))->toArray() === ['text' => $text, 'level' => 'info', 'isHtml' => false], 'named all fields');
foreach (['text', 'level', 'isHtml'] as $field) {
    $message = new FlashMessage('literal');
    check((new ReflectionProperty(FlashMessage::class, $field))->isReadOnly(), 'readonly declaration ' . $field);
    rejects(static function () use ($message, $field): void { $message->$field = $message->$field; }, Error::class, 'readonly write ' . $field);
}
rejects(static fn () => new FlashMessage([]), TypeError::class, 'malformed text');
rejects(static fn () => new FlashMessage('literal', []), TypeError::class, 'malformed level');
rejects(static fn () => new FlashMessage('literal', 'success', []), TypeError::class, 'malformed HTML flag');
foreach (['bad', ['not-list' => []], [17], [['text' => []]], [['isHtml' => 'yes']], [['level' => false]]] as $bad) {
    $session = new FlashFixtureSession();
    $session->data['flashMessages'] = $bad;
    $flash = new FlashMessages($session);
    rejects(static fn () => $flash->peek(), UnexpectedValueException::class, 'malformed queue');
}
$runtime = ['checks' => $checks, 'php' => PHP_VERSION, 'source_hashes' => $hashes];
file_put_contents("$proof/runtime.json", json_encode($runtime, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
printf("runtime: %d checks passed on PHP %s\n", $checks, PHP_VERSION);
if ($mode === '--runtime-only') {
    exit(0);
}
// Named-argument cases require the separately checked engine repair. Never use a global fallback.
$engine = realpath($engine);
must($engine !== false && is_executable($engine), 'Selected Psalm launcher is unavailable');
$root = dirname(dirname($engine));
$pins = [
    $engine => '5b33e45bea87af5491d80403a5645f5c020adef6bbc1e5f308393c61ec2137e5',
    "$root/original/psalm-6.16.1.phar" => '7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00',
    "$root/engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentsAnalyzer.php" => '1c0ba8ba626104f8c983f4c34451503887c822652b11fd917b504cd46c09ec8f',
    "$root/engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php" => 'e54be128641ce2bb8bed11f53897e6f8e61a45d5368e8b0e984816825749483c',
    "$root/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php" => 'ed870ddfc6402c3f06995cd9ffbf0a61a8db8bba94c32dd4ff829cd2582f356e',
];
foreach ($pins as $path => $expected) {
    must(is_file($path) && hash_file('sha256', $path) === $expected, 'Checked patched Psalm 6.16.1 required: ' . $path);
}
file_put_contents("$proof/engine-pins.json", json_encode($pins, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
$input = '(string) ($_GET["text"] ?? "")';
$cases = [
    'direct_source' => [true, 'echo ' . $input . ';'],
    'property' => [true, '$m = new FlashMessage(' . $input . '); echo $m->text;'],
    'to_array' => [true, '$m = new FlashMessage(' . $input . '); echo $m->toArray()["text"];'],
    'restored' => [true, '$m = new FlashMessage(' . $input . '); echo FlashMessage::fromArray($m->toArray())->text;'],
    'from_array' => [true, 'echo FlashMessage::fromArray(["text" => ' . $input . '])->text;'],
    'named_reordered' => [true, '$m = new FlashMessage(isHtml: true, level: "info", text: ' . $input . '); echo $m->text;'],
    'named_skip_level' => [true, '$m = new FlashMessage(text: ' . $input . ', isHtml: true); echo $m->toArray()["text"];'],
    'escaped' => [false, '$m = new FlashMessage(' . $input . '); echo htmlspecialchars(FlashMessage::fromArray($m->toArray())->text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");'],
    'literal' => [false, 'echo FlashMessage::fromArray((new FlashMessage("literal", "success", true))->toArray())->text;'],
    'missing_defaults' => [false, 'echo FlashMessage::fromArray([])->text;'],
];
$results = [];
$failed = [];
foreach ($cases as $name => [$tainted, $body]) {
    $directory = "$proof/$name";
    must(mkdir($directory, 0700), 'Cannot create fixture ' . $name);
    foreach ($files as $file) {
        $target = $directory . '/' . basename($file);
        must(copy("$repo/$file", $target), 'Cannot copy source ' . $file);
        must(hash_file('sha256', $target) === $hashes[$file], 'Source changed during fixture copy');
    }
    $probe = "<?php\nrequire_once __DIR__ . '/SessionStorage.php';\nrequire_once __DIR__ . '/FlashMessages.php';\nrequire_once __DIR__ . '/FlashMessage.php';\nuse ViMbAdmin\\Kernel\\Flash\\FlashMessage;\n" . $body . "\n";
    file_put_contents("$directory/Probe.php", $probe);
    file_put_contents("$directory/psalm.xml", '<?xml version="1.0"?><psalm errorLevel="1" phpVersion="8.3" resolveFromConfigFile="true" xmlns="https://getpsalm.org/schema/config"><projectFiles><directory name="."/></projectFiles></psalm>');
    // Taint mode can omit syntax/type diagnostics: validate PHP before observing taint.
    foreach (glob("$directory/*.php") as $file) {
        $process = proc_open([PHP_BINARY, '-l', $file], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/lint.log", 'a'], 2 => ['file', "$directory/lint.log", 'a']], $pipes, $directory);
        must(is_resource($process) && proc_close($process) === 0, 'Fixture syntax failed: ' . $file);
    }
    $command = [$engine, '--config=' . $directory . '/psalm.xml', '--taint-analysis', '--no-cache', '--threads=1', '--output-format=json', '--no-progress'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/stdout.json", 'w'], 2 => ['file', "$directory/stderr.log", 'w']], $pipes, $directory);
    must(is_resource($process), 'Cannot launch selected Psalm');
    $status = proc_close($process);
    $issues = json_decode(file_get_contents("$directory/stdout.json"), true, 512, JSON_THROW_ON_ERROR);
    must(is_array($issues) && array_is_list($issues), 'Psalm did not return a diagnostic list');
    $types = array_column($issues, 'type');
    $ok = $tainted ? $status === 2 && in_array('TaintedHtml', $types, true) : $status === 0 && $issues === [];
    $results[$name] = ['expected_tainted_html' => $tainted, 'exit' => $status, 'types' => $types, 'pass' => $ok, 'command' => $command];
    if (!$ok) {
        $failed[] = $name;
    }
    printf("%s: %s\n", $name, $ok ? 'PASS' : 'FAIL expected ' . ($tainted ? 'TaintedHtml' : 'no taint'));
}
file_put_contents("$proof/summary.json", json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
foreach ($pins as $path => $expected) {
    must(hash_file('sha256', $path) === $expected, 'Engine changed during proof');
}
foreach ($files as $file) {
    must(hash_file('sha256', "$repo/$file") === $hashes[$file], 'Source changed during proof');
}
must($failed === [], 'Flash propagation assertion failed: ' . implode(', ', $failed));
printf("taint: %d isolated boundary cases passed; renderer/application graph not covered\n", count($cases));
PHP
