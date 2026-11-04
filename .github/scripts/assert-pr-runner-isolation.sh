#!/usr/bin/env bash
set -euo pipefail

readonly workflow_dir=${PR_RUNNER_WORKFLOW_DIR:-.github/workflows}
readonly workflows=(
  "$workflow_dir/ci.yml"
  "$workflow_dir/regression.yml"
  "$workflow_dir/security.yml"
  "$workflow_dir/static-analysis.yml"
)

# This is a fail-closed placement/preflight contract, not attestation against
# guest root. The host supervisor owns single-job JIT identity and pristine reset.
# Never expose its credential or mount its state namespace into a job container.
if [[ ${RUNNER_ENVIRONMENT:-} != self-hosted ]]; then
  printf 'Checks require an authorized self-hosted runner.\n' >&2
  exit 1
fi
case ${GITHUB_EVENT_NAME:-} in
pull_request | push | schedule | workflow_dispatch) ;;
*)
  printf 'Untrusted checks require an ordinary supported event.\n' >&2
  exit 1
  ;;
esac
case ${RUNNER_NAME:-} in
builder02-docker-01 | builder02-docker-02 | builder03-docker-01 | vi-local-ci-208c2d13-01) ;;
*)
  printf 'Runner is not an authorized Docker slot.\n' >&2
  exit 1
  ;;
esac
# Own Docker/KVM producer publishes this placement-only file from its own
# creation-ID/run/project receipt. Fleet /run/myguard-ci/slot-env is not consumed
# or forged; host credentials/state stay outside every guest/job.
readonly slot_file=${PR_RUNNER_SLOT_FILE:-/run/myguard-ci-slot}
if [[ ! -f $slot_file || -L $slot_file ]] ||
  [[ $(stat -c '%u:%g:%a' -- "$slot_file") != 0:0:444 ]]; then
  printf 'Missing or invalid published slot metadata.\n' >&2
  exit 1
