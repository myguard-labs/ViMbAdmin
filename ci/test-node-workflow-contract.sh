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
    function operator_is_unquoted(value, position, i, char, quote) {
      quote = literal_quote
      for (i = 1; i < position; i++) {
        char = substr(value, i, 1)
        if (quote == "ansi") {
          if (char == "\\") {
            if (i + 1 == position) return 0
            i++
          } else if (char == single_quote) quote = ""
          continue
        }
        if (quote == single_quote) {
          if (char == single_quote) quote = ""
          continue
        }
        if (char == "\\") {
          if (i + 1 == position) return 0
          i++
          continue
        }
        if (quote == "\"") {
          if (char == "\"") quote = ""
          continue
        }
        if (char == "$" && substr(value, i + 1, 1) == "$") {
          i++
          continue
        }
        if (char == "$" && substr(value, i + 1, 1) == single_quote) {
          quote = "ansi"
          i++
        } else if (char == single_quote || char == "\"") quote = char
      }
      return quote == ""
    }
    function shell_without_comment(value, rest, offset, position, previous) {
      rest = value
      while (match(rest, /#/)) {
        position = offset + RSTART
        previous = substr(value, position - 1, 1)
        if ((position == 1 || (previous ~ /[[:space:];&|()<>]/ &&
                              operator_is_unquoted(value, position - 1))) &&
            operator_is_unquoted(value, position)) {
          return substr(value, 1, position - 1)
        }
        offset = position
        rest = substr(value, offset + 1)
      }
      return value
    }
    function advance_literal_context(value, i, char) {
      continued_line = 0
      for (i = 1; i <= length(value); i++) {
        char = substr(value, i, 1)
        if (literal_quote == "ansi") {
          if (char == "\\") {
            if (i == length(value)) continued_line = 1
            i++
          } else if (char == single_quote) literal_quote = ""
        } else if (literal_quote == single_quote) {
          if (char == single_quote) literal_quote = ""
        } else if (char == "\\") {
          if (i == length(value)) continued_line = 1
          i++
        } else if (literal_quote == "\"") {
          if (char == "\"") literal_quote = ""
        } else if (char == "$" && substr(value, i + 1, 1) == "$") {
          i++
        } else if (char == "$" && substr(value, i + 1, 1) == single_quote) {
          literal_quote = "ansi"
          i++
        } else if (char == single_quote || char == "\"") {
          literal_quote = char
        }
      }
    }
    function inside_arithmetic(value, position, i, char, depth) {
      # Only classify this << operator; balance unquoted arithmetic parentheses.
      for (i = 1; i < position; i++) {
        char = substr(value, i, 1)
        if (char !~ /[()]/ || !operator_is_unquoted(value, i)) continue
        if (depth) {
          if (char == "(") depth++
          else depth--
        } else if (substr(value, i, 2) == "((") {
          depth = 2
          i++
        }
      }
      return depth > 0
    }
    function queue_heredocs(value, rest, offset, operator, marker, consumed, i, char, quote, escaped, j, digit, number, base, limit, ansi_delimiter, masked) {
      rest = value
      while (match(rest, /<<-?[[:space:]]*/)) {
        operator = substr(rest, RSTART, RLENGTH)
        rest = substr(rest, RSTART + RLENGTH)
        offset += RSTART + RLENGTH - 1
        if (!operator_is_unquoted(value, offset - RLENGTH + 1)) continue
        if (inside_arithmetic(value, offset - RLENGTH + 1)) continue
        if (substr(rest, 1, 1) == "<") {
          rest = substr(rest, 2)
          offset++
          continue
        }

        # Read only this delimiter word, removing its shell quote syntax.
        marker = quote = ""
        ansi_delimiter = 0
        for (i = 1; i <= length(rest); i++) {
          char = substr(rest, i, 1)
          if (quote == "ansi") {
            if (char == single_quote) {
              quote = ""
            } else if (char == "\\") {
              escaped = substr(rest, ++i, 1)
              if (escaped == "") {
                unsupported_delimiter = 1
                return value
              }
              if (escaped ~ /[\\"?]/ || escaped == single_quote) marker = marker escaped
              else if (escaped ~ /[0-7x]/) {
                base = escaped == "x" ? 16 : 8
                limit = base == 16 ? 2 : 3
                number = 0
                if (base == 8) i--
                for (j = 0; j < limit; j++) {
                  digit = substr(rest, i + 1, 1)
                  if (digit == "" || (base == 8 && digit !~ /[0-7]/) ||
                      (base == 16 && digit !~ /[[:xdigit:]]/)) break
                  number = number * base + index("0123456789abcdef", tolower(digit)) - 1
                  i++
                }
                # Byte escapes only: never guess Unicode, control or NUL delimiters.
                if (!j && base == 16) marker = marker "\\x"
                else if (number % 256 >= 32 && number % 256 < 127) marker = marker sprintf("%c", number % 256)
                else unsupported_delimiter = 1
              } else if (escaped ~ /[abefnrtvEuUc]/) unsupported_delimiter = 1
              else marker = marker "\\" escaped
            } else marker = marker char
          } else if (quote == single_quote) {
            if (char == single_quote) quote = ""
            else marker = marker char
          } else if (char == "\\") {
            escaped = substr(rest, i + 1, 1)
            if (escaped == "") return value
            if (quote == "\"" && escaped !~ /[$`"\\]/) {
              marker = marker char
            } else {
              marker = marker escaped
              i++
            }
          } else if (quote == "\"") {
            if (char == "\"") quote = ""
            else marker = marker char
          } else if (char == "$" && substr(rest, i + 1, 1) == single_quote) {
            quote = "ansi"
            ansi_delimiter = 1
            i++
          } else if (char == "\"" || char == single_quote) {
            quote = char
          } else if (char ~ /[[:space:];|&<>()]/) {
            break
          } else {
            marker = marker char
          }
        }
        if (quote != "") {
          if (ansi_delimiter) unsupported_delimiter = 1
          return value
        }
        consumed = i - 1
        if (consumed > 0) {
          heredoc_delimiters[++heredoc_tail] = marker
          heredoc_strip_tabs[heredoc_tail] = operator ~ /^<<-/
          if (ansi_delimiter) {
            # Hide only this parsed delimiter from the later literal-quote scan.
            masked = substr(rest, 1, consumed)
            gsub(/./, "x", masked)
            value = substr(value, 1, offset) masked substr(value, offset + consumed + 1)
          }
        }
        rest = substr(rest, consumed + 1)
        offset += consumed
      }
      return value
    }
    function update_control_depth(value, i, char, word, at_command) {
      # Count only if/fi at unquoted command boundaries; other grammar is outside this check.
      at_command = !literal_quote && !continued_line
      for (i = 1; i <= length(value); i++) {
        char = substr(value, i, 1)
        if (test_expression) {
          if (substr(value, i) ~ /^\]\]([[:space:];&|()]|$)/ &&
              (i == 1 || (substr(value, i - 1, 1) ~ /[[:space:]]/ &&
                          operator_is_unquoted(value, i - 1))) &&
              operator_is_unquoted(value, i)) {
            test_expression = 0
            at_command = 0
            i++
          }
          continue
        }
        if (char ~ /[;|&]/ && operator_is_unquoted(value, i)) {
          at_command = 1
          continue
        }
        if (!at_command || char ~ /[[:space:]]/) continue
        at_command = 0
        word = substr(value, i)
        if (word ~ /^\[\[([[:space:]]|$)/ && operator_is_unquoted(value, i)) {
          test_expression = 1
          i++
          continue
        }
        if (word !~ /^(if|fi|then|else|elif|while|until|do|!|time|\{)([[:space:];&|()]|$)/) continue
        sub(/[[:space:];&|()].*$/, "", word)
        if (word == "if") control_depth++
        else if (word == "fi" && control_depth) control_depth--
        at_command = word != "fi"
        i += length(word) - 1
      }
    }
    function reset_literal_context(delimiter) {
      for (delimiter in heredoc_delimiters) delete heredoc_delimiters[delimiter]
      for (delimiter in heredoc_strip_tabs) delete heredoc_strip_tabs[delimiter]
      control_depth = 0
      test_expression = 0
      unsupported_delimiter = 0
      continued_list = 0
      continued_line = 0
      literal_quote = ""
      heredoc_head = 1
      heredoc_tail = 0
      literal_indent = 0
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
      if (in_run && $0 ~ /^ *$/) {
        if (run_style == "|") {
          if (heredoc_head <= heredoc_tail && heredoc_delimiters[heredoc_head] == "" &&
              substr($0, literal_indent + 1) == "") {
            delete heredoc_delimiters[heredoc_head]
            delete heredoc_strip_tabs[heredoc_head++]
          }
          continued_line = 0
        }
        next
      }
      if (in_run && indent <= run_indent) {
        finish_folded()
        in_run = 0
      }

      if ($0 ~ /^(        run:|      - run:)([[:space:]]|$)/) {
        run_indent = ($0 ~ /^      - run:/) ? indent + 2 : indent
        value = trim(substr($0, index($0, "run:") + 4))
        if (value ~ /^[|>]([+-][1-9]?|[1-9][+-]?)?([[:space:]]+#.*|[[:space:]]*)$/) {
          run_style = substr(value, 1, 1)
          if (run_style == "|") reset_literal_context()
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
        } else {
          if (value != "" && (!literal_indent || indent < literal_indent)) {
            literal_indent = indent
          }
          if (heredoc_head <= heredoc_tail) {
            shell_line = substr($0, literal_indent + 1)
            if (heredoc_strip_tabs[heredoc_head]) sub(/^\t+/, "", shell_line)
            if (shell_line == heredoc_delimiters[heredoc_head]) {
              delete heredoc_delimiters[heredoc_head]
              delete heredoc_strip_tabs[heredoc_head++]
            }
            next
          }

          shell_code = shell_without_comment(substr($0, literal_indent + 1))
          if (value == consumer && !literal_quote && !continued_line && !control_depth && !continued_list && !test_expression && !unsupported_delimiter) {
            print NR
            exit
          }
          if (shell_code != "") shell_code = queue_heredocs(shell_code)
          update_control_depth(shell_code)
          if (value !~ /^#/ && value != "") {
            continued_list = shell_code ~ /(&&|\|\|)[[:space:]]*$/
          }
          advance_literal_context(shell_code)
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

  heredoc_body=$'        run: |\n          cat <<\'PAYLOAD\'\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  heredoc_line=$(consumer_run_line "$heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $heredoc_line ]] || fail "literal heredoc body was accepted as an executable Node consumer"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a literal heredoc body; assertion=literal heredoc body was accepted as an executable Node consumer'

  commented_heredoc_body=$'        run: |\n          # cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  commented_heredoc_line=$(consumer_run_line "$commented_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$commented_heredoc_line" == 3 ]] || fail "executable consumer after a commented heredoc marker was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a commented heredoc marker'

  trailing_comment_heredoc_body=$'        run: |\n          echo done # see <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  trailing_comment_heredoc_line=$(consumer_run_line "$trailing_comment_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$trailing_comment_heredoc_line" == 3 ]] || fail "executable consumer after heredoc text in a trailing comment was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after heredoc text in a trailing comment'

  quoted_heredoc_text_body=$'        run: |\n          printf \'%s\\n\' "<<PAYLOAD"\n          npm ci --prefix bin --ignore-scripts'
  quoted_heredoc_text_line=$(consumer_run_line "$quoted_heredoc_text_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$quoted_heredoc_text_line" == 3 ]] || fail "executable consumer after quoted heredoc text was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after quoted heredoc text'

  quoted_then_real_heredoc_body=$'        run: |\n          echo "<<" ; cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  quoted_then_real_heredoc_line=$(consumer_run_line "$quoted_then_real_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_then_real_heredoc_line ]] || fail "literal heredoc after quoted operator text accepted its body as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a literal heredoc after quoted operator text; assertion=literal heredoc after quoted operator text accepted its body as executable'

  apostrophe_then_heredoc_body=$'        run: |\n          echo "don\'t" ; cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  apostrophe_then_heredoc_line=$(consumer_run_line "$apostrophe_then_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $apostrophe_then_heredoc_line ]] || fail "apostrophe in double quotes hid a following literal heredoc"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a heredoc after a double-quoted apostrophe; assertion=apostrophe in double quotes hid a following literal heredoc'

  escaped_quote_then_heredoc_body=$'        run: |\n          echo "a \\"quoted\\" value" ; cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  escaped_quote_then_heredoc_line=$(consumer_run_line "$escaped_quote_then_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $escaped_quote_then_heredoc_line ]] || fail "escaped double quotes hid a following literal heredoc"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a heredoc after escaped double quotes; assertion=escaped double quotes hid a following literal heredoc'

  multiple_heredoc_body=$'        run: |\n          cat <<FIRST <<SECOND\n          fixture one\n          FIRST\n          npm ci --prefix bin --ignore-scripts\n          SECOND'
  multiple_heredoc_line=$(consumer_run_line "$multiple_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $multiple_heredoc_line ]] || fail "second literal heredoc body was accepted as an executable Node consumer"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a second literal heredoc body; assertion=second literal heredoc body was accepted as an executable Node consumer'

  indented_delimiter_body=$'        run: |\n          cat <<PAYLOAD\n            PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  indented_delimiter_line=$(consumer_run_line "$indented_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $indented_delimiter_line ]] || fail "consumer after a space-indented heredoc delimiter was accepted as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer after a space-indented non-delimiter; assertion=consumer after a space-indented heredoc delimiter was accepted as executable'

  tab_stripped_heredoc_body=$'        run: |\n          cat <<-PAYLOAD\n          \tPAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  tab_stripped_heredoc_line=$(consumer_run_line "$tab_stripped_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$tab_stripped_heredoc_line" == 4 ]] || fail "executable consumer after a tab-stripped heredoc delimiter was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a tab-stripped heredoc delimiter'

  post_multiple_heredoc_body=$'        run: |\n          cat <<FIRST <<SECOND\n          fixture one\n          FIRST\n          fixture two\n          SECOND\n          npm ci --prefix bin --ignore-scripts'
  post_multiple_heredoc_line=$(consumer_run_line "$post_multiple_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_multiple_heredoc_line" == 7 ]] || fail "executable consumer after multiple closed literal heredocs was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after multiple closed literal heredocs'

  unterminated_heredoc_body=$'        run: |\n          cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  unterminated_heredoc_line=$(consumer_run_line "$unterminated_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unterminated_heredoc_line ]] || fail "unterminated literal heredoc body was accepted as an executable Node consumer"
  printf '%s\n' 'Malformed boundary rejected: an exact consumer inside an unterminated literal heredoc body'

  post_heredoc_body=$'        run: |\n          cat <<PAYLOAD\n          fixture text\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  post_heredoc_line=$(consumer_run_line "$post_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_heredoc_line" == 5 ]] || fail "executable consumer after a closed literal heredoc was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a closed literal heredoc'

  deep_literal_heredoc_body=$'        run: |\n            cat <<PAYLOAD\n            fixture text\n            PAYLOAD\n            npm ci --prefix bin --ignore-scripts'
  deep_literal_heredoc_line=$(consumer_run_line "$deep_literal_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$deep_literal_heredoc_line" == 5 ]] || fail "executable consumer in a deeply indented literal block was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer in a deeply indented literal block'

  escaped_heredoc_body=$'        run: |\n          cat <<\\PAYLOAD\n          fixture text\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  escaped_heredoc_line=$(consumer_run_line "$escaped_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$escaped_heredoc_line" == 5 ]] || fail "executable consumer after an escaped heredoc marker was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after an escaped heredoc marker'

  redirected_heredoc_body=$'        run: |\n          cat <<PAYLOAD>output.txt\n          fixture text\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  redirected_heredoc_line=$(consumer_run_line "$redirected_heredoc_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$redirected_heredoc_line" == 5 ]] || fail "executable consumer after an adjacent heredoc redirection was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after an adjacent heredoc redirection'

  arithmetic_shift_body=$'        run: |\n          size=$((1 << 20))\n          npm ci --prefix bin --ignore-scripts'
  arithmetic_shift_line=$(consumer_run_line "$arithmetic_shift_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$arithmetic_shift_line" == 3 ]] || fail "executable consumer after an arithmetic shift was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after an arithmetic shift'

  here_string_body=$'        run: |\n          cat <<<"fixture text"\n          npm ci --prefix bin --ignore-scripts'
  here_string_line=$(consumer_run_line "$here_string_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$here_string_line" == 3 ]] || fail "here-string syntax was mistaken for a heredoc body"
  printf '%s\n' 'Positive boundary accepted: consumer after a here-string'

  conditional_body=$'        run: |\n          if false; then\n            npm ci --prefix bin --ignore-scripts\n          fi'
  conditional_line=$(consumer_run_line "$conditional_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $conditional_line ]] || fail "consumer inside an unreachable conditional was accepted as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer inside an unreachable conditional; assertion=consumer inside an unreachable conditional was accepted as executable'

  short_circuit_body=$'        run: |\n          false &&\n            npm ci --prefix bin --ignore-scripts'
  short_circuit_line=$(consumer_run_line "$short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $short_circuit_line ]] || fail "consumer inside a short-circuited AND-list was accepted as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer inside a short-circuited AND-list; assertion=consumer inside a short-circuited AND-list was accepted as executable'

  commented_short_circuit_body=$'        run: |\n          false && # skip installation\n            npm ci --prefix bin --ignore-scripts'
  commented_short_circuit_line=$(consumer_run_line "$commented_short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $commented_short_circuit_line ]] || fail "consumer after a commented short-circuit operator was accepted as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer after a commented short-circuit operator; assertion=consumer after a commented short-circuit operator was accepted as executable'

  commented_operator_text_body=$'        run: |\n          echo done # not an operator: &&\n          npm ci --prefix bin --ignore-scripts'
  commented_operator_text_line=$(consumer_run_line "$commented_operator_text_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$commented_operator_text_line" == 3 ]] || fail "executable consumer after a commented operator token was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a commented operator token'

  quoted_hash_short_circuit_body=$'        run: |\n          echo "text # literal" && # skip installation\n            npm ci --prefix bin --ignore-scripts'
  quoted_hash_short_circuit_line=$(consumer_run_line "$quoted_hash_short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_hash_short_circuit_line ]] || fail "consumer after a quoted hash and short-circuit operator was accepted as executable"
  printf '%s\n' 'Negative control rejected: an exact consumer after a quoted hash and short-circuit operator; assertion=consumer after a quoted hash and short-circuit operator was accepted as executable'

  apostrophe_short_circuit_body=$'        run: |\n          echo "it\'s skipped" && # skip installation\n            npm ci --prefix bin --ignore-scripts'
  apostrophe_short_circuit_line=$(consumer_run_line "$apostrophe_short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $apostrophe_short_circuit_line ]] || fail "apostrophe in double quotes hid a following shell comment"
  printf '%s\n' 'Negative control rejected: an exact consumer after a double-quoted apostrophe and short-circuit operator; assertion=apostrophe in double quotes hid a following shell comment'

  escaped_quote_short_circuit_body=$'        run: |\n          echo "a \\"quoted\\" value" && # skip installation\n            npm ci --prefix bin --ignore-scripts'
  escaped_quote_short_circuit_line=$(consumer_run_line "$escaped_quote_short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $escaped_quote_short_circuit_line ]] || fail "escaped double quotes hid a following shell comment"
  printf '%s\n' 'Negative control rejected: an exact consumer after escaped double quotes and a short-circuit operator; assertion=escaped double quotes hid a following shell comment'

  post_short_circuit_body=$'        run: |\n          false &&\n            echo skipped\n          npm ci --prefix bin --ignore-scripts'
  post_short_circuit_line=$(consumer_run_line "$post_short_circuit_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_short_circuit_line" == 4 ]] || fail "executable consumer after a completed AND-list was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a completed AND-list'

  unterminated_conditional_body=$'        run: |\n          if false; then\n            npm ci --prefix bin --ignore-scripts'
  unterminated_conditional_line=$(consumer_run_line "$unterminated_conditional_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unterminated_conditional_line ]] || fail "consumer inside an unterminated conditional was accepted as executable"
  printf '%s\n' 'Malformed boundary rejected: an exact consumer inside an unterminated conditional'

  post_conditional_body=$'        run: |\n          if false; then\n            echo skipped\n          fi\n          npm ci --prefix bin --ignore-scripts'
  post_conditional_line=$(consumer_run_line "$post_conditional_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_conditional_line" == 5 ]] || fail "executable consumer after a closed conditional was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a closed conditional'

  single_line_conditional_body=$'        run: |\n          if false; then echo skipped; fi\n          npm ci --prefix bin --ignore-scripts'
  single_line_conditional_line=$(consumer_run_line "$single_line_conditional_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$single_line_conditional_line" == 3 ]] || fail "executable consumer after a single-line conditional was not accepted"
  printf '%s\n' 'Positive boundary accepted: an exact consumer after a single-line conditional'

  # These physical-line controls exercise the bounded shell context directly.
  multiline_quote_body=$'        run: |\n          printf "%s" "payload\n          npm ci --prefix bin --ignore-scripts\n          "'
  multiline_quote_line=$(consumer_run_line "$multiline_quote_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $multiline_quote_line ]] || fail "multiline double-quoted text was accepted as an executable consumer"
  printf '%s\n' 'Negative control rejected: multiline double-quoted consumer text; assertion=multiline double-quoted text was accepted as an executable consumer'

  multiline_single_quote_body=$'        run: |\n          printf "%s" \'payload\n          npm ci --prefix bin --ignore-scripts\n          \'\n          npm ci --prefix bin --ignore-scripts'
  multiline_single_quote_line=$(consumer_run_line "$multiline_single_quote_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$multiline_single_quote_line" == 5 ]] || fail "consumer after multiline single-quote closure was not accepted at its executable boundary"
  printf '%s\n' 'Positive boundary accepted: consumer after multiline single-quote closure'

  multiline_quote_close_body=$'        run: |\n          printf "%s" "payload\n          # literal quote text <<PAYLOAD\n          if false; then\n          "\n          npm ci --prefix bin --ignore-scripts'
  multiline_quote_close_line=$(consumer_run_line "$multiline_quote_close_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$multiline_quote_close_line" == 6 ]] || fail "consumer after multiline double-quote closure was not accepted"
  printf '%s\n' 'Positive boundary accepted: consumer after multiline double-quote closure and quoted shell syntax'

  escaped_multiline_quote_body=$'        run: |\n          printf "%s" "payload\\"\n          npm ci --prefix bin --ignore-scripts\n          "\n          npm ci --prefix bin --ignore-scripts'
  escaped_multiline_quote_line=$(consumer_run_line "$escaped_multiline_quote_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$escaped_multiline_quote_line" == 5 ]] || fail "escaped quote closed a multiline shell string prematurely"
  printf '%s\n' 'Positive boundary accepted: consumer only after an escaped multiline quote closes'

  continuation_body=$'        run: |\n          printf "%s" \\\n          npm ci --prefix bin --ignore-scripts'
  continuation_line=$(consumer_run_line "$continuation_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $continuation_line ]] || fail "backslash-continued argument was accepted as an executable consumer"
  printf '%s\n' 'Negative control rejected: odd-backslash continuation consumer; assertion=backslash-continued argument was accepted as an executable consumer'

  post_continuation_body=$'        run: |\n          printf "%s" \\\\\\\n          npm ci --prefix bin --ignore-scripts\n          npm ci --prefix bin --ignore-scripts'
  post_continuation_line=$(consumer_run_line "$post_continuation_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_continuation_line" == 4 ]] || fail "consumer after an odd-backslash continuation was not accepted at its standalone boundary"
  printf '%s\n' 'Positive boundary accepted: standalone consumer after an odd-backslash continuation'

  even_backslash_body=$'        run: |\n          printf "%s" \\\\\n          npm ci --prefix bin --ignore-scripts'
  even_backslash_line=$(consumer_run_line "$even_backslash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$even_backslash_line" == 3 ]] || fail "even trailing backslashes incorrectly continued the next consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after even trailing backslashes'

  backslash_space_body=$'        run: |\n          printf "%s" \\ \n          npm ci --prefix bin --ignore-scripts'
  backslash_space_line=$(consumer_run_line "$backslash_space_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$backslash_space_line" == 3 ]] || fail "backslash followed by a space incorrectly continued the next consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after a backslash followed by a space'

  blank_continuation_body=$'        run: |\n          printf "%s" \\\n\n          npm ci --prefix bin --ignore-scripts'
  blank_continuation_line=$(consumer_run_line "$blank_continuation_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$blank_continuation_line" == 4 ]] || fail "blank physical line did not end a backslash continuation"
  printf '%s\n' 'Positive boundary accepted: consumer after a blank continuation line'

  commented_conditional_close_body=$'        run: |\n          if false; then # fi\n            npm ci --prefix bin --ignore-scripts\n          fi'
  commented_conditional_close_line=$(consumer_run_line "$commented_conditional_close_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $commented_conditional_close_line ]] || fail "commented fi token hid an unreachable conditional"
  printf '%s\n' 'Negative control rejected: conditional with a commented fi token; assertion=commented fi token hid an unreachable conditional'

  post_commented_conditional_body=$'        run: |\n          if false; then # fi\n            echo skipped\n          fi # if false; then\n          npm ci --prefix bin --ignore-scripts'
  post_commented_conditional_line=$(consumer_run_line "$post_commented_conditional_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_commented_conditional_line" == 5 ]] || fail "consumer after a comment-stripped conditional closure was not accepted"
  printf '%s\n' 'Positive boundary accepted: consumer after a comment-stripped conditional closure'

  quoted_conditional_terminator_body=$'        run: |\n          if false; then echo " fi "\n            npm ci --prefix bin --ignore-scripts\n          fi'
  quoted_conditional_terminator_line=$(consumer_run_line "$quoted_conditional_terminator_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_conditional_terminator_line ]] || fail "quoted fi argument suppressed conditional tracking"
  printf '%s\n' 'Negative control rejected: quoted conditional terminator argument; assertion=quoted fi argument suppressed conditional tracking'

  quoted_separator_terminator_body=$'        run: |\n          if false; then echo "; fi "\n            npm ci --prefix bin --ignore-scripts\n          fi'
  quoted_separator_terminator_line=$(consumer_run_line "$quoted_separator_terminator_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_separator_terminator_line ]] || fail "quoted semicolon and fi suppressed conditional tracking"
  printf '%s\n' 'Negative control rejected: quoted semicolon and conditional terminator; assertion=quoted semicolon and fi suppressed conditional tracking'

  unquoted_fi_argument_body=$'        run: |\n          if false; then echo fi\n            npm ci --prefix bin --ignore-scripts\n          fi'
  unquoted_fi_argument_line=$(consumer_run_line "$unquoted_fi_argument_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unquoted_fi_argument_line ]] || fail "unquoted fi argument suppressed conditional tracking"
  printf '%s\n' 'Negative control rejected: unquoted conditional terminator argument; assertion=unquoted fi argument suppressed conditional tracking'

  bracket_if_operand_body=$'        run: |\n          [[ ready == ready || if == other ]]\n          npm ci --prefix bin --ignore-scripts'
  bracket_if_operand_line=$(consumer_run_line "$bracket_if_operand_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$bracket_if_operand_line" == 3 ]] || fail "double-bracket if operand hid a later executable consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after a double-bracket if operand'

  bracket_fi_operand_body=$'        run: |\n          if false; then\n            [[ ready == ready && fi == other ]]\n            npm ci --prefix bin --ignore-scripts\n          fi'
  bracket_fi_operand_line=$(consumer_run_line "$bracket_fi_operand_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $bracket_fi_operand_line ]] || fail "double-bracket fi operand closed a real conditional"
  printf '%s\n' 'Negative control rejected: consumer after a double-bracket fi operand inside a real conditional'

  multiline_bracket_body=$'        run: |\n          [[ ready == ready ||\n             if == other ]]\n          npm ci --prefix bin --ignore-scripts'
  multiline_bracket_line=$(consumer_run_line "$multiline_bracket_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$multiline_bracket_line" == 4 ]] || fail "multiline double-bracket operand changed conditional depth"
  printf '%s\n' 'Positive boundary accepted: consumer after a multiline double-bracket expression'

  unclosed_bracket_body=$'        run: |\n          [[ ready == ready ||\n          npm ci --prefix bin --ignore-scripts'
  unclosed_bracket_line=$(consumer_run_line "$unclosed_bracket_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unclosed_bracket_line ]] || fail "unclosed double-bracket expression accepted an operand as a consumer"
  printf '%s\n' 'Malformed boundary rejected: consumer inside an unclosed double-bracket expression'

  quoted_bracket_close_body=$'        run: |\n          [[ "]]" == ready || if == other ]] ; if false; then\n            npm ci --prefix bin --ignore-scripts\n          fi'
  quoted_bracket_close_line=$(consumer_run_line "$quoted_bracket_close_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_bracket_close_line ]] || fail "quoted double-bracket closure hid a following real conditional"
  printf '%s\n' 'Negative control rejected: consumer inside a conditional after quoted double-bracket text'

  for bracket_operand in '"]]"' '\]\]' ']]suffix' 'word\ ]]'; do
    printf -v bracket_operand_body '        run: |\n          [[ ready == %s || if == other ]]\n          npm ci --prefix bin --ignore-scripts' "$bracket_operand"
    bracket_operand_line=$(consumer_run_line "$bracket_operand_body" 'npm ci --prefix bin --ignore-scripts')
    [[ "$bracket_operand_line" == 3 ]] || fail "double-bracket operand text was mistaken for the expression closure: $bracket_operand"
    printf -v unclosed_bracket_operand_body '        run: |\n          [[ ready == %s ||\n          npm ci --prefix bin --ignore-scripts' "$bracket_operand"
    unclosed_bracket_operand_line=$(consumer_run_line "$unclosed_bracket_operand_body" 'npm ci --prefix bin --ignore-scripts')
    [[ -z $unclosed_bracket_operand_line ]] || fail "double-bracket operand text exposed a consumer inside the expression: $bracket_operand"
  done
  printf '%s\n' 'Positive and negative boundaries: quoted, escaped and suffixed bracket operands stay inside the expression'

  bracket_command_list_body=$'        run: |\n          [[ ready == ready || if == other ]] && if false; then echo skipped; fi\n          echo "[["; echo [[; echo fi\n          npm ci --prefix bin --ignore-scripts'
  bracket_command_list_line=$(consumer_run_line "$bracket_command_list_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$bracket_command_list_line" == 4 ]] || fail "double-bracket closure or bracket arguments hid a later executable consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after a double-bracket expression and a real conditional list'

  bracket_prefix_commands=(
    '! [[ x == y || KEYWORD == z ]]'
    'time [[ x == y || KEYWORD == z ]]'
    '{ [[ x == y || KEYWORD == z ]]; }'
    'if false; then :; elif [[ x == y || KEYWORD == z ]]; then :; fi'
    'while [[ x == y || KEYWORD == z ]]; do :; done'
    'until [[ x == x || KEYWORD == z ]]; do :; done'
    'for item in skipped; do [[ x == y || KEYWORD == z ]]; done'
  )
  for bracket_prefix_command in "${bracket_prefix_commands[@]}"; do
    printf -v bracket_prefix_body '        run: |\n          %s\n          npm ci --prefix bin --ignore-scripts' "${bracket_prefix_command/KEYWORD/if}"
    bracket_prefix_line=$(consumer_run_line "$bracket_prefix_body" 'npm ci --prefix bin --ignore-scripts')
    [[ "$bracket_prefix_line" == 3 ]] || fail "prefixed double-bracket if operand hid a later executable consumer: $bracket_prefix_command"
    printf -v nested_bracket_prefix_body '        run: |\n          if false; then\n            %s\n            npm ci --prefix bin --ignore-scripts\n          fi' "${bracket_prefix_command/KEYWORD/fi}"
    nested_bracket_prefix_line=$(consumer_run_line "$nested_bracket_prefix_body" 'npm ci --prefix bin --ignore-scripts')
    [[ -z $nested_bracket_prefix_line ]] || fail "prefixed double-bracket fi operand closed a real conditional: $bracket_prefix_command"
  done
  printf '%s\n' 'Positive and negative boundaries: finite command prefixes preserve double-bracket operand context'

  concatenated_delimiter_body=$'        run: |\n          cat <<\'PAY\'LOAD\n          fixture text\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  concatenated_delimiter_line=$(consumer_run_line "$concatenated_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$concatenated_delimiter_line" == 5 ]] || fail "concatenated heredoc delimiter hid a later executable consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after a concatenated heredoc delimiter; assertion=concatenated heredoc delimiter hid a later executable consumer'

  mixed_delimiter_body=$'        run: |\n          cat <<P\'A\'Y"LO"\\AD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  mixed_delimiter_line=$(consumer_run_line "$mixed_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$mixed_delimiter_line" == 5 ]] || fail "mixed-quote heredoc did not distinguish its body from a later executable consumer"
  printf '%s\n' 'Positive boundary accepted: consumer only after a mixed-quote heredoc delimiter; assertion=mixed-quote heredoc did not distinguish its body from a later executable consumer'

  double_quote_backslash_delimiter_body=$'        run: |\n          cat <<"PAY\\LOAD"\n          fixture text\n          PAY\\LOAD\n          npm ci --prefix bin --ignore-scripts'
  double_quote_backslash_delimiter_line=$(consumer_run_line "$double_quote_backslash_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$double_quote_backslash_delimiter_line" == 5 ]] || fail "literal backslash in a double-quoted heredoc delimiter was removed"
  printf '%s\n' 'Positive boundary accepted: consumer after a double-quoted delimiter with a literal backslash'

  escaped_dollar_delimiter_body=$'        run: |\n          cat <<"PAY\\$LOAD"\n          fixture text\n          PAY$LOAD\n          npm ci --prefix bin --ignore-scripts'
  escaped_dollar_delimiter_line=$(consumer_run_line "$escaped_dollar_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$escaped_dollar_delimiter_line" == 5 ]] || fail "escaped dollar in a double-quoted delimiter was not unquoted"
  printf '%s\n' 'Positive boundary accepted: consumer after a double-quoted delimiter with an escaped dollar'

  ansi_delimiters=(
    "\$'PAYLOAD'" "\$'PAY'LOAD" "P\$'AY'\"LOAD\"" "\$'PAY\\x4cOAD'"
    "\$'PAY\\114OAD'" "\$'PAY\\x4c\\x4fAD'" "\$'PAY\\x4cOAD'\$'2'"
    "\$'PAY\\\\LOAD'" "\$'PAY\\'LOAD'" "\$'PAY\\\"LOAD'" "\$'PAY\\?LOAD'"
    "\$'PAY\\qLOAD'" "\$'PAY\\xLOAD'" "\$'PAY\\1x4LOAD'"
  )
  ansi_markers=(
    PAYLOAD PAYLOAD PAYLOAD PAYLOAD PAYLOAD PAYLOAD PAYLOAD2
    'PAY\LOAD' "PAY'LOAD" 'PAY"LOAD' 'PAY?LOAD' 'PAY\qLOAD' 'PAY\xLOAD' ''
  )
  for ansi_case in "${!ansi_delimiters[@]}"; do
    printf -v ansi_delimiter_body '        run: |\n          cat <<%s\n          npm ci --prefix bin --ignore-scripts\n          %s\n          npm ci --prefix bin --ignore-scripts' \
      "${ansi_delimiters[ansi_case]}" "${ansi_markers[ansi_case]}"
    ansi_delimiter_line=$(consumer_run_line "$ansi_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
    if [[ -n ${ansi_markers[ansi_case]} ]]; then
      [[ "$ansi_delimiter_line" == 5 ]] || fail "ANSI-C heredoc delimiter hid a later executable consumer: ${ansi_delimiters[ansi_case]}"
    else
      [[ -z $ansi_delimiter_line ]] || fail "ANSI-C control-byte delimiter accepted a consumer"
    fi
  done
  printf '%s\n' 'Positive boundary accepted: consumers only after bounded ANSI-C heredoc delimiters'

  ansi_empty_body=$'        run: |\n          cat <<$\'\'\n          npm ci --prefix bin --ignore-scripts\n\n          npm ci --prefix bin --ignore-scripts'
  ansi_empty_line=$(consumer_run_line "$ansi_empty_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$ansi_empty_line" == 5 ]] || fail "empty ANSI-C heredoc delimiter did not distinguish body and consumer"
  printf '%s\n' 'Positive boundary accepted: consumer only after an empty ANSI-C heredoc delimiter'

  for unsupported_ansi_delimiter in "\$'PAY\\u004cOAD'" "\$'PAY\\U0000004cOAD'" "\$'PAY\\cLOAD'" "\$'PAY\\0LOAD'" "\$'PAY\\x00LOAD'" "\$'PAY\\x80LOAD'" "\$'PAY\\nLOAD'" "\$'PAYLOAD" "\$'PAYLOAD\\'" "\$'PAYLOAD\\"; do
    printf -v unsupported_ansi_body '        run: |\n          cat <<%s\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts' "$unsupported_ansi_delimiter"
    unsupported_ansi_line=$(consumer_run_line "$unsupported_ansi_body" 'npm ci --prefix bin --ignore-scripts')
    [[ -z $unsupported_ansi_line ]] || fail "unsupported or malformed ANSI-C heredoc delimiter accepted a consumer: $unsupported_ansi_delimiter"
  done
  printf '%s\n' 'Malformed boundary rejected: unsupported and unclosed ANSI-C heredoc delimiters'

  quoted_ansi_text_body=$'        run: |\n          printf \'%s\\n\' "$\'PAYLOAD\'"\n          cat <<"$\'PAYLOAD\'"\n          npm ci --prefix bin --ignore-scripts\n          $\'PAYLOAD\'\n          npm ci --prefix bin --ignore-scripts'
  quoted_ansi_text_line=$(consumer_run_line "$quoted_ansi_text_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$quoted_ansi_text_line" == 6 ]] || fail "ANSI-C syntax outside an unquoted heredoc delimiter was evaluated"
  printf '%s\n' 'Positive boundary accepted: quoted ANSI-C text stays literal outside delimiter normalization'

  context_reset_body=$'        run: |\n          [[ ready == ready ||\n        run: |\n          cat <<$\'PAY\\u004cOAD\'\n          PAYLOAD\n        run: |\n          npm ci --prefix bin --ignore-scripts'
  context_reset_line=$(consumer_run_line "$context_reset_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$context_reset_line" == 7 ]] || fail "unsupported delimiter or double-bracket context leaked into a later run block"
  printf '%s\n' 'Positive boundary accepted: unsupported delimiter and double-bracket contexts reset for a later run block'

  multiple_ansi_heredocs_body=$'        run: |\n          cat <<A <<$\'B\' <<<x\n          fixture A\n          A\n          npm ci --prefix bin --ignore-scripts\n          B\n          npm ci --prefix bin --ignore-scripts'
  multiple_ansi_heredocs_line=$(consumer_run_line "$multiple_ansi_heredocs_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$multiple_ansi_heredocs_line" == 7 ]] || fail "ANSI-C delimiter after another heredoc lost its source offset"
  printf '%s\n' 'Positive boundary accepted: consumer after mixed heredocs and a here-string on one line'

  post_here_string_ansi_body=$'        run: |\n          cat <<<x <<$\'B\'\n          npm ci --prefix bin --ignore-scripts\n          B\n          npm ci --prefix bin --ignore-scripts'
  post_here_string_ansi_line=$(consumer_run_line "$post_here_string_ansi_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_here_string_ansi_line" == 5 ]] || fail "ANSI-C delimiter after a here-string lost its source offset"
  printf '%s\n' 'Positive boundary accepted: consumer after an ANSI-C heredoc following a here-string'

  mixed_ansi_bracket_body=$'        run: |\n          if [[ x == y || if == z ]]; then cat <<$\'PAY\\\'LOAD\'\n          npm ci --prefix bin --ignore-scripts\n          PAY\'LOAD\n          npm ci --prefix bin --ignore-scripts\n          fi\n          npm ci --prefix bin --ignore-scripts'
  mixed_ansi_bracket_line=$(consumer_run_line "$mixed_ansi_bracket_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$mixed_ansi_bracket_line" == 7 ]] || fail "ANSI-C delimiter masking changed real conditional or double-bracket context"
  printf '%s\n' 'Positive boundary accepted: consumer only after combined ANSI-C heredoc, double-bracket and conditional closure'

  ansi_commented_fi_body=$'        run: |\n          if false; then cat <<$\'A\\\'B\' # ; fi\n          body\n          A\'B\n          npm ci --prefix bin --ignore-scripts\n          fi'
  ansi_commented_fi_line=$(consumer_run_line "$ansi_commented_fi_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $ansi_commented_fi_line ]] || fail "comment after an escaped ANSI-C delimiter closed a real conditional"
  printf '%s\n' 'Negative control rejected: commented fi after an escaped ANSI-C delimiter'

  ansi_comment_delimiters=("\$'A\\'B'" "\$'A\\\\'" "\$'A\\\\\\'B'")
  ansi_comment_markers=("A'B" "A\\" "A\\'B")
  for ansi_comment_case in "${!ansi_comment_delimiters[@]}"; do
    printf -v ansi_commented_if_body '        run: |\n          cat <<%s # ; if false; then\n          npm ci --prefix bin --ignore-scripts\n          %s\n          npm ci --prefix bin --ignore-scripts' \
      "${ansi_comment_delimiters[ansi_comment_case]}" "${ansi_comment_markers[ansi_comment_case]}"
    ansi_commented_if_line=$(consumer_run_line "$ansi_commented_if_body" 'npm ci --prefix bin --ignore-scripts')
    [[ "$ansi_commented_if_line" == 5 ]] || fail "comment after an ANSI-C delimiter opened a fake conditional"
    printf -v ansi_nested_comment_body '        run: |\n          if false; then cat <<%s # ; fi\n          body\n          %s\n          npm ci --prefix bin --ignore-scripts\n          fi' \
      "${ansi_comment_delimiters[ansi_comment_case]}" "${ansi_comment_markers[ansi_comment_case]}"
    ansi_nested_comment_line=$(consumer_run_line "$ansi_nested_comment_body" 'npm ci --prefix bin --ignore-scripts')
    [[ -z $ansi_nested_comment_line ]] || fail "ANSI-C backslash parity exposed a conditional consumer"
  done
  printf '%s\n' 'Positive and negative boundaries: odd and even ANSI-C backslashes preserve trailing comments'

  ansi_delimiter_hash_body=$'        run: |\n          cat <<$\'A\\\' # ; fi B\'\n          npm ci --prefix bin --ignore-scripts\n          A\' # ; fi B\n          npm ci --prefix bin --ignore-scripts'
  ansi_delimiter_hash_line=$(consumer_run_line "$ansi_delimiter_hash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$ansi_delimiter_hash_line" == 5 ]] || fail "escaped ANSI-C apostrophe exposed a hash inside the delimiter"
  printf '%s\n' 'Positive boundary accepted: a hash after an escaped apostrophe stays inside the ANSI-C delimiter'

  ansi_literal_comment_body=$'        run: |\n          printf \'%s\\n\' $\'A\\\' # ; if \\; fi\' # ; if false; then\n          npm ci --prefix bin --ignore-scripts'
  ansi_literal_comment_line=$(consumer_run_line "$ansi_literal_comment_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$ansi_literal_comment_line" == 3 ]] || fail "ANSI-C literal quote tracking hid a later executable consumer"
  printf '%s\n' 'Positive boundary accepted: ANSI-C literal quoting and a trailing comment preserve command context'

  multiline_ansi_literal_body=$'        run: |\n          printf \'%s\\n\' $\'open\\\'word\n          npm ci --prefix bin --ignore-scripts\n          close\'\n          npm ci --prefix bin --ignore-scripts'
  multiline_ansi_literal_line=$(consumer_run_line "$multiline_ansi_literal_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$multiline_ansi_literal_line" == 5 ]] || fail "multiline ANSI-C literal exposed its consumer text"
  printf '%s\n' 'Positive boundary accepted: consumer only after multiline ANSI-C quote closure'

  unclosed_ansi_literal_body=$'        run: |\n          printf \'%s\\n\' $\'open\\\'\n          npm ci --prefix bin --ignore-scripts'
  unclosed_ansi_literal_line=$(consumer_run_line "$unclosed_ansi_literal_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unclosed_ansi_literal_line ]] || fail "unclosed ANSI-C literal accepted its consumer text"
  printf '%s\n' 'Malformed boundary rejected: consumer inside an unclosed escaped ANSI-C literal'

  pid_literal_comment_body=$'        run: |\n          printf \'%s\\n\' $$\'A\\\' # ; if false; then\n          npm ci --prefix bin --ignore-scripts'
  pid_literal_comment_line=$(consumer_run_line "$pid_literal_comment_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$pid_literal_comment_line" == 3 ]] || fail "PID expansion was mistaken for an ANSI-C quote prefix"
  printf '%s\n' 'Positive boundary accepted: consumer after PID expansion and an ordinary single-quoted literal'

  pid_commented_fi_body=$'        run: |\n          if false; then printf \'%s\\n\' $$\'A\\\' # \' ; fi\n          npm ci --prefix bin --ignore-scripts\n          fi'
  pid_commented_fi_line=$(consumer_run_line "$pid_commented_fi_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $pid_commented_fi_line ]] || fail "comment after a PID-prefixed literal closed a real conditional"
  printf '%s\n' 'Negative control rejected: commented fi after a PID-prefixed ordinary single-quoted literal'

  for dollar_quote_prefix in '$' '$$$' '\$$'; do
    printf -v dollar_prefix_body '        run: |\n          printf "%%s\\n" %s\x27A\\\x27B\x27 # ; if false; then\n          npm ci --prefix bin --ignore-scripts' "$dollar_quote_prefix"
    dollar_prefix_line=$(consumer_run_line "$dollar_prefix_body" 'npm ci --prefix bin --ignore-scripts')
    [[ "$dollar_prefix_line" == 3 ]] || fail "an unpaired or escaped dollar prefix changed ANSI-C literal context"
  done
  printf '%s\n' 'Positive boundary accepted: odd dollar counts and an escaped dollar preserve ANSI-C quote recognition'

  continued_ansi_literal_body=$'        run: |\n          printf \'%s\\n\' $\'open\\\n          npm ci --prefix bin --ignore-scripts\n          close\'\n          npm ci --prefix bin --ignore-scripts'
  continued_ansi_literal_line=$(consumer_run_line "$continued_ansi_literal_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$continued_ansi_literal_line" == 5 ]] || fail "trailing backslash in an ANSI-C literal exposed its consumer text"
  printf '%s\n' 'Positive boundary accepted: consumer only after backslash-continued ANSI-C literal closure'

  escaped_delimiter_newline_body=$'        run: |\n          cat <<PAY\\\n          npm ci --prefix bin --ignore-scripts'
  escaped_delimiter_newline_line=$(consumer_run_line "$escaped_delimiter_newline_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $escaped_delimiter_newline_line ]] || fail "continued heredoc delimiter word accepted a joined consumer"
  printf '%s\n' 'Negative control rejected: consumer joined to a continued heredoc delimiter word'

  unterminated_delimiter_word_body=$'        run: |\n          cat <<\'PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  unterminated_delimiter_word_line=$(consumer_run_line "$unterminated_delimiter_word_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $unterminated_delimiter_word_line ]] || fail "unterminated heredoc delimiter quote was accepted as executable"
  printf '%s\n' 'Malformed boundary rejected: consumer after an unterminated heredoc delimiter quote'

  operator_comment_body=$'        run: |\n          false &&# skip installation\n            npm ci --prefix bin --ignore-scripts'
  operator_comment_line=$(consumer_run_line "$operator_comment_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $operator_comment_line ]] || fail "operator-adjacent comment hid a short-circuited consumer"
  printf '%s\n' 'Negative control rejected: consumer after an operator-adjacent comment; assertion=operator-adjacent comment hid a short-circuited consumer'

  quoted_operator_hash_body=$'        run: |\n          echo "done;# literal"\n          npm ci --prefix bin --ignore-scripts'
  quoted_operator_hash_line=$(consumer_run_line "$quoted_operator_hash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$quoted_operator_hash_line" == 3 ]] || fail "quoted operator-adjacent hash changed executable context"
  printf '%s\n' 'Positive boundary accepted: consumer after a quoted operator-adjacent hash'

  in_word_hash_body=$'        run: |\n          echo word#literal\n          npm ci --prefix bin --ignore-scripts'
  in_word_hash_line=$(consumer_run_line "$in_word_hash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$in_word_hash_line" == 3 ]] || fail "in-word hash changed executable context"
  printf '%s\n' 'Positive boundary accepted: consumer after an in-word hash'

  escaped_operator_hash_body=$'        run: |\n          echo escaped\\;#literal &&\n          npm ci --prefix bin --ignore-scripts'
  escaped_operator_hash_line=$(consumer_run_line "$escaped_operator_hash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $escaped_operator_hash_line ]] || fail "escaped operator before an in-word hash hid a continued list"
  printf '%s\n' 'Negative control rejected: consumer after an escaped operator and in-word hash'

  escaped_space_hash_body=$'        run: |\n          echo escaped\\ #literal &&\n          npm ci --prefix bin --ignore-scripts'
  escaped_space_hash_line=$(consumer_run_line "$escaped_space_hash_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $escaped_space_hash_line ]] || fail "escaped space before an in-word hash hid a continued list"
  printf '%s\n' 'Negative control rejected: consumer after an escaped space and in-word hash'

  nested_inline_if_body=$'        run: |\n          if false; then if true; then echo skipped; fi\n            npm ci --prefix bin --ignore-scripts\n          fi'
  nested_inline_if_line=$(consumer_run_line "$nested_inline_if_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $nested_inline_if_line ]] || fail "inline nested if closure hid the outer conditional depth"
  printf '%s\n' 'Negative control rejected: consumer inside an outer conditional with an inline nested if; assertion=inline nested if closure hid the outer conditional depth'

  close_reopen_if_body=$'        run: |\n          if true; then\n            echo done\n          fi; if false; then\n            npm ci --prefix bin --ignore-scripts\n          fi'
  close_reopen_if_line=$(consumer_run_line "$close_reopen_if_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $close_reopen_if_line ]] || fail "closing and reopening an if on one line lost conditional depth"
  printf '%s\n' 'Negative control rejected: consumer after a same-line if close and reopen; assertion=closing and reopening an if on one line lost conditional depth'

  post_nested_if_body=$'        run: |\n          if false; then if true; then echo skipped; fi\n          fi\n          echo "if fi"; echo if fi\n          npm ci --prefix bin --ignore-scripts'
  post_nested_if_line=$(consumer_run_line "$post_nested_if_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_nested_if_line" == 5 ]] || fail "consumer after balanced nested conditionals and keyword arguments was not accepted"
  printf '%s\n' 'Positive boundary accepted: consumer after balanced nested conditionals and keyword arguments'

  empty_delimiter_body=$'        run: |\n          cat <<\'\'\n          npm ci --prefix bin --ignore-scripts\n\n'
  empty_delimiter_line=$(consumer_run_line "$empty_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $empty_delimiter_line ]] || fail "empty-delimiter heredoc body was accepted as executable"
  printf '%s\n' 'Negative control rejected: consumer inside an empty-delimiter heredoc; assertion=empty-delimiter heredoc body was accepted as executable'

  post_empty_delimiter_body=$'        run: |\n          cat <<""\n          npm ci --prefix bin --ignore-scripts\n\n          npm ci --prefix bin --ignore-scripts'
  post_empty_delimiter_line=$(consumer_run_line "$post_empty_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$post_empty_delimiter_line" == 5 ]] || fail "empty heredoc delimiter did not distinguish its body from a later consumer"
  printf '%s\n' 'Positive boundary accepted: consumer after an empty heredoc delimiter; assertion=empty heredoc delimiter did not distinguish its body from a later consumer'

  spaced_empty_delimiter_body=$'        run: |\n          cat <<\'\'\n            \n          npm ci --prefix bin --ignore-scripts'
  spaced_empty_delimiter_line=$(consumer_run_line "$spaced_empty_delimiter_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $spaced_empty_delimiter_line ]] || fail "space-containing heredoc line closed an empty delimiter"
  printf '%s\n' 'Negative control rejected: consumer after a space-containing empty heredoc delimiter'

  quoted_arithmetic_prefix_body=$'        run: |\n          echo "((" ; cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD'
  quoted_arithmetic_prefix_line=$(consumer_run_line "$quoted_arithmetic_prefix_body" 'npm ci --prefix bin --ignore-scripts')
  [[ -z $quoted_arithmetic_prefix_line ]] || fail "quoted arithmetic prefix hid a real heredoc body"
  printf '%s\n' 'Negative control rejected: consumer in a heredoc after a quoted arithmetic prefix; assertion=quoted arithmetic prefix hid a real heredoc body'

  nested_arithmetic_shift_body=$'        run: |\n          size=$(( (1 + 2) << 20 ))\n          npm ci --prefix bin --ignore-scripts'
  nested_arithmetic_shift_line=$(consumer_run_line "$nested_arithmetic_shift_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$nested_arithmetic_shift_line" == 3 ]] || fail "nested arithmetic shift was mistaken for a heredoc"
  printf '%s\n' 'Positive boundary accepted: consumer after a nested arithmetic shift; assertion=nested arithmetic shift was mistaken for a heredoc'

  closed_arithmetic_prefix_body=$'        run: |\n          size=$(( (1 + 2) << 20 )); cat <<PAYLOAD\n          npm ci --prefix bin --ignore-scripts\n          PAYLOAD\n          npm ci --prefix bin --ignore-scripts'
  closed_arithmetic_prefix_line=$(consumer_run_line "$closed_arithmetic_prefix_body" 'npm ci --prefix bin --ignore-scripts')
  [[ "$closed_arithmetic_prefix_line" == 5 ]] || fail "closed arithmetic context hid a later real heredoc"
  printf '%s\n' 'Positive boundary accepted: consumer after a heredoc following closed arithmetic'

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
