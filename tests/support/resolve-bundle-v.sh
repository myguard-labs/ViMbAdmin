#!/usr/bin/env bash

# Resolve the current JS bundle filename dynamically.
#
# Prints the bundle name (e.g., "min.bundle-v24.js") to stdout.
# Fails with a hard error (exit 2) if zero or multiple bundles exist.
#
# Usage:
#   bundle=$(source tests/support/resolve-bundle-v.sh && resolve_bundle_v)

resolve_bundle_v() {
  local -a matches_array
  local saved_nullglob

  cd "$(dirname "${BASH_SOURCE[0]}")/../.." || return 2

  # Save the current nullglob state to restore it later
  saved_nullglob=$(shopt -p nullglob)

  # Disable nullglob to detect no-match case explicitly
  shopt -u nullglob

  # Use array to handle filenames with spaces correctly
  matches_array=(public/js/min.bundle-v*.js)

  # Restore the original nullglob state
  eval "$saved_nullglob"

  # Check if the glob matched anything (unmatched glob returns literal pattern)
  if [[ "${matches_array[0]}" == "public/js/min.bundle-v*.js" ]]; then
    echo "FAIL: no JS bundle found matching public/js/min.bundle-v*.js" >&2
    return 2
  fi

  local count=${#matches_array[@]}

  if [[ $count -gt 1 ]]; then
    echo "FAIL: ambiguous bundle name; multiple matches found:" >&2
    for match in "${matches_array[@]}"; do
      echo "  $(basename "$match")" >&2
    done
    return 2
  fi

  basename "${matches_array[0]}"
}
