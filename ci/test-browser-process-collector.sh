#!/usr/bin/env bash
# Exercise the exact collector when a proc status disappears after its check.
set -euo pipefail
cd "$(dirname "$0")/.."
evidence_dir=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-process-collector.XXXXXX")
printf 'collector_evidence=%s\n' "$evidence_dir"
helper=$evidence_dir/collector.sh
sed -n '/^collector_loop() {$/,/^}$/p' tests/support/browser-application-fixture.sh >"$helper"
[[ $(grep -c '^collector_loop() {' "$helper") == 1 ]] || exit 1

# Restore the unsafe optimized read only in a private negative-control copy.
awk '
  /^        status=\x27\x27$/ {
    print "        status=$(<\"$task/status\") || continue"
    replacing = 1
    next
  }
  replacing && /^        fi$/ { replacing = 0; next }
  !replacing { print }
' "$helper" >"$evidence_dir/mutant.sh"
# shellcheck disable=SC2016 # Match the literal unsafe shell expression.
[[ $(grep -cF 'status=$(<"$task/status") || continue' "$evidence_dir/mutant.sh") == 1 ]] || exit 1

cat >"$evidence_dir/driver.sh" <<'DRIVER'
set -eEuo pipefail
set -T
control=$1
evidence_dir=$2
source_file=$3
mkdir -p "$evidence_dir/proc/1234/task/1234"
touch "$evidence_dir/proc/1234/stat" "$evidence_dir/proc/1234/task/1234/stat"
status_file=$evidence_dir/proc/1234/task/1234/status
printf 'Pid:\t1234\nTgid:\t1234\nPPid:\t1\nNSpid:\t1234\n' >"$status_file"
printf '0::/fixture\n' >"$evidence_dir/proc/1234/task/1234/cgroup"
[[ $control != empty ]] || : >"$status_file"
[[ $control != missing ]] || rm "$status_file"
ps() { printf '1234 1\n'; }
process_starttime() { printf '123\n'; }
date() { printf '1000\n'; }
sleep() { rm "$evidence_dir/proc/1234/stat"; }
# A new external cat per sampled task would regress process-startup batching.
cat() { echo 'FAIL: collector spawned cat' >&2; exit 97; }
# shellcheck disable=SC1090 # This is the exact extracted collector.
source "$source_file"
if [[ $control == vanished ]]; then
  trap 'if [[ $BASH_COMMAND == status=* ]]; then rm -f "$status_file"; fi' DEBUG
fi
collector_loop fixture 1234 123 "$evidence_dir/ready"
DRIVER

run_control() {
  local name=$1 control=$2 source_file=$3 expected=$4 status=0
  local run_dir=$evidence_dir/$name
  mkdir "$run_dir"
  # Redirect only proc paths, leaving the actual collector body intact.
  sed "s@/proc/@$run_dir/proc/@g" "$source_file" >"$run_dir/collector.sh"
  bash "$evidence_dir/driver.sh" "$control" "$run_dir" "$run_dir/collector.sh" >"$run_dir/run.log" 2>&1 || status=$?
  if [[ $expected == red ]]; then
    # Bash 5.2 aborts; newer Bash can recover but still emits the raced read.
    if [[ $status != 0 && $status != 1 ]] || ! grep -qF '/status: No such file or directory' "$run_dir/run.log"; then
      echo 'FAIL: vanished-status mutation did not reach the named red read' >&2
      exit 1
    fi
    printf 'RED: unsafe status read emits disappearing-task error (exit=%s)\n' "$status"
  elif [[ $status != 0 ]]; then
    cat "$run_dir/run.log" >&2
    exit 1
  elif [[ $control == live ]]; then
    [[ $(wc -l <"$run_dir/php-processes.tsv") == 1 ]] || exit 1
    grep -q $'fixture\t1000\t1234\t123\t1234\t1234\t1234\t1\t123\t' "$run_dir/php-processes.tsv"
  else
    [[ ! -s $run_dir/php-processes.tsv ]] || exit 1
    [[ ! -s $run_dir/run.log ]] || exit 1
  fi
  printf 'OK: %s exit=%s\n' "$name" "$status"
}

run_control live live "$helper" green
run_control missing missing "$helper" green
run_control empty empty "$helper" green
run_control vanished vanished "$helper" green
run_control optimized-read-mutant vanished "$evidence_dir/mutant.sh" red
run_control restored live "$helper" green
