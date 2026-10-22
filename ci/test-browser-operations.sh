#!/usr/bin/env bash
# Rendered maintenance, queue and MCP operations against disposable state.
set -euo pipefail
cd "$(dirname "$0")/.."
export VIMBADMIN_BROWSER_APPLICATION_SUITE=operations
export VIMBADMIN_BROWSER_RUN_LABEL=${VIMBADMIN_BROWSER_RUN_LABEL:-c126-browser-operations}
exec bash tests/support/browser-application-fixture.sh "$@"
