# ViMbAdmin queue runner — cron HOWTO

ViMbAdmin's **repair / optimize / archive / delete** actions are not done inline
in the web request. The panel writes a row to the `mailbox_task` queue, and a
**queue runner** drains it in the background, carrying out the work against
Dovecot over the **doveadm HTTP API** (`doveadm backup`, `mailbox delete`,
`fs delete`, `sync`). That runner has to be invoked periodically — that is all
this directory is for.

> There is **no** tar/bzip2 archive cron any more, and no mail-host filesystem
> job. Archive/delete backups are written by the queue as zstd-compressed
> Dovecot maildirs under the configured `doveadm.backup.dest` (e.g. `/backups`),
> shown on the **Archives** tab, and pruned from the **Maintenance** tab (or a
> cron hitting `maintenance/prune-expired`). Mailbox **usage** is fed live by
> Dovecot's quota-clone plugin — see the main
> [README](../../README.md#live-quota-usage-dovecot-quota-clone).

| File | Purpose |
|---|---|
| `crontab.example` | sample crontab that drains the queue every 2 minutes |

---

## What it runs

A thin wrapper around the ViMbAdmin CLI:

```sh
php <VIMBADMIN_DIR>/bin/vimbtool.php -a queue.cli-run
```

`queue.cli-run` claims up to `queue.runner.max_per_run` (application.ini) PENDING
tasks per invocation and executes them. It is safe to run frequently — the
claim is race-safe (atomic PENDING→RUNNING), so overlapping runs won't double-
process a task.

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

The `eilandert/vimbadmin` image already has everything. Run the drain from the
**host** crontab as a `docker exec`:

```cron
# /etc/cron.d/vimbadmin  (on the docker host)
*/2 * * * *  root  docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a queue.cli-run
```

That is exactly how the reference deployment runs it (every 2 minutes). For an
off-box trigger instead of cron, the runner also exposes an authenticated
`/queue/trigger` HTTP endpoint — see `queue.runner.key` / `queue.runner.allowed_ips`
in `application.ini`.

Optionally, also prune expired archive backups on a schedule (the same thing the
Maintenance-tab button does):

```cron
# once a day, remove autoprune backups older than queue.autoprune.days
30 3 * * *  root  docker exec vimbadmin php /opt/vimbadmin/bin/vimbtool.php -a maintenance.prune-expired
```

---

## Bare-metal deployment

```sh
# 1) PHP + the app's extensions (no tar/bzip2 needed).
sudo apt-get install --no-install-recommends -y \
    php-cli php-mysql php-mbstring php-intl php-gettext \
    php-xml php-curl php-bcmath php-gmp php-sodium git unzip

# 2) Code + composer deps.
sudo git clone https://github.com/eilandert/ViMbAdmin.git /opt/vimbadmin
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

`-v` prints per-task progress. A non-zero exit means a task failed — check the
panel's Queue tab (the task is marked FAILED with the error in its log) and the
audit log.
