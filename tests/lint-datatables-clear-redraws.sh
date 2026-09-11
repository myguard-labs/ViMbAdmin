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
#   1. DISCOVERY over-matches every SINGLE-LINE `.clear(` or `["clear"](` /
#      `['clear'](` call shape, with any whitespace between tokens. That is
#      the COVERED set -- precisely stated, not "any spelling variant":
#      discovery is a single-line ERE and cannot see, among others:
#        - `.clear/**/()`  (a comment splitting the tokens)
#        - `.clear` and `()` split across two lines
#        - an aliased/indirect call: `var m='clear'; x[m]()`
#        - a concatenated string: `["cle"+"ar"]()`
#        - `.clear.apply(...)` / `.clear.call(...)`
#        - optional invocation: `table.clear?.()`
#        - `var f=api.clear; f()` (method torn off before calling)
#        - destructuring: `var {clear}=api;`
#        - a unicode-escaped property (`\u0063lear` or the ES6 code-point
#          form `\u{63}lear`) in place of the literal identifier `clear`
#        - `[` then a newline then `"clear"]()`
#        - a comment anywhere inside a bracket computed call, e.g.
#          `["clear"]/*c*/()` or `[/*c*/"clear"]()`
#        - `]` and `(` split across lines, e.g. `["clear"]` newline `();`
#      A discovery miss in any of these shapes is invisible to stage 2 and
#      silently passes. The exact-count tripwire below only catches
#      CONVERSION of an already-approved site into a wrong form; it does NOT
#      catch ADDITION of a brand-new site spelled in one of the uncovered
#      shapes above -- that case is a known, accepted hole, not a covered one.
#   2. Only the anchored NORMAL-FORM check in stage 2 is exact. Everything
#      discovery finds that does not match the normal form byte-for-byte
#      fails loudly -- no discovered candidate is ever waved through as
#      unjudgeable. (The script does abort with "cannot judge" when a
#      precondition fails -- the view root or view JS is missing, or a file
#      is unreadable -- but those are environment faults, never a verdict
#      on a candidate.)
# This invariant (over-match the covered set, then judge exactly) must never
# regress: widen discovery's regex freely, never relax the normal-form regex.
# Do NOT attempt to turn discovery into a complete JavaScript tokenizer --
# three prior redesigns tried exactly that and each one closed the shape a
# review had just named while reopening the defect class in a new shape.
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
# Exit 0 = every discovered candidate matches the approved form (or is skipped
#          as a whole-line `//` comment) and the count matches, no in-scope
#          file with a discovered call has a carriage return, and no
#          non-.js view file containing a literal `<script` tag has entered
#          this gate's scope with a call shape.
# Exit 1 = an unrecognised candidate, a wrong compliant count, a CRLF/CR file
#          that has a discovered call, or a non-.js view file containing a
#          literal `<script` tag with a call shape that has entered scope.

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

# This gate's scope is *.js only. Any non-.js file under the view root
# (.phtml, .php, .html, .md, .tpl, ...) gaining an inline <script> call site
# would be invisible to discovery above AND would not move the count
# tripwire. Assert the scope assumption still holds every run, generalised
# to every extension rather than a per-extension list that must be extended
# by hand. Only a file containing a literal `<script` tag (any case) is even
# considered, so a plain CSS/text/HTML-doc/JSON file can never trip this
# check regardless of what it contains. The check then matches a CALL SHAPE
# (`vmDataTableApi(` or an identifier/`)`/`]` followed by `.clear(`, or a
# bracket computed call `["clear"](`/`['clear'](`/`` [`clear`]( ``), not a
# bare word or a bare CSS selector, so prose like "the dataTable is nice", a
# CSS class like `mydataTableWrapper`, or a CSS rule like
# `.clear (min-width: 0)` does not false-positive the gate. The receiver
# must abut its member dot on both sides (no space before or after the dot),
# so English prose with an abbreviation -- `e.g. clear (temp)` -- is not read
# as a call. Scope-assertion known-uncovered, consequently: a call written
# `obj. clear()` or `obj .clear()` with space around the dot.
# Generic optional member access, `table?.clear()`, is also uncovered here:
# the `?` separates the receiver from the member dot. Discovery in .js files
# still sees its `.clear()` suffix; this is a scope-assertion hole only.
#
# Scope-assertion known-uncovered (distinct from discovery's known-uncovered
# list above): a `<script` tag emitted by PHP/echo/string concatenation
# rather than written literally in the source (e.g.
# `<?php echo "<scr"."ipt>"; ?>`) is invisible to the literal-substring
# prefilter below and is a known, accepted hole in this assertion.
scope_call_re='vmDataTableApi[[:space:]]*\(|[]A-Za-z0-9_$)]\.clear[[:space:]]*\(|\[[[:space:]]*["'"'"'\`]clear["'"'"'\`][[:space:]]*\][[:space:]]*\('
scope_hits=()
while IFS= read -r -d '' file; do
  case "$file" in
  *.js) continue ;;
  esac
  [ -r "$file" ] || {
    echo "FAIL: '$file' is not readable; cannot judge scope." >&2
    exit 1
  }
  grep -qiF '<script' "$file" || continue
  scope_hits+=("$file")
