#!/usr/bin/env bash
#
# VIM-A15.23 removed the hard-coded Bootstrap 2 `sDom`/`dom` domPositioning
# strings (`<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>`) from
# every view-JS DataTables initialiser and let the vendored
# `public/js/152-datatables.bootstrap5.js` integration's own default
# `dom` string apply instead. tests/lint-bs2-grid-classes.sh deliberately
# does NOT scan these strings (see its own comment) because `span6` there is
# a DataTables domPositioning TOKEN, not a CSS class in a `class="..."`
# attribute -- this gate is the one that closes that gap. A reintroduced
# `span6`/BS2 token inside an `sDom`/`dom` string does not fail loudly: the
# table still renders, just with dead Bootstrap 2 wrapper divs that
# Bootstrap 5 has no `.span*`/`.row-fluid` rules for, so the layout silently
# collapses to a stacked block exactly like the class-attribute case.
#
# Scope: the whole tree (own view-JS plus anything else that might carry a
# hand-written dom string), minus vendored/generated assets (public/css/**,
# public/js/**) and every tests/lint-*.sh script -- several sibling gates
# (lint-bs2-component-classes.sh, lint-bs2-grid-classes.sh) carry their OWN
# `"sDom": "<'row'<'span6'..."` self-test fixtures in heredocs, proving THEY
# correctly ignore dom strings as out of their scope. Those fixtures are
# shell test data, not markup that ships to a browser, so scanning them here
# would flag every sibling gate's own negative-control fixture as a false
# "regression" on every run.
#
# Approach: PLAIN LITERAL-TOKEN matching, not attribute/string parsing. A
# hand-rolled parser for the `sDom`/`dom` value's quoting (single vs double,
# `sDom` vs 1.11's also-valid `dom` key, whitespace around `:`) has to meet
# every spelling variant its author enumerates -- and DataTables domPositioning
# syntax is only ever a short character-class token list joined by the
# structural DOM syntax (`<'...'...>` / `t` / `r` / `i` / `p` / `l` / `f`),
# never a sentence with `span6` as one word among many. So: find any `dom`
# or `sDom` KEY (case-insensitive on the JS property name, both quoted and
# unquoted forms), and if a BS2 grid token (`span1`-`span12`, `row-fluid`)
# appears ANYWHERE in the same statement -- from the key up to the
# terminating `,`/`}`/`;` -- flag it. This survives quote style and
# whitespace variation without needing to parse either.
#
# False friends `spanner` / `span6x` must NOT match: token boundaries are
# enforced with `\b...\b` scoped to the exact span1-span12 set (mirroring
# lint-bs2-grid-classes.sh's own numeric bound -- Bootstrap 2 only ever
# defined span1-span12, so span13+ is not a Bootstrap 2 leftover).
#
# Exit 0 = clean, 1 = a BS2 grid token was found inside a dom/sDom value.
# Any case this gate cannot judge (unreadable file, scan error) is a HARD
# FAILURE, never a silent skip.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# BS2 grid tokens: span1-span12 (word-bounded, so span13+/spanner/span6x
# never match) and row-fluid.
bs2_tokens_re='\b(row-fluid|span(1[0-2]|[1-9]))\b'

# A `dom`/`sDom` key (quoted "dom"/'dom'/"sDom"/'sDom', or bare dom: in a JS
# object literal), case-insensitive, followed eventually by a BS2 grid
# token before the statement closes. We do this in two stages exactly like
# lint-bs2-grid-classes.sh: stage 1 finds candidate LINES cheaply, stage 2
# confirms token boundaries precisely -- keeps the regex simple and the
# false-friend logic auditable.
key_re='["'"'"']?\bs?dom\b["'"'"']?[[:space:]]*:'

scan_files() {
  local files=("$@") fail=0 file_list hits_file rc=0

  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi

  file_list=$(mktemp)
  hits_file=$(mktemp)
  printf '%s\0' "${files[@]}" >"$file_list"

  # -z: NUL-delimited records so a dom string split across lines (wrapped
  # attribute values) is still scanned as one unit, matching how
  # lint-bs2-grid-classes.sh handles multi-line class attributes. -P for
  # Perl regex (\b support with -z). Print per-file:per-match with -o so a
  # hit is unambiguous in the log.
  #
  # We scan for the key, then require a BS2 token within the next 200
  # characters (comfortably longer than any real dom string, short enough
  # to not spill into the next statement in a minified/dense file).
  xargs -0 -a "$file_list" -I{} grep -PazoiI \
    "(?s)${key_re}[^;,}]{0,200}?${bs2_tokens_re}" -- {} \
    >"$hits_file" 2>"$hits_file.err" || rc=$?

  if [ -s "$hits_file" ]; then
    # Report which files matched (grep -z loses -n; re-grep each hit file
    # normally, best-effort, purely for a human-readable line number).
    while IFS= read -r -d '' f; do
      if grep -PznoiI "(?s)${key_re}[^;,}]{0,200}?${bs2_tokens_re}" "$f" >/dev/null 2>&1; then
        grep -PnoiI "${bs2_tokens_re}" "$f" | sed "s#^#${f}:#"
      fi
    done <"$file_list"
    fail=1
  elif [ -s "$hits_file.err" ]; then
    cat "$hits_file.err" >&2
    echo "  -> grep reported an error while scanning; refusing to report a partial scan as clean." >&2
    fail=1
  elif [ "$rc" -gt 1 ] && [ "$rc" -ne 123 ]; then
    echo "  -> xargs exited $rc unexpectedly; refusing to report a partial scan as clean." >&2
    fail=1
  fi

  rm -f "$file_list" "$hits_file" "$hits_file.err"

  return "$fail"
}

