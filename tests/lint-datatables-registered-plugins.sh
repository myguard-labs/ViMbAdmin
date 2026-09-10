#!/usr/bin/env bash
#
# VIM-A15.23. Every view-JS DataTables initialiser used to carry
# `"sPaginationType": "bootstrap"`. That is NOT a styling hint: DataTables
# resolves it as `DataTable.ext.pager["bootstrap"]` in `_fnFeatureHtmlPaginate`
# (public/js/150-jquery.datatables.js) and, when the name is not registered,
# immediately calls `plugin.fnInit(...)` on `undefined` -- a hard TypeError
# that aborts table construction. The Bootstrap 2 era shipped a
# `dataTables.bootstrap` plugin that registered that pager; the DataTables
# 1.11.5 bundle does not, and neither did the BS5 integration that replaced
# it (152-jquery.datatables.bootstrap5.js registers a
# `DataTable.ext.renderer.pageButton.bootstrap` RENDERER, which is a
# different extension point resolved by a different lookup). So the option
# survived the upgrade as a silent, latent break on every list page and no
# existing gate could see it -- the string "bootstrap" is not a BS2 grid
# token, not a BS2 component class, and not a data- attribute.
#
# This gate closes that class: a DataTables extension NAME referenced from
# our own code must be registered somewhere in the tree, or be a documented
# DataTables built-in. Two extension points are covered because they are the
# two our code names by string:
#
#   sPaginationType / pagingType -> DataTable.ext.pager[<name>]
#   renderer (string form)       -> DataTable.ext.renderer.pagingButton[<name>]
#                                   (1.x spelled this extension point
#                                   `pageButton`; DataTables 2.x renamed it to
#                                   `pagingButton` and split the container off
#                                   into `pagingContainer`. Both spellings are
#                                   harvested so this gate keeps working across
#                                   the version boundary, but ONLY a real
#                                   assignment counts -- see below.)
#
# Built-ins are taken from the vendored engine itself rather than a
# hand-written list, so a DataTables version bump cannot leave this gate
# asserting against a stale set.
#
# Any case this gate cannot judge (missing engine file, unreadable file,
# scan error) is a HARD FAILURE, never a silent skip.
#
# Exit 0 = clean, 1 = an unregistered extension name is referenced.
#
set -euo pipefail

cd "$(dirname "$0")/.."

engine='public/js/150-jquery.datatables.js'
integration='public/js/152-jquery.datatables.bootstrap5.js'

fail=0

require_file() {
  if [ ! -r "$1" ]; then
    echo "  -> required file '$1' is missing or unreadable; refusing to report a partial scan as clean." >&2
    exit 1
  fi
}

# grep exits 0 (matched), 1 (no match) or >1 (an actual error: unreadable file,
# bad regex, I/O failure). Suppressing that third case with `|| true` is how a
# gate reports success on a scan that never happened -- the exact failure class
# this gate exists to catch, so it must not commit it itself. Status 1 is a
# legitimate empty result; anything above it is a HARD FAILURE.
# Called as `x=$(strict_grep grep ...)`, so its body runs in a subshell and a
# bare `exit` there would only end that subshell. It therefore signals a scan
# error by *returning* non-zero, and every caller uses the result in a context
# where `set -e` turns that into an abort. The abort is asserted by this gate's
# own self-test rather than assumed.
strict_grep() {
  local out rc=0
  out=$("$@") || rc=$?
  if [ "$rc" -gt 1 ]; then
    echo "  -> grep exited $rc while scanning (args: $*)." >&2
    echo "  -> refusing to report a partial or failed scan as clean." >&2
    return 1
  fi
  printf '%s' "$out"
}

require_file "$engine"

# --- built-in pager names, harvested from the engine's own registration ---
# The engine registers them as `$.extend( extPagination, { simple: ..., })`
# inside its pagination block. Harvest the keys of that object literal.
builtin_pagers=$(
  awk '
    /\$\.extend\( *extPagination, *\{/ { inblock=1; next }
    inblock && /^[[:space:]]*\}[[:space:]]*\);/ { inblock=0 }
    inblock && match($0, /^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*:/, m) { print m[1] }
  ' "$engine" | sort -u
)

