#!/usr/bin/env bash

# Resolve the current JS bundle filename dynamically.
#
# Prints the bundle name (e.g., "min.bundle-v24.js") to stdout.
# Fails with a hard error (exit 2) if zero or multiple bundles exist.
#
# Usage:
#   bundle=$(source tests/support/resolve-bundle-v.sh && resolve_bundle_v)

# Capture the repo root at source time to avoid relying on BASH_SOURCE[0]
# which may not work correctly depending on how the file is sourced.
_resolve_bundle_v_repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

resolve_bundle_v() {
  local -a matches_array
  local saved_nullglob
  local repo_root
  local -a full_matches

  # Use the repo root captured at source time
  repo_root="$_resolve_bundle_v_repo_root"

  # Save the current nullglob state to restore it later
  saved_nullglob=$(shopt -p nullglob)

  # Disable nullglob to detect no-match case explicitly
  shopt -u nullglob

  # Use array to handle filenames with spaces correctly
  # Glob against the absolute path
  full_matches=("${repo_root}/public/js/min.bundle-v"*.js)

  # Restore the original nullglob state
  eval "$saved_nullglob"

  # Check if the glob matched anything (unmatched glob returns literal pattern)
  local pattern="${repo_root}/public/js/min.bundle-v"'*.js'
  if [[ "${full_matches[0]}" == "$pattern" ]]; then
    echo "FAIL: no JS bundle found matching public/js/min.bundle-v*.js" >&2
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
