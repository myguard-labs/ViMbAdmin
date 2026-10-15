#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."
readonly base_sha=${DEPENDENCY_BASE_SHA:?immutable dependency base SHA is required}
[[ $base_sha =~ ^[0-9a-f]{40}$ ]] || {
  printf 'Dependency base must be a full immutable commit SHA.\n' >&2
  exit 64
}
base_lock=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-dependency-base.XXXXXX")
trap 'rm -f -- "$base_lock"' EXIT

git fetch --no-tags --depth=1 origin "$base_sha"
git show "$base_sha:composer.lock" >"$base_lock"
php tools/refresh-dependencies.php --base-lock "$base_lock" --check
