# MCP adapter

ViMbAdmin ships a small **MCP (Model Context Protocol) adapter**: a JSON-RPC 2.0
endpoint at **`/mcp`** that lets an agent query the mailbox database over HTTP.
It is **off by default** and guarded in depth.

## Security model

Three independent layers — defence in depth, machine-friendly, no OTP:

1. **Edge IP allowlist (primary).** The web server only lets known client IPs
   reach `/mcp` at all; everything else is denied before PHP runs. See the
   `location = /mcp` block in
   [`contrib/angie/vimbadmin.conf`](../contrib/angie/vimbadmin.conf).
2. **Bearer token.** Every request carries `Authorization: Bearer <token>`.
   Only the **SHA-256 hash** of the token is stored (table `mcp_token`); the
   raw token is shown once at generation and never persisted. Tokens are
   **scoped**, **revocable**, and can **expire**.
3. **Per-token IP/CIDR allowlist + scope (in-app).** A second, independent IP
   check bound to the token itself (reuses the same CIDR logic as the
   brute-force whitelist), plus a scope check. Catches an edge misconfig and
   ties identity to origin.

> Why not TOTP / OTP here? MCP is machine-to-machine and non-interactive — an
> OTP secret would just be a second static secret next to the token, not a
> second factor. A short-lived/scoped/revocable bearer + IP allowlist is the
> right tool. mTLS is a possible future upgrade (a client cert as "what you
> have"); the token+IP model is enough for a handful of trusted clients.

## Enable it

```ini
; application.ini  ([user] section)
mcp.enabled = 1
```

Restrict `/mcp` to your client IP(s) in the vhost (`allow ...; deny all;`), and
create a token:

```sh
./bin/vimbtool.php -a mcp.cli-token-generate --name=agent1 --scope="read" \
    --ip="10.0.0.0/8" --days=365
# -> prints the raw token ONCE; store it.

./bin/vimbtool.php -a mcp.cli-token-list
./bin/vimbtool.php -a mcp.cli-token-revoke --name=agent1     # or --id=N
```

Flags: `--name` (label, required), `--scope` (default `read`; space-separated,
`*` = all), `--ip` (space/comma IP/CIDR allowlist; omit = any, rely on the
edge), `--days` (validity; omit = no expiry).

Schema: fresh installs get the `mcp_token` table from
`./bin/doctrine-cli.php orm:schema-tool:create`; on an existing DB run
`orm:schema-tool:update --force`.

## Calling it

JSON-RPC 2.0 over POST:

```sh
curl -s https://mail.example.com/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
```

### Read methods (scope: `read`)

| Method | Params | Returns |
|---|---|---|
| `ping` | — | `{pong, time}` |
| `domains.list` | — | all domains (id, name, active, transport, quotas, counts) |
| `mailboxes.list` | `{domain}` | mailboxes of that domain |
| `aliases.list` | `{domain}` | aliases of that domain |

### Write methods (scope: `write`)

| Method | Params | Notes |
|---|---|---|
| `domain.create` | `{domain, active?, transport?, quota?, maxquota?, max_mailboxes?, max_aliases?}` | |
| `domain.delete` | `{domain}` | **destructive** — purges the domain + its mailboxes/aliases |
| `mailbox.create` | `{domain, local_part, password, name?, quota?, active?}` | password hashed in the configured scheme (`doveadm pw`) |
| `mailbox.delete` | `{username}` | **destructive** |
| `alias.create` | `{domain, address, goto, active?}` | `address` may be a local part (domain appended) or full |
| `alias.delete` | `{address}` | **destructive** |
| `mailbox.archive` | `{username}` | **destructive** — queues `PENDING_ARCHIVE` (panel-compatible) |
| `archive.restore` | `{username}` | **destructive** — sets `PENDING_RESTORE` |
| `archive.delete` | `{username}` | **destructive** — sets `PENDING_DELETE` |

A token's scope must contain the method's scope (`read`/`write`) or `*`. Issue a
read-only token by default; only grant `write` where needed.

### Rate limiting (destructive methods)

The **destructive** methods above are additionally rate-limited **per token**:
max `N` per `window` seconds (file-based sliding window under `var/`). Over the
limit returns HTTP `429`. So a leaked or buggy `write` token still can't
mass-destroy mailboxes. Configure in `application.ini`:

```ini
mcp.ratelimit.destructive.max    = 10     ; 0 disables the limiter
mcp.ratelimit.destructive.window = 3600
;mcp.ratelimit.statedir = APPLICATION_PATH "/../var/mcp-ratelimit"
```

### Errors

Transport/auth failures return the matching HTTP status (`401` missing/invalid
token, `403` revoked/expired/scope/IP, `404` adapter disabled, `405` non-POST)
plus a JSON-RPC error envelope. Method/param errors return HTTP 200 with a
JSON-RPC `error` object (`-32601` unknown method, `-32602`-ish bad params,
`-32603` internal).

## Notes

- The endpoint never uses the admin session; it is bearer-only. It also
  bypasses the login rate-limiter (its own `location`).
- The per-token IP check uses the resolved client IP. Behind a reverse proxy,
  set `trustedproxy.mode` in `application.ini` (default `auto` — trusts
  `X-Forwarded-For` only from a private/loopback proxy) or let the web server
  rewrite `REMOTE_ADDR` (Angie `realip`). Otherwise the check sees the proxy's
  IP, not the client's.
- `last_used_at` is updated per successful call (cheap audit trail);
  `mcp.cli-token-list` shows it.
