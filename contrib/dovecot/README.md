# Dovecot configuration for ViMbAdmin

ViMbAdmin's modern features drive Dovecot directly — there is **no shared
`/srv/vmail` filesystem**, everything goes over Dovecot's **doveadm HTTP API**.
This directory documents every Dovecot-side change ViMbAdmin needs.

> These are **operator / deployment** config snippets. On the reference
> deployment they live in the Dovecot container's `conf.d/` (a host bind mount
> at `/opt/docker/myguard/config/dovecot/conf.d/`), **not** in the
> `dovecot-core` package or the Docker image — so rebuilding either does NOT
> touch them. Drop these into your own Dovecot `conf.d/` and adjust the DB
> credentials / API key.

Snippets are numbered to control load order (Dovecot reads `conf.d/*.conf`
lexically).

## What each feature needs

| ViMbAdmin feature | Dovecot snippet | What it does |
|---|---|---|
| **Mailbox-task queue** (repair / optimize / archive / delete) | `95-doveadm-http.conf` | Exposes the doveadm HTTP API on `:8081` with a bearer key. ViMbAdmin POSTs `force-resync`, `index`, `purge`, `backup`, `mailbox delete`, `quota recalc`, `sync` (restore) here. **Never publish 8081 to the host** — the docker network + the key are the perimeter. |
| **Archive autoprune** (delete the `/backups` maildir) | `96-fs-posix.conf` | A named `fs posix` filter so `doveadm fs delete -R` can remove a backup directory over the API. Required by doveadm 2.4 `fs` commands. |
| **Live quota → ViMbAdmin** (mailbox size, archive size) | `99-zzz-quotaclone.conf` | `quota_clone` writes real-time usage into a dedicated `dovecot_quota` table (username, bytes, messages); a DB trigger mirrors it into `mailbox.maildir_size`. Replaces the daily `du` scan (`USE_VIMBADMIN=no`). The archive size column reads this (a recalc runs at archive time). The SQL `dict_server` map it needs is self-contained in this same file. |
| **Last-login tracking** | `99-zzz-lastlogin.conf` | Writes `last_login` into `dovecot_last_login` (IMAP/POP3 only — scope it tightly or every doveadm action counts as a login). |

## Caveats learned the hard way

- **doveadm HTTP `fs` param shape:** `fsStat`/`fsIter` need `path` as a
  **string**; passing it as an array crashes the worker (empty reply, curl 52).
  `fsDelete` takes `path` as an **array**. (See `library/ViMbAdmin/Doveadm.php`.)
- **`doveadm fs iter` lists FILES only, not subdirectories** — so you cannot
  recursively walk a maildir tree (`cur/`, `.Folder/cur/`) over the API to sum
  its on-disk size. ViMbAdmin's archive "Size" therefore uses the **logical
  quota** (`dovecot_quota.bytes`), not the compressed on-disk footprint.
- **dict socket perms:** mail procs run as `vmail` (5000); the default dict
  socket is `root:dovecot 0660` and `vmail` isn't in `dovecot` → "Permission
  denied". Hand the socket to `vmail` (see the `service dict` block).
- **last-login scope:** the last-login dict hook fires on *every* userdb
  lookup; scope it to `protocol imap`/`pop3` or every doveadm queue action
  registers as a "login".

See also: the workspace lessons `reference-vimbadmin-doveadm-queue-deploy`,
`project-dovecot-hardened-docker`, `reference-vimbadmin-backup-zstd`,
`feedback-vimbadmin-quota-clone-table`.
