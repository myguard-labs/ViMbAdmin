#!/usr/bin/env bash
#
# VIM-A15.56a. DataTables 1.x `fnClearTable()` redrew by default; the 2.x
# `clear()` does not -- it only empties internal data. The migration missed
# this in three list views: the search handler cleared the table, fired
# `$.ajax`, and called `.draw()` only inside the `success` callback:
#
#     vmDataTableApi( t ).clear();
#     $.ajax({ success: function(){ vmDataTableApi( t ).draw(); } });
#
# None of those requests has an `error` handler, so a failed or non-2xx
# `list-search` left the PREVIOUS query's rows painted while the internal
# data was already empty. Proving "is that draw() reachable?" from shell
# means parsing JavaScript, and three successive versions of this gate tried:
# each one closed the shape a review had just named and silently reopened the
# defect class in a different shape (multiple call sites per line, a receiver
# split across lines, an unrecognised receiver form). A line-oriented shell
# scanner cannot judge arbitrary JavaScript reachability; every attempt to
# make it do so produced a new silent pass.
#
# So this gate does not parse JavaScript at all. It asserts a NORMAL FORM.
# All six real call sites in the tree are the identical exact spelling:
#
#     vmDataTableApi( oDataTable ).clear().draw();
#
# Every line containing the literal `.clear()` must match that form
# (whitespace-tolerant), or the gate fails -- there is no "cannot judge"
# branch. A genuinely different, legitimate call form must update this gate
# deliberately; it is never silently accepted. The only skip is a whole-line
# `//` comment; comments are not otherwise reasoned about. The gate also
# hard-fails unless it finds EXACTLY the count of compliant sites recorded
# below -- a deliberate change in the number of call sites (added, removed,
# or rewritten out of the approved form) must update that number, which is
# the tripwire that replaces judging reachability.
#
# Exit 0 = every `.clear()` matches the approved form and the count matches.
# Exit 1 = an unrecognised `.clear()` form, or the compliant count is wrong.

set -euo pipefail
export LC_ALL=C

cd "$(dirname "${BASH_SOURCE[0]}")/.."

views_root='application/views'
expected_count=6

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

normal_form_re='^[[:space:]]*vmDataTableApi\([[:space:]]*[A-Za-z_$][A-Za-z0-9_$]*[[:space:]]*\)\.clear\(\)\.draw\(\);[[:space:]]*$'

compliant=0
violations=0

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  while IFS=: read -r lineno line; do
    [ -n "$lineno" ] || continue

    if [[ "$line" =~ ^[[:space:]]*// ]]; then
      continue
    fi

    if [[ "$line" =~ $normal_form_re ]]; then
      compliant=$((compliant + 1))
      continue
    fi

    echo "FAIL: $file:$lineno .clear() is not the approved form." >&2
    echo "      found: $(printf '%s' "$line" | cut -c1-90)" >&2
    echo "      view JS must clear through the single approved form:" >&2
    echo "      vmDataTableApi( oDataTable ).clear().draw();" >&2
    echo "      A genuinely different, legitimate call form requires this" >&2
    echo "      gate to be updated deliberately, not worked around." >&2
    violations=$((violations + 1))
  done < <(grep -n '\.clear()' "$file")
done

if [ "$violations" -ne 0 ]; then
  echo "FAIL: $violations clear() call(s) not in the approved normal form." >&2
  exit 1
fi

if [ "$compliant" -ne "$expected_count" ]; then
  echo "FAIL: found $compliant compliant clear().draw() call site(s)," >&2
  echo "      expected exactly $expected_count. A deliberate change in the" >&2
  echo "      number of clear() call sites must update this gate's" >&2
  echo "      expected_count -- this is the tripwire that catches a call" >&2
  echo "      site being added, removed, or going dark." >&2
  exit 1
fi

echo "OK: all $compliant DataTables clear() call(s) use the approved form (${#files[@]} files scanned)"
