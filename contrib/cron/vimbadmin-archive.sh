#!/bin/sh
# =====================================================================
#  vimbadmin-archive.sh  --  process the ViMbAdmin archive queue
# =====================================================================
#  EXAMPLE script. Run it ON THE MAIL HOST (where the maildirs live),
#  NOT on the web/panel host. The panel only flags mailboxes in the DB;
#  this script does the actual tar/untar/rm of the mail on disk.
#
#  It drives three CLI actions in turn:
#    archive.cli-archive-pendings  -> tar a PENDING_ARCHIVE mailbox into
#                                     application.ini's archive.path
#    archive.cli-restore-pendings  -> untar a PENDING_RESTORE mailbox back
#    archive.cli-delete-pendings   -> rm -rf an archived mailbox's tarballs
#
#  ---------------------------------------------------------------------
#  REQUIREMENTS (all on the host that runs this script):
#  ---------------------------------------------------------------------
#    * PHP CLI (the same major version ViMbAdmin targets).
#    * A ViMbAdmin code checkout, with bin/vimbtool.php reachable. Point
#      VIMBADMIN_DIR at it (or edit the default below).
#    * application/configs/application.ini configured with the SAME MariaDB
#      credentials as the web panel -- this is how the host sees the queue.
#    * Read/write access to BOTH:
#        - the maildir / homedir tree of every mailbox, and
#        - application.ini's archive.path  (default /srv/archives; must exist)
#    * The binaries named in application.ini's binary.path.* present in PATH:
#        tar, bzip2, bunzip2, chown, rm
#      (bzip2 is NOT installed by default on many minimal hosts -- install it.)
#    * Run as a user allowed to read the maildirs and chown the tarballs
#      (usually the mail/vmail user, or root). cron is the normal home.
#
#  ---------------------------------------------------------------------
#  INSTALL (example):
#    cp contrib/cron/vimbadmin-archive.sh /usr/local/sbin/
#    # then from cron, e.g. /etc/cron.d/vimbadmin :
#    */5 * * * *  vmail  VIMBADMIN_DIR=/opt/vimbadmin /usr/local/sbin/vimbadmin-archive.sh
#  ---------------------------------------------------------------------
set -eu

# Path to the ViMbAdmin checkout (contains bin/vimbtool.php). Override via env.
VIMBADMIN_DIR="${VIMBADMIN_DIR:-/opt/vimbadmin}"
PHP="${PHP:-php}"
VIMBTOOL="${VIMBTOOL:-${VIMBADMIN_DIR}/bin/vimbtool.php}"

# Set VERBOSE=1 to pass -v to vimbtool (per-mailbox progress on stdout).
VFLAG=""
[ "${VERBOSE:-0}" = "1" ] && VFLAG="-v"

if [ ! -f "${VIMBTOOL}" ]; then
    echo "vimbadmin-archive.sh: ${VIMBTOOL} not found -- set VIMBADMIN_DIR" >&2
    exit 1
fi

run() {
    # $1 = module.controller.action
    if ! "${PHP}" "${VIMBTOOL}" -a "$1" ${VFLAG}; then
        echo "vimbadmin-archive.sh: '$1' failed" >&2
        return 1
    fi
}

rc=0
run archive.cli-archive-pendings || rc=1
run archive.cli-restore-pendings || rc=1
run archive.cli-delete-pendings  || rc=1
exit "${rc}"
