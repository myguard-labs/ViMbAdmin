#!/usr/bin/env bash

# VIM-D07: the destructive-action confirmations used to be inline
# onsubmit="return confirm('...')" attributes. Under a nonce-only script-src an
# inline event handler never runs, so they were moved to data-confirm attributes
# enforced by one delegated guard in public/js/990-vimbadmin.js.
#
# The failure mode that matters is a guard that stops guarding: a confirm the
# user CANCELS must still block the submit. A string check cannot prove that, so
# this drives the real production guard in a real browser with window.confirm
# stubbed, and asserts the submit is prevented on cancel and allowed on accept.

set -euo pipefail

cd "$(dirname "$0")/.."

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the confirm-guard regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-confirm-guard.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

cp public/js/100-jquery.js "$tmp/jquery.js"

# The guard is exercised twice: once from the source file, and once from the
# minified bundle production actually serves. A bundle whose minification broke
# the guard would otherwise ship unnoticed -- a string check cannot catch that.
extract_guard() {
  # $1 = source file, $2 = destination
  case "$1" in
    *min.bundle*)
      # The bundle is one line; ship it whole rather than trying to slice it.
      cp "$1" "$2"
      ;;
    *)
      awk '/^jQuery\( document \)\.on\( .submit., .form\[data-confirm\]./ { copying = 1 }
           copying { print }' "$1" >"$2"
      ;;
  esac

  if ! grep -q 'data-confirm' "$2"; then
    echo "FAIL: could not extract the delegated confirm guard from $1" >&2
    exit 2
  fi
}

run_case() {
  # $1 = label, $2 = js source file
  extract_guard "$2" "$tmp/guard.js"

cat >"$tmp/regression.html" <<HTML
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script src="file://$tmp/jquery.js"></script>
HTML

{
  printf '<script>\n'
  cat "$tmp/guard.js"
  cat <<'HTML'
</script>

<form id="guarded" method="post" action="/mailbox/queue-delete"
      data-confirm="DELETE this mailbox?"></form>
<form id="unguarded" method="post" action="/mailbox/list"></form>
<form id="empty-message" method="post" action="/x" data-confirm=""></form>

<script>
var prompts = [];
var submitted = [];

// Record every submit that was NOT prevented; preventDefault() in the guard is
// what has to stop it. Navigation is suppressed so the page survives to report.
$(document).on('submit', function (event) {
    if (!event.isDefaultPrevented()) {
        submitted.push(event.target.id);
    }
    event.preventDefault();
});

function drive(answer, formId) {
    window.confirm = function (message) { prompts.push(message); return answer; };
    $('#' + formId).trigger('submit');
}

$(function () {
    var failures = [];

    // 1. The user CANCELS: the submit must be blocked. This is the assertion the
    //    whole item turns on -- a guard that silently stops confirming would let
    //    the destructive POST through here.
    drive(false, 'guarded');
    if (submitted.indexOf('guarded') !== -1) {
        failures.push('cancelled confirm did NOT block the submit');
    }
    if (prompts.length !== 1 || prompts[0] !== 'DELETE this mailbox?') {
        failures.push('the data-confirm message was not put to the user: ' + JSON.stringify(prompts));
    }

    // 2. The user ACCEPTS: the submit must proceed, or the guard has broken the
    //    feature instead of protecting it.
    submitted = [];
    drive(true, 'guarded');
    if (submitted.indexOf('guarded') === -1) {
        failures.push('accepted confirm did not let the submit through');
    }

    // 3. A form with no data-confirm must never be prompted about.
    submitted = [];
    prompts = [];
    drive(false, 'unguarded');
    if (prompts.length !== 0) {
        failures.push('a form without data-confirm was still prompted about');
    }
    if (submitted.indexOf('unguarded') === -1) {
        failures.push('a form without data-confirm was blocked');
    }

    // 4. An empty data-confirm must not block silently with no prompt.
    submitted = [];
    prompts = [];
    drive(false, 'empty-message');
    if (prompts.length !== 0) {
        failures.push('an empty data-confirm still prompted');
    }
    if (submitted.indexOf('empty-message') === -1) {
        failures.push('an empty data-confirm blocked the submit with no prompt');
    }

    document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
    document.body.dataset.testFailures = failures.join('; ');
});
</script>
</body>
HTML
} >>"$tmp/regression.html"

  rm -rf "$tmp/profile"
  "$browser" \
    --headless \
    --disable-gpu \
    --allow-file-access-from-files \
    --user-data-dir="$tmp/profile" \
    --virtual-time-budget=1000 \
    --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

  if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
    failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
    echo "FAIL: delegated confirm guard is unsafe in $1: ${failures:-no browser verdict}" >&2
    exit 1
  fi

  echo "ok   $1: a cancelled confirm blocks the submit and an accepted one lets it through"
}

run_case "source (990-vimbadmin.js)" public/js/990-vimbadmin.js
run_case "minified bundle (min.bundle-v24.js)" public/js/min.bundle-v24.js

echo "ALL PASSED"
