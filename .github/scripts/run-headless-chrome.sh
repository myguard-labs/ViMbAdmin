#!/usr/bin/env bash
set -euo pipefail

readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'
script_dir=$(cd "$(dirname "$0")" && pwd)
readonly script_dir
readonly http_runner=$script_dir/run-chrome-http-fixture.sh

# Keep the legacy CLI entry point and fixture allowlist for every engine.
# Build the matching driver/browser image with:
# docker build -t vimbadmin-browser:1.63.0 .github/scripts/browser
browser=${VIMBADMIN_BROWSER:-chrome}
case "$browser" in
chrome | chromium | firefox | webkit) ;;
*)
  echo "FAIL: unsupported browser engine: $browser" >&2
  exit 64
  ;;
esac

profile=''
fixture_url=''
for arg in "$@"; do
  case "$arg" in
  --user-data-dir=*)
    profile=${arg#--user-data-dir=}
    ;;
  http://127.0.0.1:8765/*)
    fixture_url=$arg
    ;;
  esac
done

if [[ -z "$profile" || "${profile##*/}" != profile ]]; then
  echo 'FAIL: browser fixture runner requires a test-owned --user-data-dir' >&2
  exit 64
fi

readonly fixture_dir=${profile%/profile}
if [[ ! $fixture_dir =~ ^/tmp/vimbadmin-(alias-destination|residual-stored-xss|confirm-guard|datatables-dependency-free|control-behaviour|source-defect-sweep|validate-group|browser-adapter)\.[[:alnum:]]+$ ]] ||
  [[ -L $fixture_dir ]] ||
  [[ ! -d $fixture_dir || ! -w $fixture_dir ]] ||
  [[ $(stat -c '%u:%a' "$fixture_dir") != "$(id -u):700" ]]; then
  echo 'FAIL: browser fixture runner requires a private test fixture directory' >&2
  exit 64
fi

if [[ $browser != chrome ]]; then
  exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges \
    --user "$(id -u):$(id -g)" \
    --env "HOME=$fixture_dir" \
    --mount "type=bind,src=$fixture_dir,dst=$fixture_dir" \
    vimbadmin-browser:1.63.0 "$browser" "$fixture_dir" "$@"
fi

if [[ ($fixture_dir == /tmp/vimbadmin-datatables-dependency-free.* || $fixture_dir == /tmp/vimbadmin-validate-group.*) && -n $fixture_url ]]; then
  exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges \
    --user "$(id -u):$(id -g)" \
    --env "HOME=$fixture_dir" \
    --mount "type=bind,src=$fixture_dir,dst=$fixture_dir" \
    --mount "type=bind,src=$http_runner,dst=/usr/local/bin/run-chrome-http-fixture,readonly" \
    "$image" /usr/local/bin/run-chrome-http-fixture "$fixture_dir" "$@"
fi

exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" \
  --env "HOME=$fixture_dir" \
  --mount "type=bind,src=$fixture_dir,dst=$fixture_dir" \
  "$image" google-chrome --no-sandbox "$@"
