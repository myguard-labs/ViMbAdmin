# Browser fixture runner

`regression.yml` runs the eight existing browser lanes on Chromium, Firefox
and WebKit. Build the runner from the repository root:

```sh
docker build -t vimbadmin-browser:1.63.0 .github/scripts/browser
VIMBADMIN_BROWSER=firefox \
  CHROMIUM_BIN="$PWD/.github/scripts/run-headless-chrome.sh" \
  bash tests/test-datatables-dependency-free-compat.sh
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

`run-fixture.cjs` also has an explicit application-origin mode for
`ci/test-browser-auth-errors.sh`. That mode accepts only a declared loopback
origin, starts no Node static server, never consults fixture DOM verdicts and
never dumps rendered HTML. The CI entry point creates a private application
tree from `composer.lock`, boots a pinned disposable MariaDB and captured
loopback PHP servers, then drives the real auth/error routes in all three
engines. This mode deliberately uses host networking so the browser can reach
the fixture's host-loopback listeners. Unlike static mode, the container then
shares the host network stack, including non-loopback and external access; the
application-origin guard and its observed-red mutation only prevent the runner
from silently targeting another origin.

`ci/test-browser-tenant.sh` reuses that private application fixture for the
admin, domain and mailbox lists and active toggles, plus domain/mailbox edit-form
access. It seeds a super admin,
an admin assigned only to one domain, an admin with no domains, and mailboxes
in two domains. Every engine checks rendered tenant visibility, exact mutation
success/denial responses, invalid IDs/CSRF, self-toggle denial, and database
snapshots proving denied requests leave all tables unchanged. Allowed toggles
check the exact entity change and full-row digests proving every column of all
other admins, domains and mailboxes stayed unchanged.
An older real domain search response is held until a newer search renders,
then released to prove it cannot replace the newer rows. This is list/toggle
and edit-form-access coverage, not coverage of every admin, domain or mailbox action.

The tenant runner removes the domain authorization guard only in its disposable
application copy, requires the assigned admin's cross-tenant denial assertion
to fail, restores the source and requires the complete suite to pass again.
It records the interpreted source artifact's timestamp, size and digest beside
the engine logs. `ci/test-entry-autoload-control.sh` independently disables
early entity-loader registration and observes the original cold-controller
signature fatal, then confirms restored configured-path behavior.

Container cleanup captures volume names from each recorded owned container
before removing it with `docker rm -v` (forced during failure cleanup), then
checks those exact anonymous volumes are absent. `ci/test-browser-owned-cleanup.sh`
exercises normal/forced removal against real volumes and observes the cleanup
assertion fail when volume removal is disabled. The entry-autoload control
prints its complete logs before deleting a successful private fixture; failed
fixtures are retained with their exact paths for diagnosis. Its lifecycle has
a separate success-removal/failure-retention mutation control.
