#!/usr/bin/env bash

# Browser regression for the destructive-submit guard. The production handler
# must stop the original submit, show the native Bootstrap 5 confirmation
# modal, and replay the submit only after an explicit confirmation.

set -euo pipefail

cd "$(dirname "$0")/.."
source tests/support/resolve-bundle-v.sh
# shellcheck source=tests/support/html-opening-tags.sh
source tests/support/html-opening-tags.sh

browser=${CHROMIUM_BIN:-}
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the confirm-guard regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-confirm-guard.XXXXXX)
trap 'rm -rf "$tmp"' EXIT

cp public/js/100-jquery.js "$tmp/jquery.js"
cp public/js/800-bootstrap.js "$tmp/bootstrap.js"
cp public/js/850-vimbadmin.modals.js "$tmp/modals.js"

awk '/^function tt_throbber/ { copying = 1 }
     /^function ossToggle/ { copying = 0 }
     /^function tt_openModalDialog/ { copying = 1 }
     /^function ossAjaxErrorHandler/ { copying = 0 }
     copying { print }' public/js/990-vimbadmin.js >"$tmp/ajax-modal.js"
awk '/^var ossConfirmedForms = new WeakSet\(\);/ { copying = 1 }
     copying { print }' public/js/990-vimbadmin.js >"$tmp/guard.js"
if ! grep -q 'function tt_openModalDialog' "$tmp/ajax-modal.js" ||
  ! grep -q 'ossConfirm( message' "$tmp/guard.js"; then
  echo 'FAIL: could not extract production AJAX modal and confirm handlers' >&2
  exit 2
fi

