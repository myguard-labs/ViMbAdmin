#!/usr/bin/env bash
#
# VIM-A15.26 / VIM-A15.56a1.
#
# ORIGINALLY this gate asserted the inverse: an initialiser carrying the
# 1.9-era `fnServerData` callback MUST also carry `sAjaxSource`, because
# DataTables 1.x passed `oSettings.sAjaxSource` -- never `oSettings.ajax` --
# as the callback's first `source` argument, so renaming the key while
# keeping the callback made `source` undefined and jQuery silently resolved
# `url: undefined` to the CURRENT PAGE URL. That gate's own comment recorded
# that the pairing was "only safe to remove once the callbacks are migrated
# to the `ajax` interface, at which point this gate should be updated rather
# than deleted."
#
# VIM-A15.56a1 did that migration: DataTables was upgraded to 2.3.4, where
# `sAjaxSource` and `fnServerData` have ZERO occurrences -- both options were
# removed outright. An initialiser that still names either one is no longer
# "legacy but working"; it is dead configuration that DataTables 2.x ignores
# completely. The failure mode is the same SILENT one the original gate
# existed to catch: with no `ajax` option the table falls back to reading the
# DOM, so a server-side list renders as a permanently empty (or
# stuck-on-page-one) table with no JS error and no server-side test able to
# see it.
#
# So the assertion is INVERTED, not weakened: the removed 1.x options must
# not reappear anywhere in own view JS, and any initialiser that declares
# `serverSide` must supply `ajax`.
#
# Exit 0 = clean, 1 = a removed 1.x ajax option was found, or a server-side
# initialiser has no ajax source.

set -u
fail=0
shopt -s nullglob

echo "== DataTables 2.x: no removed 1.x ajax options; server-side tables need 'ajax' =="

files=(application/views/*/js/*.js)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> own view-JS inventory is empty; refusing to report a scan that never happened as clean." >&2
  exit 1
fi

# --- 1. the removed 1.x options must not reappear ---
for f in "${files[@]}"; do
  for opt in sAjaxSource fnServerData; do
    if grep -qE "^[[:space:]]*['\"]?${opt}['\"]?[[:space:]]*:" "$f"; then
      echo "  $f: uses '${opt}', removed in DataTables 2.x"
      echo "    -> 2.3.4 has zero occurrences of it; the option is ignored"
      echo "       entirely and the table silently loads no data."
      fail=1
    fi
  done
done

# --- 2. a server-side initialiser must declare an ajax source ---
for f in "${files[@]}"; do
  grep -qE "^[[:space:]]*['\"]?serverSide['\"]?[[:space:]]*:[[:space:]]*true" "$f" || continue

  if ! grep -qE "^[[:space:]]*['\"]?ajax['\"]?[[:space:]]*:" "$f"; then
    echo "  $f: declares serverSide but supplies no 'ajax' option"
    echo "    -> DataTables falls back to reading the DOM; the table renders"
    echo "       empty with no error."
    fail=1
  fi
done

# --- self-test: the scan must actually fire on the shapes it exists to catch ---
echo "== self-test =="
selftest_dir=$(mktemp -d)
trap 'rm -rf "$selftest_dir"' EXIT

cat >"$selftest_dir/dirty_legacy.js" <<'EOF'
t = {
    'sAjaxSource': "/x/list-data",
    'fnServerData': cb
};
EOF
hits=0
for opt in sAjaxSource fnServerData; do
  grep -qE "^[[:space:]]*['\"]?${opt}['\"]?[[:space:]]*:" "$selftest_dir/dirty_legacy.js" && hits=$((hits + 1))
done
if [ "$hits" -eq 2 ]; then
  echo "  OK: both removed 1.x options are detected"
else
  echo "  FAIL: expected to detect 2 removed options, detected $hits" >&2
  fail=1
fi

cat >"$selftest_dir/dirty_noajax.js" <<'EOF'
t = {
    'serverSide': true,
    'processing': true
};
EOF
if grep -qE "^[[:space:]]*['\"]?serverSide['\"]?[[:space:]]*:[[:space:]]*true" "$selftest_dir/dirty_noajax.js" \
   && ! grep -qE "^[[:space:]]*['\"]?ajax['\"]?[[:space:]]*:" "$selftest_dir/dirty_noajax.js"; then
  echo "  OK: a serverSide initialiser with no ajax option is detected"
else
  echo "  FAIL: a serverSide initialiser without ajax was not detected" >&2
  fail=1
fi

cat >"$selftest_dir/clean.js" <<'EOF'
t = {
    'serverSide': true,
    'ajax': vmThingServerData( "/x/list-data" )
};
EOF
clean_ok=1
for opt in sAjaxSource fnServerData; do
  grep -qE "^[[:space:]]*['\"]?${opt}['\"]?[[:space:]]*:" "$selftest_dir/clean.js" && clean_ok=0
done
grep -qE "^[[:space:]]*['\"]?ajax['\"]?[[:space:]]*:" "$selftest_dir/clean.js" || clean_ok=0
if [ "$clean_ok" -eq 1 ]; then
  echo "  OK: a migrated 2.x initialiser is not flagged"
else
  echo "  FAIL: a migrated 2.x initialiser was flagged" >&2
  fail=1
fi

if [ "$fail" -eq 0 ]; then
  echo "  OK: no removed 1.x ajax option, and every server-side table has an ajax source"
fi

exit "$fail"
