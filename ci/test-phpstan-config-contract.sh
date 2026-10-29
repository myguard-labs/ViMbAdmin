#!/usr/bin/env bash
#
# Regression for the PHPStan configuration contract.
#
# 1. ci/reconcile-phpstan-coverage.sh accepts the real repository and rejects
#    each seeded defect (new tracked root, stale/duplicate/covered/unknown
#    allowlist entries, outside-by-exclude file, non-git tree, native config
#    parsing failures and invalid parameter dump output).
# 2. phpstan-common.neon pins phpVersion, so the verdict on a version-dependent
#    probe is identical with and without a composer.json in the working
#    directory; with the pin removed the verdicts diverge (control).
# 3. The real shared config discovers the browser fixture's CI-only class;
#    removing its scan registration reports an unknown class (control).
#
# Requires phpstan on PATH (or PHPSTAN_BIN).

set -euo pipefail

repo_root=$(cd "$(dirname "$0")/.." && pwd -P)
cd "$repo_root"
reconcile=$repo_root/ci/reconcile-phpstan-coverage.sh
work=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-phpstan-contract.XXXXXX")
nopin=$(mktemp "$repo_root/.phpstan-nopin.XXXXXX.neon")
noscan=$(mktemp "$repo_root/.phpstan-noscan.XXXXXX.neon")
nofixture=$(mktemp "$repo_root/.phpstan-nofixture.XXXXXX.neon")
cleanup() {
  rm -rf -- "${work:?}"
  rm -f -- "$nopin" "$noscan" "$nofixture"
}
trap cleanup EXIT
failures=0

expect() { # expect <green|red> <label> <needle-for-red> -- command...
  local want=$1 label=$2 needle=$3 out rc
  shift 4
  set +e
  out=$("$@" 2>&1)
  rc=$?
  set -e
  if [ "$want" = green ] && [ "$rc" -eq 0 ]; then
    printf 'ok   green %s\n' "$label"
  elif [ "$want" = red ] && [ "$rc" -ne 0 ] && grep -qF -- "$needle" <<<"$out"; then
    printf 'ok   red   %s\n' "$label"
  else
    printf 'FAIL %s (want %s, rc=%s)\n%s\n' "$label" "$want" "$rc" "$out" >&2
    failures=$((failures + 1))
  fi
}

# --- section 1: reconciliation ------------------------------------------
expect green 'real repository reconciles' '' -- bash "$reconcile"

# GitHub's checkout action writes safe.directory to a temporary HOME; the
# container's actual HOME does not inherit it. On a cross-UID checkout, prove
# the untrusted invocation fails while the exact-root guard still works.
if [ "$(stat -c %u "$repo_root")" != "$(id -u)" ]; then
  expect red 'cross-UID unscoped Git rejects checkout' 'dubious ownership' -- \
    env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
    git -C "$repo_root" ls-files '*.php'
  expect green 'cross-UID reconciliation trusts only checkout root' '' -- \
    env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
    bash "$reconcile"
fi