self_test() {
  local tmpdir status=0
  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  echo "== self-test: seeded BS2 dom-string shapes must be caught =="
  local dirty="$tmpdir/dirty.js"
  cat >"$dirty" <<'EOF'
a1 = { "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>" };
a2 = { 'sDom': '<\'row\'<\'span6\'l>>' };
a3 = { dom: "<'row-fluid'<'span6'l>>" };
a4 = { SDOM : "<'row'<'span6'l>>" };
a5 = {
    "sDom":
        "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
};
a6 = { "sDom": "<'row'<'  span6  '  l>>" };
a7 = { "other": "span6", "sDom": "<'row'<'span6'l>>" };
EOF
  local expected=7 actual
  actual=0
  while IFS= read -r -d '' f; do
    if grep -PznoiI "(?s)${key_re}[^;,}]{0,200}?${bs2_tokens_re}" "$f" >/dev/null 2>&1; then
      actual=$((actual + $(grep -Pzoi "(?s)${key_re}[^;,}]{0,200}?${bs2_tokens_re}" "$f" | tr -cd '\0' | wc -c)))
    fi
  done < <(printf '%s\0' "$dirty")
  if [ "$actual" -eq "$expected" ]; then
    echo "  OK: all $expected seeded dom/sDom BS2-token shapes detected"
  else
    echo "  FAIL: expected $expected statement-hits in $dirty, got $actual" >&2
    status=1
  fi

  echo "== self-test: the vendored BS5 default dom string must not false-positive =="
  local clean="$tmpdir/clean.js"
  cat >"$clean" <<'EOF'
a1 = { "sPaginationType": "bootstrap" };
a2 = { "sDom": "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" };
a3 = { dom: "<'row'<'col-md-6'l><'col-md-6'f>r>t<'row'<'col-md-6'i><'col-md-6'p>>" };
EOF
  if scan_files "$clean" >/dev/null 2>&1; then
    echo "  OK: BS5 col-md-* dom strings and unrelated sPaginationType key reported clean"
  else
    echo "  FAIL: scan false-positived on a BS5 dom string or unrelated key" >&2
    status=1
  fi

  echo "== self-test: false friends (spanner, span6x, span13+) must not false-positive =="
  local lookalike="$tmpdir/lookalike.js"
  cat >"$lookalike" <<'EOF'
a1 = { "sDom": "<'row'<'spanner'l>>" };
a2 = { "sDom": "<'row'<'span6x'l>>" };
a3 = { "sDom": "<'row'<'span13'l>>" };
a4 = { "sDomething": "<'row'<'span6'l>>" };
a5 = "the wingspan6 of a plane";
EOF
  if scan_files "$lookalike" >/dev/null 2>&1; then
    echo "  OK: spanner/span6x/span13/sDomething/wingspan6 not flagged"
  else
    echo "  FAIL: scan incorrectly flagged a false-friend token" >&2
    status=1
  fi

  return "$status"
}

echo "== no BS2 grid token inside a DataTables dom/sDom string anywhere in the tree =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob globstar

inventory=$(mktemp)
trap 'rm -f "$inventory"' EXIT
# Exclude .git (not shipped content), this gate's own script (self-test
# fixtures must contain the literal tokens), and the same vendored/generated
# asset classes lint-bs2-grid-classes.sh excludes: public/css, public/js
# (vendored DataTables/Bootstrap ship no dom string of their own to flag --
# 152-datatables.bootstrap5.js's own default uses col-md-*, not
# span6, so excluding it costs nothing but keeps this gate's scope aligned
# with its sibling) and any min.bundle-v* generated artifact.
if ! find . -type d -name .git -prune \
  -o -path './public/css' -prune \
  -o -path './public/js' -prune \
  -o -path './tests/lint-*.sh' -prune \
  -o -type f -print0 >"$inventory"; then
  echo "  -> find failed while building the project file inventory; refusing to report a partial scan as clean." >&2
  exit 1
fi
mapfile -d '' -t files <"$inventory"

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Project file inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: no BS2 grid token found in a dom/sDom string (${#files[@]} files scanned)"
  exit 0
else
  exit 1
fi
