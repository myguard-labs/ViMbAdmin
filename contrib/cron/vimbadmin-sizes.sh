#!/bin/sh
# =====================================================================
#  vimbadmin-sizes.sh  --  refresh maildir sizes for the quota display
# =====================================================================
#  EXAMPLE script. Run it ON THE MAIL HOST (where the maildirs live).
#  It walks every mailbox's maildir, measures its size, and writes the
#  totals back to the DB so the panel's quota/usage column is accurate.
#
#  CLI action driven:  mailbox.cli-get-sizes
#
#  ---------------------------------------------------------------------
#  REQUIREMENTS (all on the host that runs this script):
#  ---------------------------------------------------------------------
#    * PHP CLI.
#    * A ViMbAdmin checkout with bin/vimbtool.php (set VIMBADMIN_DIR).
#    * application/configs/application.ini pointing at the SAME MariaDB as
#      the web panel (that's where the sizes are written).
#    * READ access to every mailbox's maildir tree (this script only reads;
#      it does not modify mail). Run as the mail/vmail user or root.
#
#  This is independent of archiving -- you can run it even if you never
#  use the archive feature. It's purely cosmetic (the usage bars in the UI);
#  Dovecot's own quota plugin enforces real limits, not this.
#
#  ---------------------------------------------------------------------
#  INSTALL (example): nightly is plenty -- sizes don't need to be live.
#    cp contrib/cron/vimbadmin-sizes.sh /usr/local/sbin/
#    # /etc/cron.d/vimbadmin :
#    15 3 * * *  vmail  VIMBADMIN_DIR=/opt/vimbadmin /usr/local/sbin/vimbadmin-sizes.sh
#  ---------------------------------------------------------------------
set -eu

VIMBADMIN_DIR="${VIMBADMIN_DIR:-/opt/vimbadmin}"
PHP="${PHP:-php}"
VIMBTOOL="${VIMBTOOL:-${VIMBADMIN_DIR}/bin/vimbtool.php}"

VFLAG=""
[ "${VERBOSE:-0}" = "1" ] && VFLAG="-v"

if [ ! -f "${VIMBTOOL}" ]; then
    echo "vimbadmin-sizes.sh: ${VIMBTOOL} not found -- set VIMBADMIN_DIR" >&2
    exit 1
fi

exec "${PHP}" "${VIMBTOOL}" -a mailbox.cli-get-sizes ${VFLAG}
