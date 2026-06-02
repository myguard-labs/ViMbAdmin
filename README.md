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

**Brought into this decade**

- **PHP 8.1 → 8.5** clean. Every implicit-nullable parameter fixed, every
  PHP-8-removed function call replaced.
- **Smarty 4 → 5.** Templating bridge ported to the new API (setters, the
  `nofilter` flag, the `{if}`-can't-call-PHP-functions rule, and the
  delightful clone bug where Smarty 5's BC plugin loader drops every custom
  plugin from a cloned view — which is why your forms used to render blank).
- **Doctrine ORM 2.8 → 2.20** (latest 2.x LTS) + DBAL 3. CLI and query API
  rewritten to match.

**Hardened, in layers**

- **Two-factor authentication (TOTP)** — opt-in per admin, secret encrypted
  at rest (libsodium), QR enrolment, one-time backup codes, and lost-device
  reset from the CLI or `application.ini`. See [Two-factor](#two-factor-authentication).
- **Brute-force protection** — per-source-IP attempt lockout with an IP/CIDR
  allowlist, configured in `application.ini`.
- **CSRF** on every form (per-session token in the base form class) *and* on
  every destructive GET link. Forge a request, get a 403.
- **XSS** auto-escaping on by default in Smarty; genuine HTML output is
  explicitly `nofilter`. Stored `<script>` payloads render as inert text.
- **Constant-time** password comparison; CSPRNG for tokens and salts;
  session-id regeneration on login (no session fixation).
- **[Snuffleupagus](snuffleupagus/vimbadmin-strict.list)** ruleset —
  code-derived, bans every dangerous function the app doesn't use.
- **Hardened PHP-FPM pool + Angie/nginx vhost** with BREACH mitigation (no
  compression of secret-bearing dynamic responses), strict CSP, security
  headers and a rate-limited login. See [`contrib/`](contrib/).
- **OWASP CRS / ModSecurity plugin** (positive security: allow what
  ViMbAdmin uses, block everything else) — separate repo:
  [vimbadmin-crs-plugin](https://github.com/eilandert/vimbadmin-crs-plugin).

**Removed**

- Dead Doctrine 1 code, an unused PDF chain, and four unreferenced "OSS API"
  integration classes — one of which carried a SQL-injection. ~1,600 lines of
  attack surface, gone.

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

PHP 8.1+ with `pdo_mysql`, `mbstring`, `intl`, `gettext`, `dom`, `ctype`.

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

If you terminate TLS at a proxy, make sure the real client IP reaches PHP as
`REMOTE_ADDR` (e.g. Angie `realip`) or every request looks like it comes from
the proxy.

## What it is *not*

Not a mail-server appliance. It manages the user database; it does not
install or configure Postfix or Dovecot, and it does not filter spam (for
that, [Rspamd](https://deb.myguard.nl/2026/05/rspamd-explained-modern-spam-filtering-bayes-neural-rbl/)).
It's a deliberately narrow component, which is exactly why it can be audited
and trusted.

## Layout

```
application/    ZF1 controllers, entities, views (Smarty)
library/        OSS + ViMbAdmin framework, Doctrine, auth
public/         web docroot (index.php front controller)
bin/            CLI tools (doctrine2-cli.php, vimbtool.php, crons)
contrib/        hardened deploy configs: php-fpm pool, Angie vhost, fastcgi
snuffleupagus/  vimbadmin-strict.list (validated SP ruleset)
doctrine2/xml/  Doctrine XML mappings (the schema source of truth)
```

## Credits & licence

Originally written by [Open Solutions](https://www.opensolutions.ie/) on the
Zend Framework, Doctrine ORM and Smarty. GPLv3 — same as it always was. This
fork keeps the licence and the gratitude; it just keeps the lights on too.

- Upstream: <https://github.com/opensolutions/ViMbAdmin>
- This fork: <https://github.com/eilandert/ViMbAdmin>
- Write-up: <https://deb.myguard.nl/2026/06/vimbadmin-postfix-dovecot-mailbox-admin-panel/>
