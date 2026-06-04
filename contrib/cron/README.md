# ViMbAdmin filesystem crons — mail-host HOWTO

These scripts do the part of ViMbAdmin that touches the **mail on disk**:
archiving a mailbox to a tarball and restoring/deleting those tarballs.

> Mailbox **usage** for the panel's quota column is no longer measured by a
> nightly maildir scan — it is fed live by Dovecot's quota-clone plugin into the
> `quota` table. See the main [README](../../README.md#live-quota-usage-dovecot-quota-clone).

**They run on the mail host (the Dovecot/Postfix box), not on the web panel.**
The panel only flags work in the database; the mail host carries it out,
because that's where the maildirs and `archive.path` actually live.

| Script | Action(s) | Cron cadence |
|---|---|---|
| `vimbadmin-archive.sh` | `archive.cli-archive-pendings` / `restore` / `delete` | every 5 min |
| `crontab.example` | sample `/etc/cron.d/vimbadmin` | — |

---

## What the scripts actually call — and what PHP needs

Both scripts are thin wrappers around the ViMbAdmin CLI:

```sh
php <VIMBADMIN_DIR>/bin/vimbtool.php -a <module.controller.action>
```

`vimbtool.php` is **not** a self-contained script. It boots the whole ViMbAdmin
application (Zend Framework + Doctrine ORM, the entity classes and their XML
mappings, the config), then dispatches the CLI action. So you can't copy a
single file — you need the parts of the tree the CLI loads at runtime:

| Path | Why it's needed |
|---|---|
| `bin/vimbtool.php`, `bin/utils.inc` | the CLI entry point |
| `vendor/` | Composer autoloader + Zend (zf1-future), Doctrine, Symfony, OSS framework |
| `library/` | on the PHP `include_path` (OSS + ViMbAdmin classes) |
| `application/` | `Bootstrap.php`, controllers, `Entities/`, `modules/`, and **`configs/application.ini`** (the DB + `archive.path` config) |
| `doctrine2/xml/` | the Doctrine XML entity mappings (the schema the CLI maps against) |

You do **not** need on the mail host: `public/` (the web docroot — no web
server here), `tests/`, `data/`, `Vagrantfile`, the docs, `.git`, or `contrib/`
(beyond the two cron scripts).

It reads `application/configs/application.ini` relative to itself, so that
config decides which database and which `archive.path` are used. The scripts
locate the CLI via `$VIMBADMIN_DIR/bin/vimbtool.php` (default
`VIMBADMIN_DIR=/opt/vimbadmin`); override with `VIMBADMIN_DIR` or `VIMBTOOL`.

### Copying just those parts to the mail host

Easiest is a full `git clone` + `composer install` (below). If you'd rather
copy a built checkout from the web host instead of building one, copy exactly
the paths above — e.g. with `rsync`:

```sh
# from the box that already has a working, composer-installed checkout:
rsync -a --relative \
    /opt/vimbadmin/./bin \
    /opt/vimbadmin/./vendor \
    /opt/vimbadmin/./library \
    /opt/vimbadmin/./application \
    /opt/vimbadmin/./doctrine2/xml \
    mailhost:/opt/vimbadmin/

# on the mail host, point its application.ini at the same DB + archive.path
# (or rsync the web host's application.ini too, if the DB host is reachable
#  from the mail host under the same name).
```

Everything else in the tree is dead weight on a mail host; skipping it keeps
the copy small and the attack surface minimal.

---

## Requirements (on the mail host)

1. **PHP CLI**, 8.4.1+ (same line ViMbAdmin targets), with the extensions the
   app needs: `pdo_mysql`, `mbstring`, `intl`, `gettext`, `dom`, `ctype`,
   `iconv`, `sodium`.
2. **A ViMbAdmin checkout** (the code + `vendor/`), reachable as
   `$VIMBADMIN_DIR/bin/vimbtool.php`.
3. **`application.ini`** in that checkout pointing at the **same MariaDB/MySQL**
   the web panel uses — that's how the host sees the archive queue.
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

# 6) Install the script.
sudo install -m 0755 contrib/cron/vimbadmin-archive.sh /usr/local/sbin/

# 7) Smoke-test by hand (verbose), as the cron user, before trusting cron.
sudo -u vmail VIMBADMIN_DIR=/opt/vimbadmin VERBOSE=1 \
     /usr/local/sbin/vimbadmin-archive.sh

# 8) Schedule it.
sudo cp contrib/cron/crontab.example /etc/cron.d/vimbadmin
sudoedit /etc/cron.d/vimbadmin     # set the run-as user + VIMBADMIN_DIR
```

That's it. Flag a mailbox for archival in the panel; within ~5 minutes the
archive cron tars it into `/srv/archives` and flips its status to *archived*.

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

- **Usage display is cosmetic.** The panel's usage column is filled live by
  Dovecot's quota-clone plugin (the `quota` table); real quota *enforcement* is
  Dovecot's `quota` plugin, not this.
- **On-disk deletion** of a live mailbox (`mailbox_deletion_fs_enabled`) is a
  separate, default-off feature handled inside the web delete flow — it would
  need the web host to see the maildirs, which the hardened Docker image
  deliberately doesn't. For disk cleanup, prefer the archive→delete path above.
- The `eilandert/vimbadmin` **Docker image is archive-capable**: it ships the
  full CLI plus `tar`/`bzip2`. Reuse it as a sidecar — run the same image with
  the maildir tree (read-write) and `/srv/archives` bind-mounted, and call
  `php /opt/vimbadmin/bin/vimbtool.php -a archive.cli-archive-pendings`. No
  separate checkout needed; the CLI is at `/opt/vimbadmin/bin/vimbtool.php`.
