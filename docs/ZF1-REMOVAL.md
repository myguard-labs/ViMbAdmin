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

> **Progress.** The pattern is established and migrated controllers so far:
> - `DomainController` → [`library/ViMbAdmin/Service/Domain.php`](../library/ViMbAdmin/Service/Domain.php)
>   (toggle-active, assign/remove admin, purge).
> - `AdminController` → [`library/ViMbAdmin/Service/Admin.php`](../library/ViMbAdmin/Service/Admin.php)
>   (toggle-active, toggle-super, assign/remove domain, purge).
>
> Each service depends only on `Doctrine\Persistence\ObjectManager` (the minimal
> port — persist / remove / flush / getRepository) and throws
> `ViMbAdmin_Service_Exception` on a business-rule violation. They are unit-tested
> with no database (`tests/test-service-domain.php`, `tests/test-service-admin.php`)
> via an in-memory `ObjectManager` fake + plain-PHP `\Entities\*` objects, run by
> the `unit` job of `.github/workflows/regression.yml`.
>
> Conventions for the remaining controllers:
> - Services are PSR-0 underscore classes (`ViMbAdmin_Service_Foo`) under
>   `library/ViMbAdmin/Service/`, matching the existing `ViMbAdmin_Mcp_*`
>   precedent. The backslash PSR-4 rename happens later, in Phase 5.
> - Depend on the **narrowest** Doctrine interface that works (`ObjectManager`),
>   not the full `EntityManagerInterface`, so the unit test needs no DB.
> - The service owns its full side effect, including writing its own
>   `\Entities\Log` rows and a single `flush()`; the controller keeps only the
>   HTTP glue (param/entity resolution, `authorise()`, plugin `notify()` hooks,
>   `addMessage()`, `redirect()`).
> - Form handling (`Zend_Form`) stays in the controller for now — it is Phase 4.
>
> **Notify-interleaved actions (Alias, Mailbox) need a no-flush variant.** Unlike
> Domain/Admin, several `AliasController` / `MailboxController` actions fire plugin
> `notify()` hooks *between* the mutation and the flush — e.g. toggle-active does
> `setActive → log → notify('…preflush', ['active'=>NEW state]) → flush →
> notify('…postflush')`. A service that owns its flush cannot preserve this: the
> `preflush` plugin must observe the post-mutation state and run immediately
> before the flush. So for these controllers the service method does the pure
> entity work (mutate + persist its Log row) and **returns without flushing**; the
> controller keeps the `notify()` ordering and the single `flush()`. Likewise
> `delete` actions gate the removal behind `notify('preRemove') !== false`, so the
> gate stays controller-side and the service exposes only the removal/decrement/log
> body. These are lower-value, higher-risk than Domain/Admin and are best done
> after Phase 4 thins the form coupling — left intentionally for later.

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

> **Split into 2a (landed) and 2b (needs a live instance).** The kernel pieces
> have very different risk profiles, so Phase 2 is delivered in two parts.
>
> **2a — Router (DONE).** [`src/Kernel/Router.php`](../src/Kernel/Router.php) +
> [`RouteMatch`](../src/Kernel/RouteMatch.php) decode a path with ZF1's exact
> scheme (`/{controller}/{action}/{k}/{v}…`, both defaulting to `index`) and
> inflect to the same `FooController::barAction()` ZF1 produced, so URLs are
> preserved. It is **dependency-free** (no fast-route: ViMbAdmin has one generic
> pattern with a variable-length key/value tail that fast-route can't express
> natively, and a hand parser is clearer + unit-testable with no framework).
> Migration is opt-in via a "native controllers" allowlist that **starts empty**,
> so `match()` returns `null` for everything and every request still goes to ZF1 —
> zero behaviour change. Fully tested with no DB in
> [`tests/test-kernel-router.php`](../tests/test-kernel-router.php) (the `unit`
> job). `src/` is PSR-4 (`ViMbAdmin\Kernel\`) and is held to a **zero-`Zend_`**
> rule by the Phase 0 guard (no allowlist — it is all new replacement code).
>
> **2b — Container / Dispatcher / AbstractController shim / new `index.php`
> (deferred, needs validation against a running instance).** These boot Doctrine,
> Smarty, Auth/Session and replace the application's entry point. They cannot be
> meaningfully validated without running the app (and this is a production mail
> admin), so they are NOT written blind. When tackled: the Container should
> **reuse** the existing ZF1 bootstrap to obtain the already-wired EM / view /
> auth rather than re-implement `application.ini`; the new `index.php` should be
> gated behind an env flag (default off = byte-identical to today's ZF1 path) and
> flipped on only after the first native route is verified in staging. fast-route
> + php-di + nyholm/psr7(-server) + laminas-httphandlerrunner are added with this
> part, where they are actually used.

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

> **Ordering correction: the session foundation is a prerequisite, not the last
> step.** Building Phase 2b/3/4 revealed that all three transitively need a
> framework-free session:
> - the Phase 3 `AbstractController` shim must supply `getAdmin()` / `authorise()`
>   / `getSessionNamespace()` / `_assertCsrf()` before any real controller can move;
> - a full Phase 4 form replacement needs a **session-backed CSRF token** (today's
>   forms use the ZF1 hash element; `_assertCsrf()` uses a session token), plus a
>   replacement for the `{addJSValidator}` client-side validation generated from
>   the ZF1 form.
>
> So the practical sequence is **0 → 1 → 2a → (session + CSRF foundation) → 2b
> skeleton → 3 → 4 → rest of 5**. The pieces below are being landed first, small
> and inert (nothing wired yet, zero behaviour change), exactly like the Router:
> - [`src/Kernel/Session/SessionStorage.php`](../src/Kernel/Session/SessionStorage.php)
>   — a narrow session port (has/get/set/remove) so security/auth services are
>   unit-testable with an in-memory fake; [`NativeSessionStorage`](../src/Kernel/Session/NativeSessionStorage.php)
>   is the namespaced `$_SESSION` implementation for production.
> - [`src/Kernel/Security/Csrf.php`](../src/Kernel/Security/Csrf.php) — one
>   framework-free CSRF service over that port (stable per-session token,
>   constant-time `hash_equals`, `rotate()` for post-login hygiene), preserving
>   `_assertCsrf()` semantics. Replaces both the controller token guard and the
>   form hash element when wired in Phase 3/4. Tested with no DB in
>   [`tests/test-kernel-csrf.php`](../tests/test-kernel-csrf.php) (the `unit` job).

## Guardrails

- Every step is an independent PR with green CI.
- No timeline. This is opportunistic: when you touch a controller, extract its
  service. It may take a long time, and that is fine — the app works throughout.

## The one step worth taking now

Phase 0's CI guard is the highest-leverage move: it costs about ten lines and
ensures the end-of-life surface only ever shrinks. Everything else can wait until
the relevant code is being touched anyway.
