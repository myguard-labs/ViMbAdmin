#!/usr/bin/env bash
#
# Phase 0 guard of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md).
#
# Locks in "new code is framework-free": the end-of-life ZF1 surface
# (shardj/zf1-future) may only ever SHRINK, never grow. The library tree
# under library/ViMbAdmin/ is already framework-free except for a tiny,
# explicit baseline of legacy glue files. This lint fails if any file under
# library/ViMbAdmin/ -- outside the Controller/ and Form/ subtrees, which the
# roadmap keeps as the thinnest possible ZF1 glue until Phases 3-4 -- contains
# a Zend_ reference that is not on the baseline allowlist below.
#
# How "new" is enforced without git history: every CURRENT Zend_-using file in
# scope is listed in the allowlist. A new file with Zend_ is, by definition,
# not on the list, so it fails. The allowlist may only get SHORTER over time
# (delete an entry when that file loses its last Zend_ reference); adding a new
# entry is a roadmap regression and should be challenged in review.
#
# The match is deliberately broad (any "Zend_" token, including in comments /
# docblocks) so that even a `@var Zend_Foo` annotation in a brand-new file is
# caught -- new code should not even document a ZF1 type.
#
# Exit 0 = clean, 1 = a non-allowlisted file introduced a Zend_ reference.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Baseline: existing library/ViMbAdmin/ files (outside Controller/ and Form/)
# that still legitimately reference Zend_. These predate the roadmap. This list
# must only shrink. Paths are relative to the repo root.
ALLOWLIST=(
    "library/ViMbAdmin/Plugin.php"
    "library/ViMbAdmin/Doveadm.php"
    "library/ViMbAdmin/Form.php"
)

in_allowlist() {
    local needle="$1"
    local entry
    for entry in "${ALLOWLIST[@]}"; do
        [ "$entry" = "$needle" ] && return 0
    done
    return 1
}

echo "== library/ViMbAdmin/ (outside Controller/ + Form/): no NEW Zend_ references =="

fail=0
stale_allowlist=()

# Every .php under library/ViMbAdmin/ except the Controller/ and Form/ subtrees.
while IFS= read -r -d '' f; do
    rel="${f#./}"
    if grep -q "Zend_" "$f"; then
        if in_allowlist "$rel"; then
            continue   # known legacy glue, allowed
        fi
        echo "  $rel:"
        grep -nE "Zend_[A-Za-z0-9_]*" "$f" | sed 's/^/    /'
        echo "    -> new/clean files must be framework-free (no Zend_)."
        echo "       Ship the feature as a ViMbAdmin\\ PSR-4 class with constructor"
        echo "       DI instead. See docs/ZF1-REMOVAL.md (Phase 0)."
        fail=1
    fi
done < <(find library/ViMbAdmin -type d \( -name Controller -o -name Form \) -prune -o \
             -name '*.php' -type f -print0)

# The framework-free kernel (Phase 2, docs/ZF1-REMOVAL.md) lives under src/ and
# is held to a stricter rule than library/: ZERO Zend_ references, no allowlist.
# Everything here is new code written to replace ZF1, so a Zend_ token is always
# a mistake.
if [ -d src ]; then
    while IFS= read -r -d '' f; do
        rel="${f#./}"
        if grep -q "Zend_" "$f"; then
            echo "  $rel:"
            grep -nE "Zend_[A-Za-z0-9_]*" "$f" | sed 's/^/    /'
            echo "    -> src/ is the framework-free kernel; it must never reference Zend_."
            fail=1
        fi
    done < <(find src -name '*.php' -type f -print0)
fi

# Catch an allowlist that no longer reflects reality: an entry whose file has
# lost its last Zend_ reference (good!) or was deleted. Such entries should be
# removed so the list keeps shrinking; flag them so the win is recorded.
for entry in "${ALLOWLIST[@]}"; do
    if [ ! -f "$entry" ] || ! grep -q "Zend_" "$entry"; then
        stale_allowlist+=("$entry")
    fi
done

if [ "${#stale_allowlist[@]}" -gt 0 ]; then
    echo
    echo "== stale allowlist entries (file no longer has Zend_ -- remove them) =="
    for entry in "${stale_allowlist[@]}"; do
        echo "  $entry"
    done
    echo "  -> delete the above from ALLOWLIST in tests/lint-no-new-zend.sh;"
    echo "     the ZF1 surface shrank and the guard should record it."
    fail=1
fi

if [ "$fail" -eq 0 ]; then
    echo "  OK: ZF1 surface under library/ViMbAdmin/ did not grow"
    echo "      (${#ALLOWLIST[@]} known legacy file(s) on the baseline allowlist)"
fi

exit $fail
