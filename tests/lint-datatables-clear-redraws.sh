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
# scanner cannot judge arbitrary JavaScript reachability.
#
# So this gate runs a two-stage contract instead of parsing JavaScript:
#   1. DISCOVERY deliberately OVER-MATCHES by construction -- any spelling
#      variant of `.clear(...)` or `["clear"](...)`, any whitespace between
#      tokens, must be caught here. A discovery miss is invisible to stage 2
#      and silently passes -- that is the failure this gate exists to close.
#   2. Only the anchored NORMAL-FORM check in stage 2 is exact. Everything
#      discovery finds that does not match the normal form byte-for-byte
#      fails loudly -- there is no "cannot judge" branch.
# This invariant (over-match, then judge exactly) must never regress: widen
# discovery's regex freely, never relax the normal-form regex.
#
# All six real call sites in the tree are the identical exact spelling:
#
#     vmDataTableApi( oDataTable ).clear().draw();
#
# The normal form is tolerant of indentation and of the spacing inside the
# `vmDataTableApi( ... )` argument parentheses, and of trailing space; every
# other byte -- including any space around `.clear()` or `.draw()` itself --
# is exact. A genuinely different, legitimate call form must update this gate
# deliberately; it is never silently accepted. The only skip is a whole-line
# `//` comment; comments are not otherwise reasoned about. The gate also
# hard-fails unless it finds EXACTLY the count of compliant sites recorded
# below -- a deliberate change in the number of call sites (added, removed,
# or rewritten out of the approved form) must update that number, which is
# the tripwire that replaces judging reachability.
#
# Exit 0 = every discovered candidate matches the approved form and the count
#          matches, no CRLF line endings, and no `.phtml` is in scope.
# Exit 1 = an unrecognised candidate, a wrong compliant count, a CRLF file, or
#          a `.phtml` file that has entered this gate's scope.

set -euo pipefail
export LC_ALL=C

cd "$(dirname "${BASH_SOURCE[0]}")/.."

views_root='application/views'
# Six approved sites: two each in the domain, mailbox and alias list views.
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

# This gate's scope is *.js only. A .phtml gaining an inline <script> call
# site would be invisible to discovery above AND would not move the count
# tripwire. Assert the scope assumption still holds every run.
phtml_hits=()
while IFS= read -r -d '' file; do
  phtml_hits+=("$file")
done < <(grep -lZE 'vmDataTableApi|dataTable' "$views_root" -r --include='*.phtml' 2>/dev/null || true)

if [ "${#phtml_hits[@]}" -gt 0 ]; then
  echo "FAIL: .phtml file(s) under '$views_root' now reference vmDataTableApi" >&2
  echo "      or dataTable -- this gate's scope assumption (call sites live" >&2
  echo "      only in *.js) has stopped holding. Discovery must be extended" >&2
  echo "      to cover .phtml <script> blocks before this gate can judge:" >&2
  printf '      %s\n' "${phtml_hits[@]}" >&2
  exit 1
fi

# Discovery: deliberately over-match. Any `.clear` call however spelled --
# whitespace or a tab between the dot and `clear`, whitespace inside the
# call parens, or bracket/string member access -- must be caught here so
# stage 2 can judge it. Verified against: `.clear ()`, `.clear( )`,
# `.clear\t()`, and `["clear"]()`, plus the plain `.clear()` form.
discovery_re='\.[[:space:]]*clear[[:space:]]*\(|\[[[:space:]]*["'"'"']clear'

normal_form_re='^[[:space:]]*vmDataTableApi\([[:space:]]*[A-Za-z_$][A-Za-z0-9_$]*[[:space:]]*\)\.clear\(\)\.draw\(\);[[:space:]]*$'

compliant=0
violations=0
compliant_sites=()

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  if LC_ALL=C grep -q $'\r$' "$file"; then
    echo "FAIL: '$file' has CRLF line endings." >&2
    echo "      this gate is exact about spelling and refuses to silently" >&2
    echo "      normalise line endings -- convert the file to LF first." >&2
    exit 1
  fi

  while IFS=: read -r lineno line; do
    [ -n "$lineno" ] || continue
    line="${line%$'\r'}"

    if [[ "$line" =~ ^[[:space:]]*// ]]; then
      continue
    fi

    if [[ "$line" =~ $normal_form_re ]]; then
      compliant=$((compliant + 1))
      compliant_sites+=("$file:$lineno")
      continue
    fi

    echo "FAIL: $file:$lineno .clear() is not the approved form." >&2
    echo "      found: $(printf '%s' "$line" | cut -c1-90)" >&2
    echo "      view JS must clear through the single approved form:" >&2
    echo "      vmDataTableApi( oDataTable ).clear().draw();" >&2
    echo "      A genuinely different, legitimate call form requires this" >&2
    echo "      gate to be updated deliberately, not worked around." >&2
    violations=$((violations + 1))
  done < <(grep -nE "$discovery_re" "$file")
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
  echo "      site being added, removed, or going dark. Compliant sites" >&2
  echo "      found this run:" >&2
  printf '      %s\n' "${compliant_sites[@]}" >&2
  exit 1
fi

echo "OK: all $compliant DataTables clear() call(s) use the approved form (${#files[@]} files scanned)"