scratch() { # scratch <dir>: a tiny git repo with the real config
  local d=$1 scan_file scan_list
  local -a scan_files=()
  rm -rf -- "${d:?}"
  mkdir -p "$d/src" "$d/ci/support" "$d/application/Proxies" "$d/vendor"
  git -C "$d" init -q
  cp phpstan-common.neon "$d/"
  scan_list=$(mktemp "$work/scan-files.XXXXXX")
  if ! sed -n '/^    scanFiles:/,/^    [A-Za-z]/ { /^        - /s/^        - //p; }' \
    phpstan-common.neon >"$scan_list"; then
    rm -f -- "$scan_list"
    echo 'scratch: failed to parse phpstan-common.neon scanFiles' >&2
    return 1
  fi
  if ! mapfile -t scan_files <"$scan_list"; then
    rm -f -- "$scan_list"
    echo 'scratch: failed to read parsed scanFiles entries' >&2
    return 1
  fi
  rm -f -- "$scan_list"
  if [ "${#scan_files[@]}" -eq 0 ]; then
    echo 'scratch: phpstan-common.neon yielded no scanFiles entries' >&2
    return 1
  fi
  for scan_file in "${scan_files[@]}"; do
    mkdir -p "$d/$(dirname "$scan_file")"
    if ! cp "$scan_file" "$d/$scan_file"; then
      printf 'scratch: failed to copy scanFiles entry: %s\n' "$scan_file" >&2
      return 1
    fi
  done
  printf '<?php\n' >"$d/src/a.php"
  printf '<?php\n' >"$d/ci/test-x.php"
  printf '<?php\n' >"$d/vendor/autoload.php"
  printf 'ci/test-x.php\tci-test-script\n' >"$d/allow.txt"
  git -C "$d" add src/a.php ci/test-x.php phpstan-common.neon
}
run() { # run <dir> [allowlist]
  RECONCILE_REPO_ROOT=$1 RECONCILE_ALLOWLIST=${2:-$1/allow.txt} bash "$reconcile"
}

s=$work/s
scratch "$s"
expect green 'scratch baseline' '' -- run "$s"

# A new CI fixture is invisible until Git tracks it. Once staged, each
# fixture must be present in the configured analysis paths.
for fixture in ci/test-mailbox-self-alias-counter.php ci/test-mailbox-worker-deadline.php; do
  printf '<?php\n' >"$s/$fixture"
  expect green "untracked $fixture is not enumerated" '' -- run "$s"
  git -C "$s" add -- "$fixture"
  expect green "tracked $fixture is covered" '' -- run "$s"
  cp "$s/phpstan-common.neon" "$work/registered.neon"
  awk -v path="        - $fixture" '$0 != path' "$work/registered.neon" >"$s/phpstan-common.neon"
  expect red "NEGATIVE CONTROL missing tracked $fixture" "not allowlisted: $fixture" -- run "$s"
  cp "$work/registered.neon" "$s/phpstan-common.neon"
  expect green "restored tracked $fixture coverage" '' -- run "$s"
done

printf '<?php\n' >"$s/untracked.php"
expect green 'untracked file is not enumerated (boundary)' '' -- run "$s"
rm "$s/untracked.php"

printf '<?php\n' >"$s/src/space name.php"
git -C "$s" add 'src/space name.php'
expect green 'tracked PHP filename with space stays covered' '' -- run "$s"

scratch "$s"
printf '<?php\n' >"$s/src/tab"$'\t'"name.php"
git -C "$s" add -- "src/tab"$'\t'"name.php"
expect red 'TAB filename fails explicitly before allowlist parsing' \
  'cannot be represented in the TAB allowlist' -- run "$s"

scratch "$s"
mkdir -p "$s/newroot"
printf '<?php\n' >"$s/newroot/x.php"
git -C "$s" add newroot/x.php
expect red 'NEGATIVE CONTROL new root outside PHPStan, not allowlisted' \
  'not allowlisted: newroot/x.php' -- run "$s"
printf 'newroot/x.php\ttooling-script\n' >>"$s/allow.txt"
expect green 'new root passes once allowlisted with a class' '' -- run "$s"

scratch "$s"
printf 'ci/gone.php\tci-test-script\n' >>"$s/allow.txt"
expect red 'NEGATIVE CONTROL stale allowlist entry' 'stale allowlist entry' -- run "$s"

scratch "$s"
printf 'src/a.php\ttooling-script\n' >>"$s/allow.txt"
expect red 'NEGATIVE CONTROL covered file allowlisted' 'remove the entry: src/a.php' -- run "$s"

scratch "$s"
printf 'ci/test-x.php\tci-test-script\n' >>"$s/allow.txt"
expect red 'NEGATIVE CONTROL duplicate entry' 'duplicate allowlist entry' -- run "$s"

scratch "$s"
printf 'ci/test-x.php\tmystery\n' >"$s/allow.txt"
expect red 'malformed: unknown reason class' "unknown reason class 'mystery'" -- run "$s"

