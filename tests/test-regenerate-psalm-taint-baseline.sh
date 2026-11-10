#!/usr/bin/env bash
set -euo pipefail

generator=$PWD/tests/regenerate-psalm-taint-baseline.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-baseline.XXXXXX")
cleanup() {
  case $test_root in
    "${TMPDIR:-/tmp}"/vimbadmin-psalm-baseline.*) rm -rf -- "${test_root:?}" ;;
    *) echo 'Refusing to remove an unexpected test directory.' >&2; return 1 ;;
  esac
}
trap cleanup EXIT

stub=$test_root/psalm
source_baseline=$test_root/source.xml
target=$test_root/psalm-taint-baseline.xml
log=$test_root/argv

cat >"$stub" <<'SH'
#!/bin/sh
candidate=
for argument in "$@"; do
  case $argument in
    --set-baseline=*) candidate=${argument#*=} ;;
  esac
done
[ -n "$candidate" ] || exit 64
printf '%s\n' "$@" >"$PSALM_STUB_LOG"
cp -- "$PSALM_STUB_SOURCE" "$candidate"
exit "${PSALM_STUB_STATUS:-0}"
SH
chmod +x "$stub"

printf '%s\n' '<files psalm-version="fixture" />' >"$source_baseline"
printf '%s\n' '<files psalm-version="old" />' >"$target"
cat >"$test_root/psalm.xml" <<'XML'
<?xml version="1.0"?>
<psalm errorBaseline="psalm-taint-baseline.xml" />
XML

# Missing inputs fail before invoking Psalm or altering the baseline.
if PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" >"$test_root/missing.out" 2>&1; then
  echo 'Missing optional models were accepted.' >&2; exit 1
fi
grep -qF 'setup-php-container.sh psalm' "$test_root/missing.out"
[[ ! -e $log ]]
if compgen -G "$test_root/.psalm-taint-*" >/dev/null; then
  echo 'Missing models left temporary artifacts.' >&2; exit 1
fi
grep -qxF '<files psalm-version="old" />' "$target"
for input in predis/src/ClientInterface.php redis/RedisArray.php redis/RedisCluster.php \
  relay/{AdaptiveCache,Cluster,Event,Events,Exception,KeyType,Relay,Sentinel,Table}.php; do
  mkdir -p "$test_root/var/tmp/psalm-optional/$(dirname "$input")"
  printf '<?php\n' >"$test_root/var/tmp/psalm-optional/$input"
done

PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator"
cmp -s -- "$source_baseline" "$target"
grep -qxF -- '--taint-analysis' "$log"
grep -qxF -- '--ignore-baseline' "$log"

# A relative entrypoint from tests/ must resolve its helper before changing cwd.
(
  cd tests
  PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
    PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
    bash regenerate-psalm-taint-baseline.sh --check
)
PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash tests/regenerate-psalm-taint-baseline.sh --check

PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub PSALM_STUB_STATUS=2 \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" --check

if PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub PSALM_STUB_STATUS=23 \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" --check >/dev/null 2>&1; then
  echo 'Generator swallowed an unexpected Psalm failure.' >&2
  exit 1
fi

PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" --check

printf '%s\n' '<files psalm-version="changed" />' >"$source_baseline"
if PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" --check >"$test_root/output" 2>&1; then
  echo 'Drift check accepted a changed Psalm baseline.' >&2
  exit 1
fi
grep -qF 'Psalm taint baseline drift' "$test_root/output"
grep -qxF '<files psalm-version="fixture" />' "$target"

if PSALM_REPO_ROOT=$test_root PSALM_BIN=$stub \
  PSALM_STUB_LOG=$log PSALM_STUB_SOURCE=$source_baseline \
  bash "$generator" unexpected >/dev/null 2>&1; then
  echo 'Generator accepted an unexpected argument.' >&2
  exit 1
fi

# Execute the README commands against distinct fake upstream and patched launchers.
# The real selector writes this exact environment-file contract after verification.
selector=$PWD/.github/scripts/setup-php-container.sh
# shellcheck disable=SC2016 # Match literal selector source.
grep -qF 'tool_parent=$(mktemp -d "$repo_root/var/tmp/psalm-tool.XXXXXX")' "$selector"
# shellcheck disable=SC2016 # Match literal selector source.
grep -qF 'launcher=$tool/bin/psalm' "$selector"
grep -qF "printf 'PSALM_BIN=%s\\n' \"\$launcher\" >>\"\$GITHUB_ENV\"" "$selector"
mkdir -p "$test_root/upstream" "$test_root/var/tmp/psalm-tool.fixture/tool/bin"
printf '#!/bin/sh\necho upstream\n' >"$test_root/upstream/psalm"
printf '#!/bin/sh\necho patched\n' >"$test_root/var/tmp/psalm-tool.fixture/tool/bin/psalm"
chmod +x "$test_root/upstream/psalm" "$test_root/var/tmp/psalm-tool.fixture/tool/bin/psalm"
awk '/^setup_psalm\(\) \{/ {capture=1} capture && /^```$/ {exit} capture {print}' README.md >"$test_root/readme-example"
[[ -s $test_root/readme-example ]]
cat >"$test_root/readme-runner" <<'SH'
    unset PSALM_BIN
    [[ $(psalm) == upstream ]] || { echo "Unexpected upstream Psalm fixture." >&2; exit 1; }
    bash() {
      case $1 in
        .github/scripts/setup-php-container.sh)
          (export PSALM_BIN=$FAKE_PATCHED; printf "PSALM_BIN=%s\\n" "$PSALM_BIN" >>"$GITHUB_ENV")
          [[ -z ${PSALM_BIN:-} ]] ;;
        .github/scripts/assert-psalm-optional-models.sh) return 0 ;;
        *) return 1 ;;
      esac
    }
    source "$1"
SH
# A wrong upstream fixture must fail before the selected launcher runs.
printf '#!/bin/sh\necho unexpected\n' >"$test_root/upstream/psalm"
if FAKE_PATCHED=$test_root/var/tmp/psalm-tool.fixture/tool/bin/psalm \
  PATH="$test_root/upstream:$PATH" bash "$test_root/readme-runner" "$test_root/readme-example" \
    >"$test_root/wrong-upstream.out" 2>&1; then
  echo 'README runner accepted the wrong upstream fixture.' >&2; exit 1
fi
grep -qF 'Unexpected upstream Psalm fixture.' "$test_root/wrong-upstream.out"
printf '#!/bin/sh\necho upstream\n' >"$test_root/upstream/psalm"
FAKE_PATCHED=$test_root/var/tmp/psalm-tool.fixture/tool/bin/psalm \
  PATH="$test_root/upstream:$PATH" bash "$test_root/readme-runner" "$test_root/readme-example" >"$test_root/readme-result"
grep -qxF patched "$test_root/readme-result"

echo 'Psalm taint baseline regeneration tests passed.'
