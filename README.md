# ViMbAdmin — modernised fork

> **Live demo:** **<https://vimbadmin.myguard.nl>** — demo account is read-only-ish
> (password + 2FA changes locked, outgoing mail no-op'd); everything else is the real panel.
>
> **Full write-up, history & guided tour:**
> [ViMbAdmin: The Postfix + Dovecot Mailbox Admin Panel (Modernised for PHP 8.5)](https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/)

*Virtual Mailbox Administration that runs on a PHP version released this decade.*

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)]()
[![Stack](https://img.shields.io/badge/Native%20kernel%20%C2%B7%20Doctrine%20ORM%203%20%C2%B7%20Smarty%205-informational)]()

**ViMbAdmin** (*vim-be-admin*) is a web panel for managing the virtual domains,
mailboxes and aliases in a **Postfix + Dovecot** mail server backed by a SQL
database — so you stop editing production mail with raw `INSERT` statements.

This is the **[eilandert](https://github.com/eilandert) fork**. Upstream
([opensolutions/ViMbAdmin](https://github.com/opensolutions/ViMbAdmin)) stopped
getting commits years ago and no longer runs cleanly on modern PHP. We needed it
on PHP 8.5 on a hardened stack, so we fixed it.

---

## What's different in this fork

It runs, and it's hard to break into.

**No helper scripts, no Dovecot-side cron — mailbox ops are native**

- **Repair / optimize / archive / delete** run against Dovecot's built-in
  **doveadm HTTP API** (`force-resync`, `index`, `purge`, `backup`,
  `mailbox delete`) — no shared mail filesystem, no `rm -rf`, no tar scripts.
  Each request is **queued** in a dedicated table and drained by a single
  throttled runner, so a bulk action can't hammer Dovecot. A **Queue** tab
  shows progress and runs it on demand; an optional key+IP-gated endpoint lets
  a remote cron kick it.
- **Passwords** are hashed **natively in PHP** (`BLF-CRYPT`, `SHA512-CRYPT`,
  `SHA256-CRYPT`) — the `doveadm pw` binary and old `dovecotpasswd.php` are gone.
- **Quota usage** comes live from Dovecot's quota-clone `dovecot_quota` table —
  the old maildir-scan accounting cron is retired.

Net: the Dovecot container ships **zero** ViMbAdmin scripts/cron; the only
optional cron left is the panel's own queue-runner.

**Brought into this decade**

- **ZF1 is completely gone.** The HTTP/CLI kernel, routing, forms, config
  loader, sessions and auth are all native PHP 8 now — not a single `Zend_*`
  class in the tree or dependency graph; `composer.json` no longer requires it.
  The one visible ZF1 leftover is the `application.ini` config *format*
  (`[child : parent]` inheritance, `resources.*` keys) — read by a small native
  loader now, a candidate to replace later. See
  [Upgrading & schema migrations](#upgrading--schema-migrations).
- **PHP 8.1 → 8.5** clean. Every implicit-nullable parameter and PHP-8-removed
  function call fixed.
- **Smarty 4 → 5.** Templating bridge ported to the new API (setters, the
  `nofilter` flag, `{if}`-can't-call-PHP-functions, and the clone bug where
  Smarty 5's BC plugin loader drops every custom plugin from a cloned view —
  why forms used to render blank).
- **Doctrine ORM 2.8 → 3.x** (orm 3.6 / dbal 4) + persistence 4. CLI and query
  API rewritten; the ORM 3 jump needed native lazy-loading proxies
  (`enableNativeLazyObjects`), PSR-6 caches and an `object`-type shim.
- **Cache layer rebuilt on Symfony Cache.** `doctrine/cache` 2.x dropped the old
  concrete `*Cache` providers, so the metadata/query cache wraps a Symfony PSR-6
  pool (`ArrayAdapter` / `ApcuAdapter` / `RedisAdapter`) in `DoctrineProvider` —
  backend picked in `application.ini`. The Docker image ships **APCu** + tuned
  **OPcache**.

---

## Security

By layer. Stock upstream had **none** of the application-layer items below.

### Authentication

- **Two-factor (TOTP).** Opt-in per admin at `/admin/two-factor`.
  - Secret **encrypted at rest** with libsodium (`crypto_secretbox`), keyed off
    `securitysalt` — a DB read alone yields no usable secrets.
  - QR + manual entry; **one-time backup codes** (bcrypt-hashed, single-use).
  - **Replay protection** — a TOTP time-slice is accepted once.
  - **Super-admin management**: provision, regenerate, disable, **force
    enrolment at next login**.
  - **Lost-device recovery** without DB surgery: backup codes, a CLI reset
    (`vimbtool.php -a admin.cli-reset-totp --username=…|--all`), or
    `application.ini` (`twofactor.force_disable`).
- **Passwords.** Admin passwords bcrypt-hashed, compared in **constant time**
  (`hash_equals`). Mailbox passwords hashed **natively in PHP** in a
  Dovecot-accepted scheme — no `doveadm pw` shell-out.
- **Session-fixation defence** — session id regenerated on every successful
  login (and again after the 2FA step).
- **Brute-force protection** — per-source-IP attempt counter with lockout
  window; a fully successful login clears it. IP/CIDR **allowlist** and all
  thresholds in `application.ini` (`[bruteforce]`). 429 when locked.
- **CSRF** — per-session token validated on **every form** and every destructive
  GET link (purge/delete/cancel/restore); forged request → 403.

### Output / input handling

- **XSS auto-escaping** — Smarty `setEscapeHtml(true)` globally; only
  deliberately-HTML output is `nofilter`. Stored `<script>` renders inert.
- **SQL injection** — Doctrine ORM with parameterised queries; the four
  unreferenced raw-SQL "OSS API" classes (one with an actual injection) deleted.
- **Command injection** — every shell-out (`doveadm`, archive tar/bzip2/du) is
  `escapeshellarg`'d.
- **Deserialisation** — `unserialize()` of archive blobs restricted with
  `['allowed_classes' => false]`.
- **CSPRNG** — tokens, salts, backup codes use `random_int()` (replaced
  `str_shuffle`/`mt_rand`).
- **Real client IP** — spoof-resistant trusted-proxy resolver
  (`trustedproxy.mode`, default `auto`) feeds the brute-force limiter and MCP IP
  allowlist the actual client. See
  [Real client IP behind a proxy](#real-client-ip-behind-a-proxy).

### MCP adapter (optional, off by default)

- **Bearer-token JSON-RPC API** at `/mcp`: SHA-256-hashed tokens (raw shown
  once), scoped read/write, optional per-token IP/CIDR allowlist, expiry +
  revoke, per-token **rate limit on destructive operations**. Edge
  IP-allowlisted in the vhost; bearer-only. See [MCP adapter](#mcp-adapter) and
  [docs/mcp-auth.md](docs/mcp-auth.md).

### Contrib

Everything under [`contrib/`](contrib/) hardens the panel around the edges;
upstream shipped none of it. All of it ships pre-wired in the
[vimbadmin Docker image](https://github.com/eilandert/dockerized/tree/master/src/vimbadmin).

- **Runtime Snuffleupagus ruleset** — code-derived
  [`vimbadmin-strict.list`](contrib/snuffleupagus/vimbadmin-strict.list): bans
  every dangerous function the app doesn't use, allow-scopes the `exec` it does,
  blocks RFI/LFI wrappers, eval/`base64_decode` webshell pipes, mail-header
  injection, env hijacking, world-writable chmod, writing PHP-loadable files,
  insecure cURL/SSRF. A unique `secret_key` must be set per deployment.
- **Hardened PHP-FPM pool** (`contrib/php-fpm/vimbadmin.conf`) — `open_basedir`,
  empty native `disable_functions` (Snuffleupagus owns policy), strict
  session-cookie flags, `security.limit_extensions=.php`, resource limits.
- **Hardened Angie/nginx vhost** (`contrib/angie/vimbadmin.conf`) — a **native
  positive-security gate**: only known HTTP methods, the real route map, and the
  app's known argument names reach PHP; scanner/empty user-agents dropped. Plus
  TLS, strict **CSP** + security headers, **rate-limited login**,
  internal-path/dotfile denies, **BREACH mitigation**.
- **OWASP CRS / ModSecurity plugin** *(optional)* — payload signature scanning
  on top of the vhost, where you already run libmodsecurity:
  [vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin).
- **Docker image** — read-only rootfs, root-owned read-only codebase,
  per-deployment secrets at first run, all caps dropped bar the few needed,
  docs/repos/setuid stripped. See the
  [image README](https://github.com/eilandert/dockerized/tree/master/src/vimbadmin).

### Attack surface removed

- Dead Doctrine 1 code, an unused PDF chain, the
  Yubico/Invoice/GeoIP/Csv/Phone/Acl/Curl/Crypt_OpenSSL utilities, and four
  unreferenced "OSS API" classes (one carrying SQLi). ~1,600+ lines gone.
- Fixed latent bugs surfaced on the way: AJAX toggle guards that printed "ko"
  but toggled anyway (privilege bug), and `$this->getLogger->` property-access
  fatals on the archive paths.

### Dependencies

Current release lines (doctrine/orm 3.6, DBAL 4, symfony/cache 6.4/7/8, Smarty
5, robthree/twofactorauth 3, bacon/bacon-qr-code 3); kernel/routing/forms/
sessions/CLI native and ZF1-free; `composer audit` reports **no advisories**.

---

## Quick start (Docker)

You bring a MariaDB/MySQL database; the image bundles the app, PHP-FPM and the
web server.

```yaml
# docker-compose.yml  --  change the passwords. "change-me" is not a password.
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

Put it behind TLS in production — ideally behind the hardened vhost and
ModSecurity plugin shipped in this repo.

## Quick start (from source)

PHP **8.4.1+** with `pdo_mysql`, `mbstring`, `intl`, `gettext`, `gd`, `dom`,
`ctype`, `iconv` and `sodium` (2FA secrets are libsodium-encrypted). `apcu`
optional but recommended (see [Performance](#performance)).

```sh
git clone https://github.com/eilandert/ViMbAdmin.git
cd ViMbAdmin
composer install --no-dev

cp application/configs/application.ini.dist application/configs/application.ini
# edit application.ini: point resources.doctrine2.connection.options.* at your DB

# create the schema (modernised CLI; the old one used a dead API)
./bin/doctrine-cli.php orm:schema-tool:create
```

Point your web server's docroot at `public/`, wire PHP-FPM to it, browse to the
site.

## First run

ViMbAdmin notices it has no admins and sends you to a setup page. Do this
immediately, on a trusted network.

1. It generates a **security salt** — keep it.
2. Create the **super-admin**. The **username is an email address**
   (`you@yourdomain.com`), *not* the word "admin" — the field is labelled
   "Email".
3. Pick a real password. It's bcrypt-hashed and constant-time-compared.

## Upgrading & schema migrations

> **Coming from an old ViMbAdmin (3.x or earlier fork build)?** Follow the
> step-by-step **[migration plan in docs/MIGRATION.md](docs/MIGRATION.md)** —
> minimum requirements, DB backup + schema update, `application.ini` re-seed,
> retiring legacy filesystem scripts, wiring Dovecot for the REST-API model.

### Config (`application.ini`) migration

A newer version may add config keys. Your live `application.ini` is **never
overwritten** (it holds DB credentials and salt), so reconcile it against the
shipped template after a `git pull`:

```sh
# see which keys the template gained that your live file lacks
diff <(grep -oE '^[a-z][a-zA-Z0-9_.\[\]]*' application/configs/application.ini      | sort -u) \
     <(grep -oE '^[a-z][a-zA-Z0-9_.\[\]]*' application/configs/application.ini.dist | sort -u)
```

Every key has a sane default in code, so a missing key just means "feature off /
default value" — nothing breaks. Copy across only the new keys you want to
change. The Docker image generates a fresh `application.ini` from the `.dist`
template at first run (injecting DB env + a per-deployment salt) and leaves it
alone thereafter, so the same "diff against `.dist`" rule applies.

> **`application.ini`'s format may change.** Its `[child : parent]` inheritance
> and `resources.*` namespace are a ZF1 convention; ZF1 is gone (a small native
> [`IniConfig`](src/Kernel/Config/IniConfig.php) reads the file now), so the
> on-disk *shape* is a candidate to replace later. Keys and meanings would be
> preserved.

(The `dovecot_quota` part of that migration retires the old nightly
maildir-scan for live usage — see
[Live quota usage](#live-quota-usage-dovecot-quota-clone).)

## Day-to-day

In order, because order matters:

1. **Domains → Add.** Set per-domain limits and quotas. (Postfix still has to be
   configured to read `virtual_mailbox_domains` from the DB — ViMbAdmin
   maintains the data, it can't make Postfix care.)
2. **Mailboxes → Add.** Local part, password, quota. Password hashed natively in
   PHP in a scheme Dovecot accepts — no external `doveadm pw`.
3. **Aliases → Add.** Address → comma-separated `goto` list: your `postmaster@`,
   role addresses, distribution lists.

Every action is logged, validated, and CSRF-protected.

## CLI reference (`bin/vimbtool.php`)

All maintenance/automation tasks run through one entry point:

```bash
./bin/vimbtool.php -a <controller.action> [options]
# in the Docker image:
docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a <controller.action> [options]
```

`bin/vimbtool.php -a help` (or `--help`) prints the action list; `--copyright`
prints the banner. The CLI is framework-free and never starts a session or
authenticates — every command works against the Doctrine EM directly.

**Global flags** (accepted by every action):

| Flag | Meaning |
|---|---|
| `-v`, `--verbose` | extra output (e.g. schema-update prints the SQL + DB version) |
| `-d`, `--debug` | debug output |
| `-h`, `--help` | usage + action list |
| `-c`, `--copyright` | print the banner and exit |

**Actions:**

| Action | What it does | Options |
|---|---|---|
| `queue.cli-run` | Drain the **mailbox-task queue** — claims up to `queue.runner.max_per_run` PENDING tasks (repair / optimize / archive / delete) and runs them against Dovecot over the doveadm HTTP API. Periodic runner (image fires it on start + every 5 min; also on login / Maintenance / MCP). Concurrency capped by `queue.runner.max_concurrent` DB lease. | — |
| `maintenance.cli-schema-update` | Apply pending **Doctrine schema migrations** (changes table structure). Same code as the in-panel **Maintenance → Update schema** button. Run on deploy/upgrade; the Docker image runs it on every start. | `--verbose` → also print the SQL + DB version |
| `maintenance.cli-precompile-templates` | Compile every **Smarty template** ahead of time into persistent `var/templates_c`, so the first web request pays no compile cost. Safe to re-run; image runs it on start. | — |
| `admin.cli-reset-totp` | **Disable two-factor** for a locked-out admin. | `--username=<email>` **or** `--all` |
| `mcp.cli-token-generate` | Mint a new **MCP API token**. Raw token printed **once** (only its SHA-256 stored). | `--name=` (required, free/revoked), `--scope=` (default `read`), `--ip=`, `--domains=`, `--days=` (default no expiry) |
| `mcp.cli-token-list` | List MCP API tokens (name, scope, ip, domains, expiry, revoked). | — |
| `mcp.cli-token-revoke` | Revoke an MCP API token (row kept for audit). | `--id=<id>` **or** `--name=<name>` |

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

Opt-in, per admin, at **`/admin/two-factor`**:

1. Scan the QR with an authenticator app (Aegis, Google Authenticator,
   1Password, …) or type the secret by hand.
2. Enter the 6-digit code to confirm and enable.
3. **Save the one-time backup codes.** Shown once; each works once.

After that, login is password → 6-digit code. The TOTP secret is stored
encrypted (libsodium, keyed off `securitysalt`); a DB read alone doesn't yield
usable secrets.

**Lost your second factor?** Two escape hatches, no DB surgery:

```sh
# CLI (immediate):
./bin/vimbtool.php -a admin.cli-reset-totp --username=admin@example.com
./bin/vimbtool.php -a admin.cli-reset-totp --all

# or in application.ini (applied at that admin's next login):
twofactor.force_disable = "admin@example.com"     ; or "*" for everyone
```

## Brute-force protection

On by default. Counts failed logins per source IP and locks the source out once
it crosses the threshold; a fully successful login (password + 2FA) clears the
counter.

### Real client IP behind a proxy

The brute-force limiter (and MCP per-token IP allowlist) need the **real** client
IP, not your reverse proxy's.

## MCP adapter

Optional **JSON-RPC API at `/mcp`** so an agent can read and manage the mailbox
database. **Off by default** (`mcp.enabled = 1`). Guarded in depth: edge IP
allowlist, a **bearer token** (only its SHA-256 stored, scoped + revocable +
expirable), per-token IP/CIDR allowlist, per-token **rate limit on destructive
calls**. Read methods (`domains.list`, `mailboxes.list`, …) and write methods
(`mailbox.create`, `mailbox.archive`, …) are scope-gated. Manage tokens:

```sh
./bin/vimbtool.php -a mcp.cli-token-generate --name=agent1 --scope="read"
./bin/vimbtool.php -a mcp.cli-token-list
./bin/vimbtool.php -a mcp.cli-token-revoke --name=agent1
```

Full method list, auth model and examples: **[docs/mcp-auth.md](docs/mcp-auth.md)**.

## Performance

Most caches warm at container start, so the first request after a (re)start
isn't cold:

- **OPcache + preload.** OPcache caches compiled bytecode; the Docker image tunes
  it for an immutable codebase (`opcache.validate_timestamps=0`) and sizes it to
  the measured footprint. `opcache.preload` runs
  [`preload.php`](preload.php) in the PHP-FPM master at startup, compiling the
  whole app + vendor tree (plus precompiled templates) into shared memory, so
  workers never compile on the first request. (A few `Can't preload unlinked
  class …` notices for Doctrine console/annotation classes are expected and
  harmless.)
- **Doctrine metadata/query cache.** Without a persistent cache Doctrine
  re-parses the XML entity mappings on **every request**.

  Docker defaults to **`ApcuCache`**. For a single container APCu beats Redis
  (in-process, no socket); reach for Redis only across multiple replicas that
  must share a cache. A configured backend whose extension is missing degrades
  to `ArrayCache` instead of fataling.

- **Precompiled Smarty templates.** `maintenance.cli-precompile-templates`
  compiles every template up front into persistent `var/templates_c`; the Docker
  bootstrap runs it at start so the first render compiles nothing. (Output
  persists across restarts; `compile_check` recompiles only what changed.)

- **Server-side pagination.** For large installs the list pages (mailbox, alias,
  domain, log, archive) page/sort/search **server-side** — the browser fetches
  only the visible page. **On by default**


## Archiving, quotas & disk deletion

Archive and delete are **queue-driven over the doveadm HTTP API** — no tarballs,
no shell tools, no mail-host checkout. The web panel never touches the mail
filesystem; it writes a `mailbox_task` row and the queue runner does the work on
the Dovecot side:

- **Archive** (keeps the account) — `doveadm backup` copies the store to a
  maildir under `doveadm.backup.dest` (e.g. `/backups/%d/%u`; compressed if
  Dovecot enables `mail_compress`), then the live store is emptied. An `archive`
  row (status *Archived*) appears on the **Archives** tab.
- **Delete** (removes the account) — same backup, then mailbox + account row
  removed. The archive row is flagged **autoprune**, pruned automatically after
  `queue.autoprune.days` (default 90; `0` = *instant* — delete takes **no**
  backup and removes the mailbox immediately).

On the **Archives** tab each backup shows when it was archived, whether the
account still exists, and its autoprune state (toggle per-row). From there you
can **restore** it (recreates the mailbox from a stored snapshot — original
password hash included — then `doveadm sync`s the mail back) or **delete** the
backup (`doveadm fs delete` removes the `/backups` maildir). The **Maintenance**
tab has *Run autoprune now (expired)* and *Delete all autoprune backups*
buttons; a cron can call the same `maintenance.prune-expired` action.

`doveadm fs delete` needs a `fs posix { driver = posix }` filter in the Dovecot
config (the prune removes a backup maildir over the HTTP API rather than sharing
the filesystem with the panel).

### Scheduling the queue runner

The queue is only drained when something invokes `queue.cli-run`:

- **Docker image — nothing to set up.** A supervised `queue-runner` service (s6)
  runs `queue.cli-run` **every 5 minutes** and once on container start. No host
  cron needed.
- **Bare metal / source — you MUST install a cron.** No daemon; add a cron
  calling `queue.cli-run` (every 2 minutes is typical — form 1 below).

On top of the scheduled runner, two on-demand nudges exist (both *best-effort*,
**not** a substitute for the periodic runner):

- the **`POST /queue/trigger`** HTTP endpoint (Bearer key + source-IP allowlist)
  spawns a one-off background runner — for off-box cron hosts that can't run the
  CLI (form 2 below);
- the in-panel **Run now** button on the Queue page drains it interactively.

Concurrency is capped by **`queue.runner.max_concurrent`** (default **1** =
strictly serial). A DB lease (`queue_runner` table) enforces it across CLI, web
and containers; a crashed runner's lease is reaped after a timeout so a slot is
never lost.

The forms below are for **bare-metal/source** installs or an *extra* off-box
trigger:

**1. Bare metal / inside the container** — plain PHP CLI:

```cron
*/2 * * * *  vmail  php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

**2. HTTP trigger** — when the cron host can't run the CLI. Set
`queue.runner.key` + `queue.runner.allowed_ips` in `application.ini`, then have
any allowlisted host `POST` the key as a Bearer token:

```cron
*/2 * * * *  root  curl -fsS -X POST -H "Authorization: Bearer <key>" \
    https://mail.example.com/vimbadmin/queue/trigger >/dev/null
```

(Empty `queue.runner.key` disables the HTTP endpoint; the CLI runner and
in-panel "Run now" always work.)

There is no direct-filesystem mailbox deletion: ViMbAdmin never touches the
maildir. Real removal is a doveadm queue `TYPE_DELETE` task over the HTTP API
(backup + `mailbox delete`); the web purge only drops DB rows. The old
`mailbox.cli-delete-pending` CLI + `binary.path.rm_rf` shell-out and
`mailbox_deletion_fs_enabled` checkbox were removed — they required a shared
maildir filesystem this fork no longer has.

Mailbox **usage** does *not* need a maildir scan — it is fed live by Dovecot's
quota-clone plugin (below).

### Live quota usage (Dovecot quota-clone)

**Two separate quota concerns** — keep them apart:

| | What | Where it lives | Who writes it |
|---|---|---|---|
| **Limit** | the cap per mailbox | `mailbox.quota` (bytes) | ViMbAdmin (you, in the GUI) |
| **Usage** | how full the mailbox is now | `dovecot_quota` table | Dovecot, live |

ViMbAdmin sets the **limit**; Dovecot enforces it and reports back the
**usage**. The panel reads `dovecot_quota` and shows usage (and a % of the
limit) in the mailbox list and per-domain totals.

#### How it used to work vs now

Older ViMbAdmin scanned every maildir from a nightly `mailbox.cli-get-sizes`
cron into `mailbox.maildir_size` — only as fresh as the cron, and a full `du`
walk. **This fork drops that entirely.** Usage now comes straight from Dovecot
2.4's [quota-clone plugin](https://doc.dovecot.org/2.4.4/core/plugins/quota_clone.html),
which writes each user's current storage + message count into the database on
every change — real-time, no cron, no scan.

#### Why a dedicated `dovecot_quota` table

quota-clone writes with `INSERT .. ON DUPLICATE KEY UPDATE`. Pointed at the
`mailbox` table that fails — `mailbox` has NOT NULL columns with no default
(`password`, `quota`, `local_part`) the upsert can't supply. So quota-clone gets
its **own** clean table — `dovecot_quota(username, bytes, messages,
updated_at)`, keyed by the full email address (= `mailbox.username`). ViMbAdmin
only reads it; Dovecot is the authority and replaces the row on every change. A
mailbox shows `0` until Dovecot writes its first figure.

The table is created on fresh installs by the entity mapping
(`orm:schema-tool:create`); existing DBs apply the consolidated
[`contrib/migrations/2026-06-fork-schema.sql`](contrib/migrations/2026-06-fork-schema.sql)
(step 1 creates `dovecot_quota`, seeds it from the old `maildir_size`, and drops
the retired `maildir_size` / `homedir_size` / `size_at` columns).

## Layout

```
application/    entities, repositories, plugins, config and Smarty views
src/Kernel/     native HTTP/CLI kernel, controllers, forms, auth and session
library/        framework-free OSS + ViMbAdmin domain helpers
public/         web docroot (native index.php front controller)
bin/            CLI tools (doctrine-cli.php, vimbtool.php, crons)
contrib/        deploy configs: php-fpm pool, Angie vhost, mail-host crons,
                snuffleupagus/ (the validated SP ruleset), migrations/, theming
application/Entities/  Doctrine entities; schema mapping in #[ORM\...] attributes
docs/           extra documentation (mcp-auth.md)
```

A separate, optional **OWASP CRS / ModSecurity plugin** lives at
[vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin) —
payload-signature scanning on top of the vhost, only if you already run
libmodsecurity.

## Credits & licence

Originally written by [Open Solutions](https://www.opensolutions.ie/) on Zend
Framework 1, Doctrine ORM and Smarty. This fork **removed Zend Framework
entirely** — kernel, routing, forms, config loader, sessions and auth are native
PHP 8 — while keeping Doctrine (ORM 3) and Smarty (5). GPLv3, same as always.

- Upstream: <https://github.com/opensolutions/ViMbAdmin>
- This fork: <https://github.com/eilandert/ViMbAdmin>
- Write-up: <https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>
```
