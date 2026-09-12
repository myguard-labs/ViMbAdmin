#!/bin/bash
#
# Every statically-authored Bootstrap modal in this repo must be announced by
# assistive technology. Bootstrap 5 does not do this for you: a `.modal` with a
# visible `<h*>` title still announces as an unnamed dialog unless the modal
# element carries `aria-labelledby` pointing at that title's `id`.
#
# That matters most here because the majority of these dialogs are DESTRUCTIVE
# confirmations ("Are you sure?" before a purge/delete/remove). A screen-reader
# user who cannot hear what the dialog is confirming is being asked to approve
# an irreversible action blind.
#
# Scope: statically-authored modals in application/views/**/*.phtml only.
#
# DELIBERATELY EXEMPT -- `#modal_dialog_shell` in footer.phtml and
# _skins/myskin/footer.phtml. Those are empty shells: `#modal_dialog` inside is
# replaced wholesale at runtime by the AJAX dialog loader, so there is no
# statically-authored title element for an `id` to point at, and a hard-coded
# `aria-labelledby` would be a permanently dangling reference (worse than none
# -- assistive tech falls back to nothing either way, but the dangling
# attribute hides the gap from this gate). Labelling those belongs with the
# runtime dialog work, not here.
#
# Exit 0 = every in-scope modal is labelled, 1 = at least one is not.
#
set -euo pipefail

# Resolve this script's directory ONCE, as an absolute path, BEFORE the cd
# below. `$0` is relative when the gate is invoked as
# `bash lint-modal-aria-labelledby.sh` from inside tests/, so a second
# `$(dirname "$0")` evaluated after the cd would re-resolve against the repo
# root, not tests/. (VIM-A15.54, the same silent-wrong-path class as the
# VIM-A15.31 gates; pinned by tests/test-lint-gate-cwd-independence.sh.)
script_dir=$(unset CDPATH; cd -- "$(dirname -- "$0")" && pwd)

cd "$script_dir/.."

if [ "${BASH_VERSINFO[0]:-0}" -lt 4 ]; then
  echo "  -> bash 4+ required for globstar; refusing to run a partial scan." >&2
  exit 1
fi

# Shared opening-tag extractor: reads from `<` to the first UNQUOTED `>`,
# spanning multiple lines and returning one record per element (so two
# elements on the same line are two records, not one). See
# tests/support/html-opening-tags.sh for the PR #171 history this replaces.
# shellcheck source=tests/support/html-opening-tags.sh
source "$script_dir/support/html-opening-tags.sh"

# Modal ids whose content is injected at runtime; see the header comment.
runtime_shell_ids='modal_dialog_shell'

# check_file FILE
# For each `.modal` opening tag in FILE, require an aria-labelledby on that same
# tag AND an element carrying the referenced id.
#
# Modal elements are matched on the CLASS ALONE, independent of attribute order
# and of whether an id is present. An earlier revision anchored on
# `<div id="..." ... class="modal ...">`; that silently skipped
# `<div class="modal fade">` and `<div class="modal fade" id="confirm">`, so
# unlabelled dialogs written in either of those equally valid orders passed the
# gate. Attribute order is not a property this gate may depend on.
check_file() {
  local f="$1" fail=0 num tag id label
  while IFS=$'\t' read -r num tag; do
    [ -n "$tag" ] || continue

    # Read the attribute by NAME through the shared reader, which walks the
    # tag attribute by attribute and steps over quoted values. A regex over
    # the raw tag text cannot do this: `\bclass` also matches the tail of
    # `data-class=` / `ng-class=` (a hyphen is a word boundary), and no regex
    # over the whole tag can tell an attribute name from the same text sitting
    # inside ANOTHER attribute's quoted value, as in
    # `<div data-example='class="modal"'>`. Both were PR #177 review findings.
    local classes
    classes=$(tag_attr "$tag" class)
    [ -n "$classes" ] || continue

    # Word-bounded within the class list so the legitimately unlabelled
    # `modal-dialog` / `modal-content` wrappers are not matched. Tokenised one
    # per line and anchored, because a leading-position class can never match
    # a pattern that requires a preceding space (carried trap 12).
    tr -s '[:space:]' '\n' <<<"$classes" | grep -qx 'modal' || continue

    id=$(tag_attr "$tag" id)
    label=$(tag_attr "$tag" aria-labelledby)

    if [ -n "$id" ] && grep -qx "$runtime_shell_ids" <<<"$id"; then
      continue
    fi

    if [ -z "$label" ]; then
      echo "  Modal ${id:+#$id }has no aria-labelledby in $f:$num"
      fail=1
      continue
    fi
    # The referenced id must actually exist in the same template, or assistive
    # technology resolves the reference to nothing and announces no name.
    # Compare the referenced id as LITERAL TEXT. Interpolating it into a regex
    # let its metacharacters widen the match: aria-labelledby="title.label"
    # resolved against id="titleXlabel", so a dangling reference passed the very
    # check this gate exists to make.
    #
    # `grep -oP` (not a greedy `sed 's/.*id=.../'`) so EVERY id= in the file is
    # collected, not just the last one on a line -- a greedy `.*` prefix drops
    # every id but the rightmost when two elements share a line, exactly the
    # multi-element-per-line failure mode this gate exists to close.
    if ! grep -oP '[[:space:]]id\s*=\s*["'"'"'][^"'"'"']*["'"'"']' "$f" |
      sed -E 's/.*=\s*["'"'"']([^"'"'"']*)["'"'"']/\1/' |
      grep -Fqx -- "$label"; then
      echo "  Modal ${id:+#$id }in $f:$num points aria-labelledby at \"$label\", which no element in that file defines"
      fail=1
    fi
  done < <(extract_opening_tags "$f")
  return "$fail"
}

