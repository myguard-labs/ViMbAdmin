#!/usr/bin/env bash
# Run once in each normal supported CLI lane; never override PHP configuration.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
php=${PHP_BINARY:-$(command -v php)}
upstream=$(realpath "${1:?Supply the pinned upstream PHPStan PHAR}")
tool=$(realpath "${2:?Supply the prepared private PHPStan tool}")
helper=$root/ci/patch-phpstan-cli-globals.php
work=$(mktemp -d "${TMPDIR:-/tmp}/phpstan-cli-globals.XXXXXX")
cleanup() {
  local status=$?
  if [[ $status == 0 ]]; then rm -rf -- "${work:?}"; else echo "PHPStan CLI failure evidence retained: $work" >&2; fi
}
trap cleanup EXIT
reject() {
  local name=$1
  shift
  if "$@" >"$work/$name.stdout" 2>"$work/$name.stderr"; then
    echo "FAIL: $name unexpectedly accepted" >&2
    exit 1
  fi
  [[ -s $work/$name.stderr ]] || { echo "FAIL: $name lacks a rejection diagnostic" >&2; exit 1; }
}
"$php" "$helper" --verify "$upstream" "$tool"
"$php" "$helper" "$upstream" "$work/guard-tool"
"$php" "$helper" --verify "$upstream" "$work/guard-tool"
reject existing-output "$php" "$helper" "$upstream" "$work/guard-tool"
printf 'unknown PHPStan PHAR\n' >"$work/unknown.phar"
reject unknown-phar "$php" "$helper" "$work/unknown.phar" "$work/unknown-output"
[[ ! -e $work/unknown-output ]]
mkdir "$work/changed-patch"
cp "$helper" "$root/ci/phpstan-cli-globals.patch" "$work/changed-patch/"
printf '\n' >>"$work/changed-patch/phpstan-cli-globals.patch"
reject changed-patch "$php" "$work/changed-patch/patch-phpstan-cli-globals.php" "$upstream" "$work/patch-output"
[[ ! -e $work/patch-output ]]
for relative in manifest.json bin/phpstan engine/src/DependencyInjection/ContainerFactory.php engine/bin/phpstan; do
  target=$work/guard-tool/$relative
  cp -p "$target" "$work/saved-file"
  chmod u+w "$target"
  printf '\nchanged\n' >>"$target"
  reject "changed-${relative//\//-}" "$php" "$helper" --verify "$upstream" "$work/guard-tool"
  cp -p "$work/saved-file" "$target"
  rm "$work/saved-file"
  "$php" "$helper" --verify "$upstream" "$work/guard-tool"
done
printf 'extra\n' >"$work/guard-tool/engine/extra.php"
reject extra-engine-member "$php" "$helper" --verify "$upstream" "$work/guard-tool"
rm "$work/guard-tool/engine/extra.php"
"$php" "$helper" --verify "$upstream" "$work/guard-tool"

# Evaluate only the exact pinned expression from the actual patched member.
# Substitute the two runtime inputs, so non-CLI cases need no FPM/INI mutation.
"$php" -r '
$member = file_get_contents($argv[1]);
if ($member === false || hash("sha256", $member) !== "8e475770f46443522cbadab1739cb5702f34e8663ab255418321fdc46229b967") throw new RuntimeException("Model member changed");
$tail = explode("\x27cliArgumentsVariablesRegistered\x27 => ", $member);
if (count($tail) !== 2) throw new RuntimeException("Ambiguous model expression");
$expression = explode(", \x27tmpDir\x27 =>", $tail[1], 2)[0];
$expression = str_replace(["PHP_SAPI", "ini_get(\x27register_argc_argv\x27)"], ["\$sapi", "\$ini"], $expression);
if ($expression !== "(\$sapi === \x27cli\x27 || \$ini === \x271\x27)") throw new RuntimeException("Unknown model expression");
foreach ([["cli", "0", true], ["cli", "1", true], ["cli", false, true], ["cgi-fcgi", "0", false], ["cgi-fcgi", "1", true], ["fpm-fcgi", false, false], ["phpdbg", "0", false]] as [$sapi, $ini, $expected]) {
    if (eval("return " . $expression . ";") !== $expected) throw new RuntimeException("SAPI/INI model regression");
}
' "$tool/engine/src/DependencyInjection/ContainerFactory.php"
normal_ini=$("$php" -r 'if (PHP_SAPI !== "cli") exit(1); $v = ini_get("register_argc_argv"); echo $v === "1" ? "1" : "0";')
[[ $normal_ini == 0 || $normal_ini == 1 ]]
printf '<?php\nvar_export($argc);\nvar_export($argv);\n' >"$work/arguments.php"
printf '<?php\nvar_export($unrelatedUndefined);\n' >"$work/unrelated.php"
run_analysis() {
  local name=$1 executable=$2 fixture=$3 expected=$4 status
  printf 'parameters:\n    level: 0\n    checkMaybeUndefinedVariables: true\n    tmpDir: %s/cache-%s\n    parallel:\n        maximumNumberOfProcesses: 1\n' "$work" "$name" >"$work/$name.neon"
  if (cd "$work" && "$php" "$executable" analyse -c "$work/$name.neon" --no-progress --error-format=json "$fixture") >"$work/$name.json" 2>"$work/$name.stderr"; then status=0; else status=$?; fi
  [[ $status == "$expected" ]] || { echo "FAIL: $name exit $status, expected $expected" >&2; exit 1; }
  [[ ! -s $work/$name.stderr ]] || { cat "$work/$name.stderr" >&2; exit 1; }
}
old_status=0
[[ $normal_ini == 1 ]] || old_status=1
run_analysis upstream "$upstream" "$work/arguments.php" "$old_status"
run_analysis patched "$tool/bin/phpstan" "$work/arguments.php" 0
run_analysis unrelated "$tool/bin/phpstan" "$work/unrelated.php" 1
"$php" -r '
[$script, $work, $ini] = $argv;
foreach (["upstream", "patched", "unrelated"] as $name) {
    $data = json_decode(file_get_contents($work . "/" . $name . ".json"), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data["errors"] ?? null) !== [] || !is_array($data["files"] ?? null)) throw new RuntimeException("Invalid analyzer result");
    $messages = [];
    foreach ($data["files"] as $row) {
        if (!is_array($row) || !is_array($row["messages"] ?? null)) throw new RuntimeException("Invalid analyzer messages");
        foreach ($row["messages"] as $message) {
            if (($message["identifier"] ?? null) !== "variable.undefined" || !is_string($message["message"] ?? null)) throw new RuntimeException("Unexpected analyzer diagnostic");
            $messages[] = $message["message"];
        }
    }
    $expected = $name === "unrelated" ? ["unrelatedUndefined"] : ($name === "upstream" && $ini === "0" ? ["argc", "argv"] : []);
    if (count($messages) !== count($expected) || ($data["totals"]["file_errors"] ?? null) !== count($expected)) throw new RuntimeException("Wrong diagnostic count");
    foreach ($expected as $variable) {
        $matches = array_filter($messages, static fn(string $message): bool => str_contains($message, "$" . $variable));
        if (count($matches) !== 1) throw new RuntimeException("Missing expected undefined variable");
    }
}
' "$work" "$normal_ini"
"$php" "$helper" --verify "$upstream" "$tool"
printf 'ALL PASSED: PHPStan CLI model; normal INI flag=%s, upstream exit=%s, patched exit=0, unrelated exit=1\n' "$normal_ini" "$old_status"
