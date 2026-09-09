#!/usr/bin/env bash

# Resolve the current JS bundle filename dynamically.
#
# Prints the bundle name (e.g., "min.bundle-v24.js") to stdout.
# Fails with a hard error (exit 2) if zero or multiple bundles exist.
#
# Usage:
#   bundle=$(source tests/support/resolve-bundle-v.sh && resolve_bundle_v)

resolve_bundle_v() {
  local matches
  matches=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && ls -1 public/js/min.bundle-v*.js 2>/dev/null | xargs -n1 basename)

  local count
  count=$(echo "$matches" | grep -c . || true)

  if [[ $count -eq 0 ]]; then
    echo "FAIL: no JS bundle found matching public/js/min.bundle-v*.js" >&2
    return 2
  fi

  if [[ $count -gt 1 ]]; then
    echo "FAIL: ambiguous bundle name; multiple matches found:" >&2
    echo "$matches" | sed 's/^/  /' >&2
    return 2
  fi

  echo "$matches"
}
