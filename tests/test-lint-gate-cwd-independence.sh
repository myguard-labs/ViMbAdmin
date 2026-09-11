#!/usr/bin/env bash
#
# Regression guard: a lint gate must produce the same verdict no matter which
# directory it is invoked from.
#
# WHY THIS EXISTS. The gates start with `cd "$(dirname "$0")/.."` to reach the
# repo root. `$0` is RELATIVE when a gate is invoked as
# `bash lint-bs2-grid-classes.sh` from inside tests/, so `$(dirname "$0")`
# re-resolves against whatever the cwd is at the moment it is evaluated. Any
# later use of `$(dirname "$0")` therefore means a DIFFERENT directory than it
# did before the cd, and a `source "$(dirname "$0")/support/..."` placed after
# it looks under the repo root instead of under tests/.
#
# That is not hypothetical: it shipped. The VIM-A15.31 lexer work added exactly
# such a source line to three gates, and all three then failed outright when run
# from tests/ while passing from the repo root -- so every local run and CI job
# (which invoke them as `bash tests/<gate>.sh` from the root) stayed green over
# a gate that was broken from any other directory.
#
# The same defect class has now bitten this tree three times in different
# spellings: a helper resolving its own path with `${BASH_SOURCE[0]%/*}` when
# the path had no slash, a gate run from a scratch directory silently scanning
# an EMPTY file list and reporting OK, and this one. What they share is that the
# wrong path produces a plausible-looking result rather than an obvious failure,
# so nothing catches it. Hence a test that pins the invariant directly.
#
# Exit 0 = every gate agrees with itself across cwds, 1 = one does not.
#
set -euo pipefail

script_dir=$(unset CDPATH; cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(unset CDPATH; cd -- "$script_dir/.." && pwd)

# The gates covered here. Deliberately an explicit list rather than a glob over
# tests/lint-*.sh, so that adding a gate to this list is a deliberate statement
# that it is cwd-independent, and a gate known to be broken is never folded in
# silently (which would make this test red for a reason it is not guarding).
# Add a gate here once it is cwd-independent.
gates=(
  lint-bs2-grid-classes.sh
  lint-bs2-component-classes.sh
  lint-template-escaping.sh
  lint-modal-aria-labelledby.sh
  lint-datatables-clear-redraws.sh
)

# Each case is "<cwd>|<path to pass to bash>". Both halves matter:
#
#   - the CWD, because a gate whose `cd` lands somewhere unexpected can scan an
#     empty file list and still exit 0;
#   - the PATH SPELLING, because the actual defect only manifests on a RELATIVE
#     invocation. `$0` is then relative, so `$(dirname "$0")` re-resolves against
#     the cwd each time it is evaluated -- before the gate's `cd` it means
#     tests/, after it means the repo root. Invoked by ABSOLUTE path the same
#     broken gate passes, because `$(dirname "$0")` is absolute and immune to the
#     cd. A version of this test that only used absolute paths was written first
#     and passed against the known-broken gates -- it proved nothing.
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

status=0

echo "== lint gates must behave identically from any working directory =="

for gate in "${gates[@]}"; do
  # Baseline: the invocation CI uses, from the repo root.
  root_out=$(cd "$repo_root" && bash "tests/$gate" 2>&1) && root_rc=0 || root_rc=$?

  # cwd | argument spelling
  cases=(
    "$repo_root/tests|$gate"
    "$repo_root/tests|./$gate"
    "$repo_root|./tests/$gate"
    "$tmpdir|$repo_root/tests/$gate"
    "/|$repo_root/tests/$gate"
  )

  for case in "${cases[@]}"; do
    from="${case%%|*}"
    arg="${case#*|}"
    out=$(cd "$from" && bash "$arg" 2>&1) && rc=0 || rc=$?
    if [ "$rc" -ne "$root_rc" ]; then
      echo "  FAIL: $gate exits $root_rc from the repo root, but $rc as \`bash $arg\` from $from" >&2
      printf '%s\n' "$out" | sed 's/^/      /' >&2
      status=1
      continue
    fi
    # A matching exit code is necessary but not sufficient: a gate that scanned
    # an empty file list can exit 0 too. Require identical output as well.
    if [ "$rc" -eq 0 ] && [ "$out" != "$root_out" ]; then
      echo "  FAIL: $gate exits 0 both ways, but its output differs as \`bash $arg\` from $from" >&2
      diff <(printf '%s\n' "$root_out") <(printf '%s\n' "$out") | sed 's/^/      /' >&2
      status=1
      continue
    fi
    echo "  OK: $gate, \`bash $arg\` from $from"
  done
done

if [ "$status" -eq 0 ]; then
  echo "  OK: all ${#gates[@]} gates are working-directory independent"
fi

exit "$status"