scratch "$s"
printf 'ci/test-x.php\n' >"$s/allow.txt"
expect red 'malformed: missing reason class' 'malformed allowlist line' -- run "$s"

scratch "$s"
printf 'ci/test-x.php\tci-test-script' >"$s/allow.txt"
expect green 'allowlist last line without newline is read' '' -- run "$s"

scratch "$s"
printf '<?php\n' >"$s/application/Proxies/__CG__X.php"
git -C "$s" add -f application/Proxies/__CG__X.php
expect red 'tracked file under an exclude must be allowlisted' \
  'not allowlisted: application/Proxies/__CG__X.php' -- run "$s"

scratch "$s"
printf 'ci/test-x.php\tci-test-script\nsrc/b.php\tci-test-script\n' >"$s/allow.txt"
expect red 'allowlist entry for an untracked path is stale' 'stale allowlist entry' -- run "$s"

scratch "$s"
sed -i 's/^    excludePaths:/    excludePaths:\n        analyse:\n            - src/' "$s/phpstan-common.neon"
expect red 'native nested excludePaths form excludes src' \
  'not allowlisted: src/a.php' -- run "$s"

scratch "$s"
sed -i '/^    excludePaths:/,/^    bootstrapFiles:/ { /^        - /d; }' "$s/phpstan-common.neon"
sed -i 's/^    excludePaths:/    excludePaths: [src]/' "$s/phpstan-common.neon"
expect red 'native inline excludePaths form excludes src' \
  'not allowlisted: src/a.php' -- run "$s"

scratch "$s"
sed -i '/^    paths:/,/^    excludePaths:/ { /^        - /d; }' "$s/phpstan-common.neon"
sed -i 's/^    paths:/    paths: [src]/' "$s/phpstan-common.neon"
expect green 'native inline paths form includes src' '' -- run "$s"

scratch "$s"
sed -i 's/^    excludePaths:/    excludePaths: src/' "$s/phpstan-common.neon"
expect red 'scalar excludePaths declaration fails native dump or schema' \
  'PHPStan parameter dump' -- run "$s"

scratch "$s"
sed -i 's/^    excludePaths:/   excludePaths:/' "$s/phpstan-common.neon"
expect red 'invalid declaration indentation fails native dump' \
  'PHPStan parameter dump failed' -- run "$s"

scratch "$s"
sed -i 's/^        - application\/Proxies.*/      - src/' "$s/phpstan-common.neon"
expect red 'native under-indented excludePaths item excludes src' \
  'not allowlisted: src/a.php' -- run "$s"

scratch "$s"
sed -i 's/^        - application\/Proxies.*/          - src/' "$s/phpstan-common.neon"
expect red 'native over-indented excludePaths item excludes src' \
  'not allowlisted: src/a.php' -- run "$s"

scratch "$s"
sed -i 's/^        - application\/Proxies.*/      src/' "$s/phpstan-common.neon"
expect red 'invalid scalar excludePaths item fails native dump' \
  'PHPStan parameter dump failed' -- run "$s"

scratch "$s"
sed -i 's|^        - src$|        - src # inline comment|' "$s/phpstan-common.neon"
expect green 'native inline item comment keeps src covered' '' -- run "$s"

scratch "$s"
sed -i 's/^    excludePaths:/    excludePaths:\n        - ci(?)/' "$s/phpstan-common.neon"
expect green 'native optional path pattern preserves covered src' '' -- run "$s"

scratch "$s"
sed -i 's/^    excludePaths:/    excludePaths:\n        - src/' "$s/phpstan-common.neon"
expect red 'bare directory exclude covers files beneath it' \
  'not allowlisted: src/a.php' -- run "$s"

scratch "$s"
printf '\nservices: []\n' >>"$s/phpstan-common.neon"
expect green 'native top-level services key follows excludes' '' -- run "$s"

