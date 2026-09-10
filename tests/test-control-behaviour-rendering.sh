#!/usr/bin/env bash

# VIM-A15.25: extend the headless lane past static class matching. Every
# tests/lint-*.sh gate proves a BS2-only class token is absent from source; none
# of them proves a migrated Bootstrap 5 control actually OPERATES when clicked
# in a real browser. VIM-A15.23 shipped exactly that gap: a DataTables pager
# config that named an unregistered plugin, so every list page threw a
# TypeError at table construction while every static gate stayed green. A
# fixture that only diffs class names would have stayed green too.
#
# This drives the two Bootstrap 5 controls that are unconditionally reachable
# on alias/list.phtml in a real browser and asserts they OPERATE, not merely
# that their markup carries the right class:
#
#   1. modal    -- clicking a row's delete button calls deleteAlias(), which
#                  calls ossModal('#purge_dialog') (public/js/850-bootbox.js),
#                  which must actually show the Bootstrap 5 modal.
#   2. alert-dismiss -- ossAddMessage() (public/js/990-vimbadmin.js) emits an
#                  alert with data-bs-dismiss="alert"; Bootstrap 5's own Alert
#                  component (enableDismissTrigger, public/js/800-bootstrap.js)
#                  must actually remove it on click.
#   3. alert-page-header-fallback -- ossAddMessage()'s .page-content branch
#                  (VIM-A15.53) must still insert the alert when .page-header
#                  is absent, instead of silently discarding it via .after()
#                  on an empty jQuery set.
#
# DELIBERATELY EXEMPT -- dropdown and tab.
#
#   - #plugin_tabs (990-vimbadmin.js addPluginTab()) is populated only by a
#     plugin; nothing in this repo ever calls addPluginTab(). Established
#     exemption, carried from VIM-A15.32's aria-labelledby gate.
#   - The row-action dropdown-menu markup in alias/list.phtml, mailbox/list.phtml
#     and domain/list.phtml is gated behind `{if isset($action_list_menu)}` /
#     `{if isset($alias_actions)}`. Grepping every controller and config under
#     application/ for `alias_actions` finds no assignment anywhere in this
#     repo -- the block never renders without a plugin setting that variable.
#   - ossDropdown() and its four `.each(ossDropdown)` call sites were removed
#     entirely (VIM-A15.39). git history showed `class="oss-dropdown"` was
#     only ever emitted by the ZF1 form classes
#     library/ViMbAdmin/Form/{Alias,Mailbox}/AddEdit.php, both deleted whole
#     in a11fddd ("final push zf1 removal"); their native-*.phtml
#     replacements render no `<select>` at all, so nothing has produced the
#     class since the ZF1 removal. The function still received Bootstrap 5
#     upkeep after that (VIM-A15.20 renamed its data-toggle to
#     data-bs-toggle), which only shows nobody had checked reachability, not
#     that the feature was live. There was never a Bootstrap-migration
#     regression to fix, so there is nothing left here to assert against.
#
#   The dropdown-menu ($action_list_menu/$alias_actions) case above is a
#   different failure shape: gated behind a plugin variable core code never
#   sets. It is keyed the same way for this fixture's purposes: isset()/
#   class-selector gates a control this repo's own code can never produce, so
#   asserting it would mean fabricating input no real deployment of this repo
#   alone ever supplies.
#
set -euo pipefail

cd "$(dirname "$0")/.."

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the control-behaviour rendering regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-control-behaviour.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

