#!/usr/bin/env bash
set -euo pipefail

generator=$PWD/tests/regenerate-psalm-taint-baseline.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-baseline.XXXXXX")
cleanup() {
  rm -rf -- "$test_root"
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

echo 'Psalm taint baseline regeneration tests passed.'
