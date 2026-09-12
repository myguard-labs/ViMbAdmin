#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

readonly runner=.github/scripts/run-headless-chrome.sh
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
readonly php_runner=.github/scripts/run-static-php-container.sh
readonly dollar='$'

require() {
  local pattern=$1
  if ! grep -Fq -- "$pattern" "$runner"; then
    echo "FAIL: Chrome container runner must contain: $pattern" >&2
    exit 1
  fi
}

require "readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'"
require 'exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges'
require "  --user \"${dollar}(id -u):${dollar}(id -g)\""
require "  --env \"HOME=${dollar}fixture_dir\""
require "  --mount \"type=bind,src=${dollar}fixture_dir,dst=${dollar}fixture_dir\""
require "  \"${dollar}image\" google-chrome --no-sandbox \"${dollar}@\""
require 'vimbadmin-(alias-destination|residual-stored-xss|confirm-guard|datatables-dependency-free|control-behaviour|source-defect-sweep|validate-group|browser-adapter)'
require 'dst=/usr/local/bin/run-chrome-http-fixture,readonly'
require 'browser fixture runner requires a test-owned --user-data-dir'
require 'browser fixture runner requires a private test fixture directory'

if grep -Fq 'Chrome container requires' "$runner"; then
  echo 'FAIL: browser fixture diagnostics must not name a Chrome container' >&2
  exit 1
fi

require_php() {
  local pattern=$1
  if ! grep -Fq -- "$pattern" "$php_runner"; then
    echo "FAIL: Static PHP container runner must contain: $pattern" >&2
    exit 1
  fi
}

require_php "readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'"
require_php 'docker run --rm --network bridge --cap-drop ALL'
require_php 'exec docker run --rm --network none --cap-drop ALL'
require_php "--mount \"type=bind,src=${dollar}workspace,dst=${dollar}workspace,readonly\""
require_php "--mount \"type=bind,src=${dollar}writable_dir,dst=${dollar}writable_dir\""
require_php 'vimbadmin-(alias-destination|residual-stored-xss|control-behaviour)'

if grep -Eq -- '(^|[[:space:]])--(privileged|cap-add)([=[:space:]]|$)|apt(-get)?[[:space:]]+(install|update)' "$runner"; then
  echo 'FAIL: Chrome container runner must not elevate or install host packages' >&2
  exit 1
fi

fixture_root=$(mktemp -d /tmp/vimbadmin-alias-destination.XXXXXX)
readonly fixture_root
readonly fixture_dir=$fixture_root
readonly stub_dir=$fixture_root/bin
readonly docker_args=$fixture_root/docker-args
readonly expected_args=$fixture_root/expected-args
readonly output=$fixture_root/output
foreign_root=$(mktemp -d /tmp/vimbadmin-chrome-foreign.XXXXXX)
readonly foreign_root
foreign_server=''

cleanup() {
  if [[ -n $foreign_server ]]; then
    kill "$foreign_server" 2>/dev/null || true
  fi
  rm -rf -- "$fixture_root"
  rm -rf -- "$foreign_root"
}
trap cleanup EXIT

mkdir -p "$stub_dir" "$foreign_root/profile"

http_stub_dir=$fixture_root/http-bin
http_output=$fixture_root/http-output
chrome_marker=$fixture_root/chrome-started
mkdir -p "$http_stub_dir"
cat >"$http_stub_dir/php" <<'SH'
#!/bin/sh
exit 1
SH
cat >"$http_stub_dir/google-chrome" <<SH
#!/bin/sh
touch "$chrome_marker"
SH
chmod +x "$http_stub_dir/php" "$http_stub_dir/google-chrome"

if VIMBADMIN_FIXTURE_PORT=48765 PATH="$http_stub_dir:/usr/bin:/bin" \
  timeout 5 "$http_runner" "$fixture_root" \
  >"$http_output" 2>&1; then
  echo 'FAIL: HTTP fixture runner accepted a server that never became ready' >&2
  exit 1
fi
grep -qF 'fixture HTTP server did not become ready' "$http_output"
if [[ -e $chrome_marker ]]; then
  echo 'FAIL: HTTP fixture runner started Chrome before server readiness' >&2
  exit 1
fi

printf 'foreign listener' >"$foreign_root/index.html"
php -S 127.0.0.1:48766 -t "$foreign_root" >/dev/null 2>&1 &
foreign_server=$!
for _ in $(seq 1 40); do
  # The expression belongs to PHP, not this shell.
  # shellcheck disable=SC2016
  if php -r '$body = @file_get_contents("http://127.0.0.1:48766/index.html"); exit($body === "foreign listener" ? 0 : 1);'; then
    break
  fi
  sleep 0.05
done
if ! kill -0 "$foreign_server" 2>/dev/null; then
  echo 'FAIL: occupied-port control did not start its foreign listener' >&2
  exit 1
fi
if VIMBADMIN_FIXTURE_PORT=48766 timeout 5 "$http_runner" "$fixture_root" \
  >"$http_output" 2>&1; then
  echo 'FAIL: HTTP fixture runner accepted an occupied port' >&2
  exit 1
