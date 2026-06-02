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
`./bin/doctrine2-cli.php orm:schema-tool:create`; on an existing DB run
`orm:schema-tool:update --force`.

## Calling it

JSON-RPC 2.0 over POST:

```sh
curl -s https://mail.example.com/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
```

### Methods (read scope)

| Method | Params | Returns |
|---|---|---|
| `ping` | — | `{pong, time}` |
| `domains.list` | — | all domains (id, name, active, transport, quotas, counts) |
| `mailboxes.list` | `{domain}` | mailboxes of that domain |
| `aliases.list` | `{domain}` | aliases of that domain |

Read methods need a token whose scope contains `read` (or `*`). Write methods
(none shipped yet) would need `write`.

### Errors

Transport/auth failures return the matching HTTP status (`401` missing/invalid
token, `403` revoked/expired/scope/IP, `404` adapter disabled, `405` non-POST)
plus a JSON-RPC error envelope. Method/param errors return HTTP 200 with a
JSON-RPC `error` object (`-32601` unknown method, `-32602`-ish bad params,
`-32603` internal).

## Notes

- The endpoint never uses the admin session; it is bearer-only. It also
  bypasses the login rate-limiter (its own `location`).
- The real client IP must reach PHP as `REMOTE_ADDR` — if you terminate TLS at
  a proxy, map it there (Angie `realip`), or the per-token IP check sees the
  proxy.
- `last_used_at` is updated per successful call (cheap audit trail);
  `mcp.cli-token-list` shows it.
