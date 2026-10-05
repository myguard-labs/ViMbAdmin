#!/usr/bin/env bash
set -euo pipefail

readonly workflows=(
  .github/workflows/ci.yml
  "${WORKFLOW_RUNTIME_REGRESSION_WORKFLOW:-.github/workflows/regression.yml}"
  .github/workflows/static-analysis.yml
  "${WORKFLOW_RUNTIME_SECURITY_WORKFLOW:-.github/workflows/security.yml}"
)

readonly php_workflows=(
  .github/workflows/ci.yml
  "${WORKFLOW_RUNTIME_REGRESSION_WORKFLOW:-.github/workflows/regression.yml}"
  .github/workflows/static-analysis.yml
)

# Keep the two-space job layout and literal minute bounds explicit. Step-level
# timeouts do not bound a whole job; actionlint checks the wider YAML schema.
if ! timeout_errors=$(awk '
  function finish_job() {
    if (job != "" && timeout_count != 1) {
      printf "%s:%d: job %s needs exactly one job-level timeout-minutes (found %d)\n", file, job_line, job, timeout_count
      failed = 1
    }
    job = ""
    timeout_count = 0
  }
  function finish_file() {
    finish_job()
    if (file != "" && job_count == 0) {
      printf "%s: no jobs found in the supported two-space layout\n", file
      failed = 1
    }
  }
  FNR == 1 {
    finish_file()
    file = FILENAME
    in_jobs = 0
    job_count = 0
  }
  /^[[:space:]]*(#|$)/ { next }
  /^jobs:[[:space:]]*(#.*)?$/ { in_jobs = 1; next }
  in_jobs && /^[^ ]/ { finish_job(); in_jobs = 0 }
  !in_jobs { next }
  /^  [^ ]/ {
    finish_job()
    if ($0 !~ /^  [A-Za-z_][A-Za-z0-9_-]*:[[:space:]]*(#.*)?$/) {
      printf "%s:%d: unsupported job declaration\n", file, FNR
      failed = 1
      next
    }
    job = $0
    sub(/^  /, "", job)
    sub(/:.*/, "", job)
    job_line = FNR
    job_count++
    next
  }
  job != "" && /^    timeout-minutes:/ {
    timeout_count++
    value = $0
    sub(/^    timeout-minutes:[[:space:]]*/, "", value)
    sub(/[[:space:]]+#.*/, "", value)
    sub(/[[:space:]]+$/, "", value)
    if (value !~ /^[1-9][0-9]*$/ || value + 0 > 360) {
      printf "%s:%d: job %s timeout-minutes must be a literal integer from 1 to 360\n", file, FNR, job
      failed = 1
    }
  }
  END { finish_file(); exit failed ? 1 : 0 }
' "${workflows[@]}"); then
  printf 'Invalid workflow job timeout:\n%s\n' "$timeout_errors" >&2
  exit 1
fi

if grep -HnE 'runs-on:.*lxc' "${workflows[@]}"; then
  printf 'Unavailable runner target found.\n' >&2
  exit 1
fi

if grep -HnF 'shivammathur/setup-php@' "${workflows[@]}"; then
  printf 'The repository action policy does not allow shivammathur/setup-php.\n' >&2
  exit 1
fi

runtime_images=$(awk '
  /^[[:space:]]+image:[[:space:]]*/ {
    value = $0
    sub(/^[[:space:]]+image:[[:space:]]*/, "", value)
    if (value == ">-") {
      if (getline <= 0) exit 2
      value = $0
      sub(/^[[:space:]]+/, "", value)
    }
    print value
  }
' "${php_workflows[@]}")
if [[ -z $runtime_images ]]; then
  printf 'PHP workflows must declare runtime images.\n' >&2
  exit 1
fi

if invalid_runtime_images=$(printf '%s\n' "$runtime_images" |
  grep -vE '^(mirror\.gcr\.io/library/(php|mariadb)@sha256:[0-9a-f]{64}|\$\{\{ matrix\.image \}\})$'); then
  printf 'Workflow runtime images must use approved registry-mirror digests:\n%s\n' \
    "$invalid_runtime_images" >&2
  exit 1
fi

if ! container_shape_errors=$(awk '
  function indentation(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  function finish_container() {
    if (in_container && image_count != 1) {
      printf "%s:%d: container must map exactly one image (found %d)\n", file, container_line, image_count
      failed = 1
    }
    in_container = 0
    image_count = 0
  }
  FNR == 1 { finish_container(); file = FILENAME }
  in_container && $0 !~ /^[[:space:]]*$/ && indentation($0) <= container_indent {
    finish_container()
  }
  /^[[:space:]]+container:[[:space:]]*/ {
    finish_container()
    value = $0
    sub(/^[[:space:]]+container:[[:space:]]*/, "", value)
    if (value != "") {
      printf "%s:%d: scalar or inline container syntax is unsupported\n", FILENAME, FNR
      failed = 1
      next
    }
    in_container = 1
    container_indent = indentation($0)
    container_line = FNR
    next
  }
  in_container && /^[[:space:]]+image:[[:space:]]*/ { image_count++ }
  END { finish_container(); exit failed ? 1 : 0 }
' "${php_workflows[@]}"); then
  printf 'Invalid PHP workflow container declaration:\n%s\n' \
    "$container_shape_errors" >&2
  exit 1
fi

if ! grep -qF "git fetch --no-tags --depth=1 origin \"\$PHPSTAN_BASE_SHA\"" \
  .github/workflows/static-analysis.yml; then
  printf 'PHPStan must explicitly fetch its immutable pull-request base.\n' >&2
  exit 1
fi

actual_php_containers=$(grep -hEc '^[[:space:]]+container:' "${php_workflows[@]}" |
  awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_php_containers -eq 0 ]]; then
  printf 'PHP workflows must declare at least one container job.\n' >&2
  exit 1
fi

actual_checkout_preparations=$(grep -hEc \
  'name: Prepare PHP container for checkout' "${php_workflows[@]}" |
  awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_checkout_preparations -ne $actual_php_containers ]]; then
  printf 'Every PHP container job needs one checkout preparation (%d jobs, %d preparations).\n' \
    "$actual_php_containers" "$actual_checkout_preparations" >&2
  exit 1
fi

printf 'Workflow runtime contract holds for %d PHP container jobs.\n' \
  "$actual_php_containers"

readonly security_workflow=${WORKFLOW_RUNTIME_SECURITY_WORKFLOW:-.github/workflows/security.yml}
if grep -HnE '\|\|[[:space:]]*true' "$security_workflow"; then
  printf 'Security Semgrep workflow must not mask command failures.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  $0 ~ /^  semgrep:[[:space:]]*$/ {
    in_semgrep = 1
    saw_semgrep = 1
    next
  }
  in_semgrep && $0 !~ /^[[:space:]]*$/ && indent($0) <= 2 {
    in_semgrep = 0
  }
  !in_semgrep { next }
  $0 ~ /^    defaults:[[:space:]]*$/ {
    in_defaults = 1
    next
  }
  in_defaults && $0 !~ /^[[:space:]]*$/ && indent($0) <= 4 {
    in_defaults = 0
    in_run = 0
  }
  !in_defaults { next }
  $0 ~ /^      run:[[:space:]]*$/ {
    in_run = 1
    next
  }
  in_run && $0 !~ /^[[:space:]]*$/ && indent($0) <= 6 {
    in_run = 0
  }
  in_run && $0 ~ /^        shell:[[:space:]]*bash[[:space:]]*$/ {
    found = 1
  }
  END { exit found ? 0 : 1 }
' "$security_workflow"; then
  printf 'Security Semgrep workflow must run every inline command with Bash.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  function trim(line) {
    gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
    return line
  }
  function is_non_bash_shell(line, quote, separator, position, key, value) {
    line = trim(line)
    quote = substr(line, 1, 1)
    if (quote == "\"" || quote == sprintf("%c", 39)) {
      separator = quote ":"
      position = index(line, separator)
      if (!position) return 0
      key = substr(line, 2, position - 2)
      value = substr(line, position + 2)
    } else {
      position = index(line, ":")
      if (!position) return 0
      key = substr(line, 1, position - 1)
      value = substr(line, position + 1)
    }
    return trim(key) == "shell" && trim(value) != "bash"
  }
  $0 ~ /^  semgrep:[[:space:]]*$/ {
    in_semgrep = 1
    saw_semgrep = 1
    next
  }
  in_semgrep && $0 !~ /^[[:space:]]*$/ && indent($0) <= 2 {
    in_semgrep = 0
    in_steps = 0
  }
  !in_semgrep { next }
  $0 ~ /^    steps:[[:space:]]*$/ {
    in_steps = 1
    saw_steps = 1
    next
  }
  in_steps && $0 !~ /^[[:space:]]*$/ && indent($0) <= 4 {
    in_steps = 0
  }
  in_steps && indent($0) == 8 && is_non_bash_shell($0) {
    failed = 1
  }
  END { exit (failed || !saw_semgrep || !saw_steps) ? 1 : 0 }
' "$security_workflow"; then
  printf 'Security Semgrep steps may only override their shell with Bash.\n' >&2
  exit 1
fi

for required in \
  'fetch-depth: 0' \
  'select-semgrep-baseline.sh' \
  'PR_BASE_SHA' \
  'BEFORE_SHA' \
  'HEAD_SHA' \
  '--baseline-commit' \
  'assert-semgrep-findings.sh' \
  'tests/test-semgrep-baseline.sh'; do
  if ! grep -qF -- "$required" "$security_workflow"; then
    printf 'Security workflow is missing baseline policy component: %s\n' \
      "$required" >&2
    exit 1
  fi
done

if grep -HnE '^[[:space:]]*(- )?uses:' "$security_workflow" |
  grep -vE '@[0-9a-f]{40}([[:space:]]+#.*)?$'; then
  printf 'Security workflow actions and images must use immutable refs.\n' >&2
  exit 1
fi

if ! grep -qE 'semgrep/semgrep@sha256:[0-9a-f]{64}' "$security_workflow" ||
  grep -HnE 'semgrep/semgrep[^[:space:]#]*' "$security_workflow" |
  grep -vE '@sha256:[0-9a-f]{64}([[:space:]]+#.*)?$'; then
  printf 'Security workflow actions and images must use immutable refs.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  function finish_checkout() {
    if (!in_checkout) return
    if (field != 4) failed = 1
    in_checkout = 0
  }
  {
    current_indent = indent($0)
    if (in_checkout && $0 !~ /^[[:space:]]*$/ &&
        current_indent <= checkout_indent) finish_checkout()

    if (index($0, "actions/checkout@")) {
      checkout_occurrences++
      if ($0 !~ /^      - uses: actions\/checkout@/) {
        failed = 1
        next
      }
      ref = $0
      sub(/^      - uses: actions\/checkout@/, "", ref)
      if (length(ref) != 40 || ref !~ /^[0-9a-f]+$/) failed = 1
      finish_checkout()
      in_checkout = 1
      checkout_indent = current_indent
      field = 0
      next
    }
    if (!in_checkout) next
    if ($0 ~ /^[[:space:]]*$/) next

    if (field == 0 && $0 == "        with:") field = 1
    else if (field == 1 && $0 == "          fetch-depth: 0") field = 2
    else if (field == 2 &&
             $0 == "          ref: ${{ github.event.pull_request.head.sha || github.sha }}") field = 3
    else if (field == 3 &&
             $0 == "          persist-credentials: false") field = 4
    else failed = 1
  }
  END {
    finish_checkout()
    exit checkout_occurrences == 1 && !failed ? 0 : 1
  }
' "$security_workflow"; then
  printf 'Security workflow checkout must disable persisted credentials.\n' >&2
  exit 1
fi
