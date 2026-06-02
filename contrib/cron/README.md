# ViMbAdmin filesystem crons — mail-host HOWTO

These scripts do the part of ViMbAdmin that touches the **mail on disk**:
archiving a mailbox to a tarball, restoring/deleting those tarballs, and
measuring maildir sizes for the panel's quota column.

**They run on the mail host (the Dovecot/Postfix box), not on the web panel.**
The panel only flags work in the database; the mail host carries it out,
because that's where the maildirs and `archive.path` actually live.

There are **two ways** to drive the queue, pick one:

| Script | Needs | What it does |
|---|---|---|
| `vimbadmin-archive-sql.sh` | **just `mariadb-client` + `tar`/`bzip2`** | Standalone worker — no PHP, no ViMbAdmin checkout. Talks to the DB directly. **Recommended for a Dovecot container.** |
| `vimbadmin-archive.sh` + `vimbadmin-sizes.sh` | a full ViMbAdmin checkout + PHP CLI | Thin wrappers around the bundled `vimbtool.php` (the PHP queue consumer). |

> **Do you need a whole ViMbAdmin checkout on the mail host to archive/delete?**
> **No.** Use `vimbadmin-archive-sql.sh` — it only needs the MariaDB client and
> `tar`/`bzip2`. Reach for the PHP scripts (and a checkout) only if you also
> want **restore** (which re-creates DB rows — the SQL script deliberately
> doesn't) or the **quota sizes** job, which is PHP-only.

### Option A — standalone, no PHP (`vimbadmin-archive-sql.sh`)

Wire-compatible with the panel: the "Archive" button writes a `PENDING_ARCHIVE`
row and purges the mailbox; this script tars the maildir exactly the way the
PHP worker does (so a later panel **restore** still finds the tarballs), writes
the `*_file`/`*_size` columns, and flips the row to `ARCHIVED`. `delete`
processes `PENDING_DELETE` rows (rm tarballs + drop row).

```sh
# deps only:
sudo apt-get install --no-install-recommends -y mariadb-client tar bzip2 coreutils

# a credentials file (chmod 600), so the password isn't on the command line:
sudo install -d -m 0750 /etc/vimbadmin
sudo tee /etc/vimbadmin/db.cnf >/dev/null <<'CNF'
[client]
host     = 127.0.0.1
user     = vimbadmin
password = the-db-password
CNF
sudo chmod 600 /etc/vimbadmin/db.cnf

sudo install -m 0755 contrib/cron/vimbadmin-archive-sql.sh /usr/local/sbin/
sudo install -d -o vmail -g vmail -m 0750 /srv/archives

# test, then cron:
sudo -u vmail DB_CNF=/etc/vimbadmin/db.cnf DB_NAME=vimbadmin \
     ARCHIVE_PATH=/srv/archives /usr/local/sbin/vimbadmin-archive-sql.sh list
```

```cron
# /etc/cron.d/vimbadmin  (DB-only worker)
*/5 * * * * vmail DB_CNF=/etc/vimbadmin/db.cnf DB_NAME=vimbadmin ARCHIVE_PATH=/srv/archives /usr/local/sbin/vimbadmin-archive-sql.sh archive
*/5 * * * * vmail DB_CNF=/etc/vimbadmin/db.cnf DB_NAME=vimbadmin ARCHIVE_PATH=/srv/archives /usr/local/sbin/vimbadmin-archive-sql.sh delete
```

`ARCHIVE_PATH` must match the panel's `application.ini` `archive.path`. Don't
run this **and** the PHP archive cron against the same DB at once — pick one.

### Option B — PHP wrappers (full checkout)

| Script | Action(s) | Cron cadence |
|---|---|---|
| `vimbadmin-archive.sh` | `archive.cli-archive-pendings` / `restore` / `delete` | every 5 min |
| `vimbadmin-sizes.sh` | `mailbox.cli-get-sizes` | nightly |
| `crontab.example` | sample `/etc/cron.d/vimbadmin` | — |

The rest of this document covers Option B.

---

## What the scripts actually call

Both scripts are thin wrappers around the ViMbAdmin CLI:

```sh
php <VIMBADMIN_DIR>/bin/vimbtool.php -a <module.controller.action>
```

`vimbtool.php` is the bundled CLI in the **`bin/`** directory of a ViMbAdmin
checkout. The scripts find it via `$VIMBADMIN_DIR/bin/vimbtool.php`
(default `VIMBADMIN_DIR=/opt/vimbadmin`); override with the `VIMBADMIN_DIR` or
`VIMBTOOL` environment variable. It reads `application/configs/application.ini`
relative to itself, so the checkout's config is what decides which database
and which `archive.path` are used.

---

## Requirements (on the mail host)

1. **PHP CLI**, 8.4.1+ (same line ViMbAdmin targets), with the extensions the
   app needs: `pdo_mysql`, `mbstring`, `intl`, `gettext`, `dom`, `ctype`,
   `iconv`, `sodium`.
2. **A ViMbAdmin checkout** (the code + `vendor/`), reachable as
   `$VIMBADMIN_DIR/bin/vimbtool.php`.
3. **`application.ini`** in that checkout pointing at the **same MariaDB/MySQL**
   the web panel uses — that's how the host sees the archive queue and writes
   sizes back.
4. **The shell tools** the archive code shells out to (configured under
   `binary.path.*` in `application.ini`): `tar`, `bzip2`, `bunzip2`, `chown`,
   `rm`. On a minimal install `bzip2` is usually **not** present — install it.
5. **Filesystem access**: read the maildir/homedir tree of every mailbox, and
   read/write `archive.path` (default `/srv/archives`, which must exist). Run
   the cron as a user that can do this — your mail/`vmail` user, or root.

---

## Debian install example (end to end)

Assumes Debian 12/13 (`trixie`) and the mail data under `/var/vmail`, archives
under `/srv/archives`. Adjust to taste.

```sh
# 1) Dependencies. PHP from Debian; add deb.sury.org if you need a newer line.
sudo apt-get update
sudo apt-get install --no-install-recommends -y \
    php-cli php-mysql php-mbstring php-intl php-gettext \
    php-xml php-curl php-bcmath php-gmp php-sodium \
    tar bzip2 coreutils git unzip ca-certificates

# (ctype, iconv and json are compiled into php-cli on Debian; dom comes with
#  php-xml; sodium is the separate php-sodium package above.)

# 2) Get the ViMbAdmin code (or copy your existing checkout here).
sudo git clone https://github.com/eilandert/ViMbAdmin.git /opt/vimbadmin

# 3) Composer deps (Composer via the distro, or the phar — distro is simplest).
sudo apt-get install -y composer
cd /opt/vimbadmin
sudo composer install --no-dev --prefer-dist --optimize-autoloader

# 4) Config: point it at the SAME database as the web panel.
sudo cp application/configs/application.ini.dist application/configs/application.ini
sudoedit application/configs/application.ini
#   - resources.doctrine2.connection.options.host / dbname / user / password
#   - archive.path            = "/srv/archives"
#   - binary.path.bzip2_q     = "/usr/bin/bzip2 -q"   (Debian path; /bin is a symlink)
#   - binary.path.bunzip2_q   = "/usr/bin/bunzip2 -q"
#   (the other binary.path.* defaults are fine on Debian)

# 5) Archive target dir, owned by the user the cron runs as (here: vmail).
sudo install -d -o vmail -g vmail -m 0750 /srv/archives

# 6) Install the scripts.
sudo install -m 0755 contrib/cron/vimbadmin-archive.sh /usr/local/sbin/
sudo install -m 0755 contrib/cron/vimbadmin-sizes.sh   /usr/local/sbin/

# 7) Smoke-test by hand (verbose), as the cron user, before trusting cron.
sudo -u vmail VIMBADMIN_DIR=/opt/vimbadmin VERBOSE=1 \
     /usr/local/sbin/vimbadmin-sizes.sh
sudo -u vmail VIMBADMIN_DIR=/opt/vimbadmin VERBOSE=1 \
     /usr/local/sbin/vimbadmin-archive.sh

# 8) Schedule it.
sudo cp contrib/cron/crontab.example /etc/cron.d/vimbadmin
sudoedit /etc/cron.d/vimbadmin     # set the run-as user + VIMBADMIN_DIR
```

That's it. Flag a mailbox for archival in the panel; within ~5 minutes the
archive cron tars it into `/srv/archives` and flips its status to *archived*.
The nightly sizes job keeps the quota column honest.

---

## Running PHP / the CLI by hand

You never need a web server for these — it's plain PHP CLI:

```sh
# list of actions / help
php /opt/vimbadmin/bin/vimbtool.php --help

# run one action with verbose output
php /opt/vimbadmin/bin/vimbtool.php -a archive.cli-archive-pendings -v
```

`-v` (verbose) prints per-mailbox progress; the scripts pass it when you set
`VERBOSE=1`. A non-zero exit from the script means at least one action failed —
check the mail host's log and the panel's audit log.

---

## Notes

- **Sizes are cosmetic.** `mailbox.cli-get-sizes` only fills the UI's usage
  column. Real quota *enforcement* is Dovecot's `quota` plugin, not this.
- **On-disk deletion** of a live mailbox (`mailbox_deletion_fs_enabled`) is a
  separate, default-off feature handled inside the web delete flow — it would
  need the web host to see the maildirs, which the hardened Docker image
  deliberately doesn't. For disk cleanup, prefer the archive→delete path above.
- If you reuse the **Docker image** as a sidecar instead of a bare checkout,
  the CLI is at the same `/opt/vimbadmin/bin/vimbtool.php`, but you must mount
  the maildirs + `/srv/archives` into it and the image needs `bzip2` (it's not
  installed by default — the web image never archives).
