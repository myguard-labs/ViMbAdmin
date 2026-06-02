#!/bin/sh
# =====================================================================
#  vimbadmin-archive-sql.sh
#  Standalone ViMbAdmin archive/delete worker -- NO PHP, NO checkout.
# =====================================================================
#  Runs ON THE MAIL HOST (where the maildirs live). Talks to the same
#  MariaDB/MySQL the panel uses, with ONLY the `mariadb` (or `mysql`)
#  client + coreutils + tar + bzip2. Lets you process the archive queue
#  on a Dovecot box without deploying the whole PHP application there.
#
#  It is wire-compatible with the panel:
#    * the panel's "Archive" button writes an `archive` row (status
#      PENDING_ARCHIVE) and purges the mailbox -- this script consumes it;
#    * it tars exactly the way the PHP worker does (cd into the parent,
#      tar the last path component, then bzip2), and writes the same
#      `*_file` / `*_size` columns + status ARCHIVED, so a later restore
#      from the panel still finds its tarballs.
#
#  SUBCOMMANDS:
#    archive   process every PENDING_ARCHIVE row  (tar + bzip2 + rm original)
#    delete    process every PENDING_DELETE  row  (rm tarballs + drop row)
#    list      show the queue and exit
#
#  RESTORE is intentionally NOT implemented here: restoring re-creates the
#  mailbox (and preference/alias) rows from the serialized snapshot, which is
#  exactly the job the PHP layer exists for. Do restores from the panel /
#  `vimbtool.php -a archive.cli-restore-pendings`. Archive + delete are the
#  destructive, disk-heavy parts you don't want to ship PHP for.
#
#  ---------------------------------------------------------------------
#  REQUIREMENTS (mail host): mariadb-client (or mysql), tar, bzip2,
#  coreutils (du, stat). No PHP. Run as a user that can read the maildirs
#  and write ARCHIVE_PATH.
#
#  CONFIG via environment (or a small wrapper that exports them):
#    DB_CNF        path to a MySQL "defaults-extra-file" with [client]
#                  host/user/password (preferred -- keeps the password off
#                  the command line and out of `ps`). See the example below.
#    DB_NAME       database name (the ViMbAdmin schema).
#    ARCHIVE_PATH  where tarballs are written  (must match the panel's
#                  application.ini `archive.path`; default /srv/archives).
#    MYSQL         client binary (default: mariadb; set to `mysql` if that's
#                  what you have).
#
#  Example DB_CNF (chmod 600, owned by the cron user):
#    [client]
#    host     = 127.0.0.1
#    user     = vimbadmin
#    password = the-db-password
#
#  Example cron (/etc/cron.d/vimbadmin):
#    */5 * * * * vmail DB_CNF=/etc/vimbadmin/db.cnf DB_NAME=vimbadmin \
#      ARCHIVE_PATH=/srv/archives /usr/local/sbin/vimbadmin-archive-sql.sh archive
#    */5 * * * * vmail DB_CNF=/etc/vimbadmin/db.cnf DB_NAME=vimbadmin \
#      ARCHIVE_PATH=/srv/archives /usr/local/sbin/vimbadmin-archive-sql.sh delete
#
#  NOTE: do NOT run this AND the PHP archive cron against the same DB at the
#  same time -- pick one worker.
# =====================================================================
set -eu

MYSQL="${MYSQL:-mariadb}"
DB_NAME="${DB_NAME:?set DB_NAME to the ViMbAdmin database}"
ARCHIVE_PATH="${ARCHIVE_PATH:-/srv/archives}"

# db <sql>  -> tab-separated, no header, raw (no escaping of the value).
# Prefer DB_CNF (defaults-extra-file); else fall back to individual vars.
db() {
    if [ -n "${DB_CNF:-}" ]; then
        printf '%s' "$1" | "${MYSQL}" --defaults-extra-file="${DB_CNF}" \
            -N -B --raw "${DB_NAME}"
    else
        # shellcheck disable=SC2086  # conditional flags, values are space-free
        printf '%s' "$1" | "${MYSQL}" \
            ${DB_HOST:+--host="${DB_HOST}"} ${DB_USER:+--user="${DB_USER}"} \
            ${DB_PASS:+--password="${DB_PASS}"} -N -B --raw "${DB_NAME}"
    fi
}

# SQL string literal or NULL, single-quotes doubled.
sqlval() {
    if [ -z "$1" ]; then printf NULL
    else printf "'%s'" "$(printf '%s' "$1" | sed "s/'/''/g")"; fi
}

