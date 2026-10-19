#!/usr/bin/env bash

# Resolve the current JS (default) or CSS bundle filename independently.
#
# Prints the bundle name (e.g., "min.bundle-v24.js") to stdout.
# Fails with a hard error (exit 2) if zero or multiple bundles exist.
#
# Usage:
#   bundle=$(source tests/support/resolve-bundle-v.sh && resolve_bundle_v [js|css])

# Capture the repo root at source time to avoid relying on BASH_SOURCE[0]
# which may not work correctly depending on how the file is sourced.
_resolve_bundle_v_repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

resolve_bundle_v() {
  local kind=${1-js}
  if [[ $# -gt 1 || ( "$kind" != js && "$kind" != css ) ]]; then
    echo 'FAIL: resolve_bundle_v expects at most one asset kind: js or css' >&2
    return 2
  fi
  local kind_label=${kind^^}
  local saved_nullglob=false
  local repo_root
  local -a full_matches

  # Use the repo root captured at source time
  repo_root="$_resolve_bundle_v_repo_root"

  # Save the current nullglob state to restore it later
  if shopt -q nullglob; then
    saved_nullglob=true
  fi

  # Disable nullglob to detect no-match case explicitly
  shopt -u nullglob

  # Use array to handle filenames with spaces correctly
  # Glob against the absolute path
  full_matches=("${repo_root}/public/$kind/min.bundle-v"*."$kind")

  # Restore the original nullglob state
  if [[ "$saved_nullglob" == true ]]; then
    shopt -s nullglob
  fi

  # Check if the glob matched anything (unmatched glob returns literal pattern)
  local pattern="${repo_root}/public/$kind/min.bundle-v"'*.'"$kind"
  if [[ "${full_matches[0]}" == "$pattern" ]]; then
    echo "FAIL: no $kind_label bundle found matching public/$kind/min.bundle-v*.$kind" >&2
    return 2
  fi

  local count=${#full_matches[@]}

  if [[ $count -gt 1 ]]; then
    echo "FAIL: ambiguous bundle name; multiple matches found:" >&2
    for match in "${full_matches[@]}"; do
      echo "  $(basename "$match")" >&2
    done
    return 2
  fi

  basename "${full_matches[0]}"
}