modal_shell_has_fallback_name() {
  local file=$1 tag id classes label
  while IFS=$'\t' read -r _line tag; do
    id=$(tag_attr "$tag" id)
    [[ $id == modal_dialog_shell ]] || continue
    classes=$(tag_attr "$tag" class)
    tr -s '[:space:]' '\n' <<<"$classes" | grep -qx modal || continue
    label=$(tag_attr "$tag" aria-label)
    [[ -n ${label//[[:space:]]/} ]]
    return
  done < <(extract_opening_tags "$file")
  return 1
}

email_fragment_has_title_id() {
  local file=$1 tag id classes
  while IFS=$'\t' read -r _line tag; do
    classes=$(tag_attr "$tag" class)
    tr -s '[:space:]' '\n' <<<"$classes" | grep -qx modal-title || continue
    id=$(tag_attr "$tag" id)
    [[ $id == email_settings_dialog_title ]]
    return
  done < <(extract_opening_tags "$file")
  return 1
}

for footer in application/views/footer.phtml application/views/_skins/myskin/footer.phtml; do
  if ! modal_shell_has_fallback_name "$footer"; then
    echo "FAIL: AJAX modal shell has no loading-state accessible name in $footer" >&2
    exit 1
  fi
done
if ! email_fragment_has_title_id application/views/mailbox/native-email-settings.phtml; then
  echo 'FAIL: email-settings fragment title has no stable id' >&2
  exit 1
fi

cat >"$tmp/reordered-shell.phtml" <<'HTML'
<div aria-hidden="true" aria-label="Loading dialog" class="fade modal" tabindex="-1" id="modal_dialog_shell"></div>
HTML
if ! modal_shell_has_fallback_name "$tmp/reordered-shell.phtml"; then
  echo 'FAIL: structurally valid reordered modal shell was rejected' >&2
  exit 1
fi
sed 's/ aria-label="Loading dialog"//' "$tmp/reordered-shell.phtml" >"$tmp/unnamed-shell.phtml"
if modal_shell_has_fallback_name "$tmp/unnamed-shell.phtml"; then
  echo 'FAIL: unnamed modal shell passed structural preflight' >&2
  exit 1
fi
sed 's/Loading dialog/   /' "$tmp/reordered-shell.phtml" >"$tmp/blank-name-shell.phtml"
if modal_shell_has_fallback_name "$tmp/blank-name-shell.phtml"; then
  echo 'FAIL: blank-named modal shell passed structural preflight' >&2
  exit 1
fi
echo 'ok   structural modal preflight accepts reorder and rejects unnamed/blank shells'

run_case() {
  local label=$1 mode=$2 script_tags
  local rendered="$tmp/rendered-$mode.html"

  if [[ $mode == source ]]; then
    # Load the modal helper before jQuery: this lane proves the replacement has
    # no hidden load-time dependency on the library the old dialog used.
    script_tags='<script src="modals.js"></script><script src="jquery.js"></script><script src="bootstrap.js"></script><script src="ajax-modal.js"></script><script src="guard.js"></script>'
  else
    local bundle_file
    bundle_file=$(resolve_bundle_v) || exit $?
    cp "public/js/$bundle_file" "$tmp/$bundle_file"
    script_tags="<script src=\"$bundle_file\"></script>"
  fi

  sed "s|@@SCRIPT_TAGS@@|$script_tags|" >"$tmp/regression-$mode.html" <<'HTML'
<!doctype html>
<html><head><meta charset="utf-8">
<style>.modal.fade .modal-dialog, .modal-backdrop.fade { transition: none !important; }</style>
@@SCRIPT_TAGS@@</head><body data-test-result="pending">
<form id="guarded" method="post" action="/mailbox/queue-delete"
      data-confirm="DELETE &lt;em&gt;this mailbox&lt;/em&gt;?">
  <button id="guarded-submit" type="submit">Delete mailbox</button>
</form>
<form id="unguarded" method="post" action="/mailbox/list"></form>
<form id="empty-message" method="post" action="/x" data-confirm=""></form>
<button id="alert-trigger" type="button">Show message</button>
<button id="in-page-trigger" type="button">Open existing modal</button>
<a id="modal-dialog-email-7" data-bs-original-title="Send Settings" href="/email-settings/7"></a>
<div id="modal_dialog_shell" class="modal fade" tabindex="-1" aria-label="Loading dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div id="modal_dialog" class="modal-content"></div></div>
</div>
<div id="in-page-modal" class="modal fade" tabindex="-1" aria-labelledby="in-page-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header">
      <h3 id="in-page-title" class="modal-title">Existing modal</h3>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">Existing application dialog</div>
    <div class="modal-footer"><button id="in-page-cancel" type="button">Cancel</button></div>
  </div></div>
</div>

<script>
(function () {
    var failures = [];
    var submitted = [];
    window.ossAjaxErrorHandler = function() {};
    window.addEventListener('error', function (event) {
        failures.push('page error: ' + event.message);
    });

    // Registered after the production delegated guard. It records only submits
    // the guard allowed to continue, then suppresses navigation for the fixture.
    document.addEventListener('submit', function (event) {
        if (!event.defaultPrevented) submitted.push(event.target.id);
        event.preventDefault();
    });

    function waitFor(predicate, description) {
        return new Promise(function (resolve, reject) {
            var started = Date.now();
            (function poll() {
                if (predicate()) return resolve();
                if (Date.now() - started > 1000) return reject(new Error('timed out waiting for ' + description));
                setTimeout(poll, 10);
            })();
        });
    }

    function submit(formId) {
        var form = document.getElementById(formId);
        var submitter = form.querySelector('[type="submit"]');
        if (submitter) {
            submitter.focus();
            form.requestSubmit(submitter);
        }
        else {
            form.requestSubmit();
        }
    }

    function assertFocusReturned(label, expectedId) {
        var actualId = document.activeElement ? document.activeElement.id : '';
        if (actualId !== expectedId)
            failures.push(label + ' did not restore focus to #' + expectedId + ': got #' + actualId);
    }

    function activateConfirmAfterDismissal(button) {
        button.click();
        button.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true
        }));
    }

    async function assertEarlyDismiss(action, label) {
        submitted = [];
        submit('guarded');
        var modal = document.querySelector('[data-oss-confirm]').closest('.modal');
        var shown = false;
        modal.addEventListener('shown.bs.modal', function() { shown = true; }, { once: true });
        action(modal);
        await waitFor(function() { return shown || !document.body.contains(modal); }, label + ' decision');
        if (document.body.contains(modal)) {
            failures.push(label + ' before shown left the generated modal open');
            bootstrap.Modal.getInstance(modal).hide();
            await waitFor(function() { return !document.body.contains(modal); }, label + ' cleanup');
        }
        if (submitted.length !== 0)
            failures.push(label + ' before shown replayed the destructive submit');
        assertFocusReturned(label + ' before shown', 'guarded-submit');
    }

    async function drive() {
        await assertEarlyDismiss(function(modal) {
            modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click();
        }, 'Cancel dismissal');
        await assertEarlyDismiss(function(modal) {
            modal.querySelector('.btn-close').click();
        }, 'Close dismissal');
        await assertEarlyDismiss(function(modal) {
            modal.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'Escape',
                code: 'Escape',
                bubbles: true,
                cancelable: true
            }));
        }, 'Escape dismissal');

        // Cancel is the destructive safety boundary: the original submit must
        // be stopped before the asynchronous modal decision is available.
        submit('guarded');
        if (submitted.indexOf('guarded') !== -1)
            failures.push('cancelled confirm did NOT block the destructive submit');

        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'shown confirm modal');
        var confirmButton = document.querySelector('[data-oss-confirm]');
        var modal = confirmButton.closest('.modal');
        var message = modal.querySelector('.modal-body');
        if (message.textContent !== 'DELETE <em>this mailbox</em>?')
            failures.push('confirm modal did not preserve the data-confirm message as text: ' + message.textContent);
        if (message.querySelector('em'))
            failures.push('confirm modal interpreted the confirmation message as HTML');

        modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click();
        activateConfirmAfterDismissal(confirmButton);
        await waitFor(function () { return !document.body.contains(modal); }, 'cancelled modal removal');
        if (submitted.indexOf('guarded') !== -1)
            failures.push('Cancel dismissal race replayed the destructive submit');
        assertFocusReturned('Cancel dismissal', 'guarded-submit');

        // Header Close and Escape are separate Bootstrap dismissal paths. Both
        // must fail closed and return keyboard focus to the invoking submitter.
        submitted = [];
        submit('guarded');
        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'close-button confirm modal');
        confirmButton = document.querySelector('[data-oss-confirm]');
        modal = confirmButton.closest('.modal');
        modal.querySelector('.btn-close').click();
        activateConfirmAfterDismissal(confirmButton);
        await waitFor(function () { return !document.body.contains(modal); }, 'close-button modal removal');
        if (submitted.length !== 0)
            failures.push('Close dismissal race replayed the destructive submit');
        assertFocusReturned('Close dismissal', 'guarded-submit');

        submitted = [];
        submit('guarded');
        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'Escape confirm modal');
        confirmButton = document.querySelector('[data-oss-confirm]');
        modal = confirmButton.closest('.modal');
        modal.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape',
            code: 'Escape',
            bubbles: true,
            cancelable: true
        }));
        activateConfirmAfterDismissal(confirmButton);
        await waitFor(function () { return !document.body.contains(modal); }, 'Escape modal removal');
        if (submitted.length !== 0)
            failures.push('Escape dismissal race replayed the destructive submit');
        assertFocusReturned('Escape dismissal', 'guarded-submit');

        // Explicit acceptance replays the submit exactly once.
        submitted = [];
        submit('guarded');
        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'second shown confirm modal');
        document.querySelector('[data-oss-confirm]').click();
        await waitFor(function () { return submitted.length > 0; }, 'accepted submit replay');
        if (submitted.length !== 1 || submitted[0] !== 'guarded')
            failures.push('accepted confirm did not replay the destructive submit exactly once: ' + JSON.stringify(submitted));
        assertFocusReturned('accepted confirmation', 'guarded-submit');

        // Two rapid activations while the first asynchronous decision is still
        // pending must share that decision. Otherwise two stacked dialogs can
        // each replay the same destructive form.
        submitted = [];
        submit('guarded');
        submit('guarded');
        await waitFor(function () {
            var buttons = Array.from(document.querySelectorAll('[data-oss-confirm]'));
            return buttons.length > 0 && buttons.every(function (button) { return !button.disabled; });
        }, 'rapid-submit confirm modal');
        var rapidButtons = Array.from(document.querySelectorAll('[data-oss-confirm]'));
        if (rapidButtons.length !== 1)
            failures.push('rapid duplicate submits opened ' + rapidButtons.length + ' confirmation modals');
        rapidButtons.forEach(function (button) { button.click(); });
        await waitFor(function () { return !document.querySelector('[data-oss-confirm]'); }, 'rapid-submit modal removal');
        if (submitted.length !== 1 || submitted[0] !== 'guarded')
            failures.push('rapid duplicate submits replayed the destructive form ' + submitted.length + ' times');
        assertFocusReturned('rapid-submit confirmation', 'guarded-submit');

        // Existing application modals are also opened programmatically by
        // ossModal(), so Bootstrap has no data-api trigger from which to infer
        // return focus. Cover header Close, the application's instance.hide()
        // Cancel pattern, and Escape over repeated lifecycles of one element.
        var inPageTrigger = document.getElementById('in-page-trigger');
        var inPageModal = document.getElementById('in-page-modal');
        var inPageInstance;
        document.getElementById('in-page-cancel').addEventListener('click', function() {
            inPageInstance.hide();
        });

        async function openInPageModal() {
            var shown = new Promise(function (resolve) {
                inPageModal.addEventListener('shown.bs.modal', resolve, { once: true });
            });
            inPageTrigger.focus();
            inPageInstance = ossModal(inPageModal);
            await shown;
        }

        async function dismissInPageModal(action, label) {
            var hidden = new Promise(function (resolve) {
                inPageModal.addEventListener('hidden.bs.modal', resolve, { once: true });
            });
            action();
            await hidden;
            assertFocusReturned(label, 'in-page-trigger');
        }

        await openInPageModal();
        await dismissInPageModal(function() { inPageModal.querySelector('.btn-close').click(); }, 'in-page Close');
        await openInPageModal();
        await dismissInPageModal(function() { document.getElementById('in-page-cancel').click(); }, 'in-page Cancel');
        await openInPageModal();
        await dismissInPageModal(function() {
            inPageModal.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'Escape',
                code: 'Escape',
                bubbles: true,
                cancelable: true
            }));
        }, 'in-page Escape');

        // Programmatic informational modals have no data-api trigger for
        // Bootstrap to remember, so the helper owns focus restoration here too.
        var alertTrigger = document.getElementById('alert-trigger');
        alertTrigger.focus();
        var alertModal = ossAlert('Mailbox action completed');
        if (alertModal.classList.contains('fade')) {
            await new Promise(function (resolve) {
                alertModal.addEventListener('shown.bs.modal', resolve, { once: true });
            });
        }
        alertModal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click();
        await waitFor(function () { return !document.body.contains(alertModal); }, 'alert modal removal');
        assertFocusReturned('alert dismissal', 'alert-trigger');

        // The shared AJAX shell must be named while its throbber is visible.
        // It transfers that name only to a nonblank, uniquely identified
        // fragment title; malformed fragments retain the safe fallback.
        var ajaxTrigger = document.getElementById('modal-dialog-email-7');
        var ajaxShell = document.getElementById('modal_dialog_shell');
        var realAjax = jQuery.ajax;

        async function assertAjaxModalName(response, label, expectTitleLink) {
            var ajaxRequest = null;
            jQuery.ajax = function(options) { ajaxRequest = options; };
            ajaxTrigger.focus();
            tt_openModalDialog({
                preventDefault: function() {},
                target: ajaxTrigger
            });
            if (ajaxShell.getAttribute('aria-label') !== 'Send Settings')
                failures.push(label + ' loading state did not use the trigger label');
            if (ajaxShell.hasAttribute('aria-labelledby'))
                failures.push(label + ' loading state retained a dangling aria-labelledby');
            if (!ajaxRequest || typeof ajaxRequest.success !== 'function') {
                failures.push(label + ' did not issue its content request');
                return;
            }

            ajaxRequest.success(response);
            var loadedTitle = ajaxShell.querySelector('.modal-title');
            var loadedTitleId = loadedTitle ? loadedTitle.id : '';
            var matchingTitleIds = Array.from(document.querySelectorAll('[id]')).filter(function(node) {
                return node.id === loadedTitleId;
            }).length;
            if (expectTitleLink) {
                if (!loadedTitleId || matchingTitleIds !== 1)
                    failures.push(label + ' title id was absent or non-unique');
                if (ajaxShell.getAttribute('aria-labelledby') !== loadedTitleId)
                    failures.push(label + ' was not labelled by its title');
                if (ajaxShell.hasAttribute('aria-label'))
                    failures.push(label + ' retained its loading label');
            }
            else {
                if (ajaxShell.hasAttribute('aria-labelledby'))
                    failures.push(label + ' trusted a missing, blank, or duplicate title');
                if (ajaxShell.getAttribute('aria-label') !== 'Send Settings')
                    failures.push(label + ' did not retain its fallback label');
            }

            await waitFor(function () { return ajaxShell.classList.contains('show'); }, label + ' shown state');
            dialog.hide();
            await waitFor(function () {
                return document.activeElement === ajaxTrigger;
            }, label + ' focus restoration');
        }

        try {
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="email_settings_dialog_title">' +
                'Email Settings</h3></div><div class="modal-body"></div>' +
                '<div class="modal-footer"><button id="modal_dialog_cancel">Close</button></div>',
                'AJAX modal loaded state',
                true
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="duplicate_dialog_title">' +
                'Duplicate</h3><span id="duplicate_dialog_title"></span></div>',
                'AJAX modal duplicate-title state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-body">No title</div>',
                'AJAX modal missing-title state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="blank_dialog_title">   </h3></div>',
                'AJAX modal blank-title state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="   ">Whitespace ID</h3></div>',
                'AJAX modal whitespace-only-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="title second">IDREF list</h3></div>',
                'AJAX modal embedded-whitespace-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="">Empty ID</h3></div>',
                'AJAX modal empty-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="title&#9;second">Tab ID</h3></div>',
                'AJAX modal tab-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="title&#10;second">LF ID</h3></div>',
                'AJAX modal LF-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="title&#12;second">FF ID</h3></div>',
                'AJAX modal FF-id state',
                false
            );
            await assertAjaxModalName(
                '<div class="modal-header"><h3 class="modal-title" id="title&#13;second">CR ID</h3></div>',
                'AJAX modal CR-id state',
                false
            );
        }
        finally {
            jQuery.ajax = realAjax;
        }

        // Boundary: forms without a usable message are not guarded.
        submitted = [];
        submit('unguarded');
        submit('empty-message');
        if (submitted.join(',') !== 'unguarded,empty-message')
            failures.push('unguarded or empty-message forms were blocked: ' + JSON.stringify(submitted));
        if (document.querySelector('[data-oss-confirm]'))
            failures.push('a form without a usable message opened a confirm modal');

        // Error path: if Bootstrap JS is unavailable, fail closed. Never turn a
        // missing dialog runtime into an unconfirmed destructive submission.
        submitted = [];
        var realBootstrap = window.bootstrap;
        var realAlert = window.alert;
        var fallbackAlertMessage = null;
        var fallbackConfirmResults = [];
        window.bootstrap = undefined;
        var fallbackConfirmDialog = ossConfirm('Direct fallback probe', function (accepted) {
            fallbackConfirmResults.push(accepted);
        });
        submit('guarded');
        alertTrigger.focus();
        window.alert = function (message) { fallbackAlertMessage = message; };
        ossAlert('<strong>Delete &amp; retry</strong> &quot;now&quot;');
        window.alert = realAlert;
        window.bootstrap = realBootstrap;
        if (submitted.indexOf('guarded') !== -1)
            failures.push('missing Bootstrap Modal runtime allowed the destructive submit');
        if (fallbackConfirmDialog !== null)
            failures.push('missing Bootstrap ossConfirm fallback returned a dialog');
        if (fallbackConfirmResults.length !== 1 || fallbackConfirmResults[0] !== false)
            failures.push('missing Bootstrap ossConfirm callback was not exactly once with false: ' + JSON.stringify(fallbackConfirmResults));
        if (fallbackAlertMessage !== 'Delete & retry "now"')
            failures.push('fallback alert did not convert markup and entities to text: ' + JSON.stringify(fallbackAlertMessage));

        // The guarded-form callback also releases its per-form pending lock.
        // Retrying with Bootstrap restored must therefore open a fresh dialog.
        submit('guarded');
        var retryConfirmButton = document.querySelector('[data-oss-confirm]');
        if (!retryConfirmButton) {
            failures.push('missing Bootstrap confirmation did not clear the guarded form pending state');
        }
        else {
            var retryModal = retryConfirmButton.closest('.modal');
            retryModal.querySelector('[data-bs-dismiss="modal"]').click();
            await waitFor(function () { return !document.body.contains(retryModal); }, 'fallback retry modal removal');
            if (submitted.indexOf('guarded') !== -1)
                failures.push('fallback retry cancellation replayed the destructive submit');
        }

        document.body.dataset.testResult = failures.length ? 'fail' : 'pass';
        document.body.dataset.testFailures = failures.join('; ');
    }

    jQuery(function() {
        drive().catch(function (error) {
            document.body.dataset.testResult = 'fail';
            failures.push(error.message);
            document.body.dataset.testFailures = failures.join('; ');
        });
    });
})();
</script>
</body></html>
HTML

  # The container adapter accepts only a test-owned basename `profile`; use a
  # fresh profile for each lane while preserving that security contract.
  rm -rf "$tmp/profile"
  if ! "$browser" \
    --headless \
    --disable-gpu \
    --allow-file-access-from-files \
    --user-data-dir="$tmp/profile" \
    --virtual-time-budget=15000 \
    --dump-dom "file://$tmp/regression-$mode.html" >"$rendered" 2>"$tmp/browser-$mode.log"; then
    cat "$tmp/browser-$mode.log" >&2
    return 1
  fi

  if ! grep -q 'data-test-result="pass"' "$rendered"; then
    local failures
    failures=$(grep -o 'data-test-failures="[^"]*"' "$rendered" || true)
    echo "FAIL: native modal behavior is unsafe in $label: ${failures:-no browser verdict}" >&2
    return 1
  fi

  echo "ok   $label: confirm gating and AJAX modal accessible naming behave safely"
}

run_popup_csp_case() {
  local rendered="$tmp/rendered-popup-csp.html"
  php tests/render-popup-csp-fixture.php "$tmp/popup-csp.html"

  rm -rf "$tmp/profile"
  if ! "$browser" \
    --headless \
    --disable-gpu \
    --allow-file-access-from-files \
    --user-data-dir="$tmp/profile" \
    --virtual-time-budget=1000 \
    --dump-dom "file://$tmp/popup-csp.html" >"$rendered" 2>"$tmp/browser-popup-csp.log"; then
    cat "$tmp/browser-popup-csp.log" >&2
    return 1
  fi

  if ! grep -q 'data-test-result="pass"' "$rendered"; then
    local failures
    failures=$(grep -o 'data-test-failures="[^"]*"' "$rendered" || true)
    echo "FAIL: nonced popup execution is unsafe: ${failures:-no browser verdict}" >&2
    return 1
  fi

  echo 'ok   nonced popup executes once with quotes, entities, and </script> preserved'
}

status=0
run_case 'source files' source || status=1
run_case 'minified production bundle' bundle || status=1
run_popup_csp_case || status=1
if [[ $status -ne 0 ]]; then
  exit "$status"
fi
echo 'ALL PASSED'
