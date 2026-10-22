#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly root
readonly setup_node='actions/setup-node@53b83947a5a98c8d113130e565377fae1a50d02f'
readonly node_major=22
readonly regression=${NODE_CONTRACT_REGRESSION_WORKFLOW:-$root/.github/workflows/regression.yml}
readonly static_analysis=${NODE_CONTRACT_STATIC_ANALYSIS_WORKFLOW:-$root/.github/workflows/static-analysis.yml}

fail() {
  printf 'Node workflow contract failed: %s\n' "$1" >&2
  exit 1
}

job_body() {
  local workflow=$1 job=$2
  awk -v job="$job" '
    $0 == "  " job ":" { found = 1 }
    found && $0 ~ /^  [A-Za-z_][A-Za-z0-9_-]*:/ && $0 != "  " job ":" { exit }
    found { print }
    END { if (!found) exit 2 }
  ' "$workflow"
}

consumer_run_line() {
  local body=$1 consumer=$2
  awk -v consumer="$consumer" '
    BEGIN { single_quote = sprintf("%c", 39) }
    function trim(value) {
      sub(/^[[:space:]]+/, "", value)
      sub(/[[:space:]]+$/, "", value)
      return value
    }
    function inline_scalar(value, first, closing, suffix, i, char, backslashes) {
      first = substr(value, 1, 1)
      if (first != "\"" && first != single_quote) {
        sub(/[[:space:]]+#.*$/, "", value)
        return trim(value)
      }

      for (i = 2; i <= length(value); i++) {
        char = substr(value, i, 1)
        if (first == single_quote) {
          if (char == single_quote && substr(value, i + 1, 1) == single_quote) {
            i++
          } else if (char == single_quote) {
            closing = i
            break
          }
        } else if (char == "\\") {
          backslashes++
        } else {
          if (char == "\"" && backslashes % 2 == 0) {
            closing = i
            break
          }
          backslashes = 0
        }
      }

      suffix = substr(value, closing + 1)
      if (!closing || suffix !~ /^([[:space:]]+#.*|[[:space:]]*)$/) return value
      return substr(value, 2, closing - 2)
    }
    function finish_folded() {
      if (run_style == ">" && folded_nonblank == 1 && folded_line) {
        in_run = 0
        print folded_line
        exit
      }
      folded_nonblank = 0
      folded_line = 0
    }
    function finish_inline() {
      if (pending_inline) {
        pending_inline = 0
        print inline_line
        exit
      }
    }
    {
      indent = match($0, /[^ ]/) ? RSTART - 1 : length($0)
      if (pending_inline) {
        value = trim($0)
        if (value == "" || value ~ /^#/) next
        if (indent > inline_key_indent) {
          pending_inline = 0
        } else {
          finish_inline()
        }
      }
      if (in_run && $0 ~ /^ *$/) next
      if (in_run && indent <= run_indent) {
        finish_folded()
        in_run = 0
      }

      if ($0 ~ /^(        run:|      - run:)([[:space:]]|$)/) {
        run_indent = ($0 ~ /^      - run:/) ? indent + 2 : indent
        value = trim(substr($0, index($0, "run:") + 4))
        if (value ~ /^[|>]([+-][1-9]?|[1-9][+-]?)?([[:space:]]+#.*|[[:space:]]*)$/) {
          run_style = substr(value, 1, 1)
          in_run = 1
          next
        }
        value = inline_scalar(value)
        if (value == consumer) {
          pending_inline = 1
          inline_line = NR
          inline_key_indent = run_indent
        }
        next
      }

      if (in_run) {
        value = trim($0)
        if (run_style == ">") {
          folded_nonblank++
          if (value == consumer) folded_line = NR
        } else if (value !~ /^#/ && value == consumer) {
          print NR
          exit
        }
      }
    }
    END {
      finish_inline()
      if (in_run) finish_folded()
    }
  ' <<<"$body"
}

check_job() {
  local workflow=$1 job=$2 consumer=$3 body setup_block setup_with setup_count pin_count cache_count setup_line consumer_line
  if ! body=$(job_body "$workflow" "$job"); then
    fail "$(basename "$workflow") job $job is missing or uses an unsupported declaration"
  fi

  setup_count=$(grep -cF -- 'uses: actions/setup-node@' <<<"$body" || true)
  [[ $setup_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must use setup-node exactly once (found $setup_count)"

  grep -qF -- "uses: $setup_node" <<<"$body" ||
    fail "$(basename "$workflow") job $job must pin actions/setup-node to $setup_node"

  setup_block=$(awk -v action="      - uses: $setup_node" '
    $0 == action { found = 1 }
    found && printed && /^      - / { exit }
    found { print; printed = 1 }
  ' <<<"$body")

  setup_with=$(awk '
    $0 == "        with:" { found = 1; next }
    found && /^        [^[:space:]#]/ { exit }
    found { print }
  ' <<<"$setup_block")

  pin_count=$(grep -cE -- "^          node-version: '$node_major'[[:space:]]*$" <<<"$setup_with" || true)
  [[ $pin_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must pin node-version '$node_major' exactly once (found $pin_count)"

  cache_count=$(grep -cE -- '^          package-manager-cache: false[[:space:]]*$' <<<"$setup_with" || true)
  [[ $cache_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must disable setup-node package-manager-cache exactly once (found $cache_count)"

  if grep -qE -- "^          ([\"']?cache[\"']?):" <<<"$setup_with"; then
    fail "$(basename "$workflow") job $job must not configure setup-node cache input"
  fi

  setup_line=$(grep -nF -- "uses: $setup_node" <<<"$body" | cut -d: -f1)
  consumer_line=$(consumer_run_line "$body" "$consumer")
  [[ -n $consumer_line ]] || fail "$(basename "$workflow") job $job has no expected Node consumer: $consumer"
  ((setup_line < consumer_line)) ||
    fail "$(basename "$workflow") job $job must provision Node before: $consumer"
}

check_job "$regression" assets 'npm ci --prefix bin --ignore-scripts'
check_job "$static_analysis" refactor-metrics 'npm ci --ignore-scripts --prefix ci/refactor-metrics'

if [[ ${NODE_CONTRACT_FIXTURE_MODE:-0} != 1 ]]; then
  fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-node-workflow.XXXXXX")
  trap 'rm -rf -- "$fixture"' EXIT
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  run_fixture() {
    NODE_CONTRACT_FIXTURE_MODE=1 \
      NODE_CONTRACT_REGRESSION_WORKFLOW="$fixture/regression.yml" \
      NODE_CONTRACT_STATIC_ANALYSIS_WORKFLOW="$fixture/static-analysis.yml" \
      bash "${BASH_SOURCE[0]}" >"$fixture/output" 2>&1
  }

  expect_rejected() {
    local label=$1 assertion=$2
    if run_fixture; then
      fail "fixture accepted $label"
    fi
    grep -qF -- "$assertion" "$fixture/output" || {
      cat "$fixture/output" >&2
      fail "fixture rejected $label without the expected assertion"
    }
    printf 'Negative control rejected: %s; command=NODE_CONTRACT_FIXTURE_MODE=1 NODE_CONTRACT_REGRESSION_WORKFLOW=%q NODE_CONTRACT_STATIC_ANALYSIS_WORKFLOW=%q bash %q; assertion=%s\n' \
      "$label" "$fixture/regression.yml" "$fixture/static-analysis.yml" "${BASH_SOURCE[0]}" "$assertion"
    cp -- "$regression" "$fixture/regression.yml"
    cp -- "$static_analysis" "$fixture/static-analysis.yml"
  }

  sed -i "s/node-version: '22'/node-version: '22.x'/" "$fixture/regression.yml"
  expect_rejected "a non-literal major boundary" \
    "regression.yml job assets must pin node-version '22' exactly once (found 0)"

  if ! awk -v expected="      - uses: $setup_node" '
    /^  assets:$/ { in_assets = 1 }
    in_assets && /^  [A-Za-z_][A-Za-z0-9_-]*:/ && $0 != "  assets:" { in_assets = 0 }
    in_assets && $0 == expected {
      print "      - uses: actions/setup-node@0000000000000000000000000000000000000000"
      changed++
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-revision.yml"; then
    fail "fixture preparation failed: expected exactly one assets setup-node revision target"
  fi
  mv -- "$fixture/regression.with-revision.yml" "$fixture/regression.yml"
  expect_rejected "a mismatched action revision" \
    "regression.yml job assets must pin actions/setup-node to $setup_node"

  sed -i '0,/package-manager-cache: false/s/package-manager-cache: false/package-manager-cache: true/' \
    "$fixture/regression.yml"
  expect_rejected "implicit package-manager cache behavior" \
    'regression.yml job assets must disable setup-node package-manager-cache exactly once (found 0)'

  if ! awk -v action="      - uses: $setup_node" '
    $0 == action { in_setup = 1 }
    in_setup && $0 == "        with:" {
      if ((getline node_version) <= 0 || node_version != "          node-version: '\''22'\''") exit 2
      print "        env:"
      print node_version
      print
      changed = 1
      in_setup = 0
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-misplaced-node-version.yml"; then
    fail "fixture preparation failed: expected exactly one assets node-version input target"
  fi
  mv -- "$fixture/regression.with-misplaced-node-version.yml" "$fixture/regression.yml"
  expect_rejected "node-version misplaced under env" \
    "regression.yml job assets must pin node-version '22' exactly once (found 0)"

  sed -i '0,/package-manager-cache: false/s//package-manager-cache: false\n          cache: npm/' \
    "$fixture/regression.yml"
  expect_rejected "explicit setup-node cache input" \
    'regression.yml job assets must not configure setup-node cache input'

  sed -i '0,/package-manager-cache: false/s//package-manager-cache: false\n          "cache": npm/' \
    "$fixture/regression.yml"
  expect_rejected "quoted explicit setup-node cache input" \
    'regression.yml job assets must not configure setup-node cache input'

  sed -i "0,/node-version: '22'/s//node-version: '22'\n        # setup-node inputs may contain YAML comments/" \
    "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a YAML comment within setup-node inputs"
  fi
  printf '%s\n' 'Positive boundary accepted: a YAML comment within setup-node inputs'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  sed -i 's/^          npm ci --prefix bin --ignore-scripts$/          # npm ci --prefix bin --ignore-scripts/' \
    "$fixture/regression.yml"
  expect_rejected "a comment-only Node consumer" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  sed -i 's/^          npm ci --prefix bin --ignore-scripts$/          # npm ci --prefix bin --ignore-scripts/' \
    "$fixture/regression.yml"
  awk '
    /^      - name: Node workflow contract$/ {
      print "      - name: npm ci --prefix bin --ignore-scripts"
    }
    { print }
  ' "$fixture/regression.yml" >"$fixture/regression.with-outside-mention.yml"
  mv -- "$fixture/regression.with-outside-mention.yml" "$fixture/regression.yml"
  expect_rejected "a consumer mention in a step name outside the run block" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  sed -i 's/^          npm ci --prefix bin --ignore-scripts$/          echo npm ci --prefix bin --ignore-scripts/' \
    "$fixture/regression.yml"
  expect_rejected "an executable echo mentioning the Node consumer" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print
      next
    }
    targets == 1 && !changed && $0 == "        run: |" {
      print "        run: npm ci --prefix bin --ignore-scripts"
      changed++
      in_original_run = 1
      next
    }
    in_original_run && /^      - / { in_original_run = 0 }
    in_original_run {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      next
    }
    { print }
    END {
      if (targets != 1 || changed != 1 || removed_consumers != 1) exit 2
    }
  ' "$regression" >"$fixture/regression.with-inline-run.yml"; then
    fail "fixture preparation failed: expected exactly one assets consumer block for inline conversion"
  fi
  mv -- "$fixture/regression.with-inline-run.yml" "$fixture/regression.yml"
  inline_shape=$(awk '
    $0 == "      - name: Install pinned asset toolchain" { in_step = 1; next }
    in_step && /^      - / { exit }
    in_step && /^        run:/ { run_keys++; if ($0 == "        run: npm ci --prefix bin --ignore-scripts") exact++ }
    END { print run_keys ":" exact }
  ' "$fixture/regression.yml")
  [[ $inline_shape == 1:1 ]] ||
    fail "fixture preparation failed: inline assets step shape is $inline_shape, expected 1:1"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected an inline executable Node consumer"
  fi
  printf '%s\n' 'Positive boundary accepted: an inline executable run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  inline_eof_body='        run: npm ci --prefix bin --ignore-scripts'
  inline_eof_line=$(consumer_run_line "$inline_eof_body" 'npm ci --prefix bin --ignore-scripts')
  [[ $inline_eof_line == 1 ]] || fail "inline consumer at EOF did not finalize exactly once"
  printf '%s\n' 'Positive boundary accepted: an exact inline run command at EOF'

  inline_sibling_body=$'      - run: npm ci --prefix bin --ignore-scripts\n        shell: bash'
  inline_sibling_line=$(consumer_run_line "$inline_sibling_body" 'npm ci --prefix bin --ignore-scripts')
  [[ $inline_sibling_line == 1 ]] || fail "list-first inline consumer did not finalize at a sibling key"
  printf '%s\n' 'Positive boundary accepted: a list-first inline run command before a sibling key'

  folded_eof_body=$'      - run: >\n          npm ci --prefix bin --ignore-scripts'
  folded_eof_line=$(consumer_run_line "$folded_eof_body" 'npm ci --prefix bin --ignore-scripts')
  [[ $folded_eof_line == 2 ]] || fail "list-first folded consumer did not finalize at EOF"
  printf '%s\n' 'Positive boundary accepted: a list-first folded run command at EOF'

  folded_sibling_body=$'      - run: >\n          npm ci --prefix bin --ignore-scripts\n        shell: bash'
  folded_sibling_line=$(consumer_run_line "$folded_sibling_body" 'npm ci --prefix bin --ignore-scripts')
  [[ $folded_sibling_line == 2 ]] || fail "list-first folded consumer did not finalize at a sibling key"
  printf '%s\n' 'Positive boundary accepted: a list-first folded run command before a sibling key'

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print
      print "        run: npm ci --prefix bin --ignore-scripts"
      print "          --dry-run"
      in_original_step = 1
      next
    }
    in_original_step && /^      - / { in_original_step = 0 }
    in_original_step {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      next
    }
    { print }
    END { if (targets != 1 || removed_consumers != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-inline-continuation.yml"; then
    fail "fixture preparation failed: expected one complete assets step for inline continuation control"
  fi
  mv -- "$fixture/regression.with-inline-continuation.yml" "$fixture/regression.yml"
  expect_rejected "a plain inline consumer with a non-comment continuation" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print
      next
    }
    targets == 1 && !changed && $0 == "        run: |" {
      print "        run: >"
      print "          npm ci --prefix bin --ignore-scripts"
      changed++
      in_original_run = 1
      next
    }
    in_original_run && /^      - / { in_original_run = 0 }
    in_original_run {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      next
    }
    { print }
    END { if (targets != 1 || changed != 1 || removed_consumers != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-folded-run.yml"; then
    fail "fixture preparation failed: expected exactly one assets consumer block for folded conversion"
  fi
  mv -- "$fixture/regression.with-folded-run.yml" "$fixture/regression.yml"
  folded_shape=$(awk '
    $0 == "      - name: Install pinned asset toolchain" { in_step = 1; next }
    in_step && /^      - / { exit }
    in_step && $0 == "        run: >" { headers++ }
    in_step && /^          [^[:space:]]/ { content++; if ($0 == "          npm ci --prefix bin --ignore-scripts") exact++ }
    END { print headers ":" content ":" exact }
  ' "$fixture/regression.yml")
  [[ $folded_shape == 1:1:1 ]] ||
    fail "fixture preparation failed: folded assets step shape is $folded_shape, expected 1:1:1"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a sole-command folded executable Node consumer"
  fi
  printf '%s\n' 'Positive boundary accepted: a sole-command folded executable run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print
      next
    }
    targets == 1 && !changed && $0 == "        run: |" {
      print "        run: >"
      print "          printf '\''asset toolchain prepared\\n'\''"
      print "          npm ci --prefix bin --ignore-scripts"
      changed++
      in_original_run = 1
      next
    }
    in_original_run && /^      - / { in_original_run = 0 }
    in_original_run {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      next
    }
    { print }
    END { if (targets != 1 || changed != 1 || removed_consumers != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-adjacent-folded-run.yml"; then
    fail "fixture preparation failed: expected exactly one assets consumer block for adjacent folded control"
  fi
  mv -- "$fixture/regression.with-adjacent-folded-run.yml" "$fixture/regression.yml"
  expect_rejected "a folded consumer adjacent to another command" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print ""
      changed = 1
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-blank-run-line.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for blank-line control"
  mv -- "$fixture/regression.with-blank-run-line.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a blank line within a block-style run command"
  fi
  printf '%s\n' 'Positive boundary accepted: a blank line within a block-style run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  if ! awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !added {
      print
      print "          echo prepared-after-consumer"
      added++
      next
    }
    { print }
    END { if (added != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-extra-consumer-command.yml"; then
    fail "fixture preparation failed: expected exactly one assets consumer before list-first conversion"
  fi
  mv -- "$fixture/regression.with-extra-consumer-command.yml" "$fixture/regression.yml"
  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print "      - run: npm ci --prefix bin --ignore-scripts"
      print "        shell: bash"
      in_original_step = 1
      next
    }
    in_original_step && /^      - / { in_original_step = 0 }
    in_original_step {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      if ($0 == "          echo prepared-after-consumer") removed_extras++
      next
    }
    { print }
    END { if (targets != 1 || removed_consumers != 1 || removed_extras != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-list-inline-run.yml"; then
    fail "fixture preparation failed: expected one complete assets step for list-first inline conversion"
  fi
  mv -- "$fixture/regression.with-list-inline-run.yml" "$fixture/regression.yml"
  list_inline_shape=$(awk '
    $0 == "      - run: npm ci --prefix bin --ignore-scripts" { found++; in_step = 1; next }
    in_step && /^      - / { exit }
    in_step && $0 == "        shell: bash" { shells++ }
    END { print found ":" shells }
  ' "$fixture/regression.yml")
  if [[ $list_inline_shape != 1:1 ]] ||
    grep -qF -- 'Install pinned asset toolchain' "$fixture/regression.yml" ||
    grep -qF -- 'prepared-after-consumer' "$fixture/regression.yml"; then
    fail "fixture preparation failed: list-first inline assets shape is not 1:1 with the original body absent"
  fi
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a list-first inline executable Node consumer"
  fi
  printf '%s\n' 'Positive boundary accepted: a list-first inline executable run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      print "      - run: |"
      print "          npm ci --prefix bin --ignore-scripts"
      print "        shell: bash"
      in_original_step = 1
      next
    }
    in_original_step && /^      - / { in_original_step = 0 }
    in_original_step {
      if ($0 == "          npm ci --prefix bin --ignore-scripts") removed_consumers++
      next
    }
    { print }
    END { if (targets != 1 || removed_consumers != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-list-block-run.yml"; then
    fail "fixture preparation failed: expected one complete assets step for list-first block conversion"
  fi
  mv -- "$fixture/regression.with-list-block-run.yml" "$fixture/regression.yml"
  list_block_shape=$(awk '
    $0 == "      - run: |" { headers++; in_step = 1; next }
    in_step && /^      - / { exit }
    in_step && $0 == "          npm ci --prefix bin --ignore-scripts" { consumers++ }
    in_step && $0 == "        shell: bash" { shells++ }
    END { print headers ":" consumers ":" shells }
  ' "$fixture/regression.yml")
  if [[ $list_block_shape != 1:1:1 ]] ||
    grep -qF -- 'Install pinned asset toolchain' "$fixture/regression.yml"; then
    fail "fixture preparation failed: list-first block assets shape is not 1:1:1 with the original body absent"
  fi
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a list-first literal block consumer before a sibling key"
  fi
  printf '%s\n' 'Positive boundary accepted: a list-first literal block run command before a sibling key'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  if ! awk '
    $0 == "      - name: Install pinned asset toolchain" {
      targets++
      in_target = 1
      print
      next
    }
    in_target && !changed && $0 == "        run: |" {
      print "        run: | # install pinned asset toolchain"
      changed++
      in_target = 0
      next
    }
    { print }
    END { if (targets != 1 || changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-commented-block-header.yml"; then
    fail "fixture preparation failed: expected exactly one assets block header for trailing-comment control"
  fi
  mv -- "$fixture/regression.with-commented-block-header.yml" "$fixture/regression.yml"
  commented_header_shape=$(awk '
    $0 == "      - name: Install pinned asset toolchain" { in_step = 1; next }
    in_step && /^      - / { exit }
    in_step && $0 == "        run: | # install pinned asset toolchain" { headers++ }
    in_step && $0 == "          npm ci --prefix bin --ignore-scripts" { consumers++ }
    END { print headers ":" consumers }
  ' "$fixture/regression.yml")
  [[ $commented_header_shape == 1:1 ]] ||
    fail "fixture preparation failed: commented assets block shape is $commented_header_shape, expected 1:1"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a block-style run header with a trailing YAML comment"
  fi
  printf '%s\n' 'Positive boundary accepted: a block-style run header with a trailing YAML comment'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          printf '\''asset toolchain prepared\\n'\''"
      print "      - name: Install assets with a quoted command"
      print "        run: \"npm ci --prefix bin --ignore-scripts\""
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-quoted-inline-run.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for quoted inline control"
  mv -- "$fixture/regression.with-quoted-inline-run.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a quoted inline executable Node consumer"
  fi
  printf '%s\n' 'Positive boundary accepted: a quoted inline executable run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    BEGIN { single_quote = sprintf("%c", 39) }
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          echo asset-toolchain-prepared"
      print "      - name: Install assets with a single-quoted command"
      print "        run: " single_quote "npm ci --prefix bin --ignore-scripts" single_quote
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-single-quoted-inline-run.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for single-quoted inline control"
  mv -- "$fixture/regression.with-single-quoted-inline-run.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a single-quoted inline executable Node consumer"
  fi
  printf '%s\n' 'Positive boundary accepted: a single-quoted inline executable run command'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          echo asset-toolchain-prepared"
      print "      - run: npm ci --prefix bin --ignore-scripts # install assets"
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-inline-comment.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for inline-comment control"
  mv -- "$fixture/regression.with-inline-comment.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a plain inline Node consumer with a trailing YAML comment"
  fi
  printf '%s\n' 'Positive boundary accepted: a plain inline run command with a trailing YAML comment'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          echo asset-toolchain-prepared"
      print "      - run: \"npm ci --prefix bin --ignore-scripts\" # install assets"
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-double-quoted-inline-comment.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for double-quoted comment control"
  mv -- "$fixture/regression.with-double-quoted-inline-comment.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a double-quoted inline Node consumer with a trailing YAML comment"
  fi
  printf '%s\n' 'Positive boundary accepted: a double-quoted inline run command with a trailing YAML comment'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    BEGIN { single_quote = sprintf("%c", 39) }
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          echo asset-toolchain-prepared"
      print "      - run: " single_quote "npm ci --prefix bin --ignore-scripts" single_quote " # install assets"
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-single-quoted-inline-comment.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for single-quoted comment control"
  mv -- "$fixture/regression.with-single-quoted-inline-comment.yml" "$fixture/regression.yml"
  if ! run_fixture; then
    cat "$fixture/output" >&2
    fail "fixture rejected a single-quoted inline Node consumer with a trailing YAML comment"
  fi
  printf '%s\n' 'Positive boundary accepted: a single-quoted inline run command with a trailing YAML comment'
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  awk '
    $0 == "          npm ci --prefix bin --ignore-scripts" && !changed {
      print "          echo asset-toolchain-prepared"
      print "      - run: \"npm ci --prefix bin --ignore-scripts # literal argument\""
      changed = 1
      next
    }
    { print }
    END { if (changed != 1) exit 2 }
  ' "$fixture/regression.yml" >"$fixture/regression.with-quoted-hash.yml" ||
    fail "fixture preparation failed: expected exactly one assets consumer for quoted-hash control"
  mv -- "$fixture/regression.with-quoted-hash.yml" "$fixture/regression.yml"
  expect_rejected "a hash inside a quoted nonmatching Node command" \
    'regression.yml job assets has no expected Node consumer: npm ci --prefix bin --ignore-scripts'

  boundary_block=$(sed -n '/      # actions\/setup-node v6.3.0/,+4p' "$fixture/regression.yml")
  sed -i '/      # actions\/setup-node v6.3.0/,+4d' "$fixture/regression.yml"
  awk -v block="$boundary_block" '
    /^  static:$/ { in_static = 1 }
    in_static && /^    steps:$/ { print; print block; in_static = 0; next }
    { print }
  ' "$fixture/regression.yml" >"$fixture/regression.with-boundary.yml"
  mv -- "$fixture/regression.with-boundary.yml" "$fixture/regression.yml"
  if ! static_body=$(job_body "$fixture/regression.yml" static); then
    fail "fixture preparation failed: neighbouring static job is missing"
  fi
  static_setup_count=$(grep -cF -- 'uses: actions/setup-node@' <<<"$static_body" || true)
  relocated_block=$(sed -n '/      # actions\/setup-node v6.3.0/,+4p' <<<"$static_body")
  [[ $static_setup_count == 1 && $relocated_block == "$boundary_block" ]] ||
    fail "fixture preparation failed: neighbouring static job lacks one identical setup-node block"
  expect_rejected "an identical pin in the neighbouring static job boundary" \
    'regression.yml job assets must use setup-node exactly once (found 0)'
fi

printf "Node workflow contract passed (assets and refactor-metrics use Node %s).\n" "$node_major"
