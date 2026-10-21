#!/usr/bin/env bash
# Real rendered tenant boundaries; the shared fixture owns private state/cleanup.
set -euo pipefail
cd "$(dirname "$0")/.."
export VIMBADMIN_BROWSER_APPLICATION_SUITE=tenant
export VIMBADMIN_BROWSER_RUN_LABEL=${VIMBADMIN_BROWSER_RUN_LABEL:-c124-browser-tenant}
exec bash tests/support/browser-application-fixture.sh "$@"
