# ViMbAdmin queue runner — cron HOWTO

ViMbAdmin's **repair / optimize / archive / delete** actions are not done inline
in the web request. The panel writes a row to the `mailbox_task` queue, and a
**queue runner** drains it in the background, carrying out the work against
Dovecot over the **doveadm HTTP API** (`doveadm backup`, `mailbox delete`,
`fs delete`, `sync`). That runner has to be invoked periodically — that is all
this directory is for.

> **A periodic runner is REQUIRED.** ViMbAdmin has no queue daemon. The Docker
> image supplies a supervised schedule; bare-metal/source installs need cron.
> The panel also does best-effort *trigger-checks* when work may be pending, but
> those nudges are not a replacement for the periodic runner.
>
> Concurrency is capped by `queue.runner.max_concurrent` (default 1, serial),
> enforced by a `queue_runner` DB lease — so frequent cron ticks + trigger-checks
> never run more than the configured number of runners at once.
>
> There is **no** tar/bzip2 archive cron any more, and no mail-host filesystem
> job. Archive/delete backups are written by the queue as zstd-compressed
> Dovecot maildirs under the configured `doveadm.backup.dest` (e.g. `/backups`),
> shown on the **Archives** tab, and pruned from the **Maintenance** tab. Mailbox
> **usage** is fed live by
> Dovecot's quota-clone plugin — see the main
> [README](../../README.md#live-quota-usage-dovecot-quota-clone).

| File | Purpose |
| --- | --- |
| `crontab.example` | sample crontab that drains the queue every 2 minutes |

---

## What it runs

A thin wrapper around the ViMbAdmin CLI:

```sh
php <VIMBADMIN_DIR>/bin/vimbtool.php -a queue.cli-run
```

Each batch claims up to `queue.runner.max_per_run` PENDING tasks. By default,
`queue.cli-run` repeats batches until the queue is empty or lease-throttled;
pass `--once` to drain one batch only. It is safe to run frequently — the claim
is race-safe (atomic PENDING→RUNNING), so overlapping runs won't double-process
a task.

The three entry points have deliberately different completion contracts:

<!-- markdownlint-disable MD013 -->

| Entry point | Immediate response | Drain bound | Execution context |
| --- | --- | --- | --- |
| `queue.cli-run` | exit `0`; no output unless `--verbose` | Repeats `max_per_run` batches until empty/throttled; `--once` stops after one batch | CLI process |
| `POST /queue/trigger` | HTTP `200` JSON `{"triggered":true}` after bearer/IP validation | Repeats until empty/throttled; stops starting another batch after 100 batches or once 60 seconds has elapsed (an in-flight batch completes) | Same FPM worker, after response send; no fork/spawn |
| Queue page **Run now** | Redirect + flash status | One `max_per_run` batch | Synchronous web request |

<!-- markdownlint-enable MD013 -->

Unlike the old archive cron, the runner needs **no** maildir access, **no**
shell tools (`tar`/`bzip2`/…) and **no** mail-host checkout: every filesystem
operation happens on the Dovecot side over the HTTP API. It only needs:

1. **PHP CLI** + the app's extensions (the same the panel uses).
2. **A ViMbAdmin checkout** reachable at `$VIMBADMIN_DIR/bin/vimbtool.php`.
3. **`application.ini`** pointing at the panel's database **and** at the doveadm
   HTTP endpoint (`doveadm.http.url` + `doveadm.http.api_key`).

So the simplest place to run it is **inside the ViMbAdmin container itself**.

---

## Dockerised deployment (recommended)

The `eilandert/vimbadmin` image already runs `queue.cli-run` every 5 minutes and
once on container start through its supervised `queue-runner` service. No host
cron is needed. If you want an additional host-side tick, use `docker exec`:

<!-- markdownlint-disable MD013 -->

```cron
# /etc/cron.d/vimbadmin  (on the docker host)
*/2 * * * *  root  docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

<!-- markdownlint-enable MD013 -->

For an off-box trigger instead of direct CLI access, the runner also exposes an
authenticated `/queue/trigger` HTTP endpoint — see `queue.runner.key` /
`queue.runner.allowed_ips` in `application.ini`.

## Bare-metal deployment

```sh
# 1) PHP + the app's extensions (no tar/bzip2 needed).
sudo apt-get install --no-install-recommends -y \
    php-cli php-mysql php-mbstring php-intl php-gettext \
    php-xml php-curl php-gd php-sodium git unzip

# 2) Code + composer deps.
sudo git clone https://github.com/myguard-labs/ViMbAdmin.git /opt/vimbadmin
cd /opt/vimbadmin
sudo composer install --no-dev --prefer-dist --optimize-autoloader

# 3) Config: same DB as the panel + the doveadm HTTP endpoint.
sudo cp application/configs/application.ini.dist application/configs/application.ini
sudoedit application/configs/application.ini
#   - resources.doctrine2.connection.options.host / dbname / user / password
#   - doveadm.http.url / doveadm.http.api_key
#   - doveadm.backup.dest, queue.autoprune.days

# 4) Smoke-test, then schedule.
php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run -v
sudo cp contrib/cron/crontab.example /etc/cron.d/vimbadmin
sudoedit /etc/cron.d/vimbadmin       # set VIMBADMIN_DIR + run-as user
```

`-v` prints per-task progress and the processed total. The command exits `0`
after the queue is empty or lease-throttled; individual failures are recorded
as FAILED tasks, so check the panel's Queue tab and audit log. Add `--once` when
the smoke test should process at most one `max_per_run` batch.

---

## HTTP trigger (off-box cron)

If the cron host can't run the CLI (no checkout, no `docker exec` access) it can
kick the runner over HTTP instead. The panel exposes **`POST /queue/trigger`**,
authenticated by a **Bearer key** and an **IP/CIDR allowlist** — both set in
`application.ini`:

```ini
queue.runner.key         = "a-long-random-secret"        ; empty = endpoint OFF
queue.runner.allowed_ips = "203.0.113.7, 10.0.0.0/8"     ; empty = deny all
```

Generate a key once (e.g. `openssl rand -hex 32`), put it in `application.ini`,
and have the remote cron POST it as a Bearer token:

```cron
# /etc/cron.d/vimbadmin-trigger  (on the OFF-BOX cron machine)
*/2 * * * *  root  curl -fsS -X POST \
    -H "Authorization: Bearer a-long-random-secret" \
    https://mail.example.com/vimbadmin/queue/trigger >/dev/null
```

Notes:

- It's **POST** — a GET returns nothing useful. `-f` makes curl exit non-zero on
  an HTTP error so cron mails you on failure.
- The key is compared by SHA-256 in constant time; a wrong/missing key is 401/403.
- The source IP must match `queue.runner.allowed_ips` (proxy-aware — it honours
  the same trusted-proxy config as the MCP endpoint). An empty allowlist denies
  everything, so the endpoint is effectively off until you set both values.
- Leaving `queue.runner.key` empty disables `/queue/trigger` entirely (returns
  404); the CLI runner and the in-panel "Run now" button still work.
- A valid trigger responds immediately with HTTP 200 JSON
  `{"triggered":true}`. After the response is sent, the same FPM worker drains
  batches until the queue is empty or lease-throttled. It stops starting new
  batches after 100 batches or 60 seconds; a batch already in flight completes.