done < <(grep -lZE "$scope_call_re" "$views_root" -r 2>/dev/null || true)

if [ "${#scope_hits[@]}" -gt 0 ]; then
  echo "FAIL: non-.js file(s) under '$views_root' now contain a" >&2
  echo "      vmDataTableApi(...)/.clear(...)/[\"clear\"](...) call shape" >&2
  echo "      inside a <script>" >&2
  echo "      block -- this gate's scope assumption (call sites live only" >&2
  echo "      in *.js) has stopped holding. Discovery must be extended to" >&2
  echo "      cover these files' inline <script> blocks before this gate" >&2
  echo "      can judge:" >&2
  printf '      %s\n' "${scope_hits[@]}" >&2
  exit 1
fi

# Discovery: deliberately over-match every single-line `.clear` call however
# spelled -- whitespace or a tab between the dot and `clear`, whitespace
# inside the call parens, or bracket/string member access (double, single,
# or backtick quoted) -- must be caught here so stage 2 can judge it.
# Verified against: `.clear ()`, `.clear( )`, `.clear\t()`, `["clear"]()`,
# `['clear']()`, `` [`clear`]() ``, `[ "clear" ]()`, `["clear"] ()`, and the
# plain `.clear()` form. The bracket alternative requires the full
# computed-call shape -- closing quote, `]`, `(` -- so a mere property read
# or write like `obj["clearance"]`
# or `css["clear"] = "both"` does not drag a non-call into stage 2. The one
# exception, in both stages: a property read whose very next token is `(`
# -- `T["clear"] ( "en" )` -- is indistinguishable from a call by regex and
# is deliberately treated as one.
# Known-uncovered (see header): `.clear/**/()`, `.clear`/`()` split across
# lines, an aliased/indirect call (`var m='clear'; x[m]()`), a concatenated
# string (`["cle"+"ar"]()`), `.clear.apply(...)`/`.clear.call(...)`, a torn-off
# method reference (`var f=api.clear; f()`), destructuring (`var {clear}=api`),
# a unicode-escaped property (`\u0063lear` or `\u{63}lear`), or `[` newline
# `"clear"]()`, or optional invocation (`table.clear?.()`).
discovery_re='\.[[:space:]]*clear[[:space:]]*\(|\[[[:space:]]*["'"'"'\`]clear["'"'"'\`][[:space:]]*\][[:space:]]*\('

normal_form_re='^[[:space:]]*vmDataTableApi\([[:space:]]*[A-Za-z_$][A-Za-z0-9_$]*[[:space:]]*\)\.clear\(\)\.draw\(\);[[:space:]]*$'

compliant=0
violations=0
compliant_sites=()

for file in "${files[@]}"; do
  if [ ! -r "$file" ]; then
    echo "FAIL: '$file' is not readable; cannot judge." >&2
    exit 1
  fi

  # Only files that discovery actually hits are judged below, so only those
  # need exact byte-for-byte spelling; a vendored file with no clear() call
  # at all must not be taken down by an unrelated line-ending quirk.
  if ! grep -qE "$discovery_re" "$file"; then
    continue
  fi

  if LC_ALL=C grep -q $'\r' "$file"; then
    echo "FAIL: '$file' contains a carriage return (CRLF line endings, a" >&2
    echo "      lone CR, or a literal CR in the source)." >&2
    echo "      this gate is exact about spelling and refuses to silently" >&2
    echo "      normalise line endings -- convert CRLF line endings to LF," >&2
    echo "      or remove the literal carriage return from the source." >&2
    exit 1
  fi

  while IFS=: read -r lineno line; do
    [ -n "$lineno" ] || continue

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
  if [ "${#compliant_sites[@]}" -gt 0 ]; then
    printf '      %s\n' "${compliant_sites[@]}" >&2
  else
    echo "      (none)" >&2
  fi
  exit 1
fi

echo "OK: all $compliant DataTables clear() call(s) use the approved form (${#files[@]} files scanned)"
