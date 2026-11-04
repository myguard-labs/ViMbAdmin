#!/usr/bin/env bash
# Exercise the MCP reader and its analysis-only model through the real callback.
# shellcheck disable=SC2016 # PHP source must remain literal in shell strings.
set -euo pipefail
repo_root=$(cd "$(dirname "$0")/.." && pwd -P)
php=${PHP_BINARY:-php}
baseline_before=$(sha256sum "$repo_root/psalm-taint-baseline.xml")
if [[ -n ${MCP_PROOF_DIR:-} ]]; then
  work=$MCP_PROOF_DIR
  mkdir "$work"
else
  work=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-mcp-body.XXXXXX")
  trap 'rm -rf -- "${work:?}"' EXIT
fi
mkdir -p "$work"/{src,vendor,ci/psalm-stubs}
"$php" -r '
    namespace ViMbAdmin\Kernel\Mvc {
        abstract class AbstractController {
            protected function em(): \Doctrine\ORM\EntityManager { throw new \LogicException("Unused fixture entity manager"); }
        }
    }
    namespace {
        class McpInputFixtureStream {
            public mixed $context;
            public static string $body = "";
            private int $offset = 0;
            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
                if ($path !== "php://input") throw new RuntimeException("Wrong MCP input stream");
                return true;
            }
            public function stream_read(int $length): string {
                $part = substr(self::$body, $this->offset, $length); $this->offset += strlen($part); return $part;
            }
            public function stream_eof(): bool { return $this->offset >= strlen(self::$body); }
            public function stream_stat(): array { return []; }
        }
        require $argv[1];
        $reader = new ReflectionFunction("vimbadmin_mcp_read_request_body");
        if ($reader->invoke() !== "") throw new RuntimeException("Empty MCP stream changed");
        if (!stream_wrapper_unregister("php")) throw new RuntimeException("Cannot isolate failed input read");
        set_error_handler(static fn(): bool => true);
        try {
            if ($reader->invoke() !== false) throw new RuntimeException("Failed MCP stream read changed");
            if (!stream_wrapper_register("php", McpInputFixtureStream::class)) throw new RuntimeException("Cannot install owned input fixture");
            foreach (["", "0", "{\"method\":\"ping\"}", "{invalid", "\x00\xff"] as $body) {
                McpInputFixtureStream::$body = $body;
                if ($reader->invoke() !== $body) throw new RuntimeException("MCP stream bytes changed");
            }
            stream_wrapper_unregister("php");
        } finally {
            restore_error_handler();
            stream_wrapper_restore("php");
        }
        if ($reader->invoke() !== "") throw new RuntimeException("MCP stream was not restored");
        $source = file($argv[1]);
        $method = new ReflectionMethod(McpController::class, "indexAction");
        $indexSource = implode("", array_slice($source, $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
        if (preg_match_all("~static fn \(\): string\|false => ([^\n,]+),~", $indexSource, $matches) !== 1) throw new RuntimeException("MCP body callback changed shape");
        $readerSource = implode("", array_slice($source, $reader->getStartLine()-1, $reader->getEndLine()-$reader->getStartLine()+1));
        file_put_contents($argv[2], "<?php\n" . $readerSource . "\n\$reader = static fn (): string|false => " . $matches[1][0] . ";\necho \$reader();\n");
    }
' "$repo_root/application/controllers/McpController.php" "$work/src/Probe.php"
echo 'MCP reader preserves empty/nonempty/malformed bytes, failed reads and restored stream behavior.'
if [[ ${1:-} == --runtime-only ]]; then exit 0; fi
: "${PSALM_BIN:?Select the checked two-stage Psalm 6.16.1 launcher}"
psalm=$(realpath "$PSALM_BIN")
tool=$("$php" -r '
    $tool = dirname($argv[1], 2);
    $m = json_decode(file_get_contents($tool . "/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
    if ($m["upstream_version"] !== "6.16.1" || $m["inverse_byte_conservation"] !== true || ($m["callable_inverse_byte_conservation"] ?? false) !== true) throw new RuntimeException("Unchecked callable tool");
    $pins = ["src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php" => "ed870ddfc6402c3f06995cd9ffbf0a61a8db8bba94c32dd4ff829cd2582f356e", "src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php" => "e54be128641ce2bb8bed11f53897e6f8e61a45d5368e8b0e984816825749483c", "src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentsAnalyzer.php" => "1c0ba8ba626104f8c983f4c34451503887c822652b11fd917b504cd46c09ec8f"];
    foreach ($pins as $member => $expected) if (hash_file("sha256", $tool . "/engine/" . $member) !== $expected) throw new RuntimeException("Wrong callable engine member");
    echo $tool;
' "$psalm")
printf '<?php\n' >"$work/vendor/autoload.php"
cp "$repo_root/ci/psalm-stubs/mcp-request-body.phpstub" "$work/ci/psalm-stubs/mcp-request-body.phpstub"
"$php" -r '
    $doc = new DOMDocument();
    if (!$doc->load($argv[1])) throw new RuntimeException("Invalid shipped Psalm config");
    $root = $doc->documentElement;
    $root->removeAttribute("errorBaseline");
    $xpath = new DOMXPath($doc); $xpath->registerNamespace("p", "https://getpsalm.org/schema/config");
    $old = $xpath->query("/p:psalm/p:projectFiles")->item(0);
    $project = $doc->createElementNS($root->namespaceURI, "projectFiles");
    $directory = $doc->createElementNS($root->namespaceURI, "directory");
    $directory->setAttribute("name", "src"); $project->appendChild($directory);
    $root->replaceChild($project, $old);
    // Preserve the shipped auxiliary model set without copying optional clients.
    // Only the MCP stub stays fixture-local so the source mutation is causal.
    foreach ($xpath->query("/p:psalm/p:extraFiles/* | /p:psalm/p:stubs/*") as $entry) {
        $name = $entry->getAttribute("name");
        if ($name === "ci/psalm-stubs/mcp-request-body.phpstub") continue;
        $path = realpath(dirname($argv[1]) . "/" . $name);
        if ($path === false) throw new RuntimeException("Missing shipped Psalm model: " . $name);
        $entry->setAttribute("name", $path);
    }
    $doc->save($argv[2]);
' "$repo_root/psalm.xml" "$work/psalm.xml"
cp "$repo_root/library/ViMbAdmin/Mcp/Dispatcher.php" "$work/src/Dispatcher.php"
"$php" -r '
    $source = file_get_contents($argv[1]);
    $needle = "$" . "requestBody = $" . "body();";
    if (substr_count($source, $needle) !== 1) throw new RuntimeException("Actual dispatcher callback changed");
    file_put_contents($argv[1], str_replace($needle, $needle . " echo $" . "requestBody;", $source));
' "$work/src/Dispatcher.php"
cat >>"$work/src/Probe.php" <<'PHP'
require __DIR__ . '/Dispatcher.php';
$dispatcher = new ViMbAdmin_Mcp_Dispatcher([], static fn (array $server, string $scope) => null, static fn ($token) => null);
$dispatcher->dispatch(true, 'POST', $reader, []);
PHP
# Known local reads and fixed values must not inherit the MCP-only source model.
cat >>"$work/src/Probe.php" <<'PHP'
echo file_get_contents(__FILE__);
echo 'fixed';
PHP
cp "$work/src/Probe.php" "$work/base.php"
dispatcher_line=$(grep -nF 'echo $requestBody;' "$work/src/Dispatcher.php" | cut -d: -f1)
sink_line=$(grep -nF 'echo $reader();' "$work/src/Probe.php" | cut -d: -f1)
analyse() {
  local status=0
  (cd "$work" && "$php" "$psalm" --config=psalm.xml --no-progress --no-cache --threads=1 \
    "$@" --output-format=json) >"$work/result.json" 2>"$work/psalm.err" || status=$?
  printf '%s\n' "$status" >"$work/exit.txt"
  if [[ $status != 0 && $status != 2 ]]; then
    cat "$work/psalm.err" "$work/result.json" >&2
    echo "MCP body analysis failed with status $status." >&2
    return 1
  fi
}
assert_source() {
  "$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($issues as $issue) $actual[] = [$issue["file_name"], $issue["line_from"], $issue["type"]];
    $expected = [["src/Probe.php", (int)$argv[2], "TaintedHtml"], ["src/Probe.php", (int)$argv[2], "TaintedTextWithQuotes"], ["src/Dispatcher.php", (int)$argv[3], "TaintedHtml"], ["src/Dispatcher.php", (int)$argv[3], "TaintedTextWithQuotes"]];
    sort($actual); sort($expected);
    if ($actual !== $expected) {
        fwrite(STDERR, "MCP body source differs: " . json_encode([$expected, $actual]) . "\n"); exit(1);
    }
  ' "$work/result.json" "$sink_line" "$dispatcher_line"
}
analyse --taint-analysis
assert_source
cp "$work/result.json" "$work/actual-source-result.json"
echo 'Actual MCP dispatcher and direct callback reach both exact sinks; constant/local-file controls stay clean.'
cp "$work/ci/psalm-stubs/mcp-request-body.phpstub" "$work/source.phpstub"
sed '/@psalm-taint-source input/d' "$work/source.phpstub" >"$work/ci/psalm-stubs/mcp-request-body.phpstub"
analyse --taint-analysis
if assert_source >"$work/control.log" 2>&1; then
  echo 'Disabled MCP source survived the oracle.' >&2
  exit 1
fi
grep -qF 'MCP body source differs:' "$work/control.log"
cat "$work/control.log"
"$php" -r '
    if (trim(file_get_contents($argv[2])) !== "0" || json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR) !== []) throw new RuntimeException("Source-disabled fixture was not clean");
' "$work/result.json" "$work/exit.txt"
cp "$work/result.json" "$work/source-disabled-result.json"
echo 'Negative control: disabling the stub source loses the required taint findings.'
cp "$work/source.phpstub" "$work/ci/psalm-stubs/mcp-request-body.phpstub"
analyse --taint-analysis
assert_source
echo 'Restored MCP source passes the same oracle.'
# These six clean cases plus actual/source-disabled exercise eight distinct source contracts.
mkdir "$work/cases"
for case_name in constant local-file captured-fixed unused-source passed-uninvoked escaped; do
  "$php" -r '
    $source = file_get_contents($argv[1]);
    $callback = "static fn (): string|false => vimbadmin_mcp_read_request_body()";
    if (substr_count($source, $callback) !== 1) throw new RuntimeException("Extracted MCP callback changed");
    switch ($argv[3]) {
      case "constant": $source = str_replace($callback, "static fn (): string|false => \"fixed\"", $source); break;
      case "local-file": $source = str_replace($callback, "static fn (): string|false => file_get_contents(__FILE__)", $source); break;
      case "captured-fixed": $source = str_replace("$" . "reader = " . $callback . ";", "$" . "captured = vimbadmin_mcp_read_request_body();\n$" . "reader = static fn (): string|false => strlen((string) $" . "captured) > 0 ? \"fixed\" : \"also-fixed\";", $source); break;
      case "escaped": $source = str_replace($callback, "static fn (): string|false => htmlspecialchars((string) vimbadmin_mcp_read_request_body(), ENT_QUOTES | ENT_SUBSTITUTE, \"UTF-8\")", $source); break;
      default:
        $source = str_replace("echo $" . "reader();", "echo \"fixed\";", $source);
        $call = "$" . "dispatcher->dispatch(true, \x27POST\x27, $" . "reader, []);";
        $replacement = $argv[3] === "unused-source"
          ? "$" . "dispatcher->dispatch(true, \x27POST\x27, static fn (): string|false => \"fixed\", []);"
          : "/** @param \\Closure():(string|false) $" . "callback */ function ignored(\\Closure $" . "callback): string { return \"fixed\"; } echo ignored($" . "reader);";
        if (substr_count($source, $call) !== 1) throw new RuntimeException("Actual dispatch call changed");
        $source = str_replace($call, $replacement, $source);
    }
    file_put_contents($argv[2], $source);
  ' "$work/base.php" "$work/src/Probe.php" "$case_name"
  "$php" -l "$work/src/Probe.php" >/dev/null
  analyse --taint-analysis
  "$php" -r '
    if (trim(file_get_contents($argv[2])) !== "0" || json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR) !== []) throw new RuntimeException("Trusted/unused callback became tainted");
  ' "$work/result.json" "$work/exit.txt"
  mkdir "$work/cases/$case_name"
  cp "$work/src/Probe.php" "$work/result.json" "$work/exit.txt" "$work/cases/$case_name/"
  echo "MCP source-specific control passes: $case_name"
done
cp "$work/base.php" "$work/src/Probe.php"
# Disable and misroute only the callable-return channel in private analyzer copies.
cp -a "$tool/engine" "$work/control-engine"
selected_psalm=$psalm
for control in disabled-channel wrong-channel; do
  cp "$tool/engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php" "$work/control-engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"
  cp "$tool/engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php" "$work/control-engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php"
  if [[ $control == disabled-channel ]]; then
    cp "$tool/callable-original/FunctionLikeAnalyzer.php" "$work/control-engine/src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php"
    cp "$tool/callable-original/ArgumentAnalyzer.php" "$work/control-engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php"
  else
    "$php" -r '
      $source = file_get_contents($argv[1]);
      if (substr_count($source, "@callable-return") !== 2) throw new RuntimeException("Callable transport changed");
      file_put_contents($argv[1], str_replace("@callable-return", "@callable-object", $source));
    ' "$work/control-engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php"
  fi
  psalm=$work/control-engine/psalm
  analyse --taint-analysis
  if assert_source >"$work/$control-oracle.log" 2>&1; then
    echo "Broken callable channel survived the actual dispatcher oracle: $control" >&2
    exit 1
  fi
  grep -qF 'MCP body source differs:' "$work/$control-oracle.log"
  "$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    if (count($issues) !== 2 || array_unique(array_column($issues, "file_name")) !== ["src/Probe.php"]) throw new RuntimeException("Channel control did not preserve direct positive source");
  ' "$work/result.json"
  cp "$work/result.json" "$work/$control-result.json"
  echo "Negative control detects $control; direct positive source remains"
  psalm=$selected_psalm
  analyse --taint-analysis
  assert_source
  echo "Restored actual dispatcher passes after $control"
done
printf '<?php function {\n' >"$work/src/Probe.php"
analyse --show-info=false
"$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach ($issues as $issue) if ($issue["type"] === "ParseError" && $issue["file_name"] === "src/Probe.php") exit(0);
    throw new RuntimeException("Malformed MCP fixture was not reported");
' "$work/result.json"
[[ $(sha256sum "$repo_root/psalm-taint-baseline.xml") == "$baseline_before" ]]
echo 'Malformed MCP fixture is rejected; baseline bytes are unchanged.'