fi
mapfile -t slot_lines <"$slot_file"
if [[ ${#slot_lines[@]} != 2 || ${slot_lines[0]} != "$RUNNER_NAME" ||
  ${slot_lines[1]} != /opt/actions-runner ]] ||
  [[ $(tail -c 1 -- "$slot_file" | od -An -tu1 | tr -d ' ') != 10 ]]; then
  printf 'Published slot identity does not match this runner.\n' >&2
  exit 1
fi
if [[ ! ${PR_RUNNER_EXPECTED_SHA:-} =~ ^[0-9a-f]{40}$ ]] ||
  [[ $(git rev-parse HEAD) != "$PR_RUNNER_EXPECTED_SHA" ]]; then
  printf 'Checkout does not match the exact event head.\n' >&2
  exit 1
fi

if grep -HnE '^[[:space:]]+pull_request_target:' "${workflows[@]}"; then
  printf 'Untrusted checks must use the ordinary pull_request event.\n' >&2
  exit 1
fi

# The fixed inventory prevents an added dispatch/reusable workflow escaping
# per-job accounting. Broader layouts require a deliberate policy update.
shopt -s nullglob
all_workflows=("$workflow_dir"/*.yml "$workflow_dir"/*.yaml)
if [[ ${#all_workflows[@]} -ne ${#workflows[@]} ]]; then
  printf 'Unexpected workflow inventory.\n' >&2
  exit 1
fi

for workflow in "${workflows[@]}"; do
  if ! awk '
      /^permissions:$/ { block = 1; blocks++; next }
      block && /^[^[:space:]#]/ { block = 0 }
      block && /^[[:space:]]*($|#)/ { next }
      block {
        if ($0 == "  contents: read") { contents++; next }
        failed = 1
      }

      END { exit (blocks != 1 || contents != 1 || failed) ? 1 : 0 }
    ' "$workflow"; then
    printf '%s violates the least-privilege token policy.\n' "$workflow" >&2
    exit 1
  fi
  if grep -qE '^[[:space:]]+(secrets|credentials|workflow_run|workflow_call):|--privileged|/var/run/docker.sock|/etc/myguard|/etc/ci-gh-token|CREDENTIALS_DIRECTORY' "$workflow"; then
    printf '%s exposes a privileged trigger, mount, or credential.\n' "$workflow" >&2
    exit 1
  fi
  if ! grep -qE '^[[:space:]]+pull_request:' "$workflow"; then
    printf '%s must declare the ordinary pull_request event.\n' "$workflow" >&2
    exit 1
  fi
  if ! awk '
      $0 == "concurrency:" {
        blocks++
        in_concurrency = 1
        next
      }
      in_concurrency && $0 ~ /^[^[:space:]#]/ {
        in_concurrency = 0
      }
      in_concurrency && $0 ~ /^  group:/ {
        groups++
        if ($0 ~ /^  group:[^#]*\$\{\{[[:space:]]*github\.event_name[[:space:]]*\}\}/) {
          isolated_groups++
        }
      }
      END {
        exit (blocks == 1 && groups == 1 && isolated_groups == 1) ? 0 : 1
      }
    ' "$workflow"; then
    printf '%s must isolate concurrency groups by event name.\n' "$workflow" >&2
    exit 1
  fi
done

awk '
  BEGIN { sq = sprintf("%c", 39) }
  function finish_job() {
    if (!in_job) return
    if (upload_job) {
      uploads++
      if (upload_if != 1 || upload_needs != 1 || upload_permissions != 1 || upload_read != 1 || upload_write != 1) per_job_failed = 1
    } else {
      jobs++
      if (upload_permissions || upload_read || upload_write) per_job_failed = 1
    }
    if (runner != 1 || guard != 1 || checkout != 1 || credentials != 1 || expected != 1 || (container && metadata != 1)) per_job_failed = 1
  }
  FNR == 1 {
    finish_job()
    in_jobs = 0
    in_job = 0
  }
  /^jobs:[[:space:]]*$/ {
    in_jobs = 1
    next
  }
  in_jobs && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ {
    finish_job()
    in_job = 1
    upload_job = (FILENAME ~ /\/security\.yml$/ && $0 == "  sarif-upload:")
    upload_if = 0
    upload_if_fold = 0
    expected_fold = 0
    upload_needs = 0
    upload_permissions = 0
    upload_read = 0
    upload_write = 0
    permission_block = 0
    runner = 0
    in_volumes = 0
    guard = 0
    checkout = 0
    credentials = 0
    expected = 0
    container = 0
    metadata = 0
    next
  }
  in_job && /^    if: >-$/ { upload_if_fold = 1; next }
  upload_if_fold == 1 {
    if ($0 == "      always() && github.event_name != " sq "pull_request" sq " &&") upload_if_fold = 2
    else upload_if_fold = 0
    next
  }
  upload_if_fold == 2 {
    if ($0 == "      needs.semgrep.outputs.sarif-artifact-id != " sq sq) upload_if++
    upload_if_fold = 0
    next
  }
  in_job && /^    needs: semgrep$/ { upload_needs++ }
  in_job && /^    permissions:/ {
    upload_permissions++
    permission_block = 1
    if ($0 != "    permissions:") per_job_failed = 1
    next
  }
  permission_block && /^      / {
    if ($0 == "      contents: read") upload_read++
    else if ($0 == "      security-events: write  # Only this non-PR job uploads SARIF.") upload_write++
    else per_job_failed = 1
    next
  }
  permission_block && !/^[[:space:]]*($|#)/ { permission_block = 0 }
  in_job && /^    runs-on: \[self-hosted, vimbadmin-owned-local-v1, docker, jit-env-input, vimbadmin-pristine-jit-v1\][[:space:]]*$/ { runner++ }
  in_job && /^      volumes:/ { in_volumes = 1; next }
  in_volumes && /^        - / {
    if ($0 != "        - /run/myguard-ci-slot:/run/myguard-ci-slot:ro") per_job_failed = 1
    else metadata++
    next
  }
  in_volumes && !/^[[:space:]]*($|#)/ { in_volumes = 0 }
  in_job && /^    container:/ { container++ }
  in_job && /^          persist-credentials: false$/ { credentials++ }
  in_job && /^          PR_RUNNER_EXPECTED_SHA: >-$/ { expected_fold = 1; next }
  expected_fold {
    if ($0 ~ /^            \$\{\{ github\.event\.pull_request\.head\.sha \|\| github\.sha \}\}$/) expected++
    expected_fold = 0
  }
  in_job && /ref: \$\{\{ github\.event\.pull_request\.head\.sha \|\| github\.sha \}\}/ {
    checkout++
  }
  in_job && /run: bash \.github\/scripts\/assert-pr-runner-isolation\.sh/ {
    guard++
  }
  END {
    finish_job()
    # Every PR-triggered job across the four workflows must be accounted for,
    # so adding or removing one requires updating this count deliberately.
    if (per_job_failed) {
      print "Every PR-triggered job must use the authorized Docker selector, pin the PR head without credentials, publish container metadata, and run the isolation guard exactly once." > "/dev/stderr"
    }
    if (uploads != 1) { print "Exactly one non-PR SARIF upload job is required." > "/dev/stderr"; failed = 1 }
    if (jobs != 13) {
      printf "PR-triggered job count is %d; expected 13.\n", jobs > "/dev/stderr"
      failed = 1
    }
    exit (per_job_failed || failed) ? 1 : 0
  }
' "${workflows[@]}" || {
  exit 1
}

printf 'All pull-request jobs use authorized disposable runner placement; host JIT/reset proof is required separately.\n'
