#!/usr/bin/env bash
set -euo pipefail
# Compatibility entry point; new policy controls live in ci/.
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
exec bash "$root/ci/test-pr-runner-selfhosted.sh"
