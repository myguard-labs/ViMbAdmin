# ViMbAdmin — modernised fork

> 🚀 **Live demo:** **<https://vimbadmin.myguard.nl>** — kick the tyres before you
> deploy. Read-only-ish demo account (password + 2FA changes locked, outgoing
> mail no-op'd); everything else is the real panel.
>
> 📖 **Full write-up, history & guided tour:**
> **<https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>**

*Virtual Mailbox Administration that runs on a PHP version released this decade.*

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)]()
[![Stack](https://img.shields.io/badge/Native%20kernel%20%C2%B7%20Doctrine%20ORM%203%20%C2%B7%20Smarty%205-informational)]()

**ViMbAdmin** (*vim-be-admin*, and yes the editor war is intentional) is a web
panel for managing the virtual domains, mailboxes and aliases in a
**Postfix + Dovecot** mail server backed by a SQL database. It sits between
your tired hands and the `mailbox` table so you stop editing production mail
with raw `INSERT` statements at 02:00. You know who you are.

This is the **[eilandert](https://github.com/eilandert) fork**. Upstream
([opensolutions/ViMbAdmin](https://github.com/opensolutions/ViMbAdmin)) is a
fine piece of software that stopped getting commits years ago and no longer
runs cleanly on a modern PHP. We needed it on PHP 8.5, on a hardened stack,
so we fixed it. Then we kept fixing it until the audit log went quiet.

> 📖 **Read the full story, the why, and a guided tour:**
> [ViMbAdmin: The Postfix + Dovecot Mailbox Admin Panel (Modernised for PHP 8.5)](https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/)
> on deb.myguard.nl — history, who it's for, and how it fits a real mail stack.

---

## What's different in this fork

The short version: it runs, and it's hard to break into.

**🆕 No helper scripts, no Dovecot-side cron — mailbox ops are native**

Mailbox maintenance no longer needs any external scripts or Dovecot-side cron
jobs. Everything is driven straight from the panel:

- **Repair / optimize / archive / delete** run against Dovecot's built-in
  **doveadm HTTP API** (`force-resync`, `index`, `purge`, `backup`,
  `mailbox delete`) — no shared mail filesystem, no `rm -rf`, no tar scripts.
  Each request is **queued** in a dedicated table and drained by a single
  throttled runner, so a bulk action can't hammer Dovecot. A **Queue** tab
  shows progress and lets you run it on demand; an optional key+IP-gated
  endpoint lets a remote cron kick it.
- **Passwords** are hashed **natively in PHP** (`BLF-CRYPT`, `SHA512-CRYPT`,
  `SHA256-CRYPT`) — the `doveadm pw` binary and the old `dovecotpasswd.php`
  workaround are gone.
- **Quota usage** comes live from Dovecot's quota-clone `dovecot_quota` table —
  the old maildir-scan accounting cron is retired.

Net result: the Dovecot container ships **zero** ViMbAdmin scripts/cron, and the
only optional cron left is the panel's own queue-runner.

**Brought into this decade**

- **ZF1 is completely gone.** Upstream was built on Zend Framework 1 (EOL since
  2016). This fork rips it out **entirely** — the HTTP/CLI kernel, routing,
  forms, config loader, sessions and auth are all native PHP 8 now. Not a single
  line of Zend Framework, nor any `Zend_*` class, remains in the tree or the
  dependency graph; `composer.json` no longer requires it. The one visible ZF1
  leftover is the `application.ini` config *format* (its `[child : parent]`
  section inheritance and `resources.*` keys are a ZF1 convention) — read by a
  small native loader now, and a candidate to be replaced in a future release.
  See [Upgrading & schema migrations](#upgrading--schema-migrations).
- **PHP 8.1 → 8.5** clean. Every implicit-nullable parameter fixed, every
  PHP-8-removed function call replaced.
- **Smarty 4 → 5.** Templating bridge ported to the new API (setters, the
  `nofilter` flag, the `{if}`-can't-call-PHP-functions rule, and the
  delightful clone bug where Smarty 5's BC plugin loader drops every custom
  plugin from a cloned view — which is why your forms used to render blank).
- **Doctrine ORM 2.8 → 3.x** (currently orm 3.6 / dbal 4) + persistence 4. CLI
  and query API rewritten to match; the ORM 3 jump needed native lazy-loading
  proxies (`enableNativeLazyObjects`), PSR-6 caches and an `object`-type shim.
- **Cache layer rebuilt on Symfony Cache.** `doctrine/cache` 2.x dropped the
  old concrete `*Cache` providers, so the metadata/query cache now wraps a
  Symfony PSR-6 pool (`ArrayAdapter` / `ApcuAdapter` / `RedisAdapter`) in
  `DoctrineProvider` — pick the backend in `application.ini`. The Docker image
  ships **APCu** + a tuned **OPcache** for a persistent, per-request-free cache.

See **[Security](#security)** below for the full list of what was hardened,
and **[Performance](#performance)** for the caching notes.

---

## Security

Everything this fork does to keep the panel hard to break into, by layer. The
stock upstream had **none** of the application-layer items below.

### Authentication

- **Two-factor authentication (TOTP).** Opt-in per admin at `/admin/two-factor`.
  - Secret **encrypted at rest** with libsodium (`crypto_secretbox`), keyed off
    the app `securitysalt` — a DB read alone yields no usable secrets.
  - QR-code enrolment + manual secret entry; **one-time backup codes**
    (bcrypt-hashed, single-use).
  - **Replay protection** — a TOTP time-slice is accepted once; a captured code
    can't be replayed inside its validity window.
  - **Super-admin management** of other accounts: provision (show secret/QR to
    hand over), regenerate, disable, and **force enrolment at next login**.
  - **Lost-device recovery** without DB surgery: backup codes, a CLI reset
    (`vimbtool.php -a admin.cli-reset-totp --username=…|--all`), or
    `application.ini` (`twofactor.force_disable`).
- **Passwords.** Admin passwords bcrypt-hashed and compared in **constant time**
  (`hash_equals`). Mailbox passwords hashed **natively in PHP** in a
  Dovecot-accepted scheme (`BLF-CRYPT`/`SHA512-CRYPT`/`SHA256-CRYPT`) — no
  `doveadm pw` shell-out.
- **Session-fixation defence** — the session id is regenerated on every
  successful login (and again after the 2FA step).
- **Brute-force protection** — per-source-IP attempt counter with lockout
  window; a fully successful login clears it. IP/CIDR **allowlist** and all
  thresholds configurable in `application.ini` (`[bruteforce]`). 429 when locked.
- **CSRF** — a per-session token validated by the native form layer on
  **every form** and on every destructive GET link
  (purge/delete/cancel/restore); forged request → 403.

### Output / input handling

- **XSS auto-escaping** — Smarty `setEscapeHtml(true)` globally; only
  deliberately-HTML output is `nofilter`. A stored `<script>` payload renders as
  inert text.
- **SQL injection** — the app uses Doctrine ORM with parameterised queries; the
  four unreferenced raw-SQL "OSS API" integration classes (one with an actual
  injection) were deleted.
- **Command injection** — every shell-out (Dovecot `doveadm`, archive
  tar/bzip2/du) is `escapeshellarg`'d.
- **Deserialisation** — `unserialize()` of archive blobs is restricted with
  `['allowed_classes' => false]`.
- **CSPRNG** — tokens, salts and backup codes use `random_int()` (the old
  `str_shuffle`/`mt_rand` was replaced).
- **Real client IP** — a spoof-resistant trusted-proxy resolver
  (`trustedproxy.mode`, default `auto`) feeds the brute-force limiter and the
  MCP IP allowlist the actual client, not the reverse proxy. See
  [Real client IP behind a proxy](#real-client-ip-behind-a-proxy).

### MCP adapter (optional, off by default)

- **Bearer-token JSON-RPC API** at `/mcp` for agents: SHA-256-hashed tokens
  (raw shown once), scoped read/write, optional per-token IP/CIDR allowlist,
  expiry + revoke, and a per-token **rate limit on destructive operations**.
  Edge IP-allowlisted in the vhost; bearer-only (no admin session). See the
  [MCP adapter](#mcp-adapter) section and [docs/mcp-auth.md](docs/mcp-auth.md).

### Contrib

Everything under [`contrib/`](contrib/) — deploy configs, the runtime
Snuffleupagus ruleset, schema migrations and the WAF plugin — that hardens the
panel around the edges. The stock upstream shipped none of it.

- **Runtime Snuffleupagus ruleset** — a **code-derived
  [`vimbadmin-strict.list`](contrib/snuffleupagus/vimbadmin-strict.list)**: bans
  every dangerous function the app doesn't use, allow-scopes the `exec` it does,
  blocks RFI/LFI wrappers, eval/`base64_decode` webshell pipes, mail-header
  injection, env hijacking, world-writable chmod, writing PHP-loadable files, and
  insecure cURL/SSRF. Logs/encrypts cookies as available. A unique `secret_key`
  must be set per deployment.
- **Hardened PHP-FPM pool** (`contrib/php-fpm/vimbadmin.conf`) —
  `open_basedir`, empty native `disable_functions` (Snuffleupagus owns the
  policy), strict session-cookie flags, `security.limit_extensions=.php`,
  resource limits.
- **Hardened Angie/nginx vhost** (`contrib/angie/vimbadmin.conf`) — a **native
  positive-security gate**: only known HTTP methods, the real route map
  (controllers + path parameters), and the app's known argument names reach PHP;
  scanner/empty user-agents are dropped. Plus TLS, strict **CSP** + security
  headers, a **rate-limited login**, internal-path/dotfile denies, and
  **BREACH mitigation** (no compression of secret-bearing dynamic responses).
- **OWASP CRS / ModSecurity plugin** *(optional, belt-and-braces)* — payload
  signature scanning on top of the vhost, only where you already run
  libmodsecurity:
  [vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin).
- **Docker image** — read-only rootfs, root-owned read-only codebase,
  per-deployment secrets generated at first run, all caps dropped bar the few
  needed, docs/repos/setuid stripped. See the
  [image README](https://github.com/eilandert/dockerized/tree/master/src/vimbadmin).

### Attack surface removed

- Dead Doctrine 1 code, an unused PDF chain, the Yubico/Invoice/GeoIP/Csv/Phone/
  Acl/Curl/Crypt_OpenSSL utilities, and four unreferenced "OSS API" classes
  (one carrying SQLi). ~1,600+ lines gone.
- Fixed real latent bugs surfaced on the way: AJAX toggle guards that printed
  "ko" but toggled anyway (privilege bug), and `$this->getLogger->` property-
  access fatals on the archive paths.

### Dependencies

- On current release lines (doctrine/orm 3.6, DBAL 4, symfony/cache 6.4/7/8,
  Smarty 5, robthree/twofactorauth 3, bacon/bacon-qr-code 3);
  the application kernel, routing, forms, sessions and CLI are native and ZF1-free;
  `composer audit` reports **no advisories**.

---

## Quick start (Docker)

The fastest way to a running panel. You bring a MariaDB/MySQL database; the
image bundles the app, PHP-FPM and the web server.

```yaml
# docker-compose.yml  --  change the passwords. "vimbpass" is not a password.
services:
  db:
    image: mariadb:lts
    environment:
      MARIADB_ROOT_PASSWORD: change-me
      MARIADB_DATABASE: vimbadmin
      MARIADB_USER: vimbadmin
      MARIADB_PASSWORD: change-me-too

  vimbadmin:
    image: eilandert/vimbadmin:latest
    depends_on: [db]
    ports:
      - "8080:80"
    environment:
      TZ: Europe/Amsterdam
```

```sh
docker compose up -d
# wait for MariaDB's first-boot, then browse to http://localhost:8080/
```

Put it behind TLS in production — ideally behind the hardened vhost and the
ModSecurity plugin shipped alongside this repo.

## Quick start (from source)

PHP **8.4.1+** (the dependency-tree floor) with `pdo_mysql`, `mbstring`,
`intl`, `gettext`, `gd`, `dom`, `ctype`, `iconv` and `sodium` (the 2FA secrets are
encrypted with libsodium). `apcu` is optional but recommended (see
[Performance](#performance)).

```sh
git clone https://github.com/eilandert/ViMbAdmin.git
cd ViMbAdmin
composer install --no-dev

cp application/configs/application.ini.dist application/configs/application.ini
# edit application.ini: point resources.doctrine2.connection.options.* at your DB

# create the schema (this is the modernised CLI; the old one used a dead API)
./bin/doctrine2-cli.php orm:schema-tool:create
```

Point your web server's docroot at `public/`, wire PHP-FPM to it, and browse
to the site.

## First run

ViMbAdmin notices it has no admins and sends you to a setup page. Do this
immediately, on a trusted network.

1. It generates a **security salt** — keep the one it gives you.
2. Create the **super-admin**. The **username is an email address**
   (`you@yourdomain.com`), *not* the word "admin". The field is literally
   labelled "Email". People miss this hourly. Don't be people.
3. Pick a real password. It's bcrypt-hashed and constant-time-compared; the
   strength is on you.

## Upgrading & schema migrations

### The panel upgrades its own schema

**ViMbAdmin knows how to migrate its own database** — one self-contained command,
no Doctrine Migrations files to manage. It tracks a `DBVERSION` and applies any
pending DDL through the native `maintenance.cli-schema-update` command
(`ViMbAdmin_Schema::migrate()`, the same code the in-panel **Maintenance →
schema update** button runs). It bundles the Doctrine `SchemaTool` diff with the
extra FK/collation/index steps the schema-tool can't express, and is idempotent:

```sh
./bin/vimbtool.php -a maintenance.cli-schema-update            # apply
./bin/vimbtool.php -a maintenance.cli-schema-update --verbose  # apply + print SQL + DB version
```

**It does not run on its own** — nothing triggers it on login or per request.
You invoke it once after an upgrade. Where it's invoked *for* you depends on the
deployment:

- **Docker** — the image runs it automatically at **every container start** (the
  s6 init-bootstrap step), so pulling a newer image and `docker compose up -d`
  brings the schema forward with no manual step.
- **From source / bare metal** — run the command above (or click **Maintenance →
  schema update**) yourself after each `git pull`. Wire it into your own
  deploy/cron if you want it automatic.

Always back up first — it issues DDL.

### Doing it by hand instead

If you'd rather drive the migration yourself, two equivalent manual routes:

```sh
# A) let Doctrine reconcile the DB with the entity mappings (shows the SQL):
./bin/doctrine2-cli.php orm:schema-tool:update --dump-sql      # preview
./bin/doctrine2-cli.php orm:schema-tool:update --force         # apply
```

```sh
# B) apply the consolidated fork-schema migration from contrib/migrations/
mysql -u<user> -p <database> < contrib/migrations/2026-06-fork-schema.sql
```

`contrib/migrations/` holds one idempotent, consolidated SQL file —
[`2026-06-fork-schema.sql`](contrib/migrations/2026-06-fork-schema.sql) — the
standalone mirror of everything the fork adds above upstream (DBVERSION 3). It
is the hand-written equivalent of `orm:schema-tool:update --force` plus the
FK/collation steps the schema-tool can't express, and is safe to re-run. In
dependency order it covers: (1) the **`dovecot_quota`** table + retirement of
the legacy maildir-scan columns; (2) the **`UNIQUE` index on `mailbox.username`**
(Postfix/Dovecot look that up on every delivery and login, and it is the FK
target in step 3); (3) **`ON DELETE CASCADE` FKs** `dovecot_quota` /
`dovecot_last_login` → `mailbox(username)` plus the collation alignment they
need; (4) the **`archive.autoprune`** column. Fresh installs build all of this
automatically; only DBs seeded from older dumps need the file. Always back up
first; step 2's index is `UNIQUE`, so dedupe any duplicate usernames before
applying:

```sh
SELECT username, COUNT(*) c FROM mailbox GROUP BY username HAVING c > 1;
```

### Config (`application.ini`) migration

The schema isn't the only thing that drifts across upgrades — a newer version
may also add config keys. Your live `application.ini` is **never overwritten**
(it holds your DB credentials and salt), so new keys won't appear by magic;
reconcile it against the shipped template after a `git pull`:

```sh
# see which keys the template gained that your live file lacks
diff <(grep -oE '^[a-z][a-zA-Z0-9_.\[\]]*' application/configs/application.ini      | sort -u) \
     <(grep -oE '^[a-z][a-zA-Z0-9_.\[\]]*' application/configs/application.ini.dist | sort -u)
```

Every key carries a sane default in code, so a missing key just means "feature
off / default value" — nothing breaks. Copy across only the new keys you want to
change. The Docker image generates a fresh `application.ini` from the `.dist`
template at first run (injecting the DB env + a per-deployment salt) and leaves
it alone thereafter, so the same "diff against `.dist`" rule applies there.

> **`application.ini` is a ZF1 leftover and will likely change.** Its
> `[child : parent]` section-inheritance and `resources.*` key namespace are a
> Zend Framework 1 convention. ZF1 itself is gone (a small native
> [`IniConfig`](src/Kernel/Config/IniConfig.php) loader reads the file now), so
> the format is a candidate to be **replaced** in a future release — likely with
> a plain PHP-array or env-driven config and a one-shot converter. The keys and
> their meanings will be preserved across any such change; only the on-disk
> *shape* would move. Treat the `application.ini` filename/format as **not yet
> final**.

The **`dovecot_quota`** part of that migration lets this fork retire the old
nightly maildir-scan (`mailbox.cli-get-sizes`) and get **live** mailbox usage
straight from Dovecot's quota-clone plugin instead: it creates the
`dovecot_quota` table, seeds it from the old `maildir_size` values, then drops
the retired `maildir_size` / `homedir_size` /
`size_at` columns. See
[Live quota usage (Dovecot quota-clone)](#live-quota-usage-dovecot-quota-clone)
for the Dovecot config.

## Day-to-day

In order, because the order matters:

1. **Domains → Add.** The `@example.com`. Set per-domain limits and quotas.
   (Postfix still has to be configured to read `virtual_mailbox_domains` from
   the DB — ViMbAdmin maintains the data, it can't make Postfix care.)
2. **Mailboxes → Add.** Local part, password, quota. The password is hashed
   natively in PHP in a scheme Dovecot accepts (`BLF-CRYPT` etc.) — no external
   `doveadm pw`.
3. **Aliases → Add.** Address → comma-separated `goto` list. This is your
   `postmaster@`, your role addresses, your distribution lists.

Every action is logged, validated, and CSRF-protected.

## CLI reference (`bin/vimbtool.php`)

All maintenance/automation tasks run through one entry point:

```bash
./bin/vimbtool.php -a <controller.action> [options]
# in the Docker image:
docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a <controller.action> [options]
```

`bin/vimbtool.php -a help` (or `--help`) prints the action list; `--copyright`
prints the banner. The CLI is framework-free (ZF1-removed) and never starts a
session or authenticates — every command works against the Doctrine EM directly.

**Global flags** (accepted by every action; honoured where meaningful):

| Flag | Meaning |
|---|---|
| `-v`, `--verbose` | extra output (e.g. schema-update prints the SQL + DB version) |
| `-d`, `--debug` | debug output |
| `-h`, `--help` | usage + action list |
| `-c`, `--copyright` | print the banner and exit |

**Actions:**

| Action | What it does | Options |
|---|---|---|
| `queue.cli-run` | Drain the **mailbox-task queue** — claims up to `queue.runner.max_per_run` PENDING tasks (repair / optimize / archive / delete) and runs them against Dovecot over the doveadm HTTP API. The periodic runner (the image fires it on start + every 5 min; also triggered on login / Maintenance / MCP). Concurrency capped by the `queue.runner.max_concurrent` DB lease. | — |
| `mailbox.cli-delete-pending` | Purge mailboxes flagged **delete-pending**: removes the on-disk maildir + homedir (via `binary.path.rm_rf`, each path `escapeshellarg`'d) then the DB row. Run from cron after a deletion grace period. | — |
| `maintenance.cli-schema-update` | Apply pending **Doctrine schema migrations** (DDL). Same code as the in-panel **Maintenance → Update schema** button. Run on deploy/upgrade; the Docker image runs it automatically on every start. | `--verbose` → also print the SQL + resulting DB version |
| `maintenance.cli-precompile-templates` | Compile every **Smarty template** ahead of time into the persistent `var/templates_c`, so the first web request pays no compile cost. Idempotent; the image runs it on start. | — |
| `admin.cli-reset-totp` | **Disable two-factor** for a locked-out admin (recovery path). | `--username=<email>` (one admin) **or** `--all` (every admin) |
| `mcp.cli-token-generate` | Mint a new **MCP API token**. The raw token is printed **once** (only its SHA-256 is stored). | `--name=` (required, must be free/revoked), `--scope=` (default `read`), `--ip=` (allow-list), `--domains=` (scope to domains), `--days=` (validity; default no expiry) |
| `mcp.cli-token-list` | List the MCP API tokens (name, scope, ip, domains, expiry, revoked). | — |
| `mcp.cli-token-revoke` | Revoke an MCP API token (sets the revoked flag; the row is kept for audit). | `--id=<id>` **or** `--name=<name>` |

Examples:

```bash
# drain the queue (the every-5-min job; safe to run by hand)
./bin/vimbtool.php -a queue.cli-run

# apply schema changes after an upgrade, showing the SQL
./bin/vimbtool.php -a maintenance.cli-schema-update --verbose

# rescue an admin who lost their 2FA device
./bin/vimbtool.php -a admin.cli-reset-totp --username=admin@example.com

# issue a read-only MCP token, locked to one IP + domain, valid 30 days
./bin/vimbtool.php -a mcp.cli-token-generate --name=monitoring \
    --scope=read --ip=10.0.0.5 --domains=example.com --days=30
./bin/vimbtool.php -a mcp.cli-token-list
./bin/vimbtool.php -a mcp.cli-token-revoke --name=monitoring
```

## Two-factor authentication

Opt-in, per admin. Each admin enables it on themselves at **`/admin/two-factor`**:

1. Scan the QR with an authenticator app (Aegis, Google Authenticator,
   1Password, …) or type the shown secret in by hand.
2. Enter the 6-digit code to confirm and enable.
3. **Save the one-time backup codes.** They're shown once. Each works once,
   for when your phone inevitably ends up in a washing machine.

After that, login is password → 6-digit code. The TOTP secret is stored
encrypted (libsodium, keyed off `securitysalt`); a database read alone
doesn't yield usable secrets.

**Lost your second factor?** Two escape hatches, no DB surgery required:

```sh
# CLI (immediate):
./bin/vimbtool.php -a admin.cli-reset-totp --username=admin@example.com
./bin/vimbtool.php -a admin.cli-reset-totp --all

# or in application.ini (applied at that admin's next login):
twofactor.force_disable = "admin@example.com"     ; or "*" for everyone
```

## Brute-force protection

On by default. Counts failed logins per source IP and locks the source out
once it crosses the threshold; a fully successful login (password + 2FA)
clears the counter. Configure in `application.ini`:

```ini
bruteforce.enabled      = 1
bruteforce.max_attempts = 5       ; failures before lockout
bruteforce.window       = 900     ; seconds the counter accumulates over
bruteforce.lockout      = 900     ; seconds locked
bruteforce.whitelist[]  = "127.0.0.1"
bruteforce.whitelist[]  = "10.0.0.0/8"    ; IPs or CIDRs never counted
```

### Real client IP behind a proxy

The brute-force limiter (and the MCP per-token IP allowlist) need the **real**
client IP, not your reverse proxy's. Controlled by `trustedproxy.mode` in
`application.ini`:

```ini
trustedproxy.mode = "auto"   ; auto | on | off
;trustedproxy.proxies[] = "10.0.0.0/8"   ; for mode "on"
```

- **`auto`** (default) — trust `X-Forwarded-For` only when the request reaches
  PHP from a private/loopback address (a local reverse proxy). Standalone
  (public `REMOTE_ADDR`) just uses `REMOTE_ADDR`. No config needed for the
  usual "proxy on the same host/LAN" setup.
- **`on`** — trust `X-Forwarded-For` only from the proxies you list.
- **`off`** — always use `REMOTE_ADDR`.

`X-Forwarded-For` is client-spoofable, so the client is taken as the right-most
address in the chain that isn't a trusted proxy. Alternatively, let the web
server rewrite `REMOTE_ADDR` (Angie/nginx `realip`; see the commented block in
`contrib/angie/vimbadmin.conf`) and leave the mode at `auto`.

## MCP adapter

An optional **JSON-RPC API at `/mcp`** so an agent can read and manage the
mailbox database. **Off by default** (`mcp.enabled = 1` to turn on). Guarded in
depth: an edge IP allowlist, a **bearer token** (only its SHA-256 hash is
stored, scoped + revocable + expirable), a per-token IP/CIDR allowlist, and a
per-token **rate limit on destructive calls**. Read methods (`domains.list`,
`mailboxes.list`, …) and write methods (`mailbox.create`, `mailbox.archive`, …)
are scope-gated. Manage tokens from the CLI:

```sh
./bin/vimbtool.php -a mcp.cli-token-generate --name=agent1 --scope="read"
./bin/vimbtool.php -a mcp.cli-token-list
./bin/vimbtool.php -a mcp.cli-token-revoke --name=agent1
```

Full method list, auth model and examples: **[docs/mcp-auth.md](docs/mcp-auth.md)**.

## Performance

The panel is light, and a few things keep it snappy — most of them warm at
container start, so the first request after a (re)start isn't cold:

- **OPcache + preload.** OPcache caches compiled PHP bytecode; the Docker image
  tunes it for an immutable codebase (`opcache.validate_timestamps=0`, no stat()
  per include) and sizes it to the measured footprint. `opcache.preload` runs
  [`preload.php`](preload.php) in the PHP-FPM master at startup, compiling the
  whole app + vendor tree (plus the precompiled templates) into shared memory, so
  worker processes never compile on the first request. Preload is the only way to
  warm OPcache without an HTTP request (it is per-SAPI) — a few `Can't preload
  unlinked class …` notices for Doctrine console/annotation classes are expected
  and harmless.
- **Doctrine metadata/query cache.** Without a persistent cache Doctrine
  re-parses the XML entity mappings on **every request**. Set a real backend in
  `application.ini`:

  ```ini
  ; per-request only (default; fine for dev)
  resources.doctrine2cache.type = "ArrayCache"
  ; persistent, in-process shared memory (recommended single-host) -- needs ext-apcu
  resources.doctrine2cache.type = "ApcuCache"
  ; shared across hosts/replicas -- needs ext-redis
  ;resources.doctrine2cache.type = "RedisCache"
  ;resources.doctrine2cache.redis.dsn = "redis://127.0.0.1:6379"
  ```

  The Docker image defaults to **`ApcuCache`**. For a single container APCu
  beats Redis (in-process, no socket); reach for Redis only when you run
  multiple replicas that must share a cache. A configured backend whose PHP
  extension is missing degrades to `ArrayCache` instead of fataling.

- **Precompiled Smarty templates.** Smarty compiles each template to PHP on
  first use. The CLI command **`maintenance.cli-precompile-templates`** compiles
  every template up front into the persistent `var/templates_c`; the Docker
  bootstrap runs it at start so the first page render compiles nothing. (The
  compiled output persists across restarts — it is not wiped; Smarty's
  `compile_check` recompiles only what changed.)

- **Server-side pagination.** For large installs the list pages (mailbox, alias,
  domain, log, archive) can page/sort/search **server-side** — the browser
  fetches only the visible page instead of every row. **On by default** (the
  log table is unbounded, so client-side paging there loads the whole history);
  set any to `false` in `application.ini` to revert a given list to client-side:

  ```ini
  defaults.server_side.pagination.enable         = true   ; mailbox + alias
  defaults.server_side.pagination.domain.enable  = true
  defaults.server_side.pagination.log.enable     = true
  defaults.server_side.pagination.archive.enable = true
  ```

  Behind a positive-security WAF, allow the
  DataTables query args (`sEcho`, `iDisplayStart`, `sSearch`, `iSortCol_0`,
  `mDataProp_*`, …) on the `/*/list-data` routes.

## Archiving, quotas & disk deletion

Archive and delete are **queue-driven over the doveadm HTTP API** — no tarballs,
no shell tools, no mail-host checkout. The web panel never touches the mail
filesystem; it writes a `mailbox_task` row and the queue runner does the work on
the Dovecot side:

- **Archive** (keeps the account) — `doveadm backup` copies the store to a
  zstd-compressed maildir under `doveadm.backup.dest` (e.g. `/backups/%d/%u`),
  then the live store is emptied. An `archive` row (status *Archived*) appears on
  the **Archives** tab.
- **Delete** (removes the account) — same backup, then the mailbox + account row
  are removed. The archive row is flagged **autoprune**, so the backup is pruned
  automatically after `queue.autoprune.days` (default 90;
  `queue.autoprune.days = 0` means *instant* — delete takes **no** backup and
  removes the mailbox immediately).

On the **Archives** tab each backup shows when it was archived, whether the
account still exists, and its autoprune state (toggle per-row). From there you
can **restore** it (recreates the mailbox from a stored snapshot — original
password hash included — then `doveadm sync`s the mail back from the backup) or
**delete** the backup (`doveadm fs delete` removes the `/backups` maildir). The
**Maintenance** tab has *Run autoprune now (expired)* and *Delete all autoprune
backups* buttons; a cron can call the same `maintenance.prune-expired` action.

`doveadm fs delete` needs a `fs posix { driver = posix }` filter in the Dovecot
config (the prune removes a backup maildir over the HTTP API rather than sharing
the filesystem with the panel).

### Scheduling the queue runner

The queue is only drained when something invokes `queue.cli-run`. How that gets
scheduled depends on how you run ViMbAdmin:

- **Docker image — nothing to set up.** The image supervises a `queue-runner`
  service (s6) that runs `queue.cli-run` **every 5 minutes**, and fires it once
  on container start. No host cron needed.
- **Bare metal / source — you MUST install a cron.** There is no daemon; add a
  cron calling `queue.cli-run` (every 2 minutes is typical — see form 2 below).

On top of the scheduled runner, two on-demand nudges exist (both *best-effort*,
**not** a substitute for the periodic runner):

- the **`POST /queue/trigger`** HTTP endpoint (Bearer key + source-IP allowlist)
  spawns a one-off background runner — for off-box cron hosts that can't run the
  CLI (form 3 below);
- the in-panel **Run now** button on the Queue page drains it interactively.

Full requirements + an autoprune cron are in [`contrib/cron/`](contrib/cron/).

Concurrency is capped by **`queue.runner.max_concurrent`** (default **1** =
strictly serial). A DB lease (`queue_runner` table) enforces it across CLI, web
and containers, so overlapping cron ticks or trigger-checks never run more than
the configured number of runners at once; a crashed runner's lease is reaped
after a timeout so a slot is never lost.

> The Docker image needs **none** of the forms below — its built-in s6
> `queue-runner` already drains the queue every 5 minutes. These are for
> bare-metal/source installs, or to add an *extra* off-box trigger.

**1. Bare metal / inside the container** — plain PHP CLI, from an
`application.ini` that points at the panel's DB + doveadm HTTP endpoint:

```cron
*/2 * * * *  vmail  php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

If you do run the upstream image without the s6 runner for some reason, the old
host-crontab form still works:

```cron
*/2 * * * *  root  docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

**2. HTTP trigger** — when the cron host can't run the CLI at all. Set
`queue.runner.key` + `queue.runner.allowed_ips` in `application.ini`, then have
any host on the allowlist `POST` the key as a Bearer token:

```cron
*/2 * * * *  root  curl -fsS -X POST -H "Authorization: Bearer <key>" \
    https://mail.example.com/vimbadmin/queue/trigger >/dev/null
```

(Empty `queue.runner.key` disables the HTTP endpoint; the CLI runner and the
in-panel "Run now" button always work.)

A separate, legacy **on-disk purge** path still exists for direct maildir
removal on a host that can see the mail, unrelated to the queue archive/delete
flow above (that backs up via doveadm and prunes from the Archives/Maintenance
tabs). Two pieces: the **web** purge UI is gated by `mailbox_deletion_fs_enabled`
(default **false**); the **CLI** `mailbox.cli-delete-pending` shells out via
`binary.path.rm_rf` over each mailbox's maildir + homedir. In the hardened
Docker image it is effectively inert — the mail lives in the Dovecot container,
not here, so the maildir paths don't exist, and the FPM pool's `open_basedir`
confines PHP to the app's own dirs anyway.

Mailbox **usage** in the panel does *not* need a maildir scan — it is fed live
by Dovecot's quota-clone plugin. See below.

### Live quota usage (Dovecot quota-clone)

There are **two separate quota concerns** — keep them apart:

| | What | Where it lives | Who writes it |
|---|---|---|---|
| **Limit** | the cap per mailbox | `mailbox.quota` (bytes) | ViMbAdmin (you, in the GUI) |
| **Usage** | how full the mailbox is now | `dovecot_quota` table | Dovecot, live |

ViMbAdmin sets the **limit**; Dovecot enforces it and reports back the **usage**.
The panel reads `dovecot_quota` and shows usage (and a % of the limit) in the
mailbox list and per-domain totals.

#### How it used to work vs now

Older ViMbAdmin scanned every maildir from a nightly `mailbox.cli-get-sizes`
cron and stored the result in `mailbox.maildir_size`. That was only as fresh as
the cron, and meant a full `du` walk of every maildir. **This fork drops that
entirely.** Usage now comes straight from Dovecot 2.4's
[quota-clone plugin](https://doc.dovecot.org/2.4.4/core/plugins/quota_clone.html),
which writes each user's current storage + message count into the database on
every change — real-time, no cron, no scan.

#### Why a dedicated `dovecot_quota` table

quota-clone writes with `INSERT .. ON DUPLICATE KEY UPDATE`. Pointed straight at
the `mailbox` table that fails, because `mailbox` has NOT NULL columns with no
default (`password`, `quota`, `local_part`) that the upsert can't supply. So
quota-clone gets its **own** clean table — `dovecot_quota(username, bytes,
messages, updated_at)`, keyed by the full email address (= `mailbox.username`).
ViMbAdmin reads that table directly; it never writes it (Dovecot is the
authority and replaces the row on every change). A mailbox shows `0` until
Dovecot writes its first figure.

The table is created on fresh installs by the entity mapping
(`orm:schema-tool:create`); existing DBs apply the consolidated
[`contrib/migrations/2026-06-fork-schema.sql`](contrib/migrations/2026-06-fork-schema.sql)
(its step 1 creates `dovecot_quota`, seeds it from the old `maildir_size`, and
drops the retired `maildir_size` / `homedir_size` / `size_at` columns).

#### Dovecot config (on the mail host, not the panel)

This is a complete, working setup. **Two plugins, two jobs:**

`quota` = the **enforcement** backend (rejects over-quota mail). The per-user
limit comes from your SQL userdb — ViMbAdmin exposes the mailbox's limit as
`userdb_quota_rule = *:bytes=N`, so this just needs to be enabled:

```
mail_plugins {
  quota = yes
}

quota "User quota" {
  driver = count          # recommended for maildir; index-based, no du
}
quota_full_tempfail = yes # 4xx on backend error instead of bouncing mail
```

`quota_clone` = the **reporting** half that feeds ViMbAdmin's display. Point its
dict at the ViMbAdmin database, writing storage→`bytes` and messages→`messages`
in `dovecot_quota`:

```
mail_plugins {
  quota_clone = yes
}

dict_server {
  dict vimbadmin {
    driver = sql
    sql_driver = mysql
    mysql <db-host> {
      user     = vimbadmin
      password = <password>
      dbname   = vimbadmin
    }
    dict_map priv/quota/storage {
      sql_table      = dovecot_quota
      username_field = username
      value_field bytes {
      }
    }
    dict_map priv/quota/messages {
      sql_table      = dovecot_quota
      username_field = username
      value_field messages {
      }
    }
  }
}

quota_clone {
  dict proxy {
    name = vimbadmin
  }
}
```

> **Don't confuse the two plugins.** `quota` enforces and is authoritative;
> `quota_clone` only mirrors usage for display. The per-mailbox **limit** is
> always the value ViMbAdmin sets (`mailbox.quota`), surfaced to Dovecot via the
> userdb `quota_rule`.

## What it is *not*

Not a mail-server appliance. It manages the user database; it does not
install or configure Postfix or Dovecot, and it does not filter spam (for
that, [Rspamd](https://deb.myguard.nl/2026/05/rspamd-explained-modern-spam-filtering-bayes-neural-rbl/)).
It's a deliberately narrow component, which is exactly why it can be audited
and trusted.

## Layout

```
application/    entities, repositories, plugins, config and Smarty views
src/Kernel/     native HTTP/CLI kernel, controllers, forms, auth and session
library/        framework-free OSS + ViMbAdmin domain helpers
public/         web docroot (native index.php front controller)
bin/            CLI tools (doctrine2-cli.php, vimbtool.php, crons)
contrib/        deploy configs: php-fpm pool, Angie vhost, mail-host crons,
                snuffleupagus/ (the validated SP ruleset), migrations/, theming
application/Entities/  Doctrine entities; schema mapping lives in #[ORM\...] attributes
docs/           extra documentation (mcp-auth.md)
```

A separate, optional **OWASP CRS / ModSecurity plugin** lives at
[vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin) —
payload-signature scanning on top of the vhost, only if you already run
libmodsecurity.

## Credits & licence

Originally written by [Open Solutions](https://www.opensolutions.ie/) on Zend
Framework 1, Doctrine ORM and Smarty. This fork has **removed Zend Framework
entirely** — kernel, routing, forms, config loader, sessions and auth are native
PHP 8 — while keeping Doctrine (ORM 3) and Smarty (5). GPLv3 — same as it always
was. This fork keeps the licence and the gratitude; it just keeps the lights on
too.

- Upstream: <https://github.com/opensolutions/ViMbAdmin>
- This fork: <https://github.com/eilandert/ViMbAdmin>
- Write-up: <https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>
