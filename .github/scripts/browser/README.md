# Browser fixture runner

`regression.yml` runs the seven existing browser lanes on Chromium, Firefox
and WebKit. Build the runner from the repository root:

```sh
docker build -t vimbadmin-browser:1.63.0 .github/scripts/browser
VIMBADMIN_BROWSER=firefox \
  CHROMIUM_BIN="$PWD/.github/scripts/run-headless-chrome.sh" \
  bash tests/test-jquery-migrate-compat.sh
```

The image pins Playwright 1.63.0 and its matching noble **linux/amd64** browser
image for the `ubuntu-24.04` GitHub runner. Update the Dockerfile digest, npm
lockfile, image tag in the workflow/wrapper and its mirror assertion together.
Version source: [official release](https://github.com/microsoft/playwright/releases/tag/v1.63.0).

`CHROMIUM_BIN` remains the fixture CLI adapter. The wrapper validates the
existing private-directory allowlist before starting any engine. Containers
have no external network, no capabilities, no new privileges, the caller's
UID/GID and only the private fixture bind mount. No host package installation
is needed. Without `VIMBADMIN_BROWSER`, the original Chrome runner is selected.

The adapter uses the test-owned persistent profile because the compatibility
lane checks cookies across page loads. File fixtures load directly; HTTP
fixtures use an owned loopback server on an ephemeral port. It waits for the
fixture's terminal DOM verdict instead of emulating Chrome's virtual clock.
A terminal failure is dumped for the existing shell assertion; launch,
navigation and missing-verdict failures exit nonzero with an engine label.

The matrix includes `test-browser-fixture-adapter.sh` (actual user-agent engine
identity, file/HTTP success, engine mismatch and missing/invalid fixture
controls) and `test-browser-engine-negative-control.sh` (the existing
compatibility lane must exit 1 on its deliberately disabled-button mutation).
The adapter fixture prefix is registered in the workflow, wrapper allowlist
and `test-headless-chrome-container.sh`, as required for every browser fixture.
