#!/usr/bin/env bash
# Purpose: boot a private ViMbAdmin/MariaDB fixture and exercise auth/error paths.
# Usage: bash tests/support/browser-application-fixture.sh [--evidence-dir DIR]
# Inputs: locked Composer graph, pinned MariaDB/browser images and browser suite.
# Side effects: loopback listeners and labeled disposable containers, all cleaned.
set -euo pipefail

if [[ ${1:-} == --help ]]; then
  sed -n '2,5p' "$0"
  exit 0
fi

engines=(chromium firefox webkit)
if [[ -n ${VIMBADMIN_BROWSER:-} ]]; then
  case $VIMBADMIN_BROWSER in
  chromium | firefox | webkit)
    engines=("$VIMBADMIN_BROWSER")
    ;;
  *)
    echo "FAIL: unsupported VIMBADMIN_BROWSER=$VIMBADMIN_BROWSER" >&2
    exit 64
    ;;
  esac
fi

cd "$(dirname "$0")/../.."
suite=${VIMBADMIN_BROWSER_APPLICATION_SUITE:-auth-errors}
case $suite in
auth-errors | tenant | operations | visual) ;;
*)
  echo 'FAIL: unsupported browser application suite' >&2
  exit 64
  ;;
esac
if [[ $suite == tenant || $suite == operations || $suite == visual ]]; then export VIMBADMIN_FIXTURE_TENANT=1; fi
if [[ $suite == operations ]]; then export VIMBADMIN_FIXTURE_OPERATIONS=1; fi
evidence_dir=''
if [[ ${1:-} == --evidence-dir ]]; then
  [[ $# -eq 2 && -n ${2:-} ]] || {
    echo 'FAIL: --evidence-dir requires a path' >&2
    exit 64
  }
  evidence_dir=$2
elif (($# != 0)); then
  echo 'FAIL: unsupported browser application fixture option' >&2
  exit 64
fi
if [[ -z $evidence_dir ]]; then
  evidence_dir=$(mktemp -d /tmp/vimbadmin-browser-application-evidence.XXXXXX)
fi
mkdir -p "$evidence_dir/logs" "$evidence_dir/processes"
evidence_dir=$(realpath "$evidence_dir")

readonly run_label="myguard.run=${VIMBADMIN_BROWSER_RUN_LABEL:-vimbadmin-c121-r1-browser-review}"
readonly name_prefix="myguard-run-${run_label#myguard.run=}-"
readonly mariadb_image='mirror.gcr.io/library/mariadb@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3'
readonly browser_image='vimbadmin-browser:1.63.0'
for required_command in composer curl docker openssl php python3 realpath sha256sum tar; do
  command -v "$required_command" >/dev/null || {
    echo "FAIL: required browser application fixture command is unavailable: $required_command" >&2
    exit 1
  }
done
php --ri pdo_mysql >/dev/null || {
  echo 'FAIL: host PHP pdo_mysql extension is unavailable' >&2
  exit 1
}

fixture_root=$(mktemp -d /tmp/vimbadmin-browser-application.XXXXXX)
readonly fixture_root
readonly app_root=$fixture_root/application-tree
readonly session_dir=$fixture_root/sessions
readonly state_dir=$fixture_root/browser-state
readonly db_password_file=$fixture_root/db-password
readonly verifier_token_file=$fixture_root/verifier-token
readonly verifier_curl_config=$fixture_root/verifier-curl.conf

declare -a container_ids=()
declare -a php_pids=()
declare -a collector_pids=()
declare -A removed_containers=()
container_counter=0
cleanup_started=0

remove_owned_container() {
  local cid=${1:?} force=${2:-} known owned=0 volume remaining
  local -a remove_args=(rm -v)
  for known in "${container_ids[@]}"; do
    if [[ $known == "$cid" ]]; then
      owned=1
      break
    fi
  done
  if ((owned == 0)); then
    echo 'FAIL: refusing cleanup of an unrecorded container' >&2
    return 1
  fi
  if [[ -z ${removed_containers[$cid]:-} ]]; then
    docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' \
      "$cid" >"$evidence_dir/logs/mounts-${cid}.log" || return 1
    if [[ $force == force ]]; then remove_args+=(-f); fi
    docker "${remove_args[@]}" "$cid" >/dev/null || return 1
    removed_containers[$cid]=1
  fi
  while IFS= read -r volume; do
    [[ -n $volume ]] || continue
    remaining=$(docker volume ls --filter "name=^${volume}$" --format '{{.Name}}') || return 1
    if [[ -n $remaining ]]; then
      echo 'FAIL: owned container volume remains after removal' >&2
      return 1
    fi
  done <"$evidence_dir/logs/mounts-${cid}.log"
}

cleanup() {
  local status=${1:?}
  if ((cleanup_started)); then return "$status"; fi
  cleanup_started=1
  set +e
  local index pid cid remaining recorded_remaining=0 cleanup_failures=0
  for ((index = ${#php_pids[@]} - 1; index >= 0; index--)); do
    pid=${php_pids[$index]}
    if kill -0 "$pid" 2>/dev/null; then kill -TERM "$pid" 2>/dev/null; fi
    wait "$pid" 2>/dev/null
  done
  for pid in "${collector_pids[@]}"; do wait "$pid" 2>/dev/null; done
  for cid in "${container_ids[@]}"; do
    remove_owned_container "$cid" force || cleanup_failures=$((cleanup_failures + 1))
  done
  {
    echo "cleanup_original_status=$status"
    for cid in "${container_ids[@]}"; do
      if docker inspect "$cid" >/dev/null 2>&1; then
        echo "recorded_container_remaining=$cid"
        recorded_remaining=$((recorded_remaining + 1))
      fi
    done
    remaining=$(docker ps -aq --filter "label=$run_label")
    if [[ -n $remaining ]]; then
      echo 'label_filtered_remaining=nonzero'
      docker ps -a --filter "label=$run_label" --format '{{.ID}} {{.Names}} {{.Status}}'
    else
      echo 'label_filtered_remaining=0'
    fi
    echo "recorded_ids_remaining=$recorded_remaining"
    echo "owned_cleanup_failures=$cleanup_failures"
  } >"$evidence_dir/logs/99-cleanup.log" 2>&1
  if [[ $fixture_root == /tmp/vimbadmin-browser-application.* && -d $fixture_root && ! -L $fixture_root ]]; then
    rm -rf -- "$fixture_root"
  fi
  if ((recorded_remaining != 0 || cleanup_failures != 0)) || [[ -n ${remaining:-} ]]; then status=1; fi
  return "$status"
}
trap 'status=$?; trap - EXIT; cleanup "$status"; exit $?' EXIT
trap 'exit 130' INT TERM

chmod 700 "$fixture_root"
mkdir -p "$app_root" "$session_dir" "$state_dir" "$fixture_root/profile"
chmod 700 "$app_root" "$session_dir" "$state_dir" "$fixture_root/profile"
for private_file in "$db_password_file" "$verifier_token_file" "$verifier_curl_config"; do
  install -m 600 /dev/null "$private_file"
done
openssl rand -hex 32 >"$db_password_file"
openssl rand -hex 32 >"$verifier_token_file"
db_password=$(<"$db_password_file")
verifier_token=$(<"$verifier_token_file")
printf 'header = "X-Vimbadmin-Fixture-Token: %s"\n' "$verifier_token" >"$verifier_curl_config"

existing=$(docker ps -aq --filter "label=$run_label")
if [[ -n $existing ]]; then
  echo 'FAIL: pre-existing task-labeled containers must be cleaned before this run' >&2
  exit 1
fi
docker pull "$mariadb_image" >"$evidence_dir/logs/00-mariadb-pull.log" 2>&1
docker image inspect "$mariadb_image" >/dev/null
docker image inspect "$browser_image" >/dev/null

printf 'role\troot_pid\troot_starttime\texecutable\texecutable_sha256\targv\n' >"$evidence_dir/php-commands.tsv"
printf 'role\texit\n' >"$evidence_dir/php-exits.tsv"
printf 'role\ttimestamp_ns\troot_pid\troot_starttime\tpid\ttid\ttgid\tppid\ttid_starttime\texecutable\texecutable_sha256\tcgroup\tnspid\n' \
  >"$evidence_dir/php-processes.tsv"
printf 'id\tname\tpurpose\timage\tcreation_status\n' >"$evidence_dir/container-ids.tsv"
printf 'id\tname\tpurpose\tconfigured_image\tlabel\n' >"$evidence_dir/container-labels.tsv"
printf 'id\tname\tpurpose\timage\texit\n' >"$evidence_dir/container-results.tsv"
printf 'engine\tcase\tactual_exit\texpected_exit\tcontrol_outcome\tassertion\tlog\n' \
  >"$evidence_dir/browser-status.tsv"

process_starttime() {
  local stat rest
  local -a stat_fields
  stat=$(cat "/proc/$1/stat" 2>/dev/null) || return 1
  rest=${stat##*) }
  read -r -a stat_fields <<<"$rest"
  printf '%s\n' "${stat_fields[19]}"
}

collector_loop() {
  local role=$1 root_pid=$2 root_start=$3 ready=$4
  declare -A seen=() exe_hashes=()
  : >"$ready"
  while :; do
    declare -A parent=() selected=()
    local pid ppid changed=1 task status tid tgid task_ppid tid_start exe exe_hash hash_key cgroup nspid key now
    while read -r pid ppid; do
      [[ $pid =~ ^[0-9]+$ && $ppid =~ ^[0-9]+$ ]] && parent[$pid]=$ppid
    done < <(ps -e -o pid=,ppid=)
    if [[ -r /proc/$root_pid/stat ]] && [[ $(process_starttime "$root_pid" 2>/dev/null || true) == "$root_start" ]]; then
      selected[$root_pid]=1
    fi
    while ((changed)); do
      changed=0
      for pid in "${!parent[@]}"; do
        if [[ -z ${selected[$pid]:-} && -n ${selected[${parent[$pid]}]:-} ]]; then
          selected[$pid]=1
          changed=1
        fi
      done
    done
    now=$(date +%s%N)
    for pid in "${!selected[@]}"; do
      for task in /proc/"$pid"/task/[0-9]*; do
        [[ -r $task/status && -r $task/stat ]] || continue
        status=''
        # A task can disappear after the readability check. Avoid Bash's
        # optimized $(<file), whose failed redirection can abort the collector.
        # read returns nonzero at EOF; a nonempty status is still complete.
        if ! { IFS= read -r -d '' status <"$task/status"; } 2>/dev/null; then
          [[ -n $status ]] || continue
        fi
        tid=$(awk '/^Pid:/{print $2}' <<<"$status")
        tgid=$(awk '/^Tgid:/{print $2}' <<<"$status")
        task_ppid=$(awk '/^PPid:/{print $2}' <<<"$status")
        nspid=$(awk '/^NSpid:/{for(i=2;i<=NF;i++) printf "%s%s", $i, (i<NF?"/":""); print ""}' <<<"$status")
        tid_start=$(process_starttime "$tid" 2>/dev/null || true)
        [[ -n $tid_start ]] || continue
        exe=$(readlink -f "$task/exe" 2>/dev/null || true)
        hash_key="$tid:$tid_start:$exe"
        if [[ -z ${exe_hashes[$hash_key]:-} ]]; then
          if [[ -n $exe && -r $exe ]]; then
            exe_hashes[$hash_key]=$(sha256sum "$exe" | awk '{print $1}')
          else
            exe_hashes[$hash_key]=unavailable
          fi
        fi
        exe_hash=${exe_hashes[$hash_key]}
        cgroup=$(tr '\n\t' '; ' "$task/cgroup" 2>/dev/null || true)
        key="$pid:$tid:$task_ppid:$tid_start:$exe_hash"
        if [[ -z ${seen[$key]:-} ]]; then
          seen[$key]=1
          printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
            "$role" "$now" "$root_pid" "$root_start" "$pid" "$tid" "$tgid" "$task_ppid" \
            "$tid_start" "$exe" "$exe_hash" "$cgroup" "$nspid" >>"$evidence_dir/php-processes.tsv"
        fi
      done
    done
    [[ -n ${selected[$root_pid]:-} ]] || break
    sleep 0.02
  done
}

start_captured_php() {
  local role=$1 logfile=$2 gate ready pid root_start collector executable executable_hash quoted=''
  shift 2
  gate=$(mktemp "$fixture_root/gate.XXXXXX")
  rm -f -- "$gate"
  mkfifo -m 600 "$gate"
  ready=$(mktemp "$fixture_root/ready.XXXXXX")
  rm -f -- "$ready"
  executable=$(command -v "$1")
  executable=$(readlink -f "$executable")
  executable_hash=$(sha256sum "$executable" | awk '{print $1}')
  printf -v quoted '%q ' "$@"
  (
    IFS= read -r _ <"$gate"
    exec "$@"
  ) >"$logfile" 2>&1 &
  pid=$!
  root_start=$(process_starttime "$pid")
  collector_loop "$role" "$pid" "$root_start" "$ready" &
  collector=$!
  while [[ ! -e $ready ]]; do sleep 0.01; done
  printf '%s\n' go >"$gate"
  rm -f -- "$gate" "$ready"
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$role" "$pid" "$root_start" "$executable" "$executable_hash" "$quoted" \
    >>"$evidence_dir/php-commands.tsv"
  CAPTURED_PID=$pid
  CAPTURED_COLLECTOR=$collector
}

run_captured_php() {
  local role=$1 logfile=$2 status
  shift 2
  start_captured_php "$role" "$logfile" "$@"
  php_pids+=("$CAPTURED_PID")
  collector_pids+=("$CAPTURED_COLLECTOR")
  status=0
  wait "$CAPTURED_PID" || status=$?
  wait "$CAPTURED_COLLECTOR"
  unset 'php_pids[-1]' 'collector_pids[-1]'
  printf '%s\t%s\n' "$role" "$status" >>"$evidence_dir/php-exits.tsv"
  return "$status"
}

create_container() {
  local purpose=$1 image=$2 name cid command_mode=0
  local -a docker_options=() container_command=()
  shift 2
  while (($#)); do
    if [[ $1 == -- && $command_mode == 0 ]]; then
      command_mode=1
    elif ((command_mode)); then
      container_command+=("$1")
    else
      docker_options+=("$1")
    fi
    shift
  done
  container_counter=$((container_counter + 1))
  name="${name_prefix}${container_counter}-${purpose}"
  cid=$(docker create --name "$name" --label "$run_label" "${docker_options[@]}" "$image" "${container_command[@]}")
  container_ids+=("$cid")
  printf '%s\t%s\t%s\t%s\t%s\n' "$cid" "$name" "$purpose" "$image" pending >>"$evidence_dir/container-ids.tsv"
  docker inspect --format '{{.Id}}{{"\t"}}{{.Name}}{{"\t"}}{{index .Config.Labels "myguard.run"}}{{"\t"}}{{.Config.Image}}' \
    "$cid" | sed 's#\t/#\t#' | awk -v purpose="$purpose" -F '\t' \
    '{print $1 "\t" $2 "\t" purpose "\t" $4 "\tmyguard.run=" $3}' >>"$evidence_dir/container-labels.tsv"
  CREATED_CONTAINER=$cid
  CREATED_NAME=$name
}

database_storage=()
if [[ $suite == operations || $suite == visual ]]; then
  # Private throwaway data only. Keep the large PHP file-fixture suites from
  # competing with cold database initialization on the host's disk.
  database_storage+=(--tmpfs '/var/lib/mysql:rw,noexec,nosuid,size=512m')
fi
create_container mariadb "$mariadb_image" "${database_storage[@]}" \
  --publish 127.0.0.1::3306 \
  --mount "type=bind,src=$db_password_file,dst=/run/secrets/vimbadmin-db-password,readonly" \
  --env MARIADB_ROOT_PASSWORD_FILE=/run/secrets/vimbadmin-db-password \
  --env MARIADB_DATABASE=vimbadmin \
  --health-cmd='healthcheck.sh --connect --innodb_initialized' \
  --health-interval=2s --health-timeout=2s --health-retries=60
readonly mariadb_id=$CREATED_CONTAINER
docker start "$mariadb_id" >"$evidence_dir/logs/01-mariadb-start.log"
for attempt in $(seq 1 90); do
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$mariadb_id")
  [[ $health == healthy ]] && break
  if [[ $health == unhealthy || $attempt == 90 ]]; then
    docker logs "$mariadb_id" >"$evidence_dir/logs/01-mariadb-failure.log" 2>&1
    echo "FAIL: pinned MariaDB did not become healthy ($health)" >&2
    exit 1
  fi
  sleep 1
done
db_binding=$(docker port "$mariadb_id" 3306/tcp)
db_port=${db_binding##*:}
[[ $db_port =~ ^[1-9][0-9]*$ ]] || {
  echo 'FAIL: invalid mapped MariaDB port' >&2
  exit 1
}

# A developer checkout may contain ignored proxies. Build the private fixture's
# proxies from its locked dependencies, just as the production image does.
tar --exclude=application/Proxies -cf - application bin contrib data library public src composer.json composer.lock | tar -xf - -C "$app_root"
mkdir -p "$app_root/var/tmp" "$app_root/var/cache" "$app_root/var/templates_c" "$app_root/var/bruteforce"
chmod 700 "$app_root/var" "$app_root/var/"*

run_captured_php composer-install "$evidence_dir/logs/02-composer-install.log" \
  env COMPOSER_HOME="$fixture_root/composer-home" COMPOSER_NO_INTERACTION=1 \
  composer install --working-dir="$app_root" --no-interaction --no-progress --prefer-dist --no-dev

run_captured_php proxy-generation "$evidence_dir/logs/02-proxy-generation.log" \
  php "$app_root/bin/generate-proxies.php"
for proxy_file in "$app_root"/application/Proxies/__CG__*.php; do
  [[ -f $proxy_file && -s $proxy_file ]] || {
    echo 'FAIL: generated fixture proxy is missing or empty' >&2
    exit 1
  }
  stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' "$proxy_file"
  sha256sum "$proxy_file"
done >"$evidence_dir/logs/02-proxy-artifacts.log"

read -r app_port verifier_port < <(python3 -c '
import socket
sockets = [socket.socket(), socket.socket()]
try:
    for sock in sockets:
        sock.bind(("127.0.0.1", 0))
    print(*(sock.getsockname()[1] for sock in sockets))
finally:
    for sock in sockets:
        sock.close()
')
readonly app_origin="http://127.0.0.1:$app_port"
readonly verifier_origin="http://127.0.0.1:$verifier_port"
salt=$(openssl rand -hex 32)
cp "$app_root/application/configs/application.ini.dist" "$app_root/application/configs/application.ini"
{
  printf '\n[browser-fixture]\n'
  printf 'securitysalt = "%s"\n' "$salt"
  printf 'resources.doctrine2.connection.options.dbname = "vimbadmin"\n'
  printf 'resources.doctrine2.connection.options.user = "root"\n'
  printf 'resources.doctrine2.connection.options.password = "%s"\n' "$db_password"
  printf 'resources.doctrine2.connection.options.host = "127.0.0.1"\n'
  printf 'resources.doctrine2.connection.options.port = %s\n' "$db_port"
  printf 'resources.doctrine2cache.type = "ArrayCache"\n'
  printf 'resources.session.save_path = "%s"\n' "$session_dir"
  printf 'resources.session.cookie_secure = false\n'
  printf 'resources.session.idle_timeout = 3600\n'
  printf 'temporary_directory = "%s"\n' "$app_root/var/tmp"
  printf 'bruteforce.enabled = 0\n'
  if [[ $suite == operations ]]; then
    printf 'mcp.enabled = true\n'
    printf 'mcp.ratelimit.statedir = "%s"\n' "$app_root/var/tmp/mcp-rate"
    printf 'queue.runner.key = "%s"\n' "$salt"
    printf 'queue.runner.allowed_ips = "127.0.0.1/32"\n'
    printf 'queue.runner.host = ""\n'
    # A private verifier URL returns an authentic HTTP rejection for any
    # accidental backend request; no live Dovecot address is configured.
    printf 'doveadm.http.url = "%s/not-doveadm"\n' "$verifier_origin"
    printf 'doveadm.http.api_key = "%s"\n' "$salt"
  fi
  printf '\n[browser-fixture-failure : browser-fixture]\n'
  printf 'resources.doctrine2.connection.options.driver = "fixture_injected_invalid_driver"\n'
} >>"$app_root/application/configs/application.ini"
chmod 600 "$app_root/application/configs/application.ini"
unset salt

candidate_diff=$fixture_root/candidate.diff
git diff --binary --no-ext-diff HEAD -- >"$candidate_diff"
while IFS= read -r untracked_file; do
  untracked_status=0
  git diff --binary --no-ext-diff --no-index -- /dev/null "$untracked_file" >>"$candidate_diff" || untracked_status=$?
  [[ $untracked_status == 1 ]] || {
    echo 'FAIL: could not bind untracked candidate file' >&2
    exit 1
  }
done < <(git ls-files --others --exclude-standard | sort)
{
  printf 'candidate_head\t%s\n' "$(git rev-parse HEAD)"
  printf 'source_tree\t%s\n' "$(git rev-parse 'HEAD^{tree}')"
  printf 'candidate_diff_sha256\t%s\n' "$(sha256sum "$candidate_diff" | awk '{print $1}')"
  for identity_file in composer.json composer.lock .github/scripts/browser/Dockerfile \
    .github/scripts/browser/README.md \
    .github/scripts/browser/package.json .github/scripts/browser/package-lock.json \
    .github/scripts/browser/run-fixture.cjs ci/test-browser-auth-errors.cjs ci/test-browser-auth-errors.sh \
    tests/support/browser-application-setup.php tests/support/browser-application-router.php \
    tests/support/browser-application-verifier.php tests/support/browser-application-fixture.sh; do
    printf 'file\t%s\t%s\n' "$identity_file" "$(sha256sum "$identity_file" | awk '{print $1}')"
  done
  if [[ $suite == tenant ]]; then
    for identity_file in ci/test-browser-tenant.cjs ci/test-browser-tenant.sh ci/support/browser-tenant-seed.php; do
      printf 'file\t%s\t%s\n' "$identity_file" "$(sha256sum "$identity_file" | awk '{print $1}')"
    done
  fi
  if [[ $suite == operations ]]; then
    for identity_file in ci/test-browser-operations.cjs ci/test-browser-operations.sh ci/support/browser-operations-seed.php ci/support/browser-operations-control.php; do
      printf 'file\t%s\t%s\n' "$identity_file" "$(sha256sum "$identity_file" | awk '{print $1}')"
    done
  fi
  if [[ $suite == visual ]]; then
    for identity_file in ci/test-browser-visual.cjs ci/test-browser-visual.sh \
      ci/browser-visual-inventory.json ci/test-browser-visual-inventory.cjs; do
      printf 'file\t%s\t%s\n' "$identity_file" "$(sha256sum "$identity_file" | awk '{print $1}')"
    done
    git ls-files application/views public/css | while IFS= read -r identity_file; do
      printf 'file\t%s\t%s\n' "$identity_file" "$(sha256sum "$identity_file" | awk '{print $1}')"
    done
  fi
  printf 'generated_config_sha256\t%s\n' "$(sha256sum "$app_root/application/configs/application.ini" | awk '{print $1}')"
  printf 'composer_installed_sha256\t%s\n' "$(sha256sum "$app_root/vendor/composer/installed.php" | awk '{print $1}')"
  printf 'browser_image_id\t%s\n' "$(docker image inspect --format '{{.Id}}' "$browser_image")"
  printf 'mariadb_image_id\t%s\n' "$(docker image inspect --format '{{.Id}}' "$mariadb_image")"
  printf 'mariadb_container_id\t%s\n' "$mariadb_id"
  printf 'mariadb_port\t%s\n' "$db_port"
  printf 'application_origin\t%s\n' "$app_origin"
  printf 'verifier_origin\t%s\n' "$verifier_origin"
} >"$evidence_dir/identities.tsv"

run_captured_php application-setup "$evidence_dir/logs/03-application-setup.log" \
  env VIMBADMIN_FIXTURE_ORIGIN="$app_origin" \
  php tests/support/browser-application-setup.php "$app_root" "$session_dir" "$state_dir"

cp "ci/test-browser-$suite.cjs" "$fixture_root/test-browser-auth-errors.cjs"
chmod 500 "$fixture_root/test-browser-auth-errors.cjs"
sed 's/if (target.origin !== origin.origin) {/if (false \&\& target.origin !== origin.origin) {/' \
  .github/scripts/browser/run-fixture.cjs >"$fixture_root/run-fixture-origin-guard-mutant.cjs"
[[ $(grep -cF 'if (false && target.origin !== origin.origin) {' \
  "$fixture_root/run-fixture-origin-guard-mutant.cjs") == 1 ]] || {
  echo 'FAIL: application-origin negative control did not replace exactly one guard' >&2
  exit 1
}
chmod 500 "$fixture_root/run-fixture-origin-guard-mutant.cjs"
printf '{"mode":"full","verifierOrigin":"%s","verifierToken":"%s","stateDirectory":"%s"}\n' \
  "$verifier_origin" "$verifier_token" "$state_dir" >"$fixture_root/full-input.json"
printf '{"mode":"guard-smoke","verifierOrigin":"%s","verifierToken":"%s","stateDirectory":"%s"}\n' \
  "$verifier_origin" "$verifier_token" "$state_dir" >"$fixture_root/guard-input.json"
chmod 600 "$fixture_root/"*-input.json
unset db_password verifier_token

application_php_options=()
if [[ $suite == operations ]]; then application_php_options+=(-d allow_url_fopen=0); fi
start_captured_php application-server "$evidence_dir/logs/04-application-server.log" \
  env APPLICATION_ENV=browser-fixture VIMBADMIN_FIXTURE_PUBLIC="$app_root/public" \
  php "${application_php_options[@]}" -d display_errors=0 -d log_errors=1 -d opcache.enable=0 -d opcache.enable_cli=0 \
  -S "127.0.0.1:$app_port" -t "$app_root/public" \
  tests/support/browser-application-router.php
app_pid=$CAPTURED_PID
php_pids+=("$CAPTURED_PID")
collector_pids+=("$CAPTURED_COLLECTOR")
start_captured_php state-verifier "$evidence_dir/logs/05-state-verifier.log" \
  env DB_HOST=127.0.0.1 DB_PORT="$db_port" DB_NAME=vimbadmin DB_USER=root DB_PASS_FILE="$db_password_file" \
  VIMBADMIN_FIXTURE_TOKEN_FILE="$verifier_token_file" \
  VIMBADMIN_FIXTURE_SESSION_DIR="$session_dir" VIMBADMIN_FIXTURE_SESSION_MAP="$state_dir/session-aliases.json" \
  php -d display_errors=0 -d log_errors=1 -S "127.0.0.1:$verifier_port" tests/support/browser-application-verifier.php
verifier_pid=$CAPTURED_PID
php_pids+=("$CAPTURED_PID")
collector_pids+=("$CAPTURED_COLLECTOR")

for attempt in $(seq 1 60); do
  app_status=$(curl --silent --max-time 2 --output /dev/null --write-out '%{http_code}' \
    "$app_origin/auth/login" || true)
  verifier_status=$(curl --silent --max-time 2 --config "$verifier_curl_config" \
    --output /dev/null --write-out '%{http_code}' \
    "$verifier_origin/snapshot?session=all" || true)
  [[ $app_status == 200 && $verifier_status == 200 ]] && break
  if ((attempt == 60)); then
    echo "FAIL: application fixture readiness failed (application $app_status, verifier $verifier_status)" >&2
    exit 1
  fi
  sleep 0.25
done
unauthorized_verifier_status=$(curl --silent --max-time 2 --output /dev/null --write-out '%{http_code}' \
  "$verifier_origin/snapshot?session=all" || true)
[[ $unauthorized_verifier_status == 403 ]] || {
  echo "FAIL: state verifier accepted an unauthenticated request ($unauthorized_verifier_status)" >&2
  exit 1
}

apply_domain_authorization_mutation() {
  local source=$1 target=$2
  # shellcheck disable=SC2016 # Match literal PHP variables in the private copy.
  sed 's/if (!$domain || (!$admin->isSuper() \&\& !$admin->canManageDomain($domain))) {/if (false) {/' \
    "$source" >"$target" || return 1
  if [[ $(grep -cF 'if (false) {' "$target") != 1 ]]; then
    echo 'FAIL: domain authorization mutation must replace exactly one guard' >&2
    return 1
  fi
}

apply_operations_rollback_mutation() {
  local source=$1 target=$2 source_commits target_commits source_rollbacks count_status matches
  if matches=$(grep -oF "\$conn->rollBack();" "$source"); then
    source_rollbacks=$(printf '%s\n' "$matches" | wc -l) || {
      echo 'FAIL: operations rollback mutation could not count rollback occurrences in source' >&2
      return 1
    }
  else
    count_status=$?
    if [[ $count_status == 1 ]]; then
      source_rollbacks=0
    else
      echo "FAIL: operations rollback mutation could not read rollback occurrences in source (grep status $count_status)" >&2
      return 1
    fi
  fi
  if ((source_rollbacks != 1)); then
    echo 'FAIL: operations rollback mutation must replace exactly one rollback' >&2
    return 1
  fi
  if matches=$(grep -oF "\$conn->commit();" "$source"); then
    source_commits=$(printf '%s\n' "$matches" | wc -l) || {
      echo 'FAIL: operations rollback mutation could not count commit occurrences in source' >&2
      return 1
    }
  else
    count_status=$?
    if [[ $count_status == 1 ]]; then
      source_commits=0
    else
      echo "FAIL: operations rollback mutation could not read commit occurrences in source (grep status $count_status)" >&2
      return 1
    fi
  fi
  # shellcheck disable=SC2016 # Match interpreted PHP in the disposable copy.
  sed 's/\$conn->rollBack();/$conn->commit();/' "$source" >"$target" || return 1
  if matches=$(grep -oF "\$conn->commit();" "$target"); then
    target_commits=$(printf '%s\n' "$matches" | wc -l) || {
      echo 'FAIL: operations rollback mutation could not count commit occurrences in target' >&2
      return 1
    }
  else
    count_status=$?
    if [[ $count_status == 1 ]]; then
      target_commits=0
    else
      echo "FAIL: operations rollback mutation could not read commit occurrences in target (grep status $count_status)" >&2
      return 1
    fi
  fi
  if ((target_commits != source_commits + 1)) || grep -qF "\$conn->rollBack();" "$target"; then
    echo 'FAIL: operations rollback mutation must add exactly one commit and remove the rollback' >&2
    return 1
  fi
}

run_browser() {
  local engine=$1 case_name=$2 mutation=$3 expected_engine=$4 target_origin=$5 input_file=$6
  local safe_case=${case_name//[^a-z0-9]/-} cid name log status exit_code profile
  log="$evidence_dir/logs/browser-${engine}-${safe_case}.log"
  profile="$fixture_root/profile-${engine}-${safe_case}"
  install -d -m 700 "$profile"
  if [[ $suite == visual ]]; then
    # Change only the disposable application's configuration; every request
    # reloads it with opcache disabled. No fixture markup replaces a real view.
    local skin=dark
    [[ $case_name != default-* ]] || skin=''
    cp "$fixture_root/visual-config.ini" "$app_root/application/configs/application.ini"
    # Override inside the existing section so all connection options remain.
    sed -i '/^\[browser-fixture-failure : browser-fixture\]/i resources.smarty.skin = "'"$skin"'"' \
      "$app_root/application/configs/application.ini"
    cp application/views/header.phtml "$app_root/application/views/header.phtml"
    cp application/views/maintenance/index.phtml "$app_root/application/views/maintenance/index.phtml"
    cp application/views/admin/list.phtml "$app_root/application/views/admin/list.phtml"
    if [[ $mutation == hidden-action ]]; then
      sed -i 's/<button class="btn btn-sm"/<button hidden class="btn btn-sm"/' "$app_root/application/views/maintenance/index.phtml"
    elif [[ $mutation == clipped-action ]]; then
      sed -i 's/<button class="btn btn-sm"/<button style="clip-path: inset(100%);" class="btn btn-sm"/' "$app_root/application/views/maintenance/index.phtml"
    elif [[ $mutation == hidden-desktop-action ]]; then
      sed -i 's/id="add_admin"/id="add_admin" style="display: none;"/' "$app_root/application/views/admin/list.phtml"
    elif [[ $mutation == hidden-narrow-action ]]; then
      sed -i '$a<style>@media (max-width: 991.98px) { #add_admin { display: none !important; } }</style>' \
        "$app_root/application/views/admin/list.phtml"
    elif [[ $mutation == clipped-narrow-action ]]; then
      sed -i '$a<style>@media (max-width: 991.98px) { #add_admin { clip-path: inset(100%) !important; } }</style>' \
        "$app_root/application/views/admin/list.phtml"
    elif [[ $mutation == hidden-narrow-toggle ]]; then
      sed -i '$a<style>@media (max-width: 991.98px) { .navbar-toggler { display: none !important; } }</style>' \
        "$app_root/application/views/header.phtml"
    elif [[ $mutation == clipped-narrow-toggle ]]; then
      sed -i '$a<style>@media (max-width: 991.98px) { .navbar-toggler { clip-path: inset(100%) !important; } }</style>' \
        "$app_root/application/views/header.phtml"
    elif [[ $mutation == covered-narrow-toggle ]]; then
      sed -i '0,/<\/button>/s@</button>@</button><span class="visual-toggle-cover" aria-hidden="true"></span>@' \
        "$app_root/application/views/header.phtml"
      sed -i '$a<style>.visual-toggle-cover { display: none; } @media (max-width: 991.98px) { .navbar > .container { position: relative; } .visual-toggle-cover { display: block; position: absolute; top: 0; right: 0; width: 80px; height: 56px; z-index: 2000; } }</style>' \
        "$app_root/application/views/header.phtml"
    elif [[ $mutation == missing-label-focus ]]; then
      sed -i 's/aria-label="Toggle navigation"/aria-label="" tabindex="-1"/' "$app_root/application/views/header.phtml"
    elif [[ $mutation == missing-focus ]]; then
      sed -i 's/aria-label="Toggle navigation"/aria-label="Toggle navigation" tabindex="-1"/' "$app_root/application/views/header.phtml"
    fi
    if [[ $mutation == omitted-skin ]]; then
      sed -i 's/resources.smarty.skin = "dark"/resources.smarty.skin = ""/g' "$app_root/application/configs/application.ini"
    fi
    # Smarty's generated templates must never outlive their mutated source.
    find "$app_root/var/templates_c" -type f -delete
    mkdir -p "$fixture_root/visual-artifacts/$engine-$case_name"
    {
      printf 'command=bash ci/test-browser-visual.sh --evidence-dir %q; engine=%s case=%s mutation=%s\n' "$evidence_dir" "$engine" "$case_name" "$mutation"
      for artifact in "$app_root/application/configs/application.ini" "$app_root/application/views/header.phtml" \
        "$app_root/application/views/maintenance/index.phtml" "$app_root/application/views/admin/list.phtml" \
        "$app_root/public/css/_skins/dark/skin.css"; do
        stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' "$artifact"
        sha256sum "$artifact"
      done
    } >"$evidence_dir/logs/artifact-$engine-$safe_case.log"
  fi
  run_captured_php "session-reset-${engine}-${safe_case}" \
    "$evidence_dir/logs/session-reset-${engine}-${safe_case}.log" \
    env VIMBADMIN_FIXTURE_ORIGIN="$app_origin" \
    php tests/support/browser-application-setup.php "$app_root" "$session_dir" "$state_dir" --sessions-only
  if [[ $suite == tenant ]]; then
    cp src/Kernel/Controller/DomainController.php "$app_root/src/Kernel/Controller/DomainController.php"
    if [[ $mutation == disable-domain-authorization ]]; then
      # Mutate only the disposable application copy. PHP is interpreted with
      # CLI opcache disabled; every request loads this exact source artifact.
      apply_domain_authorization_mutation src/Kernel/Controller/DomainController.php \
        "$app_root/src/Kernel/Controller/DomainController.php"
    fi
    stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' \
      "$app_root/src/Kernel/Controller/DomainController.php" >"$evidence_dir/logs/artifact-${engine}-${safe_case}.log"
    sha256sum "$app_root/src/Kernel/Controller/DomainController.php" >>"$evidence_dir/logs/artifact-${engine}-${safe_case}.log"
  fi
  if [[ $suite == operations ]]; then
    cp src/Kernel/Controller/MaintenanceController.php "$app_root/src/Kernel/Controller/MaintenanceController.php"
    if [[ $mutation == commit-on-rollback ]]; then
      apply_operations_rollback_mutation src/Kernel/Controller/MaintenanceController.php \
        "$app_root/src/Kernel/Controller/MaintenanceController.php"
    fi
    stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' \
      "$app_root/src/Kernel/Controller/MaintenanceController.php" >"$evidence_dir/logs/artifact-${engine}-${safe_case}.log"
    sha256sum "$app_root/src/Kernel/Controller/MaintenanceController.php" >>"$evidence_dir/logs/artifact-${engine}-${safe_case}.log"
  fi
  args=(--network host --cap-drop ALL --security-opt no-new-privileges
    --user "$(id -u):$(id -g)" --env "HOME=$fixture_root"
    --mount "type=bind,src=$fixture_root,dst=$fixture_root")
  if [[ $suite == visual ]]; then
    args+=(--env "VIMBADMIN_VISUAL_SKIN=${skin:-default}"
      --env "VIMBADMIN_VISUAL_ARTIFACTS=$fixture_root/visual-artifacts/$engine-$case_name")
  fi
  if [[ $mutation == disable-origin-guard ]]; then
    args+=(--mount "type=bind,src=$fixture_root/run-fixture-origin-guard-mutant.cjs,dst=/opt/browser/run-fixture.cjs,readonly")
  fi
  if [[ -n $mutation ]]; then args+=(--env "VIMBADMIN_BROWSER_APP_MUTATION=$mutation"); fi
  create_container "browser-${engine}-${safe_case}" "$browser_image" "${args[@]}" -- \
    "$engine" "$fixture_root" "--application-origin=$app_origin" \
    "--application-target=$target_origin" "--application-suite=$fixture_root/test-browser-auth-errors.cjs" \
    "--application-input=$input_file" "--application-profile=$profile" \
    "--application-expected-engine=$expected_engine"
  cid=$CREATED_CONTAINER
  name=$CREATED_NAME
  if [[ $suite == visual ]]; then
    docker inspect --format '{{json .Path}} {{json .Args}}' "$cid" >>"$evidence_dir/logs/artifact-$engine-$safe_case.log"
  fi
  status=0
  docker start --attach "$cid" >"$log" 2>&1 || status=$?
  exit_code=$(docker inspect --format '{{.State.ExitCode}}' "$cid")
  [[ $status == "$exit_code" ]] || status=$exit_code
  printf '%s\t%s\t%s\t%s\t%s\n' "$cid" "$name" "browser-$engine-$case_name" "$browser_image" "$status" \
    >>"$evidence_dir/container-results.tsv"
  remove_owned_container "$cid"
  if [[ $suite == visual ]]; then
    mkdir -p "$evidence_dir/visual-artifacts"
    cp -a "$fixture_root/visual-artifacts/$engine-$case_name" "$evidence_dir/visual-artifacts/"
  fi
  BROWSER_STATUS=$status
  BROWSER_LOG=$log
}

expect_green() {
  local engine=$1 case_name=$2 mutation=${3:-} expected=${4:-$1} target=${5:-$app_origin} input=${6:-$fixture_root/full-input.json}
  run_browser "$engine" "$case_name" "$mutation" "$expected" "$target" "$input"
  if ((BROWSER_STATUS != 0)); then
    echo "FAIL: $engine $case_name exited $BROWSER_STATUS" >&2
    sed -n '1,160p' "$BROWSER_LOG" >&2
    exit 1
  fi
  printf '%s\t%s\t0\t0\tcandidate-pass\t%s\t%s\n' "$engine" "$case_name" \
    'restored application behavior passed' "$BROWSER_LOG" \
    >>"$evidence_dir/browser-status.tsv"
}

expect_red() {
  local engine=$1 case_name=$2 mutation=$3 expected=$4 assertion=$5
  run_browser "$engine" "$case_name" "$mutation" "$expected" "$app_origin" "$fixture_root/full-input.json"
  if [[ $BROWSER_STATUS != 1 ]] || ! grep -qF "$assertion" "$BROWSER_LOG"; then
    echo "FAIL: $engine $case_name did not observe the named red assertion" >&2
    sed -n '1,160p' "$BROWSER_LOG" >&2
    exit 1
  fi
  printf '%s\t%s\t1\t1\tmutation-red\t%s\t%s\n' "$engine" "$case_name" "$assertion" "$BROWSER_LOG" \
    >>"$evidence_dir/browser-status.tsv"
}

if [[ $suite == visual ]]; then cp "$app_root/application/configs/application.ini" "$fixture_root/visual-config.ini"; fi
for engine in "${engines[@]}"; do
  if [[ $suite == visual ]]; then
    expect_green "$engine" default-full
    expect_green "$engine" dark-full
    expect_red "$engine" dark-hidden-action hidden-action "$engine" 'visual action is visible: maintenance release check'
    expect_red "$engine" dark-clipped-action clipped-action "$engine" 'maintenance: action is not clipped or covered'
    expect_red "$engine" dark-hidden-desktop-action hidden-desktop-action "$engine" 'admins: primary action visible'
    expect_red "$engine" dark-hidden-narrow-action hidden-narrow-action "$engine" 'admins: primary action visible at 768'
    expect_red "$engine" dark-clipped-narrow-action clipped-narrow-action "$engine" 'admins: action is not clipped or covered at 768'
    expect_red "$engine" dark-hidden-narrow-toggle hidden-narrow-toggle "$engine" 'navigation toggle is visible at 768'
    expect_red "$engine" dark-clipped-narrow-toggle clipped-narrow-toggle "$engine" 'navigation toggle is not clipped or covered at 768'
    expect_red "$engine" dark-covered-narrow-toggle covered-narrow-toggle "$engine" 'navigation toggle is not clipped or covered at 768'
    expect_red "$engine" dark-missing-label-focus missing-label-focus "$engine" 'navigation toggle has accessible name and keyboard focus'
    expect_red "$engine" dark-missing-focus missing-focus "$engine" 'navigation toggle has accessible name and keyboard focus'
    expect_red "$engine" dark-omitted-skin omitted-skin "$engine" 'dark skin stylesheet is loaded last'
    expect_green "$engine" dark-restored
    expect_green "$engine" default-restored
    continue
  fi
  if [[ $suite == operations ]]; then
    expect_green "$engine" full
    expect_red "$engine" commit-on-rollback commit-on-rollback "$engine" \
      'maintenance rollback preserves complete protected rows'
    expect_green "$engine" restored
    continue
  fi
  if [[ $suite == tenant ]]; then
    expect_green "$engine" full
    expect_red "$engine" domain-authorization-disabled disable-domain-authorization "$engine" \
      'assigned cross-tenant domain toggle: expected exact denial ko'
    expect_green "$engine" restored
    continue
  fi
  expect_green "$engine" full
  expect_red "$engine" wrong-engine '' deliberately-wrong \
    'application engine assertion: expected deliberately-wrong'
  expect_red "$engine" skip-auth-assertion skip-auth-assertion "$engine" \
    'application auth assertion coverage: missing auth/login'
  expect_red "$engine" skip-error-status skip-error-status "$engine" \
    'application error assertion coverage: missing unknown-route-404'
  expect_red "$engine" skip-state-snapshot skip-state-snapshot "$engine" \
    'application state assertion coverage: missing csrf-invalid'

  # Restored guard rejects a different loopback origin before browser launch.
  run_browser "$engine" origin-guard-restored '' "$engine" "http://localhost:$app_port" "$fixture_root/guard-input.json"
  if [[ $BROWSER_STATUS != 1 ]] || ! grep -qF 'application target is outside the declared application origin' "$BROWSER_LOG"; then
    echo "FAIL: $engine restored application-origin guard did not reject" >&2
    exit 1
  fi
  printf '%s\t%s\t1\t1\tcandidate-pass\t%s\t%s\n' "$engine" origin-guard-restored \
    'alternate actual application origin rejected' "$BROWSER_LOG" >>"$evidence_dir/browser-status.tsv"

  # With the guard disabled, the same actual application path becomes reachable.
  # That is the observed-red outcome for the outer guard assertion.
  run_browser "$engine" origin-guard-disabled disable-origin-guard "$engine" \
    "http://localhost:$app_port" "$fixture_root/guard-input.json"
  if ((BROWSER_STATUS != 0)); then
    echo "FAIL: $engine origin-guard mutation did not reach the actual application" >&2
    exit 1
  fi
  printf '%s\t%s\t0\t0\tmutation-red\t%s\t%s\n' "$engine" origin-guard-disabled \
    'application origin guard accepted an alternate actual application origin' "$BROWSER_LOG" \
    >>"$evidence_dir/browser-status.tsv"
done

kill -TERM "$verifier_pid" "$app_pid"
verifier_exit=0
app_exit=0
wait "$verifier_pid" || verifier_exit=$?
wait "$app_pid" || app_exit=$?
php_pids=()
for pid in "${collector_pids[@]}"; do wait "$pid"; done
collector_pids=()
printf '%s\t%s\n' application-server "$app_exit" >>"$evidence_dir/php-exits.tsv"
printf '%s\t%s\n' state-verifier "$verifier_exit" >>"$evidence_dir/php-exits.tsv"

docker stop --time 10 "$mariadb_id" >/dev/null
mariadb_exit=$(docker inspect --format '{{.State.ExitCode}}' "$mariadb_id")
printf '%s\t%s\t%s\t%s\t%s\n' "$mariadb_id" "${name_prefix}1-mariadb" mariadb "$mariadb_image" "$mariadb_exit" \
  >>"$evidence_dir/container-results.tsv"
remove_owned_container "$mariadb_id"
remaining=$(docker ps -aq --filter "label=$run_label")
[[ -z $remaining ]] || {
  echo 'FAIL: label-filtered containers remain after fixture run' >&2
  exit 1
}

sha256sum "$evidence_dir"/logs/mounts-*.log >"$evidence_dir/container-mounts.sha256"
if [[ $suite == visual ]]; then
  find "$evidence_dir/visual-artifacts" -type f -print0 | sort -z | xargs -0 sha256sum >"$evidence_dir/visual-manifest.sha256"
fi
sha256sum "$evidence_dir"/identities.tsv "$evidence_dir"/php-commands.tsv \
  "$evidence_dir"/php-processes.tsv "$evidence_dir"/browser-status.tsv \
  "$evidence_dir"/container-ids.tsv "$evidence_dir"/container-labels.tsv \
  "$evidence_dir"/container-results.tsv "$evidence_dir/container-mounts.sha256" >"$evidence_dir/core-manifest.sha256"
printf 'OK: actual ViMbAdmin %s application fixture passed engines [%s] and observed-red controls\n' \
  "$suite" "${engines[*]}"
