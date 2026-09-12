#!/usr/bin/env bash

# Browser regression for the destructive-submit guard. The production handler
# must stop the original submit, show the native Bootstrap 5 confirmation
# modal, and replay the submit only after an explicit confirmation.

set -euo pipefail

cd "$(dirname "$0")/.."
source tests/support/resolve-bundle-v.sh

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

extract_guard() {
  awk '/^var ossConfirmedForms = new WeakSet\(\);/ { copying = 1 }
       copying { print }' public/js/990-vimbadmin.js >"$tmp/guard.js"

  if ! grep -q 'ossConfirm( message' "$tmp/guard.js"; then
    echo 'FAIL: could not extract the delegated native-modal confirm guard' >&2
    exit 2
  fi
}
extract_guard

run_case() {
  local label=$1 mode=$2 script_tags
  local rendered="$tmp/rendered-$mode.html"

  if [[ $mode == source ]]; then
    # Load the modal helper before jQuery: this lane proves the replacement has
    # no hidden load-time dependency on the library the old dialog used.
    script_tags='<script src="bootstrap.js"></script><script src="modals.js"></script><script src="jquery.js"></script><script src="guard.js"></script>'
  else
    local bundle_file
    bundle_file=$(resolve_bundle_v) || exit $?
    cp "public/js/$bundle_file" "$tmp/$bundle_file"
    script_tags="<script src=\"$bundle_file\"></script>"
  fi

  sed "s|@@SCRIPT_TAGS@@|$script_tags|" >"$tmp/regression-$mode.html" <<'HTML'
<!doctype html>
<html><head><meta charset="utf-8">@@SCRIPT_TAGS@@</head><body data-test-result="pending">
<form id="guarded" method="post" action="/mailbox/queue-delete"
      data-confirm="DELETE &lt;em&gt;this mailbox&lt;/em&gt;?">
  <button id="guarded-submit" type="submit">Delete mailbox</button>
</form>
<form id="unguarded" method="post" action="/mailbox/list"></form>
<form id="empty-message" method="post" action="/x" data-confirm=""></form>
<button id="alert-trigger" type="button">Show message</button>

<script>
(function () {
    var failures = [];
    var submitted = [];
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

    async function drive() {
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
        await waitFor(function () { return !document.body.contains(modal); }, 'cancelled modal removal');
        if (submitted.indexOf('guarded') !== -1)
            failures.push('dismissed confirm replayed the destructive submit');
        assertFocusReturned('Cancel dismissal', 'guarded-submit');

        // Header Close and Escape are separate Bootstrap dismissal paths. Both
        // must fail closed and return keyboard focus to the invoking submitter.
        submitted = [];
        submit('guarded');
        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'close-button confirm modal');
        modal = document.querySelector('[data-oss-confirm]').closest('.modal');
        modal.querySelector('.btn-close').click();
        await waitFor(function () { return !document.body.contains(modal); }, 'close-button modal removal');
        if (submitted.length !== 0)
            failures.push('Close dismissal replayed the destructive submit');
        assertFocusReturned('Close dismissal', 'guarded-submit');

        submitted = [];
        submit('guarded');
        await waitFor(function () {
            var button = document.querySelector('[data-oss-confirm]');
            return button && !button.disabled;
        }, 'Escape confirm modal');
        modal = document.querySelector('[data-oss-confirm]').closest('.modal');
        modal.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape',
            code: 'Escape',
            bubbles: true,
            cancelable: true
        }));
        await waitFor(function () { return !document.body.contains(modal); }, 'Escape modal removal');
        if (submitted.length !== 0)
            failures.push('Escape dismissal replayed the destructive submit');
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

        // Programmatic informational modals have no data-api trigger for
        // Bootstrap to remember, so the helper owns focus restoration here too.
        var alertTrigger = document.getElementById('alert-trigger');
        alertTrigger.focus();
        var alertModal = ossAlert('Mailbox action completed');
        await new Promise(function (resolve) {
            alertModal.addEventListener('shown.bs.modal', resolve, { once: true });
        });
        alertModal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click();
        await waitFor(function () { return !document.body.contains(alertModal); }, 'alert modal removal');
        assertFocusReturned('alert dismissal', 'alert-trigger');

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
        window.bootstrap = undefined;
        submit('guarded');
        window.bootstrap = realBootstrap;
        if (submitted.indexOf('guarded') !== -1)
            failures.push('missing Bootstrap Modal runtime allowed the destructive submit');

        document.body.dataset.testResult = failures.length ? 'fail' : 'pass';
        document.body.dataset.testFailures = failures.join('; ');
    }

    drive().catch(function (error) {
        document.body.dataset.testResult = 'fail';
        failures.push(error.message);
        document.body.dataset.testFailures = failures.join('; ');
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
    --virtual-time-budget=3000 \
    --dump-dom "file://$tmp/regression-$mode.html" >"$rendered" 2>"$tmp/browser-$mode.log"; then
    cat "$tmp/browser-$mode.log" >&2
    return 1
  fi

  if ! grep -q 'data-test-result="pass"' "$rendered"; then
    local failures
    failures=$(grep -o 'data-test-failures="[^"]*"' "$rendered" || true)
    echo "FAIL: native confirm guard is unsafe in $label: ${failures:-no browser verdict}" >&2
    return 1
  fi

  echo "ok   $label: native confirm gates one destructive submit and coalesces rapid duplicates"
}

status=0
run_case 'source files' source || status=1
run_case 'minified production bundle' bundle || status=1
if [[ $status -ne 0 ]]; then
  exit "$status"
fi
echo 'ALL PASSED'
