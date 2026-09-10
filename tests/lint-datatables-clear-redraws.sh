#!/usr/bin/env bash
#
# VIM-A15.56a. DataTables 1.x `fnClearTable()` redrew the table by default
# (its second argument defaulted to true). The 2.x replacement `clear()` does
# NOT: it calls `_fnClearTable(settings)` and nothing else
# (public/js/150-jquery.datatables.js, `_api_register( 'clear()' ...)`), so the
# emptied data is not painted until something else draws.
#
# The migration introduced exactly that gap in three list views: the search
# handler cleared the table, fired `$.ajax`, and called `.draw()` only inside
# the `success` callback. None of those requests carries an `error` handler, so
# a failed or non-2xx `list-search` left the PREVIOUS query's rows painted while
# the internal data was already empty -- an admin UI showing mailbox, alias or
# domain records that do not match what the operator typed. PR-Agent caught it;
# no test did, because nothing asserted the clear path.
#
# This gate closes that class: in our own view JS, a `clear()` call must redraw
# unconditionally -- either chained as `clear().draw()`, or followed by a draw
# on the very next statement. A `.draw()` that sits inside a conditional or a
# callback does not count, because the failure path is exactly the one that
# skips it.
#
# Scope is our view JS only. The vendored engine and the minified bundles are
# excluded: they contain the library's own `clear()` definition and its
# call sites, which are not ours to constrain.
#
# Any case this gate cannot judge (missing tree, unreadable file, scan error)
# is a HARD FAILURE, never a silent skip.
#
# Exit 0 = clean, 1 = a clear() was found whose redraw is conditional.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

views_root='application/views'

if [ ! -d "$views_root" ]; then
  echo "FAIL: view root '$views_root' not found; cannot judge." >&2
  exit 1
fi

mapfile -t files < <(find "$views_root" -type f -name '*.js' | sort)

if [ "${#files[@]}" -eq 0 ]; then
  echo "FAIL: no view JS found under '$views_root'; cannot judge." >&2
  exit 1
fi

violations=0

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  # Every line bearing a clear() call that is ours (not a property named
  # clear, not a comment). Chained clear().draw() is already compliant.
  while IFS=: read -r lineno _; do
    [ -n "$lineno" ] || continue

    line=$(sed -n "${lineno}p" "$file")

    # Chained on the same line: compliant.
    if printf '%s' "$line" | grep -q 'clear()[[:space:]]*\.[[:space:]]*draw()'; then
      continue
    fi

    # Otherwise the next non-blank, non-comment statement must draw.
    next=$(awk -v start="$lineno" 'NR > start {
            line = $0
            sub(/^[[:space:]]+/, "", line)
            if (line == "") next
            if (line ~ /^\/\//) next
            print line
            exit
        }' "$file")

    if printf '%s' "$next" | grep -q '\.draw()'; then
      continue
    fi

    echo "FAIL: $file:$lineno clear() does not redraw unconditionally." >&2
    echo "      next statement: ${next:-<none>}" >&2
    violations=$((violations + 1))
  done < <(grep -n '\.clear()' "$file" | grep -v '^\s*//' || true)
done

if [ "$violations" -ne 0 ]; then
  echo "FAIL: $violations conditional clear() redraw(s); a failed request leaves stale rows painted." >&2
  exit 1
fi

echo "OK: every view-JS clear() redraws unconditionally (${#files[@]} files scanned)"
