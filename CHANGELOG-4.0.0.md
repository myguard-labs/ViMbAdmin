# ViMbAdmin 4.0.0

_Released 2026-06-02 — eilandert fork ([github.com/eilandert/ViMbAdmin](https://github.com/eilandert/ViMbAdmin))_

First tagged release of the modernised fork. PHP 8.5, Doctrine ORM 3, a full security pass, an optional MCP adapter and 2FA. **40 commits since 3.4.1.**

## Minimum requirements

- **PHP 8.4.1+** (runs clean on 8.4 / 8.5).
- PHP extensions: `ctype`, `dom`, `gd`, `gettext`, `iconv`, `intl`, `json`, `mbstring`, `pdo`, `pdo_mysql`, `sodium` (2FA secrets are libsodium-encrypted). Optional: `apcu` (cache), `redis` (multi-replica cache).
- **MariaDB / MySQL** database.
- Doctrine ORM 3.3+, Smarty 5.0+ (pulled by Composer).

> **Upgrading:** schema migrates itself — run `maintenance.cli-schema-update` (the Docker image does this at container start) and apply `contrib/migrations/`. Note the new `UNIQUE` index on `mailbox.username`. See the README *Upgrading & schema migrations* section.

## Breaking changes

- **Doctrine ORM 3.** Entity mapping moved XML → PHP 8 attributes, all properties typed, `useResultCache()` → `enableResultCache()`, mapped collections initialised in constructors.
- **Smarty 4.3 → 5.x**; Composer dependencies updated.
- `application.ini` flattened to a section-less base; dead/ZF1 keys stripped; ini defaults flattened.
- `bin/doctrine2-cli.php` renamed to `bin/doctrine-cli.php`.
- `skipVersionCheck=1` is now the default (the upstream version endpoint is dead).
- PHP 8.5 deprecation fixes; removed dead `OSS_` classes and `& ~E_STRICT`.

## Security

- **CSRF protection** on every form and destructive link + Smarty HTML auto-escaping (XSS).
- **Two-factor auth (TOTP)** with brute-force login protection; super-admin can provision/QR/regenerate/force 2FA for others; TOTP replay guard.
- **Snuffleupagus ruleset** + native positive-security gate in the Angie vhost (rejects bare-`@` goto wildcard; `noControlChars` widened to C1 / U+2028 / bad UTF-8).
- Hardened deploy configs and additional PHP hardening.
- Input validation (non-negative quota); fixed stale-metadata phantom schema; `AccessPermissions` persist fix.
- **Dovecot owns storage:** dropped mailbox uid/gid/homedir/maildir, killed all shell-out.
- **Real client IP behind a proxy** via `ViMbAdmin_Net` + `trustedproxy.mode` (default `auto`), feeding the brute-force limiter and MCP IP checks.

## MCP adapter (optional, off by default)

- Bearer-token + IP-allowlist JSON-RPC endpoint at `/mcp`.
- Write abilities with per-token destructive rate-limit.
- Token-generate can reuse a name whose token was revoked.
- `/mcp` re-includes `fastcgi_params` and forces `SCRIPT_NAME=/index.php`.

## Schema

- New `UNIQUE` index on `mailbox.username` (+ migration).
- Mailbox create reuses an existing auto-alias instead of duplicating it.

## Performance & cache

- Dropped Memcached, added **Redis** (`RedisCache`) with graceful fallback.
- Doctrine cache defaults to `auto` (APCu when available, else Array).
- Documented OPcache/APCu + Symfony cache.

## UI & theming

- Skin-aware asset loading + sample dark skin and guide; dark-skin contrast fixes.
- 2FA enrol pages: centred QR, secret and form.
- New `footer.hide` toggle to hide the branding block.

## Deploy / contrib

- Angie: dropped login `limit_req` on `/index.php` (front-controller funnels all traffic there → throttled browsing / 503 behind a proxy; app handles login brute-force). Allow ZF1 param-pair URLs in the route allowlist.
- `contrib/cron`: mail-host archive + maildir-size example scripts and HOWTO; folded SP ini into the FPM pool and `fastcgi.inc` into the vhost.
- `application.ini.dist`: 587/STARTTLS mail default + TLS cert-ignore.
- CLI: resolve `APPLICATION_ENV` from the env var first (containers); silenced framework deprecation noise.

## Docs

- README rewrite: Security, Performance, *Upgrading & schema migrations*, MCP adapter + trusted-proxy sections; PHP badge bumped to 8.4+; deb.myguard.nl write-up linked at the top.

---

**Full diff:** [`3.4.1...4.0.0`](https://github.com/eilandert/ViMbAdmin/compare/3.4.1...4.0.0)