scratch "$s"
sed -i 's/^    paths:/    # - commented-out-root\n    paths:/' "$s/phpstan-common.neon"
expect green 'whole-line comments are skipped' '' -- run "$s"

scratch "$s"
printf '\nparameters: [\n' >>"$s/phpstan-common.neon"
expect red 'native parser failure propagates' 'PHPStan parameter dump failed' -- run "$s"

scratch "$s"
printf '#!/bin/sh\nprintf "not-json\\n"\n' >"$work/invalid-json-phpstan"
chmod +x "$work/invalid-json-phpstan"
PHPSTAN_BIN="$work/invalid-json-phpstan" expect red 'invalid native JSON fails closed' \
  'invalid paths/excludePaths schema' -- run "$s"

printf '#!/bin/sh\nprintf "{\\\"paths\\\":[\\\"/tmp\\\"],\\\"excludePaths\\\":{\\\"analyse\\\":[],\\\"analyseAndScan\\\":\\\"bad\\\"}}\\n"\n' >"$work/invalid-schema-phpstan"
chmod +x "$work/invalid-schema-phpstan"
PHPSTAN_BIN="$work/invalid-schema-phpstan" expect red 'invalid native schema fails closed' \
  'invalid paths/excludePaths schema' -- run "$s"

for bad_shape in \
  '{"paths":[],"excludePaths":{"analyse":[],"analyseAndScan":[]}}' \
  '{"paths":{"0":"/tmp"},"excludePaths":{"analyse":[],"analyseAndScan":[]}}' \
  '{"paths":["/tmp"],"excludePaths":[]}' \
  '{"paths":["/tmp"],"excludePaths":{"analyse":[],"analyseAndScan":[],"extra":[]}}'; do
  printf '#!/bin/sh\nprintf "%%s\\n" '\''%s'\''\n' "$bad_shape" >"$work/invalid-shape-phpstan"
  chmod +x "$work/invalid-shape-phpstan"
  PHPSTAN_BIN="$work/invalid-shape-phpstan" expect red 'native JSON type and key schema fails closed' \
    'invalid paths/excludePaths schema' -- run "$s"
done

printf '#!/bin/sh\nexit 42\n' >"$work/failing-phpstan"
chmod +x "$work/failing-phpstan"
PHPSTAN_BIN="$work/failing-phpstan" expect red 'native command failure propagates' \
  'PHPStan parameter dump failed' -- run "$s"

scratch "$s"
cat >"$work/failing-classifier" <<'EOF'
#!/bin/sh
if [ "$#" -eq 3 ]; then exec "${PHP_BINARY:-php}" "$@"; fi
printf 'src/a.php\0'
exit 42
EOF
chmod +x "$work/failing-classifier"
RECONCILE_PHP_BIN="$work/failing-classifier" expect red \
  'classifier partial output and failure propagates' \
  'PHPStan exclusion classification failed' -- run "$s"

# Forms that the old handwritten NEON reader silently accepted as covered.
# PHPStan excludes src/a.php for valid forms and rejects the replacement key.
grammar_case() { # grammar_case <label> <declaration> <item> <newline yes|no> [error]
  local label=$1 declaration=$2 item=$3 newline=$4 needle=${5:-not allowlisted: src/a.php}
  scratch "$s"
  printf 'parameters:\n    paths:\n        - src\n    level: 10\n    %s\n        - %s' \
    "$declaration" "$item" >"$s/phpstan-common.neon"
  [ "$newline" = no ] || printf '\n' >>"$s/phpstan-common.neon"
  expect red "$label cannot silently cover src" "$needle" -- run "$s"
}
grammar_case 'quoted single key' "'excludePaths':" src yes
grammar_case 'quoted double key' '"excludePaths":' src yes
grammar_case 'replacement key' 'excludePaths!:' src yes 'PHPStan parameter dump failed'
grammar_case 'leading space item' excludePaths: ' src' yes
grammar_case 'trailing space item' excludePaths: 'src ' yes
grammar_case 'leading tab item' excludePaths: $'\tsrc' yes
grammar_case 'trailing tab item' excludePaths: $'src\t' yes
grammar_case 'tab comment item' excludePaths: $'src\t# comment' yes
grammar_case 'leading quoted item' excludePaths: ' "src"' yes
grammar_case 'dot relative item' excludePaths: ./src yes
grammar_case 'dot segment item' excludePaths: src/../src yes
grammar_case 'absolute item' excludePaths: "$s/src" yes
grammar_case 'final line without newline' excludePaths: src no
printf 'src/a.php\ttooling-script\n' >>"$s/allow.txt"
expect green 'grammar exclusion passes after explicit reason allowlist' '' -- run "$s"

