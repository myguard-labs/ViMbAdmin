#!/usr/bin/env bash
#
# VIM-A15.56a. DataTables 1.x `fnClearTable()` redrew by default; the 2.x
# `clear()` does not -- it only empties internal data. The obsolete manual
# search handlers that needed clear/redraw were removed; native server-side
# DataTables transport now owns searching and redraws.
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
# There are no approved call sites in the view tree. Discovery still rejects
# any new `.clear()` unless it uses the exact normal form, and the zero-count
# tripwire then requires deliberate review before even that form can return.
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
# Native server-side DataTables owns redraws; no manual clear site is approved.
expected_count=0

if [ ! -d "$views_root" ]; then
  echo "FAIL: view root '$views_root' not found; cannot judge." >&2
  exit 1
fi

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

# This gate judges an exact set of files, so selection is a stage of its own
# and gets the same treatment as judgement: one builder, one exit status, one
# count. Three separate ways of trusting a plausible-looking result have been
# caught here, all with the same shape -- a stage that reports on work it did
# not do:
#
#   1. A cardinality FLOOR ("at least N files") is not an identity check. It
#      counts without checking WHICH, so an unreadable subtree dropping N files
#      and any N unrelated pad files re-derived the same count. A genuine
#      legacy token in a view file, a chmod-000 subtree, and one pad file read
#      as "9 files scanned", exit 0. The recorded relative paths below replace
#      it: the set is pinned exactly, in both directions, which also catches
#      adding a file (a floor cannot) and never needs a count kept in sync.
#   2. `find` inside a process substitution cannot report failure. `set -e`
#      does not apply inside `< <(...)`; the loop's status is `read`'s, so a
#      permission-denied subtree made `find` exit 2 while the gate printed its
#      count and exited 0. Selection now redirects to real files, checks the
#      status, and treats `find` stderr as fatal.
#   3. A symlinked directory (or a symlinked view *.js) is invisible to
#      `find -type f` and is not authoritatively rejected the way the anchor
#      test rejects a symlinked anchor. Rejecting symlinks under the view root
#      closes that asymmetry rather than leaving it half-guarded.
#
# Verified relative paths, sorted. Adding, removing, renaming or relocating a
# view JS file is a deliberate act and must update this list -- it is never
# silently absorbed.
expected_paths=(
  application/views/admin/js/domains.js
  application/views/admin/js/list.js
  application/views/alias/js/list.js
  application/views/archive/js/list.js
  application/views/domain/js/admins.js
  application/views/domain/js/list.js
  application/views/log/js/list.js
  application/views/mailbox/js/aliases.js
  application/views/mailbox/js/list.js
)

# Emit NUL-separated matches to $2, diagnostics to $3, and return the real
# pipeline status. Deliberately a function: an inline `find ... | sort` whose
# status is discarded is exactly the defect shape being fixed here.
select_nul() {
  local out="$2" err="$3" rc=0
  find "$1" -type f -name '*.js' -print0 2>"$err" | sort -z >"$out" || rc=$?
  return "$rc"
}

read_nul_into_files() {
  local line
  while IFS= read -r -d '' line; do
    [ -n "$line" ] || continue
    files+=("$line")
  done <"$1"
}

files=()
if ! select_nul "$views_root" "$tmpdir/find.out" "$tmpdir/find.err"; then
  echo "FAIL: listing view JS under '$views_root' failed:" >&2
  sed 's/^/      /' "$tmpdir/find.err" >&2
  echo "      A partially failed listing would otherwise be reported as a" >&2
  echo "      smaller -- but still plausible -- scan count." >&2
  exit 1
fi
if [ -s "$tmpdir/find.err" ]; then
  echo "FAIL: listing view JS under '$views_root' wrote to stderr:" >&2
  sed 's/^/      /' "$tmpdir/find.err" >&2
  echo "      cannot judge the obsolete-path tripwire over a partial list." >&2
  exit 1
fi
read_nul_into_files "$tmpdir/find.out"

if [ "${#files[@]}" -eq 0 ]; then
  echo "FAIL: no view JS found under '$views_root'; cannot judge." >&2
  exit 1
fi

