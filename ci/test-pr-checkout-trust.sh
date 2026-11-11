#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-checkout-trust.XXXXXX")
owner=$(id -u)
foreign=0
[[ "$owner" != 0 ]] || foreign=65534
as_root() { if [[ "$owner" == 0 ]]; then "$@"; else sudo -n "$@"; fi; }
cleanup() {
  as_root chown "$owner" "$fixture/checkout" "$fixture/other" 2>/dev/null || true
  rm -rf -- "${fixture:?}"
}
trap cleanup EXIT
for checkout in "$fixture/checkout" "$fixture/other"; do
  git init --quiet "$checkout"
  # rev-parse only needs a named ref. No commit, stage or shared-index writes.
  printf '%040d\n' 1 >"$checkout/.git/refs/heads/fixture"
  printf 'ref: refs/heads/fixture\n' >"$checkout/.git/HEAD"
done
mkdir -p "$fixture/checkout/.github"
cp -R "$root/.github/workflows" "$fixture/checkout/.github/"
printf '%s\n' vi-local-ci-208c2d13-01 /opt/actions-runner >"$fixture/slot-source"
as_root install -o 0 -g 0 -m 0444 "$fixture/slot-source" "$fixture/slot"
as_root chown "$foreign" "$fixture/checkout" "$fixture/other"
git_clean() { env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 git "$@"; }
if git_clean -C "$fixture/checkout" rev-parse HEAD >"$fixture/out" 2>"$fixture/error"; then
  echo 'Untrusted cross-owner checkout accepted.' >&2; exit 1
fi
grep -qF 'dubious ownership' "$fixture/error"
# Trust for one physical path must not grant trust to another owner/path.
if git_clean -C "$fixture/other" -c safe.directory="$fixture/checkout" rev-parse HEAD >"$fixture/out" 2>"$fixture/error"; then
  echo 'Command-scoped trust leaked to another checkout.' >&2; exit 1
fi
grep -qF 'dubious ownership' "$fixture/error"
before=$(sha256sum "$fixture/checkout/.git/config")
run_guard() {
  (cd "$fixture/checkout" && env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
    RUNNER_ENVIRONMENT=github-hosted RUNNER_NAME=vi-local-ci-208c2d13-01 \
    PR_RUNNER_SLOT_FILE="$fixture/slot" GITHUB_EVENT_NAME=pull_request \
    PR_RUNNER_EXPECTED_SHA="$1" bash "$root/.github/scripts/assert-pr-runner-isolation.sh") \
    >"$fixture/out" 2>"$fixture/error"
}
run_guard 0000000000000000000000000000000000000001
if run_guard 0000000000000000000000000000000000000002; then
  echo 'Trusted checkout with wrong event SHA accepted.' >&2; exit 1
fi
grep -qF 'Checkout does not match the exact event head.' "$fixture/error"
[[ "$before" == "$(sha256sum "$fixture/checkout/.git/config")" ]]
printf 'Cross-owner checkout trust accepts only its physical path and exact head.\n'