if [ -z "$builtin_pagers" ]; then
  echo "  -> could not harvest any built-in pager name from $engine." >&2
  echo "  -> the engine's registration shape changed; this gate cannot judge and refuses to pass." >&2
  exit 1
fi

# --- names registered by our own vendored/first-party assets ---
registered_pagers=$(
  { strict_grep grep -hPo 'DataTable\.ext\.pager\.\K[A-Za-z_][A-Za-z0-9_]*' public/js/*.js
    strict_grep grep -hPo "DataTable\.ext\.pager\[\s*['\"]\K[^'\"]+"        public/js/*.js
  } | sort -u
)

# Only an actual REGISTRATION counts, i.e. the name must be the target of an
# assignment (`... .pagingButton.bootstrap = function ...`) or appear inside an
# `$.extend( ..., { bootstrap: ... } )` block. Matching a bare mention would
# harvest names out of the engine's own doc comments -- 2.3.4's dataTables.js
# still *documents* the old `renderer.pageButton` spelling in a comment while
# registering nothing under it, which previously let this gate report the
# renderer half clean without any renderer being registered at all.
registered_renderers=$(
  { strict_grep grep -hPo 'DataTable\.ext\.renderer\.pag(?:e|ing)Button\.\K[A-Za-z_][A-Za-z0-9_]*(?=\s*=)' public/js/*.js
    strict_grep grep -hPo "renderer\.pag(?:e|ing)Button\[\s*['\"]\K[^'\"]+(?=['\"]\s*\]\s*=)"            public/js/*.js
  } | sort -u
)

known_pagers=$(printf '%s\n%s\n' "$builtin_pagers" "$registered_pagers" | sed '/^$/d' | sort -u)
# DataTables' own default renderer name when none is registered explicitly.
known_renderers=$(printf '%s\n' "$registered_renderers" | sed '/^$/d' | sort -u)

echo "== DataTables extension names referenced by our code must be registered =="
echo "  known pagers:    $(echo "$known_pagers"    | tr '\n' ' ')"
echo "  known renderers: $(echo "$known_renderers" | tr '\n' ' ')"

# --- scan our own (non-vendored) sources for referenced names ---
scan_paths=(application public/js/990-vimbadmin.js)

check_refs() {
  local label="$1" key_re="$2" known="$3" hits
  for path in "${scan_paths[@]}"; do
    if [ ! -e "$path" ]; then
      echo "  -> scan path '$path' does not exist; refusing to report a partial scan as clean." >&2
      exit 1
    fi
  done

  hits=$(strict_grep grep -rnPoI "${key_re}" "${scan_paths[@]}")
  [ -n "$hits" ] || return 0

  while IFS= read -r hit; do
    local loc name
    loc=${hit%:*}
    name=${hit##*:}
    if ! printf '%s\n' "$known" | grep -qxF -- "$name"; then
      echo "  FAIL: $loc references $label '$name', which nothing registers." >&2
      echo "        DataTables will resolve it to undefined and throw at table init." >&2
      fail=1
    fi
  done <<<"$hits"
}

check_refs "pagination type" \
  "['\"]?s?[Pp]ag(?:inationType|ingType)['\"]?\s*:\s*['\"]\K[^'\"]+" \
  "$known_pagers"

check_refs "pageButton renderer" \
  "['\"]?renderer['\"]?\s*:\s*['\"]\K[^'\"]+" \
  "$known_renderers"

# --- self-test: the gate must actually fail on the shape it exists to catch ---
echo "== self-test =="
selftest_dir=$(mktemp -d)
trap 'rm -rf "$selftest_dir"' EXIT
cat >"$selftest_dir/dirty.js" <<'EOF'
t = { "sPaginationType": "definitely_not_registered" };
EOF
if grep -qPoI "['\"]?s?[Pp]ag(?:inationType|ingType)['\"]?\s*:\s*['\"]\K[^'\"]+" "$selftest_dir/dirty.js"; then
  bad=$(grep -hPoI "['\"]?s?[Pp]ag(?:inationType|ingType)['\"]?\s*:\s*['\"]\K[^'\"]+" "$selftest_dir/dirty.js")
  if printf '%s\n' "$known_pagers" | grep -qxF -- "$bad"; then
    echo "  FAIL: self-test fixture name '$bad' unexpectedly counts as registered" >&2
    fail=1
  else
    echo "  OK: an unregistered pagination type is detected and rejected"
  fi
else
  echo "  FAIL: the pagination-type extractor did not match its own fixture" >&2
  fail=1
fi

# Negative control: a genuinely registered name must NOT be flagged.
if printf '%s\n' "$known_pagers" | grep -qxF -- 'simple_numbers'; then
  echo "  OK: built-in 'simple_numbers' harvested from the engine and accepted"
else
  echo "  FAIL: built-in 'simple_numbers' was not harvested from $engine" >&2
  fail=1
fi

if [ -r "$integration" ]; then
  if printf '%s\n' "$known_renderers" | grep -qxF -- 'bootstrap'; then
    echo "  OK: 'bootstrap' paging-button renderer registered by $integration"
  else
    echo "  FAIL: $integration is present but registers no 'bootstrap' paging-button renderer" >&2
    fail=1
  fi
fi

# The renderer harvest must reject a mere MENTION of a name. DataTables 2.3.4's
# engine documents `renderer.pageButton` in a comment but registers nothing
# under it, and an earlier version of this gate accepted that comment as proof
# the renderer existed -- a vacuous pass. Assert the harvest discriminates.
mention_only=$(mktemp)
cat >"$mention_only" <<'EOF'
// $.fn.dataTable.ext.renderer.pageButton.mentioned_only_in_a_comment
EOF
if grep -qPo 'DataTable\.ext\.renderer\.pag(?:e|ing)Button\.\K[A-Za-z_][A-Za-z0-9_]*(?=\s*=)' "$mention_only"; then
  echo "  FAIL: the renderer harvest accepts a commented mention as a registration" >&2
  fail=1
else
  echo "  OK: a commented mention of a renderer name is not harvested as registered"
fi
rm -f "$mention_only"

# The scan-error path must ABORT, not fall through to a clean verdict. Assert it
# by running this same script with a deliberately broken regex and requiring a
# non-zero exit -- the PR-Agent review of VIM-A15.23 caught exactly this shape
# (`2>/dev/null || true` letting an unreadable source read as no-matches), so
# the guarantee is tested rather than asserted in a comment.
if [ "${DT_PLUGIN_GATE_SELFTEST_CHILD:-}" != "1" ]; then
  # The child must live in this same directory: the script resolves its
  # project root with `cd "$(dirname "$0")/.."`, so a copy under a temp dir
  # would abort on the missing engine file and the assertion below would pass
  # for the wrong reason -- a vacuous control.
  broken="$(dirname "$0")/.lint-datatables-registered-plugins.broken.$$.sh"
  # shellcheck disable=SC2016  # the literal string ${key_re} is the sed
  # pattern being replaced in the child script's source, not an expansion.
  sed 's#\${key_re}#(((BROKEN#g' "$0" >"$broken"
  if DT_PLUGIN_GATE_SELFTEST_CHILD=1 bash "$broken" >/dev/null 2>&1; then
    echo "  FAIL: a broken scan regex still produced a clean exit; the error path does not abort" >&2
    fail=1
  else
    echo "  OK: a failing scan aborts instead of reporting clean"
  fi
  rm -f "$broken"
fi

if [ "$fail" -eq 0 ]; then
  echo "  OK: every referenced DataTables extension name is registered"
fi
exit "$fail"