# Direction 1 -- every recorded path must be in the discovered set. This is
# what the old floor was reaching for, done as identity: a recorded file that
# `find -type f` did not return has gone missing, been replaced by a
# directory, been replaced by a symlink, or sits under an unreadable subtree.
for expected_path in "${expected_paths[@]}"; do
  found=0
  for file in "${files[@]}"; do
    if [ "$file" = "$expected_path" ]; then
      found=1
      break
    fi
  done
  if [ "$found" -eq 0 ]; then
    echo "FAIL: recorded view JS '$expected_path' was not returned by" >&2
    echo "      discovery ('find -type f -name '*.js'' under '$views_root')." >&2
    echo "      It is missing, a directory, a symlink, or unreadable -- so it" >&2
    echo "      would be silently unscanned. cannot judge the obsolete-path" >&2
    echo "      tripwire over the recorded set." >&2
    exit 1
  fi
done

# Direction 2 -- nothing may enter the scanned set unrecorded. A floor cannot
# catch an addition; an exact set can, and it keeps the count printed below a
# claim about a set this gate actually verified.
unrecorded=()
for file in "${files[@]}"; do
  recorded=0
  for expected_path in "${expected_paths[@]}"; do
    if [ "$file" = "$expected_path" ]; then
      recorded=1
      break
    fi
  done
  if [ "$recorded" -eq 0 ]; then
    unrecorded+=("$file")
  fi
done
if [ "${#unrecorded[@]}" -gt 0 ]; then
  echo "FAIL: view JS file(s) under '$views_root' are not in this gate's" >&2
  echo "      recorded scope:" >&2
  printf '      %s\n' "${unrecorded[@]}" >&2
  echo "      Adding a view JS file is a deliberate act: add it to" >&2
  echo "      expected_paths above, or it is scanned without being covered" >&2
  echo "      by the recorded scope." >&2
  exit 1
fi

# Reject symlinks under the view root. `find -type f` does not follow them, so
# a symlinked directory or a symlinked *.js contributes files this gate never
# scans; the anchor test already refuses a symlinked anchor, and leaving the
# non-anchor half unguarded was the asymmetry. If symlinked view JS ever
# becomes legitimate here, the fix is `find -L` at discovery plus a broken-link
# guard -- never dropping this check alone.
if ! find "$views_root" -type l -print0 >"$tmpdir/links.out" 2>"$tmpdir/links.err"; then
  echo "FAIL: scanning '$views_root' for symlinks failed:" >&2
  sed 's/^/      /' "$tmpdir/links.err" >&2
  exit 1
fi
symlinks=()
while IFS= read -r -d '' line; do
  [ -n "$line" ] || continue
  symlinks+=("$line")
done <"$tmpdir/links.out"
if [ "${#symlinks[@]}" -gt 0 ]; then
  echo "FAIL: symlink(s) under '$views_root' would add files this gate" >&2
  echo "      never scans, because discovery uses 'find -type f':" >&2
  printf '      %s\n' "${symlinks[@]}" >&2
  exit 1
fi

# The removed manual-search path must not return under a different declaration
# or configuration spelling. Match the semantic identifier and route tokens;
# adjacent identifier/route characters keep harmless longer names out.
legacy_token_re='(^|[^A-Za-z0-9_$])getEntries([^A-Za-z0-9_$]|$)|(^|[^A-Za-z0-9_-])list-search([^A-Za-z0-9_-]|$)'

# Executable negative controls cover declaration-independent handler syntax and
# an alternate endpoint configuration shape. Near-misses prove the boundaries.
for mutant in \
  'const getEntries = function () {};' \
  'const getEntries = () => {};' \
  'const config = { endpoint: "/domain/list-search" };'; do
  if ! grep -qE "$legacy_token_re" <<<"$mutant"; then
    echo "FAIL: legacy-token matcher missed negative control: $mutant" >&2
    exit 1
  fi
done
for near_miss in 'const getEntriesNew = () => {};' 'endpoint: "/domain/list-search-v2"'; do
  if grep -qE "$legacy_token_re" <<<"$near_miss"; then
    echo "FAIL: legacy-token matcher crossed a token boundary: $near_miss" >&2
    exit 1
  fi
done

