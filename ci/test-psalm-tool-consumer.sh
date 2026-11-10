#!/usr/bin/env bash
# Exercise the real static-profile selector without running host/package setup.
# shellcheck disable=SC2016 # PHP and child-shell source must remain literal.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
setup=${PSALM_SETUP_SOURCE:-$root/.github/scripts/setup-php-container.sh}
self=$root/ci/test-psalm-tool-consumer.sh
php=${PHP_BINARY:-$(command -v php)}

binding() {
  local block
  block=$(awk '/^if \[\[ \$profile == static \|\| \$profile == psalm \]\]; then$/ { copying=1 } copying { print } copying && /^fi$/ { exit }' "$setup")
  [[ $(grep -c '^  install_psalm_toolchain ' <<<"$block") == 1 && $(grep -c '^  install_psalm_toolchain ' "$setup") == 1 ]] || {
    echo 'FAIL: static/psalm profiles do not install the checked toolchain exactly once' >&2
    return 1
  }
  local toolchain
  toolchain=$(awk '/^install_psalm_toolchain\(\) \{$/ { copying=1 } copying { print } copying && /^}$/ { exit }' "$setup")
  [[ $(grep -c '^  select_patched_psalm ' <<<"$toolchain") == 1 && $(grep -c '^  run_psalm_contracts ' <<<"$toolchain") == 1 ]] || {
    echo 'FAIL: static profile does not select the checked engine exactly once' >&2
    return 1
  }
  local contracts
  contracts=$(awk '/^run_psalm_contracts\(\) \{$/ { copying=1 } copying { print } copying && /^}$/ { exit }' "$setup")
  grep -qF 'env -u PSALM_NAMED_EVIDENCE_DIR bash "$repo_root/ci/test-psalm-named-argument-taint.sh" "$upstream" "$engine" || return' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual engine suite' >&2
    return 1
  }
  grep -qF 'bash "$repo_root/ci/test-psalm-tool-consumer.sh" --ci-child "$upstream"' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual consumer suite' >&2
    return 1
  }
  grep -qF 'env -u FLASH_PROOF_DIR bash "$repo_root/ci/test-psalm-flash-propagation.sh"' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual Flash suite' >&2
    return 1
  }
  grep -qF 'bash "$repo_root/ci/test-psalm-named-argument-taint.sh" --helper-only "$upstream"' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual guard suite' >&2
    return 1
  }
  grep -qF 'bash "$repo_root/ci/test-psalm-response-propagation.sh"' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual Response suite' >&2
    return 1
  }
  grep -qF 'env -u MCP_PROOF_DIR bash "$repo_root/ci/test-psalm-mcp-body-source.sh"' <<<"$contracts" || {
    echo 'FAIL: CI does not run the actual MCP suite' >&2
    return 1
  }
  local patch_block
  patch_block=$(awk '/^if \[\[ \$profile == static \|\| \$profile == phpstan \|\| \$profile == psalm \]\]; then$/ { copying=1 } copying { print } copying && /^fi$/ { exit }' "$setup")
  [[ $(grep -c '^  apt-get install .* patch$' <<<"$patch_block") == 1 && $(grep -c '^  apt-get install .* patch$' "$setup") == 1 ]] || {
    echo 'FAIL: patch must be installed exactly once for static/phpstan/psalm profiles' >&2
    return 1
  }
}
binding
if [[ ${1:-} == --binding-only ]]; then exit 0; fi
ci_child=0
if [[ ${1:-} == --ci-child ]]; then
  ci_child=1
  shift
fi
upstream=$(realpath "${1:?Supply the CI-pinned upstream Psalm PHAR}")
if [[ -n ${PSALM_CONSUMER_EVIDENCE_DIR:-} ]]; then
  work=$PSALM_CONSUMER_EVIDENCE_DIR
  mkdir "$work"
else
  work=$(mktemp -d "${TMPDIR:-/tmp}/psalm-tool-consumer.XXXXXX")
fi
: >"$work/created-roots"
cleanup() {
  local status=$? created
  if [[ $status != 0 ]]; then
    printf 'Consumer failure evidence retained: %s\n' "$work" >&2
    if [[ -f $work/ci-contracts.log ]]; then tail -c 6000 "$work/ci-contracts.log" >&2; fi
    return
  fi
  if [[ -z ${PSALM_CONSUMER_EVIDENCE_DIR:-} ]]; then
    while IFS= read -r created; do
      [[ $created == "$root"/var/tmp/psalm-tool.* && -d $created && ! -L $created ]] || continue
      rm -rf -- "$created"
    done <"$work/created-roots"
    rm -rf -- "${work:?}"
  fi
}
trap cleanup EXIT

