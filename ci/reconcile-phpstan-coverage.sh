#!/usr/bin/env bash
#
# Reconcile every tracked PHP file with what PHPStan analyses.
#
# Usage: ci/reconcile-phpstan-coverage.sh
#
# A tracked `*.php` file is covered when it lies under a `paths` entry of
# phpstan-common.neon and matches no `excludePaths` entry. Every other file
# must be listed, with a reason class, in ci/phpstan-outside-allowlist.txt.
# The check fails on an unlisted outside file (for example a new top-level
# directory), a stale allowlist entry (untracked or now covered), a duplicate
# entry, an unknown reason class, or a failed/invalid PHPStan parameter dump.
# RECONCILE_REPO_ROOT, RECONCILE_ALLOWLIST, PHPSTAN_BIN and
# RECONCILE_PHP_BIN are test seams.

set -euo pipefail

if [ "${1:-}" = --help ]; then
  sed -n '3,12s/^# \{0,1\}//p' "$0"
  exit 0
fi
if [ "$#" -ne 0 ]; then
  echo "usage: $0" >&2
  exit 2
fi

repo_root=${RECONCILE_REPO_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$repo_root"
repo_root=$(pwd -P)
config='phpstan-common.neon'
allowlist=${RECONCILE_ALLOWLIST:-ci/phpstan-outside-allowlist.txt}
readonly valid_classes=' ci-test-script ci-support-script tooling-script external-tool-config '

fail=0
problem() {
  printf 'phpstan-coverage: %s\n' "$*" >&2
  fail=1
}

[ -f "$config" ] || {
  echo "Missing $config" >&2
  exit 1
}
[ -f "$allowlist" ] || {
  echo "Missing $allowlist" >&2
  exit 1
}

tracked_file=$(mktemp)
trap 'rm -f -- "$tracked_file"' EXIT
if ! git ls-files -z '*.php' >"$tracked_file"; then
  echo 'git ls-files failed' >&2
  exit 1
fi
mapfile -d '' -t tracked_paths <"$tracked_file"
[ "${#tracked_paths[@]}" -gt 0 ] || {
  echo 'No tracked PHP files found' >&2
  exit 1
}
for path in "${tracked_paths[@]}"; do
  if [[ $path == *$'\n'* || $path == *$'\t'* ]]; then
    problem "tracked PHP path cannot be represented in the TAB allowlist: $path"
  fi
done
[ "$fail" -eq 0 ] || exit 1

# Use PHPStan's resolved configuration so NEON aliases, indentation,
# whitespace, and path normalization cannot diverge from its analysis.
if [ -n "${PHPSTAN_BIN:-}" ]; then
  phpstan=("$PHPSTAN_BIN")
elif [ -x vendor/bin/phpstan ]; then
  phpstan=("$repo_root/vendor/bin/phpstan")
else
  phpstan=(phpstan)
fi
parameters=$(mktemp)
parameter_error=$(mktemp)
roots_file=$(mktemp)
excluded_file=$(mktemp)
trap 'rm -f -- "$tracked_file" "$parameters" "$parameter_error" "$roots_file" "$excluded_file"' EXIT
if ! "${phpstan[@]}" dump-parameters -l 10 -c "$config" --json >"$parameters" 2>"$parameter_error"; then
  problem "PHPStan parameter dump failed: $(sed -n '/./{p;q;}' "$parameter_error")"
  exit 1
fi
if ! jq -e '
  def absolute_array:
    type == "array" and length > 0 and all(.[]; type == "string" and startswith("/"));
  def exclude_array:
    type == "array" and all(.[]; type == "string" and startswith("/"));
  (.paths | absolute_array) and
  (.excludePaths | type == "object" and (keys | sort) == ["analyse", "analyseAndScan"]) and
  (.excludePaths.analyse | exclude_array) and
  (.excludePaths.analyseAndScan | exclude_array)
' "$parameters" >/dev/null 2>&1; then
  problem 'PHPStan parameter dump has invalid paths/excludePaths schema'
  exit 1
fi
if ! jq -j '.paths[] | ., "\u0000"' "$parameters" >"$roots_file"; then
  problem 'PHPStan parameter paths could not be decoded'
  exit 1
fi
mapfile -d '' -t roots <"$roots_file"

# Mirror PHPStan FileExcluder on its already normalized effective paths:
# wildcard patterns use fnmatch; literal files match exactly, and literal
# directories match descendants. Classify the tracked set in one PHP process.
classifier_php=${RECONCILE_PHP_BIN:-${PHP_BINARY:-php}}
# shellcheck disable=SC2016 # PHP source is intentionally single-quoted for the shell.
if ! "$classifier_php" -r '
  $parameters = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $patterns = array_merge($parameters["excludePaths"]["analyse"], $parameters["excludePaths"]["analyseAndScan"]);
  $files = []; $directories = []; $globs = [];
  foreach ($patterns as $path) {
      if (strpbrk($path, "*?[]") !== false) {
          $globs[] = $path;
      } elseif (is_file($path)) {
          $files[$path] = true;
      } elseif (is_dir($path)) {
          $directories[] = rtrim($path, "/") . "/";
      }
  }
  foreach (explode("\0", file_get_contents($argv[3])) as $relative) {
      if ($relative === "") continue;
      $file = $argv[2] . "/" . $relative;
      $excluded = isset($files[$file]);
      if (!$excluded) foreach ($directories as $directory) {
          if (str_starts_with($file, $directory)) { $excluded = true; break; }
      }
      if (!$excluded) foreach ($globs as $glob) {
          if (fnmatch($glob, $file, 0)) { $excluded = true; break; }
      }
      if ($excluded) echo $relative, "\0";
  }
' "$parameters" "$repo_root" "$tracked_file" >"$excluded_file" 2>"$parameter_error"; then
  problem "PHPStan exclusion classification failed: $(sed -n '/./{p;q;}' "$parameter_error")"
  exit 1
fi
mapfile -d '' -t excluded_paths <"$excluded_file"
declare -A excluded=()
for path in "${excluded_paths[@]}"; do excluded[$path]=1; done

covered() {
  local f="$repo_root/$1" root under=0
  for root in "${roots[@]}"; do
    if [ "$f" = "$root" ] || [[ "$f" == "$root"/* ]]; then
      under=1
      break
    fi
  done
  [ "$under" -eq 1 ] || return 1
  [ -z "${excluded[$1]:-}" ]
}

declare -A allowed=()
while IFS=$'\t' read -r path class extra || [ -n "${path:-}" ]; do
  case $path in '' | '#'*) continue ;; esac
  if [ -z "${class:-}" ] || [ -n "${extra:-}" ]; then
    problem "malformed allowlist line for '$path' (need path<TAB>class)"
    continue
  fi
  if [[ "$valid_classes" != *" $class "* ]]; then
    problem "unknown reason class '$class' for $path"
    continue
  fi
  if [ -n "${allowed[$path]:-}" ]; then
    problem "duplicate allowlist entry: $path"
    continue
  fi
  allowed[$path]=$class
done <"$allowlist"

declare -A seen=()
outside=0
for f in "${tracked_paths[@]}"; do
  seen[$f]=1
  if covered "$f"; then
    if [ -n "${allowed[$f]:-}" ]; then
      problem "allowlisted file is analysed by PHPStan, remove the entry: $f"
    fi
    continue
  fi
  outside=$((outside + 1))
  if [ -z "${allowed[$f]:-}" ]; then
    problem "tracked PHP file is outside PHPStan paths and not allowlisted: $f"
  fi
done

for path in "${!allowed[@]}"; do
  if [ -z "${seen[$path]:-}" ]; then
    problem "stale allowlist entry (not a tracked PHP file): $path"
  fi
done

if [ "$fail" -ne 0 ]; then
  exit 1
fi
printf 'phpstan-coverage: %s tracked PHP files, %s outside PHPStan, all allowlisted with a reason class.\n' \
  "${#tracked_paths[@]}" "$outside"