# The three list views that carried the removed path must keep existing, or
# this check would silently pass by scanning nothing where it once scanned
# something. The anchor test must reject exactly what `find -type f` rejects,
# or the two stages disagree about what a file is and the anchor passes while
# the scan skips it. `-f` alone is not enough in either direction:
#   - a DIRECTORY at an anchor path is readable, so plain `-r` accepted it
#     while `-type f` dropped it -- hence `-f`;
#   - a SYMLINK to a regular file satisfies `-f` (it follows), but `find
#     -type f` does NOT follow, so the target's content is never scanned --
#     hence `-h`. A symlinked view file is an ordinary refactor artifact
#     (shared view tree, vendor relocation), not only an adversarial shape.
# If symlinked view JS ever becomes legitimate here, the fix is `find -L` at
# discovery plus a broken-link guard -- never relaxing this test alone.
# Assert them, then scan EVERY discovered view JS file -- scoping the scan to
# those three filenames would let the same dead path return in any other view
# (archive, log, admin, ...) with the gate still green.
# Known-uncovered, stated so this scope is not read as complete: the scan is
# bounded to `*.js` under the view root, so a legacy token inside a `.phtml`
# inline `<script>` block, or in JS outside the view root (`public/js/`, which
# holds the DataTables integration), is NOT covered here. The `<script>`-scope
# assertion below does not backstop it -- that one matches clear-call shapes,
# not these legacy tokens.
for anchor in \
  application/views/domain/js/list.js \
  application/views/alias/js/list.js \
  application/views/mailbox/js/list.js; do
  if [ ! -f "$anchor" ] || [ -h "$anchor" ] || [ ! -r "$anchor" ]; then
    echo "FAIL: expected view JS '$anchor' is missing, a symlink, or" >&2
    echo "      unreadable; it must be a real regular file, because" >&2
    echo "      discovery uses 'find -type f' and would not scan it." >&2
    echo "      cannot judge the obsolete-path tripwire." >&2
    exit 1
  fi
done

legacy_hits=()
for file in "${files[@]}"; do
  if grep -qE "$legacy_token_re" "$file"; then
    legacy_hits+=("$file")
  fi
done
if [ "${#legacy_hits[@]}" -gt 0 ]; then
  echo "FAIL: obsolete getEntries/list-search path found:" >&2
  printf '      %s\n' "${legacy_hits[@]}" >&2
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

# `grep -r` exits 1 for "no match" and 2 for "a read error occurred". Discarding
# both with `|| true` erases the distinction that matters: a non-.js file holding
# a call-shape <script> under an unreadable subtree passed silently, because the
# only guard was the `-r` test on files `grep` had already managed to list.
# Treat 1 as a clean negative and every other non-zero status -- and any stderr
# -- as a scope-assertion failure, so "nothing matched" is never conflated with
# "nothing could be read".
scope_candidates=()
scope_rc=0
grep -lZE "$scope_call_re" "$views_root" -r \
  >"$tmpdir/scope.out" 2>"$tmpdir/scope.err" || scope_rc=$?
case "$scope_rc" in
0 | 1) ;;
*)
  echo "FAIL: searching '$views_root' for non-.js call shapes failed" >&2
  echo "      (grep exit $scope_rc):" >&2
  sed 's/^/      /' "$tmpdir/scope.err" >&2
  echo "      a read error is not 'no match' -- files under an unreadable" >&2
  echo "      subtree may hold an inline <script> call site this assertion" >&2
  echo "      never saw." >&2
  exit 1
  ;;
esac
if [ -s "$tmpdir/scope.err" ]; then
  echo "FAIL: searching '$views_root' for non-.js call shapes wrote to" >&2
  echo "      stderr:" >&2
  sed 's/^/      /' "$tmpdir/scope.err" >&2
  exit 1
fi
while IFS= read -r -d '' line; do
  [ -n "$line" ] || continue
  scope_candidates+=("$line")
done <"$tmpdir/scope.out"

scope_hits=()
for file in "${scope_candidates[@]}"; do
  case "$file" in
  *.js) continue ;;
  esac
  [ -r "$file" ] || {
    echo "FAIL: '$file' is not readable; cannot judge scope." >&2
    exit 1
  }
  grep -qiF '<script' "$file" || continue
  scope_hits+=("$file")
done

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