# Load verbatim maintained constants and the actual function, not a test copy.
awk '/^readonly psalm_(version|sha256)=/ { print } /^select_patched_psalm\(\) \{$/ { copying=1 } copying { print } copying && /^}$/ { exit }' "$setup" >"$work/selector.sh"
grep -q '^select_patched_psalm() {$' "$work/selector.sh"
# shellcheck source=/dev/null
source "$work/selector.sh"
: "${psalm_sha256:?Missing maintained Psalm pin}"
base_path=$PATH

# Wrap only allocation and post-build corruption controls. Every build and
# executable in this test is the real pinned PHAR/maintained helper/launcher.
mktemp() {
  local created
  created=$(command mktemp "$@") || return
  printf '%s\n' "$created" >>"$work/created-roots"
  if [[ ${FORCE_EXISTING:-0} == 1 ]]; then
    mkdir "$created/tool"
    printf 'retain\n' >"$created/tool/marker"
  fi
  printf '%s\n' "$created"
}
php() {
  "$php" "$@" || return
  if [[ $1 == "$root/ci/patch-psalm-named-taint.php" && -n ${CORRUPT_BUILD:-} ]]; then
    "$php" -r '
      $tool = $argv[1];
      switch ($argv[2]) {
        case "manifest":
          $path = $tool . "/manifest.json";
          $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
          $data["upstream_version"] = "unknown";
          file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
          break;
        case "member":
          file_put_contents($tool . "/engine/src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentsAnalyzer.php", "\n// changed\n", FILE_APPEND);
          break;
        case "launcher":
          file_put_contents($tool . "/bin/psalm", "\n// changed\n", FILE_APPEND);
          break;
      }
    ' "$3" "$CORRUPT_BUILD"
  fi
}

invoke() (
  local name=$1 mutation=${2:-} source=$upstream
  mkdir "$work/$name"
  # shellcheck disable=SC2030 # Private invocation state is deliberately confined to this subshell.
  export GITHUB_ACTIONS=true GITHUB_PATH=$work/$name/github-path GITHUB_ENV=$work/$name/github-env
  : >"$GITHUB_PATH"
  : >"$GITHUB_ENV"
  if [[ $name == bad-environment ]]; then GITHUB_ENV=$work/$name/missing; fi
  if [[ $name == unknown-source ]]; then source=$work/unknown.phar; fi
  if [[ $name == local ]]; then unset GITHUB_ACTIONS GITHUB_PATH GITHUB_ENV; fi
  CORRUPT_BUILD=$mutation FORCE_EXISTING=$([[ $name == existing ]] && printf 1 || printf 0) \
    select_patched_psalm "$root" "$source" || return
  printf '%s\n' "$PSALM_BIN" >"$work/$name/selected"
  [[ $(command -v psalm) == "$PSALM_BIN" ]]
)
printf 'not the pinned PHAR\n' >"$work/unknown.phar"
invoke positive >"$work/positive.log" 2>&1
invoke local >"$work/local.log" 2>&1
for name in bad-environment unknown-source existing manifest member launcher; do
  mutation=
  case $name in
    manifest | member | launcher) mutation=$name ;; esac
  if invoke "$name" "$mutation" >"$work/$name.log" 2>&1; then
    echo "FAIL: selection accepted $name" >&2
    exit 1
  fi
  [[ ! -s $work/$name/github-path && ! -s $work/$name/github-env ]]
  case $name in
    bad-environment) diagnostic='Invalid GitHub environment files' ;;
    unknown-source) diagnostic='Unknown or modified Psalm PHAR' ;;
    existing) diagnostic='Refusing an existing/already patched output' ;;
    *) diagnostic='Psalm selection manifest/hash mismatch' ;;
  esac
  grep -qF "$diagnostic" "$work/$name.log"
  echo "PASS: selector rejects $name before publication"
done
while IFS= read -r created; do
  if [[ -f $created/tool/marker ]]; then [[ $(cat "$created/tool/marker") == retain ]]; fi
done <"$work/created-roots"