scratch "$s"
printf '<?php\n' >"$s/src/@(a|b).php"
git -C "$s" add 'src/@(a|b).php'
sed -i "/^    excludePaths:/a\\        - 'src/@(a|b).php'" "$s/phpstan-common.neon"
expect red 'literal extglob-looking filename is excluded by PHPStan' \
  'not allowlisted: src/@(a|b).php' -- run "$s"
printf 'src/@(a|b).php\ttooling-script\n' >>"$s/allow.txt"
expect green 'literal extglob-looking filename passes with reason' '' -- run "$s"

mkdir -p "$work/notgit"
cp phpstan-common.neon "$work/notgit/"
printf 'x\ty\n' >"$work/notgit/allow.txt"
GIT_CEILING_DIRECTORIES="$work" expect red 'non-git tree is an error' 'git ls-files failed' -- run "$work/notgit"

expect red 'unexpected argument is a usage error' 'usage:' -- bash "$reconcile" extra

# --- section 2: phpVersion pin ------------------------------------------
if [ -n "${PHPSTAN_BIN:-}" ]; then
  phpstan=("$PHPSTAN_BIN")
elif [ -x vendor/bin/phpstan ]; then
  phpstan=("$repo_root/vendor/bin/phpstan")
else
  phpstan=(phpstan)
fi

expect green 'phpstan-common.neon pins phpVersion min/max' '' -- \
  bash -c 'grep -Eq "^    phpVersion:" phpstan-common.neon &&
    grep -Eq "^        min: 80200$" phpstan-common.neon &&
    grep -Eq "^        max: 80699$" phpstan-common.neon'

mkdir -p "$work/empty"
printf '<?php\nif (PHP_VERSION_ID >= 80200) { echo "x"; }\n' >"$work/probe.php"
verdict() { # verdict <config> <cwd> <log>: exit status of the probe analysis
  local rc
  set +e
  (cd "$2" && "${phpstan[@]}" analyse -c "$1" --level 10 --no-progress \
    --error-format=json "$work/probe.php" >"$3" 2>"$3.stderr")
  rc=$?
  set -e
  echo "$rc"
}
sed '/^    # Pin the analysed/,/^        max: 80699/d' phpstan-common.neon >"$nopin"
if grep -q phpVersion "$nopin"; then
  echo 'FAIL seeded unpinned config still contains phpVersion' >&2
  failures=$((failures + 1))
fi

pinned_repo=$(verdict phpstan-level10.neon "$repo_root" "$work/pinned-repo.log")
pinned_empty=$(verdict "$repo_root/phpstan-level10.neon" "$work/empty" "$work/pinned-empty.log")
nopin_repo=$(verdict "$nopin" "$repo_root" "$work/nopin-repo.log")
nopin_empty=$(verdict "$nopin" "$work/empty" "$work/nopin-empty.log")
has_pinned_diagnostic() { # PHPStan's JSON identifier is independent of raw-output verbosity.
  # shellcheck disable=SC2016 # PHP source is intentionally single-quoted.
  "${PHP_BINARY:-php}" -r '
    try {
        $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $files = $data["files"] ?? [];
        $messages = $files[$argv[2]]["messages"] ?? [];
        exit(count($messages) === 1
            && ($messages[0]["identifier"] ?? null) === "greaterOrEqual.alwaysTrue"
            && ($data["totals"]["file_errors"] ?? null) === 1
            && ($data["totals"]["errors"] ?? null) === 0 ? 0 : 1);
    } catch (Throwable $error) { exit(1); }
  ' "$1" "$work/probe.php"
}
printf 'pinned repo=%s empty=%s; unpinned repo=%s empty=%s\n' \
  "$pinned_repo" "$pinned_empty" "$nopin_repo" "$nopin_empty"
