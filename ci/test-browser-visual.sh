#!/usr/bin/env bash
# Real application render, responsive, focus and skin checks; private state only.
set -euo pipefail
cd "$(dirname "$0")/.."
export VIMBADMIN_BROWSER_APPLICATION_SUITE=visual
export VIMBADMIN_BROWSER_RUN_LABEL=${VIMBADMIN_BROWSER_RUN_LABEL:-c133-browser-visual}
node ci/test-browser-visual-inventory.cjs
exec bash tests/support/browser-application-fixture.sh "$@"
