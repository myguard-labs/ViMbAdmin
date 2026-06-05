# ZF1 removal roadmap

> **Status: Phase 0 done; Phases 1–5 not started.** This is the agreed strategy
> for incrementally removing Zend Framework 1. The app runs on ZF1 today (via
> `shardj/zf1-future`) and that is fully supported; the only landed work so far
> is the Phase 0 CI guard that stops the ZF1 surface from growing. The remaining
> phases are followed opportunistically so the application never breaks.

## Principle

**Strangler fig, not a rewrite.** We do not big-bang rewrite, and we do not swap
to a full framework. We replace each ZF1 concern, one at a time, with a small
maintained PSR component. The application keeps working at every commit.

## Why remove ZF1 (ranked)

1. **Security.** ZF1 reached end-of-life in 2016 and receives no upstream
   security patches; we depend on the community `shardj/zf1-future` fork for
   compatibility and CVE coverage. Removing ZF1 deletes a dead, unpatched
   dependency from the attack surface. It also removes the `Zend_Form` + Smarty
   auto-escaping footgun (server-side form output must be marked `nofilter`,
   which is easy to misuse) and lets auth / CSRF / rate-limiting become explicit,
   auditable PSR-15 middleware.
2. **Maintainability.** Less code, standard interfaces, and an end to the
   perpetual "does not comply with psr-0 autoloading standard" warnings (ZF1 uses
   the older PSR-0 layout).
3. **Speed.** Only a modest gain (leaner bootstrap, classmap autoloading). Not a
   reason on its own.

## Current ZF1 surface

- 15 controllers (`application/controllers/`)
- 19 `Zend_Form` subclasses (`library/ViMbAdmin/Form/`)
- ~113 files reference `Zend_`
- Single entry point: `public/index.php` → `Zend_Application`->bootstrap->run

Already framework-free (proof the pattern works in this codebase):
`library/ViMbAdmin/Mcp/*`, `QueueRunner`, `MailboxQueue`, `Doveadm`, `Dovecot`.
Already modern underneath: Doctrine ORM 2.x, `symfony/cache`, Smarty 5.

## Destination: components, not a framework

| ZF1 piece | Replaced with |
|---|---|
| `Zend_Controller` (front controller + routing) | `nikic/fast-route` + a small PSR-15 dispatcher |
| `Zend_Form` (19 classes) | `symfony/validator` + plain Smarty templates |
| `Zend_Auth` / `Zend_Session` | a small Auth/Session service |
| `Zend_Application` (bootstrap / DI) | `php-di/php-di` (PSR-11) |
| `Zend_Db` glue | already Doctrine — done |
| HTTP request/response | PSR-7 (`nyholm/psr7`) + PSR-15 middleware |

Kept: Doctrine, Smarty, `symfony/cache`. New libraries: `nikic/fast-route`,
`php-di/php-di`, `nyholm/psr7` (+ `psr7-server`),
`laminas/laminas-httphandlerrunner` (SAPI emitter), `symfony/validator`.
Removed at the end: `shardj/zf1-future`.

Every replacement is a stand-alone library. Because the Symfony pieces are
à-la-carte components, this path can later graduate to the full Symfony framework
if ever desired — the same path, just a later stopping point.

## Roadmap

Each step is its own pull request with green CI; the application is fully working
at every commit.

### Phase 0 — stop the bleeding (do first, cheap) — **DONE**
- **CI guard:** a lint that fails if any *new* file under `library/ViMbAdmin/`
  (outside `Controller/` and `Form/`) introduces a `Zend_` reference. This locks
  in "new code is framework-free" — already true for `Mcp/*`.
  Implemented as [`tests/lint-no-new-zend.sh`](../tests/lint-no-new-zend.sh),
  wired into the `static` job of `.github/workflows/regression.yml`. It carries a
  baseline allowlist of the three existing legacy-glue files (`Plugin.php`,
  `Doveadm.php`, `Form.php`) and fails the build if any other in-scope file —
  new or previously clean — grows a `Zend_` reference; it also flags a baseline
  entry that has lost its last `Zend_` (the list may only shrink).
- **Rule:** every new feature ships as a `ViMbAdmin\` PSR-4 class with constructor
  dependency injection and no `Zend_`. Controllers/forms remain only as the
  thinnest possible ZF1 glue.

### Phase 1 — extract domain logic out of the controllers (highest value)
For each controller, one at a time:
1. Move its business logic into a plain service in `ViMbAdmin\Service\` that takes
   the Doctrine entity manager plus scalar parameters and returns data or throws —
   no `$this->view`, no action helpers, no `Zend_`.
2. The controller action shrinks to: read request → call service → assign to view.
3. Unit-test the service (now possible without the framework).

After Phase 1 the business logic is framework-free and tested; ZF1 only does HTTP
plumbing.

### Phase 2 — build the framework-free kernel alongside ZF1
1. Add the libraries above via Composer.
2. Write four small kernel pieces (roughly 250 lines total) under `src/Kernel/`:
   - **Container** (PHP-DI) — replaces the `Bootstrap::_init*` methods: Doctrine
     EM, cache pool, Smarty view, Auth/Session.
   - **Router** (fast-route) — preserves the existing
     `/:controller/:action` scheme plus the `/key/value` parameter tail.
   - **Dispatcher** — instantiates the controller from the container, calls the
     action, renders Smarty into a PSR-7 response.
   - **AbstractController** shim — exposes `$this->em`, `$this->view`,
     `param()`, `admin()`, i.e. the same affordances ZF1 gave controllers, so
     migrating a controller is mostly swapping `extends` and renaming a few calls.
3. New `public/index.php`: Container → PSR-7 request → Dispatcher → SAPI emitter.
   The dispatcher falls back to the ZF1 front controller for any route not yet
   migrated, so old and new run side by side.

### Phase 3 — migrate controllers to the shim (one at a time)
Per controller: change `extends ViMbAdmin_Controller_PluginAction` to
`extends AbstractController` and rename the handful of accessor calls. The body
barely changes. The route flips from the ZF1 fallback to native dispatch. URLs are
unchanged. Repeat for all 15.

### Phase 4 — replace `Zend_Form` (the largest chunk: 19 classes)
Replace each form with `symfony/validator` constraints plus a plain, auto-escaped
Smarty template partial. This removes the `setEscapeHtml` / `nofilter` pattern and
the XSS footgun that comes with it.

### Phase 5 — auth/session and finish
1. Replace `Zend_Auth` / `Zend_Session` with a small Auth/Session service using
   modern cookie defaults (SameSite, secure, current hashing), wired as PSR-15
   middleware.
2. Convert the codebase from PSR-0 to PSR-4 namespaces
   (`ViMbAdmin_Foo_Bar` → `ViMbAdmin\Foo\Bar`); this ends the PSR-0 warnings.
3. Delete the ZF1 front-controller fallback.
4. Remove `shardj/zf1-future` from `composer.json`. Done.

## Guardrails

- Every step is an independent PR with green CI.
- No timeline. This is opportunistic: when you touch a controller, extract its
  service. It may take a long time, and that is fine — the app works throughout.

## The one step worth taking now

Phase 0's CI guard is the highest-leverage move: it costs about ten lines and
ensures the end-of-life surface only ever shrinks. Everything else can wait until
the relevant code is being touched anyway.