# Stage the SOURCE JavaScript, not public/js/min.bundle-v19.js.
#
# Loading the bundle made both behavioural assertions below VACUOUS: neutering
# ossModal()'s .show() in public/js/850-bootbox.js, or swapping data-bs-dismiss
# back to data-dismiss in public/js/990-vimbadmin.js, left this gate green,
# because neither file was ever loaded. The bundle is hand-regenerated
# (VIM-A15.36), was last rebuilt in PR #168, and already omits changes to
# 990-vimbadmin.js plus the whole of 152-jquery.datatables.bootstrap5.js. A
# negative control that mutates the bundle proves only that the harness reacts
# to the bundle.
#
# The fixture parses the authoritative load order out of header-js.phtml's
# non-minified branch, so staging here is a plain directory copy and the two
# cannot drift apart.
js_dir="$tmp/js"
mkdir -p "$js_dir"
cp public/js/*.js "$js_dir/"

if [[ -n ${PHP_RENDERER:-} ]]; then
  PHP_CONTAINER_WRITE_DIR=$tmp \
    "$PHP_RENDERER" tests/render-control-behaviour-fixture.php \
    "$tmp/regression.html" "$js_dir"
else
  php tests/render-control-behaviour-fixture.php "$tmp/regression.html" "$js_dir"
fi

# --- Half 1: no BS2-only class survives in the RENDERED output. ---
# tests/lint-bs2-component-classes.sh and tests/lint-bs2-grid-classes.sh already
# scan source templates and view-JS exhaustively; this is a second, independent
# check on what the browser actually receives after Smarty has resolved every
# {if}/{foreach} branch and {tmplinclude}, complementing rather than
# duplicating the source scan (a class token could in principle survive
# template composition even if no single source file carries it token-for-
# token; this fixture is where that would be caught).
# The class list is extracted one token per line first, so the token boundary
# is the whole line. Anchoring on (^|[[:space:]]) against a raw class="..."
# attribute would silently never match the FIRST token in an attribute, whose
# left neighbour is the quote character -- a control that looked green because
# the regex could not fire.
bs2_tokens_re='^(well|label|label-important|label-success|label-info|label-warning|btn-mini|btn-small|pull-right|pull-left|alert-error|nav-collapse|navbar-inner)$'
rendered_class_tokens() {
  grep -oE 'class="[^"]*"' "$tmp/regression.html" |
    sed -e 's/^class="//' -e 's/"$//' |
    tr '[:space:]' '\n' |
    grep -v '^$'
}
if rendered_class_tokens | grep -qE "$bs2_tokens_re"; then
  echo "FAIL: rendered alias/list.phtml carries a Bootstrap 2 class token" >&2
  rendered_class_tokens | grep -E "$bs2_tokens_re" | sort -u >&2
  exit 1
fi

# --- Half 2: each migrated control OPERATES. ---
# Append a driver that clicks the real, production-rendered delete button
# (which calls the real deleteAlias() from {tmplinclude}'d alias/js/list.js)
# and separately raises a real ossAddMessage() alert, then asserts each
# control's OWN observable marker rather than a shared before/after diff --
# a modal and an alert dismissal don't share one oracle.
{
  printf '<script nonce="">\n'
  cat <<'HTML'
$(function () {
    var failures = [];

    function assertModalOperates(done) {
        var modalEl = document.getElementById('purge_dialog');
        if (!modalEl) {
            failures.push('modal: #purge_dialog not rendered');
            return done();
        }
        if (modalEl.classList.contains('show')) {
            failures.push('modal: already shown before any click -- fixture is not isolating the trigger');
        }

        var deleteButton = document.getElementById('delete-alias-41');
        if (!deleteButton) {
            failures.push('modal: production delete button (#delete-alias-41) not rendered');
            return done();
        }

        deleteButton.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        // Bootstrap 5's Modal.show() runs a CSS transition before adding
        // .show; give it real time in a real browser rather than asserting
        // synchronously against an event that hasn't finished yet.
        setTimeout(function () {
            var shown = modalEl.classList.contains('show') && modalEl.style.display === 'block';
            if (!shown) {
                failures.push('modal: #purge_dialog did not open on delete-button click (class=' +
                    modalEl.className + ' display=' + modalEl.style.display + ')');
            }
            done();
        }, 400);
    }

    function assertAlertDismissOperates(done) {
        var before = document.querySelectorAll('.alert').length;
        ossAddMessage('control-behaviour regression alert', 'info', false);
        var afterAdd = document.querySelectorAll('.alert').length;
        if (afterAdd !== before + 1) {
            failures.push('alert-dismiss: ossAddMessage() did not insert a new .alert (before=' +
                before + ' after=' + afterAdd + ')');
            return done();
        }

        var alertEl = document.querySelectorAll('.alert')[document.querySelectorAll('.alert').length - 1];
        var dismissButton = alertEl.querySelector('[data-bs-dismiss="alert"]');
        if (!dismissButton) {
            failures.push('alert-dismiss: inserted alert has no data-bs-dismiss="alert" control');
            return done();
        }

        dismissButton.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        // Bootstrap 5's Alert.close() removes the element after its own fade
        // transition (CLASS_NAME_SHOW removed synchronously, element removed
        // via a queued callback), so give it real time rather than asserting
        // against an in-flight transition.
        setTimeout(function () {
            var stillPresent = document.body.contains(alertEl);
            if (stillPresent) {
                failures.push('alert-dismiss: alert was not removed after clicking its dismiss control');
            }
            done();
        }, 400);
    }

    function assertAlertLandsWithoutPageHeader(done) {
        // VIM-A15.53: ossAddMessage()'s .page-content branch tested
        // $('.page-content').length but inserted relative to
        // $('.page-header'), which the PRECEDING branch already proved is
        // absent on this path -- .after() ran on an empty jQuery set and the
        // alert was silently discarded. Every real page ships .page-header
        // alongside .page-content (see application/views/*/list.phtml etc.),
        // so this branch is only reachable by removing .page-header here,
        // same as the production condition the buggy branch claimed to
        // handle.
        var pageHeader = document.querySelector('.page-header');
        var pageContent = document.querySelector('.page-content');
        if (!pageHeader || !pageContent) {
            failures.push('page-header-fallback: fixture page is missing .page-header or .page-content to begin with');
            return done();
        }
        pageHeader.parentNode.removeChild(pageHeader);

        if (document.querySelector('.page-header')) {
            failures.push('page-header-fallback: .page-header still present after removal -- fixture setup is broken');
            return done();
        }

        var before = pageContent.querySelectorAll('.alert').length;
        ossAddMessage('control-behaviour regression alert (no page-header)', 'warning', false);
        var after = pageContent.querySelectorAll('.alert').length;
        if (after !== before + 1) {
            failures.push('page-header-fallback: ossAddMessage() did not insert its alert inside .page-content when .page-header was absent (before=' +
                before + ' after=' + after + ')');
        }
        done();
    }

    assertModalOperates(function () {
        assertAlertDismissOperates(function () {
            assertAlertLandsWithoutPageHeader(function () {
                document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
                document.body.dataset.testFailures = failures.join('; ');
            });
        });
    });
});
HTML
  printf '</script>\n'
} >>"$tmp/regression.html"

"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=4000 \
  --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: a migrated Bootstrap 5 control did not operate: ${failures:-no browser verdict}" >&2
  exit 1
fi

echo "OK: rendered alias/list.phtml carries no Bootstrap 2 class, and the modal, alert-dismiss and alert-page-header-fallback controls all operate"
