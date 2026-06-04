# ViMbAdmin — modernised fork

> 📖 **Full write-up, history & guided tour:**
> **<https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>**

*Virtual Mailbox Administration that runs on a PHP version released this decade.*

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)]()
[![Framework](https://img.shields.io/badge/ZF1--future%20%C2%B7%20Doctrine%202.20%20%C2%B7%20Smarty%205-informational)]()

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

- **PHP 8.1 → 8.5** clean. Every implicit-nullable parameter fixed, every
  PHP-8-removed function call replaced.
- **Smarty 4 → 5.** Templating bridge ported to the new API (setters, the
  `nofilter` flag, the `{if}`-can't-call-PHP-functions rule, and the
  delightful clone bug where Smarty 5's BC plugin loader drops every custom
  plugin from a cloned view — which is why your forms used to render blank).
- **Doctrine ORM 2.8 → 2.20** (latest 2.x LTS) + DBAL 3. CLI and query API
  rewritten to match.
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
  (`hash_equals`). Mailbox passwords hashed in a Dovecot-accepted scheme
  (`doveadm pw`).
- **Session-fixation defence** — the session id is regenerated on every
  successful login (and again after the 2FA step).
- **Brute-force protection** — per-source-IP attempt counter with lockout
  window; a fully successful login clears it. IP/CIDR **allowlist** and all
  thresholds configurable in `application.ini` (`[bruteforce]`). 429 when locked.
- **CSRF** — a per-session token on **every form** (auto-validated by
  `Zend_Form::isValid()`) *and* on every destructive GET link
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

### Runtime (Snuffleupagus)

- A **code-derived [`vimbadmin-strict.list`](contrib/snuffleupagus/vimbadmin-strict.list)**
  ruleset: bans every dangerous function the app doesn't use, allow-scopes the
  `exec` it does, blocks RFI/LFI wrappers, eval/`base64_decode` webshell pipes,
  mail-header injection, env hijacking, world-writable chmod, writing
  PHP-loadable files, and insecure cURL/SSRF. Logs/encrypts cookies as available.
  A unique `secret_key` must be set per deployment.

### Edge / deployment (`contrib/`)

- **Hardened PHP-FPM pool** (`contrib/php-fpm/vimbadmin.conf`) —
  `open_basedir`, empty native `disable_functions` (Snuffleupagus owns the
  policy), strict session-cookie flags, `security.limit_extensions=.php`,
  resource limits.
- **Hardened Angie/nginx vhost** (`contrib/angie/vimbadmin.conf`) — a **native
  positive-security gate**: only known HTTP methods, the real route map
  (controllers + ZF1 param URLs), and the app's known argument names reach PHP;
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

- On current LTS lines (doctrine/orm 2.20, dbal 3, symfony/cache 6.4/7,
  smarty 5, zf1-future 1.25, robthree/twofactorauth 3, bacon/bacon-qr-code 3);
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
`intl`, `gettext`, `dom`, `ctype`, `iconv` and `sodium` (the 2FA secrets are
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

Pulling a newer version of the fork may add columns, indexes or tables. Bring
your database in line in one of two ways:

```sh
# A) let Doctrine reconcile the DB with the entity mappings (shows the SQL):
./bin/doctrine2-cli.php orm:schema-tool:update --dump-sql      # preview
./bin/doctrine2-cli.php orm:schema-tool:update --force         # apply
```

```sh
# B) apply a specific hand-written migration from contrib/migrations/
mysql -u<user> -p <database> < contrib/migrations/2026-06-mailbox-username-unique.sql
```

`contrib/migrations/` holds idempotent, targeted SQL for changes that warrant a
note (each file documents *why* and any pre-checks). The current one adds the
**`UNIQUE` index on `mailbox.username`** — Postfix and Dovecot query that column
on every delivery and login, so without the index they full-scan the mailbox
table. Fresh installs (`orm:schema-tool:create`) already include it; only DBs
created from the older SQL dumps need the migration. Always back up first; the
index is `UNIQUE`, so dedupe any duplicate usernames before applying:

```sh
SELECT username, COUNT(*) c FROM mailbox GROUP BY username HAVING c > 1;
```

There is also a **`dovecot_quota` table** migration
([`2026-06-quota-clone-table.sql`](contrib/migrations/2026-06-quota-clone-table.sql)).
This fork has retired the old nightly maildir-scan (`mailbox.cli-get-sizes`) and
gets **live** mailbox usage straight from Dovecot's quota-clone plugin instead.
The migration creates the `dovecot_quota` table, seeds it from the old
`maildir_size` values, then drops the retired `maildir_size` / `homedir_size` /
`size_at` columns. See
[Live quota usage (Dovecot quota-clone)](#live-quota-usage-dovecot-quota-clone)
for the Dovecot config.

## Day-to-day

In order, because the order matters:

1. **Domains → Add.** The `@example.com`. Set per-domain limits and quotas.
   (Postfix still has to be configured to read `virtual_mailbox_domains` from
   the DB — ViMbAdmin maintains the data, it can't make Postfix care.)
2. **Mailboxes → Add.** Local part, password, quota. The password is hashed
   in a scheme Dovecot accepts (it can shell out to `doveadm pw`).
3. **Aliases → Add.** Address → comma-separated `goto` list. This is your
   `postmaster@`, your role addresses, your distribution lists.

Every action is logged, validated, and CSRF-protected.

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

The panel is light, but two things keep it snappy:

- **OPcache** — caches compiled PHP bytecode. The Docker image tunes it for an
  immutable codebase (`opcache.validate_timestamps=0`, no stat() per include).
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

## Archiving, quotas & disk deletion

Two features touch the **mail filesystem** (the maildirs), so they run shell
tools (`tar`/`bzip2`/`rm`) and must execute **where the mail lives — the
Dovecot host — not the web panel**:

- **Archive** — flagging a mailbox in the UI queues a DB row and purges it from
  the live tables; the actual tarball is produced by the
  `archive.cli-*-pendings` cron.
- **On-disk deletion** (`mailbox_deletion_fs_enabled`, default off).

Example mail-host script + crontab, with their requirements documented inline,
are in [`contrib/cron/`](contrib/cron/) (`vimbadmin-archive.sh`,
`crontab.example`). They need a checkout + PHP CLI + an
`application.ini` pointing at the **same database** as the panel, plus read
access to the maildirs. Without them, the Archive button just queues rows that
are never tarred — by design; ignore it if you don't archive.

Mailbox **usage** in the panel is *not* in this list — it no longer needs a
maildir scan. See below.

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
(`orm:schema-tool:create`); existing DBs apply
[`contrib/migrations/2026-06-quota-clone-table.sql`](contrib/migrations/2026-06-quota-clone-table.sql)
(it creates `dovecot_quota`, seeds it from the old `maildir_size`, and drops the
retired `maildir_size` / `homedir_size` / `size_at` columns).

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
application/    ZF1 controllers (incl. McpController), entities, views (Smarty)
library/        OSS + ViMbAdmin framework (Doctrine, auth, Net, Mcp/)
public/         web docroot (index.php front controller)
bin/            CLI tools (doctrine2-cli.php, vimbtool.php, crons)
contrib/        deploy configs: php-fpm pool, Angie vhost, mail-host crons,
                snuffleupagus/ (the validated SP ruleset), migrations/, theming
doctrine2/xml/  Doctrine XML mappings (the schema source of truth)
docs/           extra documentation (mcp-auth.md)
```

A separate, optional **OWASP CRS / ModSecurity plugin** lives at
[vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin) —
payload-signature scanning on top of the vhost, only if you already run
libmodsecurity.

## Credits & licence

Originally written by [Open Solutions](https://www.opensolutions.ie/) on the
Zend Framework, Doctrine ORM and Smarty. GPLv3 — same as it always was. This
fork keeps the licence and the gratitude; it just keeps the lights on too.

- Upstream: <https://github.com/opensolutions/ViMbAdmin>
- This fork: <https://github.com/eilandert/ViMbAdmin>
- Write-up: <https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>
