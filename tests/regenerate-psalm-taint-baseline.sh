#!/usr/bin/env bash
#
# Regenerate or verify the Psalm taint baseline byte for byte.
#
# Usage: tests/regenerate-psalm-taint-baseline.sh [--check]

set -euo pipefail

repo_root=${PSALM_REPO_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$repo_root"

mode='write'
case ${1:-} in
'') ;;
--check) mode=check ;;
--help)
  sed -n '3,5s/^# \{0,1\}//p' "$0"
  exit 0
  ;;
*)
  echo "usage: $0 [--check]" >&2
  exit 2
  ;;
esac
if [ "$#" -gt 1 ]; then
  echo "usage: $0 [--check]" >&2
  exit 2
fi

bash "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)/.github/scripts/assert-psalm-optional-models.sh" "$repo_root"

if [ -n "${PSALM_BIN:-}" ]; then
  psalm=("$PSALM_BIN")
elif [ -x vendor/bin/psalm ]; then
  psalm=(vendor/bin/psalm)
elif command -v psalm >/dev/null 2>&1; then
  psalm=(psalm)
else
  echo "Psalm is required but was not found" >&2
  exit 127
fi

target='psalm-taint-baseline.xml'
candidate=$(mktemp "$repo_root/.psalm-taint-baseline.XXXXXX.xml")
config=$(mktemp "$repo_root/.psalm-taint-config.XXXXXX.xml")
cleanup() {
  rm -f -- "$candidate" "$config"
}
trap cleanup EXIT

rm -f -- "$candidate"
sed '/^[[:space:]]*errorBaseline=/d' psalm.xml >"$config"
set +e
"${psalm[@]}" --config="$config" --no-progress --taint-analysis \
  --ignore-baseline \
  --set-baseline="$candidate"
psalm_status=$?
set -e

if [ ! -s "$candidate" ]; then
  echo "Psalm did not generate a taint baseline artifact" >&2
  exit "$psalm_status"
fi
if [ "$psalm_status" -ne 0 ] && [ "$psalm_status" -ne 2 ]; then
  echo "Psalm baseline generation failed with exit $psalm_status" >&2
  exit "$psalm_status"
fi

if [ "$mode" = check ]; then
  if ! cmp -s -- "$target" "$candidate"; then
    echo "Psalm taint baseline drift; regenerate it with $0" >&2
    diff -u -- "$target" "$candidate" || true
    exit 1
  fi
else
  chmod 0644 "$candidate"
  mv -- "$candidate" "$target"
fi

echo "Psalm taint baseline is reproducible."