scan_files() {
  local files=("$@") f fail=0
  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi
  for f in "${files[@]}"; do
    check_file "$f" || fail=1
  done
  return "$fail"
}

self_test() {
  local tmpdir status=0
  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  echo "== self-test: an unlabelled modal must be caught =="
  cat >"$tmpdir/unlabelled.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-hidden="true">
    <h3 class="modal-title">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/unlabelled.phtml" >/dev/null 2>&1; then
    echo "  FAIL: unlabelled modal was not caught" >&2
    status=1
  else
    echo "  OK: unlabelled modal caught"
  fi

  echo "== self-test: attribute order and a missing id must not let a modal escape =="
  # Regression guard for the original defect: the scan anchored on
  # `<div id="..." ... class="modal ...">`, so both of these -- equally valid
  # markup -- were skipped entirely rather than reported.
  cat >"$tmpdir/order.phtml" <<'EOF'
<div class="modal fade" tabindex="-1">
    <h3 class="modal-title">No id at all, class first</h3>
</div>
<div class="modal fade" id="confirm" tabindex="-1">
    <h3 class="modal-title">Class before id</h3>
</div>
EOF
  local order_hits
  order_hits=$(check_file "$tmpdir/order.phtml" | grep -c 'has no aria-labelledby' || true)
  if [ "$order_hits" -eq 2 ]; then
    echo "  OK: both attribute-order variants caught"
  else
    echo "  FAIL: expected 2 unlabelled modals caught regardless of attribute order, got $order_hits" >&2
    status=1
  fi

  echo "== self-test: modal-dialog / modal-content wrappers must not be treated as modals =="
  # The class match must be word-bounded: these inner wrappers legitimately
  # carry no aria-labelledby, and flagging them would make the gate unusable.
  cat >"$tmpdir/wrappers.phtml" <<'EOF'
<div class="modal fade" id="d" aria-labelledby="d_label">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <h3 class="modal-title" id="d_label">Title</h3>
        </div>
    </div>
</div>
EOF
  if scan_files "$tmpdir/wrappers.phtml" >/dev/null 2>&1; then
    echo "  OK: modal-dialog/modal-content wrappers not treated as modals"
  else
    echo "  FAIL: an inner modal-* wrapper was flagged as an unlabelled modal" >&2
    status=1
  fi

  echo "== self-test: single-quoted class and id attributes are handled =="
  cat >"$tmpdir/quotes.phtml" <<'EOF'
<div class='modal fade' id='q_bad' tabindex="-1">
    <h3 class="modal-title">Unlabelled, single-quoted</h3>
</div>
EOF
  if scan_files "$tmpdir/quotes.phtml" >/dev/null 2>&1; then
    echo "  FAIL: a single-quoted unlabelled modal escaped the gate" >&2
    status=1
  else
    echo "  OK: single-quoted unlabelled modal caught"
  fi
  cat >"$tmpdir/quotes-good.phtml" <<'EOF'
<div class='modal fade' id='q_ok' aria-labelledby='q_ok_label'>
    <h3 class='modal-title' id='q_ok_label'>Labelled, single-quoted</h3>
</div>
EOF
  if scan_files "$tmpdir/quotes-good.phtml" >/dev/null 2>&1; then
    echo "  OK: single-quoted labelled modal accepted, id reference resolved"
  else
    echo "  FAIL: false positive on a correctly labelled single-quoted modal" >&2
    status=1
  fi

  echo "== self-test: an unlabelled multi-line opening tag must be read and caught, not skipped =="
  # The shared extractor spans a tag across lines, so this must be judged
  # exactly like any single-line unlabelled modal -- not silently skipped.
  cat >"$tmpdir/multiline.phtml" <<'EOF'
<div
    class="modal fade" id="ml">
    <h3 class="modal-title">Split opening tag</h3>
</div>
EOF
  local multiline_msg
  multiline_msg=$(check_file "$tmpdir/multiline.phtml" || true)
  if grep -q 'has no aria-labelledby' <<<"$multiline_msg"; then
    echo "  OK: split modal opening tag read whole and caught for the right reason"
  else
    echo "  FAIL: split modal opening tag was not correctly judged: $multiline_msg" >&2
    status=1
  fi

  echo "== self-test: a correctly labelled multi-line opening tag must not false-positive =="
  # Proves the tag is genuinely parsed (attributes on the second physical
  # line are read), not just always-red on any multi-line input.
  cat >"$tmpdir/multiline-good.phtml" <<'EOF'
<div
    class="modal fade" id="ml_ok" aria-labelledby="ml_ok_label">
    <h3 class="modal-title" id="ml_ok_label">Split opening tag, labelled</h3>
</div>
EOF
  if scan_files "$tmpdir/multiline-good.phtml" >/dev/null 2>&1; then
    echo "  OK: correctly labelled multi-line modal accepted"
  else
    echo "  FAIL: false positive on a correctly labelled multi-line modal" >&2
    status=1
  fi

  echo "== self-test: only a real class ATTRIBUTE counts -- not a name tail, not a quoted value =="
  # `\bclass` treats the hyphen in `data-class` as a word boundary and matches
  # the tail, so `<div data-class="modal">` was classified as a modal and failed
  # for a missing aria-labelledby. Anchored with `(?<![-\w])` instead. PR #177.
  cat >"$tmpdir/hyphen-attr.phtml" <<'EOF'
<div data-class="modal" id="dc">Not a modal</div>
<div ng-class="modal" id="ngc">Also not a modal</div>
<div data-example='class="modal"' id="nested">class= inside another value</div>
EOF
  local hyphen_msg
  hyphen_msg=$(check_file "$tmpdir/hyphen-attr.phtml" || true)
  if [[ -n "$hyphen_msg" ]]; then
    echo "  FAIL: a hyphenated *-class attribute was treated as class=: $hyphen_msg" >&2
    status=1
  else
    echo "  OK: data-class / ng-class are not mistaken for class="
  fi

  echo "== self-test: two modals on ONE line -- the SECOND must be scanned, not lost =="
  # This is the concrete open bug the shared extractor exists to fix:
  # grep -nP + tag="\${tag%%>*}>" kept only the first opening tag on a line, so
  # a labelled modal followed by an unlabelled one on the SAME line meant the
  # second modal was never scanned at all -- the gate exited 1 only by
  # accident, misreporting the FIRST modal's aria-labelledby as dangling.
  cat >"$tmpdir/two-on-one-line.phtml" <<'EOF'
<div class="modal fade" id="first" aria-labelledby="first_label"><h3 id="first_label">First</h3></div><div class="modal fade" id="second"><h3 id="second_title">Second, unlabelled</h3></div>
EOF
  local twomodal_msg
  twomodal_msg=$(check_file "$tmpdir/two-on-one-line.phtml" || true)
  if grep -q 'Modal #second has no aria-labelledby' <<<"$twomodal_msg"; then
    echo "  OK: second modal on the same line is scanned and named as the failure"
  else
    echo "  FAIL: second same-line modal was not correctly named as unlabelled: $twomodal_msg" >&2
    status=1
  fi
  if grep -q '#first' <<<"$twomodal_msg"; then
    echo "  FAIL: the correctly labelled first modal was misreported: $twomodal_msg" >&2
    status=1
  else
    echo "  OK: the correctly labelled first modal was not misreported"
  fi

  echo "== self-test: a DANGLING aria-labelledby must be caught, not accepted =="
  # This is the failure mode a naive `grep -c aria-labelledby` gate misses
  # entirely: the attribute is present, so a presence check passes, but it
  # names an id no element defines and the dialog still announces unnamed.
  cat >"$tmpdir/dangling.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" aria-labelledby="does_not_exist" aria-hidden="true">
    <h3 class="modal-title" id="purge_dialog_label">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/dangling.phtml" >/dev/null 2>&1; then
    echo "  FAIL: dangling aria-labelledby was accepted" >&2
    status=1
  else
    echo "  OK: dangling aria-labelledby caught"
  fi

  echo "== self-test: regex metacharacters in the referenced id must not widen the match =="
  # The referenced id is compared as literal text. Interpolated into a regex,
  # "title.label" matched id="titleXlabel" and a dangling reference passed the
  # dangling check -- defeating the gate's main purpose.
  cat >"$tmpdir/metachar.phtml" <<'EOF'
<div class="modal fade" id="meta" aria-labelledby="title.label">
    <h3 class="modal-title" id="titleXlabel">Dangling, but regex-matched</h3>
</div>
EOF
  if scan_files "$tmpdir/metachar.phtml" >/dev/null 2>&1; then
    echo "  FAIL: a dangling reference passed because its metacharacter matched another id" >&2
    status=1
  else
    echo "  OK: referenced id compared literally, metacharacter did not widen the match"
  fi

  echo "== self-test: inline markup must not credit a child's attributes to the modal =="
  # grep returns the whole line, so without narrowing to the modal's own opening
  # tag the <h3>'s aria-labelledby was read as the modal's and the unlabelled
  # dialog passed.
  cat >"$tmpdir/inline.phtml" <<'EOF'
<div class="modal fade" id="inl"><h3 id="t" aria-labelledby="t">Title</h3></div>
EOF
  if scan_files "$tmpdir/inline.phtml" >/dev/null 2>&1; then
    echo "  FAIL: a child element's aria-labelledby was credited to the modal" >&2
    status=1
  else
    echo "  OK: inline child attributes not credited to the modal"
  fi

  echo "== self-test: a modal on a non-div element must still be scanned =="
  # Bootstrap identifies a modal by its .modal class, not by tag name.
  cat >"$tmpdir/section.phtml" <<'EOF'
<section class="modal fade" id="sec" tabindex="-1">
    <h3 class="modal-title">Unlabelled section modal</h3>
</section>
EOF
  if scan_files "$tmpdir/section.phtml" >/dev/null 2>&1; then
    echo "  FAIL: an unlabelled non-div modal escaped the gate" >&2
    status=1
  else
    echo "  OK: non-div modal scanned"
  fi

  echo "== self-test: whitespace around = must not bypass discovery =="
  # HTML permits `class = "modal fade"`. Requiring a bare `class=` meant such a
  # modal never reached check_file at all -- the gate passed without ever having
  # examined it, which is a bypass rather than a formatting nit.
  cat >"$tmpdir/wsattr.phtml" <<'EOF'
<div class = "modal fade" id = "ws" tabindex="-1">
    <h3 class="modal-title">Unlabelled, spaced attributes</h3>
</div>
EOF
  if scan_files "$tmpdir/wsattr.phtml" >/dev/null 2>&1; then
    echo "  FAIL: a modal written with whitespace around = bypassed discovery" >&2
    status=1
  else
    echo "  OK: whitespace around = does not bypass discovery"
  fi
  cat >"$tmpdir/wsattr-good.phtml" <<'EOF'
<div class = "modal fade" id = "wsok" aria-labelledby = "wsok_label">
    <h3 class="modal-title" id = "wsok_label">Labelled, spaced attributes</h3>
</div>
EOF
  if scan_files "$tmpdir/wsattr-good.phtml" >/dev/null 2>&1; then
    echo "  OK: spaced aria-labelledby and id extracted and resolved"
  else
    echo "  FAIL: false positive on a correctly labelled modal with spaced attributes" >&2
    status=1
  fi

  echo "== self-test: a correctly labelled modal must not false-positive =="
  cat >"$tmpdir/good.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-labelledby="purge_dialog_label" aria-hidden="true">
    <h3 class="modal-title" id="purge_dialog_label">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/good.phtml" >/dev/null 2>&1; then
    echo "  OK: correctly labelled modal accepted"
  else
    echo "  FAIL: false positive on a correctly labelled modal" >&2
    status=1
  fi

  echo "== self-test: the runtime shell is exempt by id, and only by id =="
  cat >"$tmpdir/shell.phtml" <<'EOF'
<div id="modal_dialog_shell" class="modal fade" tabindex="-1" aria-hidden="true">
    <div id="modal_dialog" class="modal-content"> </div>
</div>
EOF
  if scan_files "$tmpdir/shell.phtml" >/dev/null 2>&1; then
    echo "  OK: runtime shell exempted"
  else
    echo "  FAIL: runtime shell was flagged despite its exemption" >&2
    status=1
  fi
  # Prove the exemption is the id and not the shape: the same markup under any
  # other id must still be caught, so renaming the shell cannot silently widen
  # the exemption.
  sed 's/modal_dialog_shell/some_other_dialog/' "$tmpdir/shell.phtml" >"$tmpdir/shell-renamed.phtml"
  if scan_files "$tmpdir/shell-renamed.phtml" >/dev/null 2>&1; then
    echo "  FAIL: exemption is shape-based, not id-based; renaming the shell escapes the gate" >&2
    status=1
  else
    echo "  OK: exemption is keyed on the id, not the markup shape"
  fi

  return "$status"
}

echo "== statically-authored Bootstrap modals must carry a resolvable aria-labelledby =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob globstar
files=(application/views/**/*.phtml)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Own template inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: every statically-authored modal is labelled (${#files[@]} templates scanned)"
  exit 0
else
  echo "  -> Add aria-labelledby to the modal element and an id to its visible title." >&2
  exit 1
fi