fi
grep -qF 'fixture HTTP server did not become ready' "$http_output"
if ! kill -0 "$foreign_server" 2>/dev/null; then
  echo 'FAIL: HTTP fixture runner killed an unowned listener' >&2
  exit 1
fi

cat >"$stub_dir/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$VIMBADMIN_DOCKER_ARGS"
SH
chmod +x "$stub_dir/docker"

# CI can install Node outside /usr/bin. Keep its caller-provided PATH when
# exercising the adapter, and prove that it reaches the selected Node binary.
readonly node_bin=$fixture_root/node-bin
readonly node_marker=$fixture_root/node-started
test_node=$(command -v node)
mkdir -p "$node_bin"
cat >"$node_bin/node" <<'SH'
#!/bin/sh
touch "$VIMBADMIN_TEST_NODE_MARKER"
exec "$VIMBADMIN_TEST_NODE" "$@"
SH
chmod +x "$node_bin/node"
PATH="$node_bin:$PATH"
export VIMBADMIN_TEST_NODE="$test_node" VIMBADMIN_TEST_NODE_MARKER="$node_marker"

run_runner() {
  PATH="$stub_dir:/usr/bin:/bin" \
    VIMBADMIN_DOCKER_ARGS="$docker_args" \
    "$runner" "$@" >"$output" 2>&1
}

run_runner \
  --headless \
  --user-data-dir="$fixture_dir/profile" \
  --dump-dom "file://$fixture_dir/regression.html"

cat >"$expected_args" <<EOF
run
--rm
--network
none
--cap-drop
ALL
--security-opt
no-new-privileges
--user
$(id -u):$(id -g)
--env
HOME=$fixture_dir
--mount
type=bind,src=$fixture_dir,dst=$fixture_dir
cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d
google-chrome
--no-sandbox
--headless
--user-data-dir=$fixture_dir/profile
--dump-dom
file://$fixture_dir/regression.html
EOF
cmp -- "$expected_args" "$docker_args"

# Every selected engine must pass through the same private-root gate before
# Docker is invoked; these are behavioural assertions, not just source matches.
for engine in chromium firefox webkit; do
  VIMBADMIN_BROWSER=$engine run_runner \
    --user-data-dir="$fixture_dir/profile" --dump-dom "file://$fixture_dir/regression.html"
  grep -qxF 'none' "$docker_args"
  grep -qxF 'ALL' "$docker_args"
  grep -qxF 'no-new-privileges' "$docker_args"
  grep -qxF "$(id -u):$(id -g)" "$docker_args"
  grep -qxF "type=bind,src=$fixture_dir,dst=$fixture_dir" "$docker_args"
  grep -qxF 'vimbadmin-browser:1.63.0' "$docker_args"
  grep -qxF "$engine" "$docker_args"
  rm -f -- "$docker_args"
  if VIMBADMIN_BROWSER=$engine run_runner --user-data-dir="$foreign_root/profile"; then
    echo "FAIL: $engine accepted an unregistered fixture" >&2
    exit 1
  fi
  grep -qF 'requires a private test fixture directory' "$output"
  [[ ! -e $docker_args ]]
done
if VIMBADMIN_BROWSER=unknown run_runner --user-data-dir="$fixture_dir/profile"; then
  echo 'FAIL: unknown browser engine was accepted' >&2
  exit 1
fi
grep -qF 'unsupported browser engine: unknown' "$output"
[[ ! -e $docker_args ]]

if run_runner --headless; then
  echo 'FAIL: Chrome container runner accepted a missing profile directory' >&2
  exit 1
fi
grep -qF 'requires a test-owned --user-data-dir' "$output"

if run_runner --user-data-dir="$fixture_dir/not-profile"; then
  echo 'FAIL: Chrome container runner accepted a non-profile user-data directory' >&2
  exit 1
fi
grep -qF 'requires a test-owned --user-data-dir' "$output"

if run_runner --user-data-dir="$foreign_root/profile"; then
  echo 'FAIL: Chrome container runner accepted a writable foreign profile' >&2
  exit 1
fi
grep -qF 'requires a private test fixture directory' "$output"

# An unexpected launch failure must keep its original exit status and its
# identifying diagnostic after the adapter test removes the fixture directory.
cat >"$stub_dir/docker" <<'SH'
#!/bin/sh
echo '[firefox] FAIL: injected browser launch failure' >&2
exit 73
SH
status=0
PATH="$stub_dir:$PATH" VIMBADMIN_BROWSER=firefox \
  bash tests/test-browser-fixture-adapter.sh >"$output" 2>&1 || status=$?
if [[ $status != 73 ]]; then
  echo "FAIL: adapter cleanup changed launch failure status to $status" >&2
  exit 1
fi
grep -qF '[firefox] FAIL: injected browser launch failure' "$output"
grep -qF '[firefox] FAIL: adapter test exited 73' "$output"
if [[ ! -e $node_marker ]]; then
  echo 'FAIL: adapter cleanup test discarded the caller-selected Node path' >&2
  exit 1
fi

echo 'OK: Chrome runner image and confinement are pinned'