# Pull one serialized key's string value out of the `data` blob on stdin.
#   s:7:"homedir";s:21:"/var/vmail/example.com";
ser_str() { tr '\n' ' ' | sed -nE "s/.*\"$1\";s:[0-9]+:\"([^\"]*)\".*/\1/p" | head -n1; }

# ViMbAdmin's Mailbox::cleanMaildir: strip a leading "maildir:" and a trailing
# ":LAYOUT=..." segment.
clean_maildir() {
    m="$1"
    case "$m" in maildir:*) m="${m#maildir:}";; esac
    case "$m" in *:LAYOUT*) m="${m%:*}";; esac
    printf '%s' "$m"
}

dir_bytes() { [ -e "$1" ] && du -sb "$1" 2>/dev/null | cut -f1 || echo 0; }
file_bytes() { [ -f "$1" ] && stat -c %s "$1" 2>/dev/null || echo 0; }

# tar <name> <srcdir>  -- mirrors the PHP worker: cd into the parent and tar
# the last path component, then bzip2. Echoes the .tar size (pre-bzip2) or
# empty on failure. Removes the source dir on success (like the panel does).
tar_dir() {
    _name="$1"; _src="$2"
    [ -d "$_src" ] || { echo ""; return 0; }
    _parent=$(dirname "$_src"); _base=$(basename "$_src")
    _tar="${ARCHIVE_PATH}/${_name}.tar"
    if ( cd "$_parent" && tar -cf "$_tar" "$_base" ); then
        _sz=$(file_bytes "$_tar")
        rm -rf "$_src"
        bzip2 -q "$_tar" || true        # -> ${_tar}.bz2 ; column keeps .tar name
        echo "$_sz"
    else
        rm -f "$_tar"; echo ""
    fi
}

do_archive() {
    install -d -m 0755 "${ARCHIVE_PATH}"
    ids=$(db "SELECT id FROM archive WHERE status='PENDING_ARCHIVE' ORDER BY id;")
    [ -n "$ids" ] || { echo "no pending archives"; return 0; }
    for id in $ids; do
        # lock
        db "UPDATE archive SET status='ARCHIVING', status_changed_at=NOW() WHERE id=${id} AND status='PENDING_ARCHIVE';"
        data=$(db "SELECT data FROM archive WHERE id=${id};")
        homedir=$(printf '%s' "$data" | ser_str homedir)
        maildir=$(clean_maildir "$(printf '%s' "$data" | ser_str maildir)")
        user=$(db "SELECT username FROM archive WHERE id=${id};")
        echo "archiving #${id} ${user}"

        horig=$(dir_bytes "$homedir"); hfile=""; hsize=0
        morig=0; mfile=""; msize=0

        if [ "$horig" -gt 0 ] 2>/dev/null; then
            hsz=$(tar_dir "homedir-${id}" "$homedir")
            [ -n "$hsz" ] && { hfile="${ARCHIVE_PATH}/homedir-${id}.tar"; hsize="$hsz"; }
            # maildir only if it's a distinct path
            if [ -n "$maildir" ] && [ "$maildir" != "$homedir" ]; then
                morig=$(dir_bytes "$maildir")
                msz=$(tar_dir "maildir-${id}" "$maildir")
                [ -n "$msz" ] && { mfile="${ARCHIVE_PATH}/maildir-${id}.tar"; msize="$msz"; }
            fi
        fi

        db "UPDATE archive SET status='ARCHIVED', status_changed_at=NOW(),
              homedir_file=$(sqlval "$hfile"), homedir_orig_size=${horig}, homedir_size=${hsize},
              maildir_file=$(sqlval "$mfile"), maildir_orig_size=${morig}, maildir_size=${msize}
            WHERE id=${id};"
    done
}

do_delete() {
    ids=$(db "SELECT id FROM archive WHERE status='PENDING_DELETE' ORDER BY id;")
    [ -n "$ids" ] || { echo "no pending deletes"; return 0; }
    for id in $ids; do
        hf=$(db "SELECT IFNULL(homedir_file,'') FROM archive WHERE id=${id};")
        mf=$(db "SELECT IFNULL(maildir_file,'') FROM archive WHERE id=${id};")
        echo "deleting #${id}"
        for f in "$hf" "$mf"; do
            [ -n "$f" ] && rm -f "$f" "${f}.bz2"
        done
        db "DELETE FROM archive WHERE id=${id};"
    done
}

do_list() {
    db "SELECT id, username, status, status_changed_at FROM archive ORDER BY id;"
}

case "${1:-}" in
    archive) do_archive ;;
    delete)  do_delete ;;
    list)    do_list ;;
    *) echo "usage: $0 {archive|delete|list}" >&2; exit 2 ;;
esac
