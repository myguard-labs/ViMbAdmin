#!/usr/bin/env bash
# Exercise the exact ownership parser; sourced by its existing self-test.
set -euo pipefail

test_regression_browser_ownership() (
  fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-browser-owner.XXXXXX")
  trap 'rm -rf -- "${fixture:?}"' EXIT
  sed -n '/^workflow_has_required_owner_step() {$/,/^}$/p' \
    tests/assert-regression-coverage.sh >"$fixture/parser.sh"
  # shellcheck disable=SC1091 # Exact function extracted from the candidate.
  source "$fixture/parser.sh"
  for target in 'bash tests/test-resolve-bundle-v.sh' 'node ci/test-refactor-ui-events.cjs'; do
    cat >"$fixture/valid.yml" <<YAML
jobs:
  static:
    strategy:
      matrix:
        browser: [chromium, firefox, webkit]
    steps:
      - name: Shared source check
        if: matrix.browser == 'chromium'
        run: $target
YAML
    if ! workflow_has_required_owner_step "$fixture/valid.yml" command "$target" static chromium; then
      echo 'FAIL: real Chromium shared source owner was rejected' >&2
      exit 1
    fi
    # Ordinary owners must continue rejecting all conditional steps.
    if workflow_has_required_owner_step "$fixture/valid.yml" command "$target" static; then
      echo 'FAIL: Chromium permission leaked into an ordinary owner' >&2
      exit 1
    fi
    for defect in false event wrong-engine missing-matrix wrong-matrix misplaced-matrix excluded-chromium extra-axis matrix-include disabled-job wrong-job duplicate-condition missing-run; do
      cp "$fixture/valid.yml" "$fixture/mutant.yml"
      case $defect in
      false) sed -i "s/if: matrix.browser == 'chromium'/if: false/" "$fixture/mutant.yml" ;;
      event) sed -i "s/if: matrix.browser == 'chromium'/if: github.event_name == 'pull_request'/" "$fixture/mutant.yml" ;;
      wrong-engine) sed -i "s/if: matrix.browser == 'chromium'/if: matrix.browser == 'firefox'/" "$fixture/mutant.yml" ;;
      missing-matrix) sed -i '/browser:/d' "$fixture/mutant.yml" ;;
      wrong-matrix) sed -i 's/\[chromium, firefox, webkit\]/[firefox, webkit]/' "$fixture/mutant.yml" ;;
      misplaced-matrix) sed -i 's/strategy:/env:/' "$fixture/mutant.yml" ;;
      excluded-chromium) sed -i '/browser: /a\        exclude: [{browser: chromium}]' "$fixture/mutant.yml" ;;
      extra-axis) sed -i '/browser: /a\        extra: [one, two]' "$fixture/mutant.yml" ;;
      matrix-include) sed -i '/browser: /a\        include: [{browser: chromium}]' "$fixture/mutant.yml" ;;
      disabled-job) sed -i '/^  static:$/a\    if: false' "$fixture/mutant.yml" ;;
      wrong-job) sed -i 's/^  static:/  unrelated:/' "$fixture/mutant.yml" ;;
      duplicate-condition) sed -i "/if: matrix.browser/a\        if: matrix.browser == 'chromium'" "$fixture/mutant.yml" ;;
      missing-run) sed -i '/run:/d' "$fixture/mutant.yml" ;;
      esac
      if cmp -s "$fixture/valid.yml" "$fixture/mutant.yml"; then
        echo "FAIL: ownership mutation did not change its fixture: $defect" >&2
        exit 1
      fi
      if workflow_has_required_owner_step "$fixture/mutant.yml" command "$target" static chromium; then
        echo "FAIL: ownership parser accepted $defect" >&2
        exit 1
      fi
    done
    sed '/if: matrix.browser/d' "$fixture/valid.yml" >"$fixture/unconditional.yml"
    workflow_has_required_owner_step "$fixture/unconditional.yml" command "$target" static
    workflow_has_required_owner_step "$fixture/valid.yml" command "$target" static chromium
  done
  echo 'OK: shared Chromium ownership, unconditional compatibility and named reachability mutations; restored green'
)
test_regression_browser_ownership
