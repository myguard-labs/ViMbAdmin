#!/bin/bash
# =============================================================================
#  ViMbAdmin — fill in the REAL on-disk (zstd-compressed) size of archive
#  backups.
#
#  WHY a host script: the compressed footprint of a /backups maildir can only
#  be read with `du` on the Dovecot filesystem. ViMbAdmin (the panel) only has
#  the doveadm HTTP API, which exposes NO recursive-size command (fs stat is
#  per-file -> a ~60s walk; quota/vsize are LOGICAL, not compressed). The
#  Dovecot container HAS the filesystem and `du` (~180ms, exact).
#
#  Split of responsibilities (each container uses only its own rightful access):
#    - `docker exec dovecot du`           -> MEASURE (dovecot owns the FS)
#    - `docker exec vimbadmin vimbtool`   -> READ list + PERSIST (owns the DB)
#  So this script needs NO database credentials.
#
#  Runs right after the queue drain (see cron.vimbadmin-queue). Only touches
#  archives whose size hasn't been measured yet -> cheap + idempotent.
# =============================================================================
set -euo pipefail

DOVECOT_CTR="${DOVECOT_CTR:-dovecot}"
VIMBADMIN_CTR="${VIMBADMIN_CTR:-vimbadmin}"
VIMBTOOL="php /opt/vimbadmin/bin/vimbtool.php"

# id<TAB>maildir_file for every archive still lacking a real on-disk size.
rows="$(docker exec "$VIMBADMIN_CTR" $VIMBTOOL -a queue.cli-list-unsized 2>/dev/null || true)"
[ -z "$rows" ] && exit 0

while IFS=$'\t' read -r id dest; do
    [ -n "$id" ] || continue
    path="${dest#*:}"                       # strip maildir:/ prefix
    case "$path" in /*) : ;; *) continue ;; esac

    bytes="$(docker exec "$DOVECOT_CTR" du -sb "$path" 2>/dev/null | cut -f1 || true)"
    case "$bytes" in
        ''|*[!0-9]*) continue ;;            # not a number -> skip (dir gone)
    esac

    docker exec "$VIMBADMIN_CTR" $VIMBTOOL -a queue.cli-set-size \
        --id="$id" --bytes="$bytes" >/dev/null 2>&1 || true
done <<< "$rows"
