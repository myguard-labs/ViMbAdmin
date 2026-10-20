#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
fixture=(DB_HOST=127.0.0.1 DB_NAME=vimbadmin_runtime_port_contract DB_USER=fixture DB_PASS=fixture VIMBADMIN_RUNTIME_FIXTURE=1)
source_file=ci/test-refactor-runtime-baseline.php

expect_port() {
  local expected=$1
  shift
  local actual
  actual=$(env "$@" "${fixture[@]}" php "$source_file" --check-port)
  if [[ "$actual" != "$expected" ]]; then
    printf 'DB_PORT contract: expected %s, got %s\n' "$expected" "$actual" >&2
    exit 1
  fi
}

reject_port() {
  local value=$1
  local output
  if output=$(env "DB_PORT=$value" "${fixture[@]}" php "$source_file" --check-port 2>&1); then
    printf 'DB_PORT contract accepted invalid value %q\n' "$value" >&2
    exit 1
  fi
  if [[ "$output" != *'DB_PORT must be a decimal TCP port from 1 to 65535'* ]]; then
    printf 'DB_PORT contract gave unexpected error for %q: %s\n' "$value" "$output" >&2
    exit 1
  fi
}

expect_port 3306 -u DB_PORT
expect_port 1 DB_PORT=1
expect_port 65535 DB_PORT=65535
expect_port 44067 DB_PORT=44067
for invalid in '' 0 65536 abc ' 3306' 03306 999999999999999999999999999; do
  reject_port "$invalid"
done
printf 'DB_PORT contract passed\n'
