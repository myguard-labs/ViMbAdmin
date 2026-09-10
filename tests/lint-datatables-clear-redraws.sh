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
# domain records that do not match what the operator typed.
#
# This gate requires the redraw to be UNCONDITIONAL, which here means
# syntactically chained: `clear().draw()`. A `.draw()` reached as a separate
# statement is not accepted, because proving it always runs means proving
# reachability, and a line-oriented shell gate cannot do that. The first
# version of this gate tried, by scanning "the next statement" for `.draw()`,
# and a review demonstrated it certified the original bug as clean:
#
#     vmDataTableApi( t ).clear();
#     $.ajax({ success: function(){ vmDataTableApi( t ).draw(); } });
#
# -- one line, a draw reachable only on success, and a bare grep for `.draw()`
# on the following line is satisfied. Requiring the chain is both stricter and
# honestly checkable. It can reject a legitimate separated draw; that is the
# intended trade, and the fix is to chain it.
#
# Scope is our own view JS, and only receivers we recognise as DataTables
# handles -- `vmDataTableApi( ... ).clear()` or a `.dataTable().api().clear()`
# chain. An unrelated `myMap.clear()` is none of this gate's business.
# The vendored engine and the minified bundles are excluded: they carry the
# library's own `clear()` definition and call sites, which are not ours.
#
# Any case this gate cannot judge (missing tree, unreadable file, scan error,
# or a recognised clear() whose form it does not understand) is a HARD
# FAILURE, never a silent skip.
#
# Exit 0 = clean, 1 = a recognised clear() does not chain its redraw.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

views_root='application/views'

if [ ! -d "$views_root" ]; then
  echo "FAIL: view root '$views_root' not found; cannot judge." >&2
  exit 1
fi

files=()
while IFS= read -r -d '' file; do
  files+=("$file")
done < <(find "$views_root" -type f -name '*.js' -print0 | sort -z)

if [ "${#files[@]}" -eq 0 ]; then
  echo "FAIL: no view JS found under '$views_root'; cannot judge." >&2
  exit 1
fi

violations=0
inspected=0

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  # Strip block and line comments so a commented-out or documented clear()
  # is not scanned, then rejoin: the chain we look for may be split across
  # lines, so the check runs against a whitespace-normalised single string
  # per file with line anchors preserved as markers.
  scan=$(sed -e 's://.*::' "$file" |
    awk '
      BEGIN { inblock = 0 }
      {
        line = $0
        while (1) {
          if (inblock) {
            end = index(line, "*/")
            if (end == 0) { line = ""; break }
            line = substr(line, end + 2)
            inblock = 0
          }
          start = index(line, "/*")
          if (start == 0) break
          rest = substr(line, start + 2)
          line = substr(line, 1, start - 1)
          if (index(rest, "*/") > 0) {
            line = line substr(rest, index(rest, "*/") + 2)
          } else {
            inblock = 1
            break
          }
        }
        printf "%d\001%s\n", NR, line
      }
    ')

  # Recognised DataTables clear() receivers, evaluated on the comment-free
  # text with line breaks collapsed so a multi-line chain still reads as one.
  flat=$(printf '%s' "$scan" | sed -e 's/^[0-9]*\x01//' | tr '\n' ' ' | tr -s ' ')

  # Every recognised clear(), with the text that immediately follows it.
  while IFS= read -r occurrence; do
    [ -n "$occurrence" ] || continue
    inspected=$((inspected + 1))

    if ! printf '%s' "$occurrence" | grep -Eq '\.clear\(\)[[:space:]]*\.[[:space:]]*draw\(\)'; then
      lineno=$(grep -nE 'vmDataTableApi\([^)]*\)[[:space:]]*\.[[:space:]]*clear\(\)|\.api\(\)[[:space:]]*\.[[:space:]]*clear\(\)' "$file" |
        head -n "$inspected" | tail -n 1 | cut -d: -f1)
      echo "FAIL: $file:${lineno:-?} clear() does not chain .draw()." >&2
      echo "      found: ${occurrence:0:90}" >&2
      violations=$((violations + 1))
    fi
  done < <(printf '%s' "$flat" |
    grep -oE '(vmDataTableApi\([^)]*\)|\.api\(\))[[:space:]]*\.[[:space:]]*clear\(\)([[:space:]]*\.[[:space:]]*draw\(\))?' || true)
done

if [ "$inspected" -eq 0 ]; then
  echo "FAIL: no DataTables clear() call found in ${#files[@]} view files;" >&2
  echo "      this gate is asserting nothing. Has the helper been renamed?" >&2
  exit 1
fi

if [ "$violations" -ne 0 ]; then
  echo "FAIL: $violations clear() call(s) without a chained redraw;" >&2
  echo "      a failed request leaves the previous query's rows painted." >&2
  exit 1
fi

echo "OK: all $inspected DataTables clear() call(s) chain .draw() (${#files[@]} files scanned)"
