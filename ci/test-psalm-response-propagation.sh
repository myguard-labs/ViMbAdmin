#!/usr/bin/env bash
# Keep Response's public value contract and real Psalm sink propagation visible.
# shellcheck disable=SC2016 # PHP source must remain literal in shell strings.
set -euo pipefail
repo_root=$(cd "$(dirname "$0")/.." && pwd -P)
php=${PHP_BINARY:-php}
"$php" -r '
    require $argv[1];
    function check(bool $ok): void { if (!$ok) throw new RuntimeException("Response contract changed"); }
    $response = new ViMbAdmin\Kernel\Http\Response(body: "", headers: []);
    check($response->body === "" && $response->status === 200);
    check($response->contentType === "text/html; charset=utf-8" && $response->headers === [] && $response->afterSend === null);
    $callback = static function (): void {};
    $response = new ViMbAdmin\Kernel\Http\Response("body", 201, "text/custom", ["X-Test" => "value"], $callback);
    check($response->body === "body" && $response->status === 201 && $response->contentType === "text/custom");
    check($response->headers === ["X-Test" => "value"] && $response->afterSend === $callback);
    $text = ViMbAdmin\Kernel\Http\Response::text("0", 204);
    check($text->body === "0" && $text->status === 204 && $text->contentType === "text/plain; charset=utf-8");
    foreach (["body", "status", "contentType", "headers", "afterSend"] as $property) {
        try { $response->$property = null; } catch (Error $error) { continue; }
        throw new RuntimeException("Response property became writable: " . $property);
    }
    foreach ([static fn() => new ViMbAdmin\Kernel\Http\Response([]), static fn() => new ViMbAdmin\Kernel\Http\Response("", headers: "invalid")] as $invalid) {
        try { $invalid(); } catch (TypeError $error) { continue; }
        throw new RuntimeException("Malformed Response argument accepted");
    }
' "$repo_root/src/Kernel/Http/Response.php"
echo 'Response values, defaults, named arguments, readonly fields and malformed types pass.'
if [[ ${1:-} == --runtime-only ]]; then exit 0; fi
if [[ -n ${PSALM_BIN:-} ]]; then
  psalm=$PSALM_BIN
elif [[ -x $repo_root/vendor/bin/psalm ]]; then
  psalm=$repo_root/vendor/bin/psalm
else
  psalm=$(command -v psalm)
fi
if [[ $psalm == */* && $psalm != /* ]]; then psalm=$repo_root/$psalm; fi
work=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-response.XXXXXX")
trap 'rm -rf -- "${work:?}"' EXIT
mkdir -p "$work"/{src,vendor}
printf '<?php\n' >"$work/vendor/autoload.php"
cp "$repo_root/src/Kernel/Http/Response.php" "$work/src/Response.php"
cat >"$work/psalm.xml" <<'XML'
<?xml version="1.0"?>
<psalm errorLevel="8" resolveFromConfigFile="true" xmlns="https://getpsalm.org/schema/config">
  <projectFiles><directory name="src" /></projectFiles>
</psalm>
XML
write_probe() {
  case "$1" in
    body) expression="echo (new Response(\$_GET['body']))->body;" ;;
    text) expression="echo Response::text(\$_GET['text'])->body;" ;;
    type) expression="header('Content-Type: ' . (new Response('', 200, \$_GET['type']))->contentType);" ;;
    value) expression="foreach ((new Response('', 200, 'text/plain', ['X-Test' => \$_GET['value']]))->headers as \$name => \$value) header(\$name . ': ' . \$value);" ;;
    name) expression="foreach ((new Response('', 200, 'text/plain', [\$_GET['name'] => 'value']))->headers as \$name => \$value) header(\$name . ': ' . \$value);" ;;
    safe) expression="echo (new Response(''))->body; echo Response::text('fixed')->body; header('Content-Type: ' . (new Response(''))->contentType); foreach ((new Response('', 200, 'text/plain', ['X-Fixed' => 'value']))->headers as \$name => \$value) header(\$name . ': ' . \$value);" ;;
  esac
  printf '<?php\nuse ViMbAdmin\\Kernel\\Http\\Response;\n%s\n' "$expression" >"$work/src/Probe.php"
}
analyse() {
  local status=0
  (cd "$work" && "$psalm" --config=psalm.xml --no-progress --no-cache --threads=1 \
    "$@" --output-format=json) >"$work/result.json" 2>"$work/psalm.err" || status=$?
  if [[ $status != 0 && $status != 2 ]]; then
    cat "$work/psalm.err" "$work/result.json" >&2
    echo "Psalm Response analysis failed with status $status." >&2
    return 1
  fi
}
assert_sinks() {
  "$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($issues as $issue) $actual[] = [$issue["file_name"], $issue["line_from"], $issue["type"]];
    $types = match ($argv[2]) {
        "body", "text" => ["TaintedHtml", "TaintedTextWithQuotes"],
        "safe" => [],
        default => ["TaintedHeader"],
    };
    $expected = array_map(static fn($type) => ["src/Probe.php", 3, $type], $types);
    sort($actual); sort($expected);
    if ($actual !== $expected) {
        fwrite(STDERR, "Response sink propagation differs (" . $argv[2] . "): " . json_encode([$expected, $actual]) . "\n"); exit(1);
    }
  ' "$work/result.json" "$1"
}
check_probes() {
  local probe
  for probe in body text type value name safe; do
    write_probe "$probe"
    analyse --taint-analysis
    assert_sinks "$probe"
    echo "Response propagation passes: $probe"
  done
}
# Isolate cases because Psalm merges property taint across class instances.
check_probes
# Recreate the old promoted sink fields in the isolated fixture only.
cp "$work/src/Response.php" "$work/original.php"
"$php" -r '
    $source = file_get_contents($argv[1]);
    foreach (["body" => "string", "contentType" => "string", "headers" => "array"] as $name => $type) {
        $property = "    public readonly " . $type . " $" . $name . ";\n";
        if (substr_count($source, $property) !== 1) throw new RuntimeException("Missing explicit property");
        $source = str_replace($property, "", $source);
        $source = str_replace("        " . $type . " $" . $name, "        public readonly " . $type . " $" . $name, $source);
        $source = str_replace("        \$this->" . $name . " = $" . $name . ";\n", "", $source);
    }
    file_put_contents($argv[1], $source);
' "$work/src/Response.php"
"$php" -l "$work/src/Response.php" >/dev/null
for probe in body text type value name; do
  write_probe "$probe"
  analyse --taint-analysis
  if assert_sinks "$probe" >"$work/control.log" 2>&1; then
    echo "Promoted Response field survived the propagation oracle: $probe" >&2
    exit 1
  fi
  grep -qF 'Response sink propagation differs' "$work/control.log"
  cat "$work/control.log"
  echo "Negative control detects promoted Response fields: $probe"
done
cp "$work/original.php" "$work/src/Response.php"
check_probes
echo 'Restored explicit Response assignments pass the same oracle.'
printf '<?php function {\n' >"$work/src/Probe.php"
analyse --show-info=false
"$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach ($issues as $issue) if ($issue["type"] === "ParseError" && $issue["file_name"] === "src/Probe.php") exit(0);
    throw new RuntimeException("Malformed Response fixture was not reported");
' "$work/result.json"
echo 'Malformed Response fixtures produce a parser error.'
