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
# handles -- `vmDataTableApi( ... ).clear()` (one level of nested parens
# allowed in the argument) or a bare `.api().clear()` chain.
# `.dataTable().clear()` with no `.api()` in between is NOT covered -- it is
# not a receiver form this gate understands, so any such call site is a hard
# failure below, not a silent pass. An unrelated `myMap.clear()` is none of
# this gate's business. The vendored engine and the minified bundles are
# excluded: they carry the library's own `clear()` definition and call sites,
# which are not ours.
#
# This gate does not strip comments at all. A prior version stripped `//...`
# textually, which also erased everything after the first `//` inside a
# string literal (e.g. `url: "https://..."`), silently hiding real code from
# the scan; and it tracked `/* */` blocks with no awareness of string or
# regex-literal contents, so a regex like `/http:\/*x/` was misread as an
# unterminated block comment and swallowed the rest of the file. Both failure
# modes turned an unjudgeable line into a silent pass, which contradicts the
# rule below. Instead: a candidate is skipped as a comment ONLY when it is
# unambiguous (a `//` before it with no quote before that `//`), and a file
# containing `/*` anywhere is a hard failure for any candidate that cannot be
# shown chained, rather than trusted to a lexer this gate does not have.
#
# Any case this gate cannot judge (missing tree, unreadable file, scan error,
# a recognised clear() whose form it does not understand, or a candidate a
# comment/block-comment scan cannot resolve) is a HARD FAILURE, never a
# silent skip.
#
# Exit 0 = clean, 1 = a recognised clear() does not chain its redraw, or a
# candidate could not be judged.

set -euo pipefail
export LC_ALL=C

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

# Recognised DataTables clear() receivers: `vmDataTableApi(...)` (allowing one
# level of nested parens in the argument, e.g. `vmDataTableApi( $('#t') )`) or
# a bare `.api()`, followed by `.clear()`.
receiver_re='(vmDataTableApi[[:space:]]*\((\([^()]*\)|[^()])*\)|\.api\(\))[[:space:]]*\.[[:space:]]*clear\(\)'

violations=0
inspected=0

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  has_block_comment=0
  if grep -qF '/*' "$file"; then
    has_block_comment=1
  fi

  file_inspected=0

  while IFS=: read -r lineno matchcol _rest; do
    [ -n "$lineno" ] || continue
    file_inspected=$((file_inspected + 1))
    inspected=$((inspected + 1))

    line=$(sed -n "${lineno}p" "$file")

    # Unambiguous line-comment skip: the line's first `//` occurs before the
    # match column, AND no quote character precedes that `//`. If a quote
    # precedes it, `//` may be inside a string literal (e.g. an ajax URL) and
    # this gate cannot tell -- that falls through to the chain/hard-failure
    # logic below instead of being skipped.
    before_match=${line:0:matchcol}
    slashpos=$(printf '%s' "$before_match" | grep -aob '//' | head -n1 | cut -d: -f1 || true)
    if [ -n "$slashpos" ]; then
      before_slash=${before_match:0:slashpos}
      if ! printf '%s' "$before_slash" | grep -q "['\"\`]"; then
        continue
      fi
    fi

    # Judge with a bounded window: this line plus the next two, squeezed to
    # one whitespace-normalised string, so a chain split across lines still
    # reads as one -- without flattening the whole file.
    # Cut at the first statement-terminating `;` at or after the match's own
    # `clear()` so an unrelated statement on line+1/line+2 (e.g. another
    # clear() call) cannot bleed into this candidate's window and manufacture
    # a false chain.
    window_raw=$(sed -n "${lineno},$((lineno + 2))p" "$file" | tr '\n' ' ' | tr -s '[:space:]' ' ')
    clear_rel=$(printf '%s' "$window_raw" | grep -aob 'clear()' | head -n1 | cut -d: -f1 || true)
    if [ -n "$clear_rel" ]; then
      tail_from_clear=${window_raw:clear_rel}
      semi_rel=$(printf '%s' "$tail_from_clear" | grep -aob ';' | head -n1 | cut -d: -f1 || true)
      if [ -n "$semi_rel" ]; then
        window=${window_raw:0:$((clear_rel + semi_rel + 1))}
      else
        window=$window_raw
      fi
    else
      window=$window_raw
    fi

    if printf '%s' "$window" | grep -Eq '\.clear\(\)[[:space:]]*\.[[:space:]]*draw\(\)'; then
      continue
    fi

    if [ "$has_block_comment" -eq 1 ]; then
      echo "FAIL: $file:$lineno cannot judge: block comments present." >&2
      echo "      found: $(printf '%s' "$window" | cut -c1-90)" >&2
      violations=$((violations + 1))
      continue
    fi

    echo "FAIL: $file:$lineno clear() does not chain .draw()." >&2
    echo "      found: $(printf '%s' "$window" | cut -c1-90)" >&2
    violations=$((violations + 1))
  done < <(grep -noE "$receiver_re" "$file" | while IFS=: read -r ln match; do
    col=$(sed -n "${ln}p" "$file" | grep -aob -E "$receiver_re" | head -n1 | cut -d: -f1)
    printf '%s:%s:%s\n' "$ln" "$col" "$match"
  done)

  if [ "$file_inspected" -eq 0 ]; then
    :
  fi
done

# Each known-bug view file must contribute at least one inspected call site,
# or this gate would silently assert nothing for that view.
for known_file in \
  "$views_root/domain/js/list.js" \
  "$views_root/mailbox/js/list.js" \
  "$views_root/alias/js/list.js"; do
  if [ ! -f "$known_file" ]; then
    echo "FAIL: expected view '$known_file' not found; cannot judge." >&2
    exit 1
  fi
  count=$(grep -cE "$receiver_re" "$known_file" || true)
  if [ "$count" -eq 0 ]; then
    echo "FAIL: '$known_file' contributes zero inspected clear() call sites;" >&2
    echo "      this gate would be asserting nothing for that view." >&2
    exit 1
  fi
done

if [ "$inspected" -eq 0 ]; then
  echo "FAIL: no DataTables clear() call found in ${#files[@]} view files;" >&2
  echo "      this gate is asserting nothing. Has the helper been renamed?" >&2
  exit 1
fi

if [ "$violations" -ne 0 ]; then
  echo "FAIL: $violations clear() call(s) without a chained redraw or unjudgeable;" >&2
  echo "      a failed request leaves the previous query's rows painted." >&2
  exit 1
fi

echo "OK: all $inspected DataTables clear() call(s) chain .draw() (${#files[@]} files scanned)"
