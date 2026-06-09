# Migrating an old ViMbAdmin to 4.0.0 — the Modernised Edition

> **Audience:** an operator running an **old ViMbAdmin** (a stock 3.x install,
> or an earlier build of this fork) who is upgrading to **ViMbAdmin 4.0.0 — the
> Modernised Edition** (`VERSION 4.0.0-rc1`, schema `DBVERSION 4`).
>
> **The one big change to understand:** the Modernised Edition **never touches
> the mail filesystem any more — not in any way.** The old ViMbAdmin read and
> wrote maildirs directly, scanned them with `du` for quota, and ran `doveadm`
> on a shared `/srv/vmail` mount. Version 4.0.0 does **none** of that. It does
> **everything mail-related by talking to Dovecot over its REST API (the doveadm
> HTTP API)** — create, repair, archive, delete, quota, last-login, all of it.
>
> So there is no shared mail mount, no `du` scan, no `doveadm` binary to call,
> and ViMbAdmin no longer stores a mailbox's `uid`/`gid`/`homedir`/`maildir`.
> **Dovecot owns the files; ViMbAdmin owns the records** (who exists, their
> quota, whether they're active) plus a job **queue** that it drains through the
> REST API. This document is the **deployment** migration to that model.

Read the whole plan before starting. The three phases are ordered: **back up and
migrate the database first**, then **swap the config**, then **retire the legacy
scripts and point Dovecot at the new integration**.

---

## Minimum requirements

The Modernised Edition will not run on the old stack. Before you start, make sure
you have at least:

- **PHP 8.4.1** or newer.
- **Dovecot 2.4** or newer (the doveadm HTTP API + the `fs` / `quota_clone`
  features the integration relies on are 2.4-era).

---

## Phase 0 — Pre-flight

- [ ] **Set up Dovecot first.** Because everything now goes through Dovecot's
      REST API, Dovecot has to be ready before ViMbAdmin can do anything useful.
      Follow [`contrib/dovecot/`](../contrib/dovecot/) (read
      [`contrib/dovecot/README.md`](../contrib/dovecot/README.md)) to drop in the
      config snippets, then reload Dovecot. Phase 3c walks through the same
      snippets in upgrade order if you'd rather do it there.
- [ ] Confirm you can reach the Dovecot host's **doveadm HTTP API** from where
      ViMbAdmin runs (default `http://dovecot:8081/doveadm/v1`). The migration
      is pointless without it — every repair/archive/delete now goes there.
- [ ] Schedule a short maintenance window: schema DDL + a config swap + a
      Dovecot reload.

---

## Phase 1 — Back up and update the database (via the Maintenance menu)

Think of the database like a form with a fixed set of boxes (the "schema").
Version 4.0.0 needs a few **new** boxes the old version never had — a job queue,
some Dovecot bookkeeping tables, and so on. "Updating the schema" just means
adding those new boxes. It only **adds**; it doesn't throw away your existing
data.

You don't have to write any SQL for this. ViMbAdmin figures out exactly which
boxes are missing, shows you the list, and adds them when you click a button —
usually it even does it by itself when the app starts. The Maintenance menu is
just the manual "do it now" button. Two rules:

1. **Always make a backup before you click** — that's your undo.
2. **Read the list it shows you before confirming** — so there are no surprises.

1. [ ] **Back up the database first — always.** From a shell with DB access:

       ```sh
       mysqldump --single-transaction --routines --triggers \
         -u <user> -p <vimbadmin_db> > vimbadmin-pre-migrate-$(date +%F).sql
       ```

       (A bad DDL on a populated table is not something the panel can undo for
       you — keep this dump until the migration is verified.)

2. [ ] **Review the pending SQL before applying.** Log in as a super-admin →
       **Maintenance → Update database schema → "Show pending schema changes".**
       Nothing is applied until you confirm; the page lists every statement.

3. [ ] **Apply.** Click **"Apply N statement(s)"**. The page reports
       `Schema updated successfully — N statement(s) executed`, and the version
       line shows `4 applied / 4 expected by code` → **up to date**.

   - CLI equivalent (same code path), if you prefer:

     ```sh
     php bin/vimbtool.php -a maintenance.cli-schema-update --verbose
     ```

> **If the tab keeps showing "N pending statement(s)" after applying**, it is a
> stale APCu metadata cache, not a real diff — restart the FPM process (e.g.
> `docker restart <vimbadmin>`). See the workspace lesson
> `reference-vimbadmin-stale-apcu-phantom-schema`. The build-versioned cache
> namespace fixes this going forward.

**Schema 4 adds:** the `mailbox_task` queue, MCP token tables, the
`dovecot_quota` / `dovecot_last_login` dict tables (+ cascade FKs to `mailbox`),
the archive-autoprune columns, and the `setting` KV store. The old
`uid`/`gid`/`homedir`/`maildir` mailbox columns are no longer used (Dovecot owns
storage — see Phase 3); the migration does **not** drop them destructively, so
they remain harmless until you choose to drop them by hand.

---

## Phase 2 — Back up `application.ini` and re-seed from the dist

The fork's `application.ini.dist` carries config sections the old file does not
(the doveadm HTTP block, the queue runner, password scheme in `dovecot:` form,
blanked mailbox storage defaults). The cleanest upgrade is to **start from the
new dist and re-apply only your site values**, rather than hand-merge.

1. [ ] **Back up your live config:**

       ```sh
       cp application/configs/application.ini application/configs/application.ini.bak-$(date +%F)
       ```

2. [ ] **Seed from the dist:**

       ```sh
       cp application/configs/application.ini.dist application/configs/application.ini
       ```

       > Dockerised deployments do this automatically on first boot when no
       > `application.ini` is present; on an in-place upgrade you do it yourself.

3. [ ] **Re-apply your site values from the `.bak`** — at minimum:

   - `resources.doctrine2.connection.options.*` — **DB host / name / user /
     password** (copy verbatim from your backup).
   - `securitysalt` — **keep the existing value** (changing it invalidates
     stored tokens / sessions).
   - `resources.doctrine2cache.*` — cache backend + `namespace` if you set one.
   - `defaults.quota.multiplier`, `defaults.mailbox.min_password_length`, any
     branding / locale you customised.

4. [ ] **Set the new doveadm + queue keys** (no equivalent existed before):

   ```ini
   ; Dovecot doveadm HTTP API — the new mail integration point.
   doveadm.http.url     = "http://dovecot:8081/doveadm/v1"
   doveadm.http.api_key = "<same bearer key as the Dovecot 95-doveadm-http.conf>"
   doveadm.http.timeout = 900
   doveadm.backup.dest  = "maildir:/backups/%d/%u"

   ; Password hashes are generated in PHP now — no local "doveadm pw" binary.
   defaults.mailbox.password_scheme = "dovecot:BLF-CRYPT"
   ```

5. [ ] **Blank the per-mailbox storage defaults** so the DB never pins a maildir
       root — Dovecot derives it (Phase 3). The dist already ships these blank;
       just confirm you did **not** carry over old `defaults.mailbox.homedir` /
       `defaults.mailbox.maildir` values from your backup.

6. [ ] Sanity-check the result boots:

       ```sh
       php bin/vimbtool.php -a maintenance.cli-schema-update --verbose   # "up to date"
       ```

> Keep `application.ini.bak-*` until the panel is verified working end to end.

---

## Phase 3 — Retire the legacy scripts and point Dovecot at the new integration

The old deployment shelled out to the mail host: a daily `du` quota scan, a
tar/bzip2 archive job, and `doveadm`/maildir crons on a shared `/srv/vmail`.
**None of that exists in the fork.** Mail work is queued and executed over the
doveadm HTTP API by a single queue runner.

### 3a. Remove the obsolete crons / scripts

- [ ] **Delete the old quota `du`-scan cron** (anything running with
      `USE_VIMBADMIN=yes`, or a periodic `du` over the maildir root). Live quota
      now arrives from Dovecot's `quota_clone` into `dovecot_quota`
      (Phase 3c). There is **no** `du` scan any more.
- [ ] **Delete the old tar/bzip2 archive cron and any mail-host filesystem
      job.** Archive / delete backups are written by the queue as
      zstd-compressed `doveadm backup` over the API — no host-side archiver.
- [ ] **Delete any `doveadm`/maildir crons that assumed a shared `/srv/vmail`
      mount.** There is no shared filesystem in this model.
- [ ] **Drop the per-mailbox shell-out tooling** — the fork needs no local
      `doveadm pw` binary (hashes are made in PHP) and no maildir access from
      the ViMbAdmin host.

### 3b. Install the one cron the fork DOES need — the queue runner

ViMbAdmin has no daemon; the queue is drained by cron (the panel's
trigger-checks on login / start / Maintenance are best-effort nudges, **not** a
replacement). See [`contrib/cron/README.md`](../contrib/cron/README.md) and
[`contrib/cron/crontab.example`](../contrib/cron/crontab.example):

```cron
# Dockerised: run from the HOST crontab as a docker exec
*/2 * * * *  root  docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

Concurrency is capped by `queue.runner.max_concurrent` (default 1) via a
`queue_runner` DB lease, so frequent ticks never over-run.

### 3c. Configure Dovecot for the new integration

Every Dovecot-side change is documented and shipped as drop-in snippets in
[`contrib/dovecot/`](../contrib/dovecot/) — read
[`contrib/dovecot/README.md`](../contrib/dovecot/README.md) for the full table
and the hard-won caveats (dict socket perms, `fs` param shapes, last-login
scope). Drop these into your Dovecot `conf.d/` and adjust DB creds / API key:

| Snippet | Purpose |
|---|---|
| [`00-mail-storage.conf`](../contrib/dovecot/conf.d/00-mail-storage.conf) | `mail_path`/`mail_inbox_path` derive the maildir from **Dovecot config, not the DB**. **Drop `maildir AS mail` / `homedir AS home` (and the passdb prefetch) from your userdb queries** — else the per-row DB value wins. Pairs with the blank `defaults.mailbox.{homedir,maildir}` from Phase 2. |
| [`95-doveadm-http.conf`](../contrib/dovecot/conf.d/95-doveadm-http.conf) | Exposes the doveadm HTTP API on `:8081` with the bearer key ViMbAdmin POSTs to. **Never publish 8081 to the host** — the docker network + key are the perimeter. The key here must equal `doveadm.http.api_key` from Phase 2. |
| [`96-fs-posix.conf`](../contrib/dovecot/conf.d/96-fs-posix.conf) | Named `fs posix` filter so `doveadm fs delete -R` can remove a backup dir over the API (archive autoprune). |
| [`99-zzz-quotaclone.conf`](../contrib/dovecot/conf.d/99-zzz-quotaclone.conf) | `quota_clone` writes real-time usage into `dovecot_quota`; a DB trigger mirrors it into `mailbox.maildir_size`. **Replaces the `du` scan.** Self-contained SQL `dict_server` map included. |
| [`99-zzz-lastlogin.conf`](../contrib/dovecot/conf.d/99-zzz-lastlogin.conf) | Writes `last_login` into `dovecot_last_login`. **Scope to `protocol imap`/`pop3`** or every doveadm queue action counts as a login. |

- [ ] Drop the snippets in, set DB creds + the shared API key, and **reload
      Dovecot**.
- [ ] Verify the dict socket is reachable by the mail user (`vmail`/5000) — the
      default `root:dovecot 0660` socket denies `vmail`; hand it to `vmail` (see
      the `service dict` block in the README).

---

## Phase 4 — Verify

- [ ] Maintenance tab: schema **up to date** (`4 / 4`).
- [ ] Create a test mailbox → it appears in Dovecot; login over IMAP works and
      stamps `dovecot_last_login`.
- [ ] Send/receive a message → `dovecot_quota` updates and the panel shows live
      usage (no `du` cron involved).
- [ ] Queue a **repair** and an **archive** on the test mailbox → the queue
      runner drains them over the doveadm HTTP API; the backup lands at
      `doveadm.backup.dest`.
- [ ] Delete the test mailbox → its `dovecot_quota` / `dovecot_last_login` rows
      cascade away (the FKs added in Phase 1).
- [ ] Only once all of the above pass: discard the `application.ini.bak-*` and
      the pre-migrate SQL dump, and optionally drop the now-unused
      `uid`/`gid`/`homedir`/`maildir` mailbox columns by hand.

---

## Rollback

Each phase is independently reversible **before** Phase 4 is confirmed:

- **DB:** restore the Phase 1 `mysqldump`.
- **Config:** restore `application.ini.bak-*`.
- **Code:** check out the version you noted in Phase 0.
- **Dovecot:** the snippets are additive drop-ins — remove them and reload to
  return to your previous mail config.

## See also

- [`contrib/dovecot/README.md`](../contrib/dovecot/README.md) — Dovecot
  integration detail + caveats.
- [`contrib/cron/README.md`](../contrib/cron/README.md) — the queue-runner cron.
- Workspace lessons: `reference-vimbadmin-doveadm-queue-deploy`,
  `project-dovecot-hardened-docker`, `reference-vimbadmin-backup-zstd`,
  `feedback-vimbadmin-quota-clone-table`,
  `reference-vimbadmin-stale-apcu-phantom-schema`.