if [ "$pinned_repo" = "$pinned_empty" ] && [ "$pinned_repo" -eq 1 ] &&
  has_pinned_diagnostic "$work/pinned-repo.log" &&
  has_pinned_diagnostic "$work/pinned-empty.log"; then
  echo 'ok   green pinned verdict is identical with and without composer.json in cwd'
else
  echo 'FAIL pinned verdict depends on the working directory' >&2
  failures=$((failures + 1))
fi
if [ "$nopin_repo" != "$nopin_empty" ]; then
  echo 'ok   red   NEGATIVE CONTROL unpinned verdict diverges by cwd'
else
  echo 'FAIL control: unpinned config did not diverge, probe is vacuous' >&2
  failures=$((failures + 1))
fi

# --- section 3: CI-only helper discovery --------------------------------
# Do not pass the helpers as analysis roots or require them from the probe:
# either would hide a missing registration in the real project config.
printf '%s\n' '<?php' \
  'function exerciseBrowserHelpers(Doctrine\ORM\EntityManagerInterface $em, Entities\Admin $admin): string {' \
  '    seedBrowserOperations($em, $admin, [], "/tmp/browser-state");' \
  '    return \ViMbAdmin\Tests\BrowserFixture\SuperAdmin::USERNAME;' \
  '}' >"$work/discovery.php"
discovery() { # discovery <config> <log>
  "${phpstan[@]}" analyse -c "$1" --level 10 --no-progress \
    --error-format=raw "$work/discovery.php" >"$2" 2>&1
  local rc=$?
  cat "$2"
  return "$rc"
}
expect green 'shared config discovers CI-only browser helpers' '' -- \
  discovery phpstan-level10.neon "$work/discovery-baseline.log"
sed '/^        - ci\/support\/browser-operations-seed\.php$/d' phpstan-common.neon >"$noscan"
printf 'discovery control: config=%s; probe=%s\n' "$noscan" "$work/discovery.php"
stat -c 'mtime=%y size=%s file=%n' "$noscan" "$work/discovery.php"
sha256sum "$noscan" "$work/discovery.php"
expect red 'NEGATIVE CONTROL missing operations seed discovery' \
  'Function seedBrowserOperations not found.' -- \
  discovery "$noscan" "$work/discovery-mutant.log"
sed -n '/Function seedBrowserOperations not found/p' "$work/discovery-mutant.log"
sed '/^        - ci\/support\/browser-fixture-super-admin\.php$/d' phpstan-common.neon >"$nofixture"
printf 'fixture discovery control: config=%s; probe=%s\n' "$nofixture" "$work/discovery.php"
stat -c 'mtime=%y size=%s file=%n' "$nofixture" "$work/discovery.php"
sha256sum "$nofixture" "$work/discovery.php"
expect red 'NEGATIVE CONTROL missing SuperAdmin discovery' \
  'Access to constant USERNAME on an unknown class ViMbAdmin\Tests\BrowserFixture\SuperAdmin.' -- \
  discovery "$nofixture" "$work/discovery-fixture-mutant.log"
sed -n '/Access to constant USERNAME on an unknown class/p' "$work/discovery-fixture-mutant.log"
expect green 'restored shared config discovers CI-only browser helpers' '' -- \
  discovery phpstan-level10.neon "$work/discovery-restored.log"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed.\n' "$failures" >&2
  exit 1
fi
echo 'PHPStan configuration contract: ALL PASSED'