mapfile -t github_paths <"$work/positive/github-path"
mapfile -t github_env <"$work/positive/github-env"
selected=$(cat "$work/positive/selected")
[[ ${#github_paths[@]} == 1 && ${#github_env[@]} == 1 ]]
[[ ${github_env[0]} == "PSALM_BIN=$selected" && ${github_paths[0]}/psalm == "$selected" ]]
engine=$(dirname "$(dirname "$selected")")/engine/psalm

# Apply the actual emitted runner protocol as data in a subsequent clean shell.
next_shell() {
  env -i HOME="$HOME" PATH="${github_paths[0]}:$base_path" PSALM_BIN="$selected" \
    EXPECTED_PSALM="$selected" bash --noprofile --norc -c '
      [[ $(command -v psalm) == "$EXPECTED_PSALM" && $PSALM_BIN == "$EXPECTED_PSALM" ]] || exit 97
      exec psalm "$@"
    ' consumer "$@"
}
compare_native() {
  local name=$1 selected_status=0 direct_status=0
  shift
  next_shell "$@" >"$work/$name.selected.out" 2>"$work/$name.selected.err" || selected_status=$?
  "$php" "$engine" "$@" >"$work/$name.direct.out" 2>"$work/$name.direct.err" || direct_status=$?
  [[ $selected_status == "$direct_status" ]]
  cmp "$work/$name.selected.out" "$work/$name.direct.out"
  cmp "$work/$name.selected.err" "$work/$name.direct.err"
  printf '%s\n' "$selected_status" >"$work/$name.exit"
}
compare_native version --root=/tmp --version
compare_native invalid-flag --consumer-invalid-option
[[ $(cat "$work/version.exit") == 0 && $(cat "$work/invalid-flag.exit") != 0 ]]
mkdir -p "$work/fixture"/{src,vendor}
cp "$root/src/Kernel/Http/Response.php" "$work/fixture/src/Response.php"
printf '<?php\n' >"$work/fixture/vendor/autoload.php"
cat >"$work/fixture/src/Probe.php" <<'PHP'
<?php
use ViMbAdmin\Kernel\Http\Response;
header((new Response('', contentType: $_GET['type']))->contentType);
PHP
printf '%s\n' '<?xml version="1.0"?><psalm errorLevel="8" findUnusedCode="false" resolveFromConfigFile="true" xmlns="https://getpsalm.org/schema/config"><projectFiles><directory name="src" /></projectFiles></psalm>' >"$work/fixture/psalm.xml"
status=0
(cd "$work/fixture" && next_shell --config=psalm.xml --no-cache --threads=1 --no-progress --taint-analysis --output-format=json) >"$work/analysis.json" 2>"$work/analysis.err" || status=$?
[[ $status == 2 ]]
"$php" -r '
  $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (count($issues) !== 1 || $issues[0]["type"] !== "TaintedHeader" || $issues[0]["file_name"] !== "src/Probe.php") throw new RuntimeException("Subsequent shell did not consume the patched engine");
' "$work/analysis.json"

printf '%s\n' '<?php function {' >"$work/fixture/src/Probe.php"
status=0
(cd "$work/fixture" && next_shell --config=psalm.xml --no-cache --threads=1 --no-progress --show-info=false --output-format=json) >"$work/plain.json" 2>"$work/plain.err" || status=$?
[[ $status == 2 ]]
"$php" -r '
  $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (count($issues) !== 1 || $issues[0]["type"] !== "ParseError") throw new RuntimeException("Plain mode did not preserve the native parser error");
' "$work/plain.json"

# Disabling the actual static-profile call must fail the same binding check.
sed '/^  select_patched_psalm /d' "$setup" >"$work/unselected-setup.sh"
if PSALM_SETUP_SOURCE=$work/unselected-setup.sh bash "$self" --binding-only >"$work/unselected.log" 2>&1; then
  echo 'FAIL: unused selector survived the binding oracle' >&2
  exit 1
fi
grep -qF 'static profile does not select the checked engine exactly once' "$work/unselected.log"

if [[ $ci_child == 0 ]]; then
  # Execute the maintained CI function verbatim, with real suite paths and engines.
  awk '/^run_psalm_contracts\(\) \{$/ { copying=1 } copying { print } copying && /^}$/ { exit }' "$setup" >"$work/contracts.sh"
  awk '/^install_verified_phar\(\) \($/ || /^install_psalm_toolchain\(\) \{$/ { copying=1 } copying { print } copying && /^[)}]$/ { copying=0 }' "$setup" >>"$work/contracts.sh"
  [[ $(grep -c '^install_verified_phar() ($' "$work/contracts.sh") == 1 && $(grep -c '^install_psalm_toolchain() {$' "$work/contracts.sh") == 1 ]] || {
    echo 'FAIL: CI extraction must load each actual installer/toolchain exactly once' >&2
    exit 1
  }
  # shellcheck source=/dev/null
  source "$work/contracts.sh"
  # shellcheck disable=SC2031 # Read original parent state; losing child mutations is required here.
  job_path=$PATH job_bin=${PSALM_BIN-} job_github_env=${GITHUB_ENV-} job_github_path=${GITHUB_PATH-}
  env_bytes_before=$(if [[ -f $job_github_env ]]; then sha256sum -- "$job_github_env"; fi)
  path_bytes_before=$(if [[ -f $job_github_path ]]; then sha256sum -- "$job_github_path"; fi)
  (
    # shellcheck disable=SC2030,SC2031 # Deliberately private runner state, checked unchanged in the parent.
    export GITHUB_ACTIONS=true GITHUB_ENV=$work/toolchain-env GITHUB_PATH=$work/toolchain-path
    : >"$GITHUB_ENV"
    : >"$GITHUB_PATH"
    mkdir "$work/installed"
    install_psalm_toolchain "$root" "$work/installed/psalm"
    printf '%s\n' "$PSALM_BIN" >"$work/toolchain-selected"
  ) >"$work/ci-contracts.log" 2>&1
  [[ $(sha256sum "$work/installed/psalm" | cut -d ' ' -f1) == "$psalm_sha256" ]]
  "$php" "$(cat "$work/toolchain-selected")" --root=/tmp --version >"$work/toolchain-version.log"
  if "$php" -r 'exit(PHP_VERSION_ID >= 80600 ? 0 : 1);'; then
    if "$php" "$work/installed/psalm" --root=/tmp --version >"$work/original-boxed-version.log" 2>&1; then
      echo 'FAIL: expected original boxed launcher to reject PHP 8.6' >&2
      exit 1
    fi
    grep -qF 'This application requires a PHP version matching' "$work/original-boxed-version.log"
  fi
  grep -qF 'PASS: actual pinned-engine red/patched-green controls; no application graph qualification' "$work/ci-contracts.log"
  grep -qF 'PASS: actual static selector, later-shell resolution, native I/O/status, guarded refusals and unused-call negative control' "$work/ci-contracts.log"
  grep -qF 'taint: 10 isolated boundary cases passed; renderer/application graph not covered' "$work/ci-contracts.log"
  # The child must not change the real job environment or publish test selections.
  # shellcheck disable=SC2031 # Verify that intentional subshell changes never reached the parent.
  [[ $PATH == "$job_path" && ${PSALM_BIN-} == "$job_bin" && ${GITHUB_ENV-} == "$job_github_env" && ${GITHUB_PATH-} == "$job_github_path" ]]
  [[ $(if [[ -f $job_github_env ]]; then sha256sum -- "$job_github_env"; fi) == "$env_bytes_before" ]]
  [[ $(if [[ -f $job_github_path ]]; then sha256sum -- "$job_github_path"; fi) == "$path_bytes_before" ]]
  for suite in engine consumer flash response mcp guard; do
    if [[ $suite == engine ]]; then
      pattern='env -u PSALM_NAMED_EVIDENCE_DIR bash '
      diagnostic='CI does not run the actual engine suite'
    elif [[ $suite == consumer ]]; then
      pattern='    bash "$repo_root/ci/test-psalm-tool-consumer.sh"'
      diagnostic='CI does not run the actual consumer suite'
    elif [[ $suite == flash ]]; then
      pattern='env -u FLASH_PROOF_DIR bash '
      diagnostic='CI does not run the actual Flash suite'
    elif [[ $suite == response ]]; then
      pattern='bash "$repo_root/ci/test-psalm-response-propagation.sh"'
      diagnostic='CI does not run the actual Response suite'
    elif [[ $suite == mcp ]]; then
      pattern='env -u MCP_PROOF_DIR bash '
      diagnostic='CI does not run the actual MCP suite'
    else
      pattern='--helper-only'
      diagnostic='CI does not run the actual guard suite'
    fi
    # Remove the complete consumer command, including its continuation prefix.
    if [[ $suite == consumer ]]; then
      sed '/^  env -u GITHUB_ACTIONS /,+1d' "$setup" >"$work/no-$suite-setup.sh"
    else
      sed "\|$pattern|d" "$setup" >"$work/no-$suite-setup.sh"
    fi
    if PSALM_SETUP_SOURCE=$work/no-$suite-setup.sh bash "$self" --binding-only >"$work/no-$suite.log" 2>&1; then
      echo "FAIL: removed $suite CI call survived the ownership oracle" >&2
      exit 1
    fi
    grep -qF "$diagnostic" "$work/no-$suite.log"
  done
  grep -qF 'Malformed MCP fixture is rejected; baseline bytes are unchanged.' "$work/ci-contracts.log"
  echo 'PASS: actual CI block executes all five real suites; removed-engine/consumer/Flash/Response/MCP calls RED'
fi

echo 'PASS: actual static selector, later-shell resolution, native I/O/status, guarded refusals and unused-call negative control'
