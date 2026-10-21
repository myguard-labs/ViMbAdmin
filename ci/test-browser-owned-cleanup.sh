#!/usr/bin/env bash
# Exercise the fixture's exact cleanup helper against real anonymous volumes.
set -euo pipefail
cd "$(dirname "$0")/.."
evidence_dir=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-browser-owned-cleanup.XXXXXX")
mkdir "$evidence_dir/logs"
printf 'cleanup_evidence=%s\n' "$evidence_dir"
readonly run_label="myguard.run=${VIMBADMIN_BROWSER_RUN_LABEL:-c124-browser-tenant}"
declare -a container_ids=() control_volumes=()
# shellcheck disable=SC2034 # The sourced production helper owns this map.
declare -A removed_containers=()
counter=0
cleanup() {
  local status=$? cid volume refs
  trap - EXIT
  for cid in "${container_ids[@]}"; do docker rm -fv "$cid" >/dev/null 2>&1 || true; done
  # Only names captured from these exact owned containers are eligible here.
  for volume in "${control_volumes[@]}"; do
    refs=$(docker ps -aq --filter "volume=$volume") || {
      status=1
      continue
    }
    if [[ -n $refs ]]; then
      status=1
      continue
    fi
    if docker volume inspect "$volume" >/dev/null 2>&1; then
      docker volume rm "$volume" >/dev/null || status=1
    fi
  done
  exit "$status"
}
trap cleanup EXIT
helper=$evidence_dir/remove-owned-container.sh
sed -n '/^remove_owned_container() {$/,/^}$/p' tests/support/browser-application-fixture.sh >"$helper"
[[ $(grep -c '^remove_owned_container() {' "$helper") == 1 ]] || exit 1
image=$(sed -n "s/^readonly mariadb_image='\([^']*\)'/\1/p" tests/support/browser-application-fixture.sh)
[[ $image == *@sha256:* ]] || exit 1
new_container() {
  local name volume
  counter=$((counter + 1))
  name="myguard-run-${run_label#myguard.run=}-cleanup-${BASHPID}-${counter}"
  CONTROL_CID=$(docker create --name "$name" --label "$run_label" --entrypoint /bin/true "$image")
  container_ids+=("$CONTROL_CID")
  printf '%s\t%s\n' "$CONTROL_CID" "$name" >>"$evidence_dir/containers.tsv"
  while IFS= read -r volume; do
    [[ -n $volume ]] || continue
    control_volumes+=("$volume")
    printf '%s\t%s\n' "$CONTROL_CID" "$volume" >>"$evidence_dir/volumes.tsv"
  done < <(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$CONTROL_CID")
  [[ ${#control_volumes[@]} == "$counter" ]] || {
    echo 'FAIL: cleanup control requires exactly one real anonymous volume per container' >&2
    exit 1
  }
}
run_control() {
  local source=$1 mode=$2 expected=$3 status=0
  # shellcheck disable=SC1090 # Exact function extracted from the candidate.
  source "$source"
  new_container
  remove_owned_container "$CONTROL_CID" "$mode" >"$evidence_dir/logs/${counter}.log" 2>&1 || status=$?
  if [[ $expected == green ]]; then
    [[ $status == 0 ]] || {
      cat "$evidence_dir/logs/${counter}.log" >&2
      exit 1
    }
  else
    if [[ $status == 0 ]] || ! grep -qxF 'FAIL: owned container volume remains after removal' "$evidence_dir/logs/${counter}.log"; then
      echo 'FAIL: missing-volume-removal mutation did not reach the named cleanup assertion' >&2
      exit 1
    fi
    cat "$evidence_dir/logs/${counter}.log"
    docker volume rm "${control_volumes[-1]}" >/dev/null
  fi
}
run_control "$helper" '' green
run_control "$helper" force green
sed 's/local -a remove_args=(rm -v)/local -a remove_args=(rm)/' "$helper" >"$evidence_dir/mutant.sh"
[[ $(grep -cF 'local -a remove_args=(rm)' "$evidence_dir/mutant.sh") == 1 ]] || exit 1
stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' "$evidence_dir/mutant.sh"
sha256sum "$evidence_dir/mutant.sh"
run_control "$evidence_dir/mutant.sh" '' red
run_control "$helper" '' green
status=0
remove_owned_container 'unrecorded-id' >"$evidence_dir/logs/unrecorded.log" 2>&1 || status=$?
if [[ $status == 0 ]] || ! grep -qxF 'FAIL: refusing cleanup of an unrecorded container' "$evidence_dir/logs/unrecorded.log"; then
  echo 'FAIL: cleanup control did not reject the unrecorded container' >&2
  exit 1
fi
echo 'OK: real owned-volume normal/forced cleanup, mutation red, restored green and ownership denial passed'
