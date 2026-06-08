#!/usr/bin/env bash
#
# ZF1 has been removed. Runtime PHP must not reference a Zend Framework class.
#
set -euo pipefail

cd "$(dirname "$0")/.."

echo "== runtime PHP contains no Zend Framework symbols =="

mapfile -d '' files < <(
    find application library public bin src -type f -name '*.php' -print0
)

if grep -nE 'Zend_[A-Za-z0-9_]+' "${files[@]}"; then
    echo "  -> Zend Framework references are forbidden; ZF1 is no longer installed."
    exit 1
fi

echo "  OK: runtime PHP is Zend-free"
