<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * MailboxTask
 *
 * A queued Dovecot mailbox operation (repair / optimize / archive / delete),
 * drained serially by the queue-runner (QueueController::cliRunAction) so a
 * bulk action cannot fire hundreds of doveadm calls at once.
 *
 * Lifecycle: PENDING -> RUNNING -> DONE | FAILED   (or PENDING -> CANCELLED).
 * The PENDING -> RUNNING transition is performed under an atomic guard
 * (claim()) so two runners cannot pick up the same task.
 */
class MailboxTask
{
    // ---- task types -----------------------------------------------------
    /** force-resync + index + purge (non-destructive repair/optimize). */
    const TYPE_REPAIR  = "REPAIR";
    /** Alias kept for clarity; handled identically to REPAIR. */
    const TYPE_OPTIMIZE = "OPTIMIZE";
    /** doveadm backup, then empty the mail store. KEEPS the mailbox row. */
    const TYPE_ARCHIVE = "ARCHIVE";
    /** doveadm backup, empty the mail store, then REMOVE the mailbox row. */
    const TYPE_DELETE  = "DELETE";
    /** doveadm quota recalc only (non-destructive; refresh quota usage). */
    const TYPE_QUOTA_RECALC = "QUOTA_RECALC";

    /**
     * Measure the real on-disk (zstd-compressed) size of an archive backup via
     * the doveadm REST fs-walk and store it on the archive row. Enqueued at low
     * priority after an ARCHIVE/DELETE backup; runs in the background.
     */
    const TYPE_MEASURE_SIZE = "MEASURE_SIZE";

    /**
     * Prune ONE expired autoprune archive: remove its /backups maildir (doveadm
     * fs delete) and its archive row. Enqueued (one per expired backup) by the
     * queue runner's periodic autoprune sweep; lowest priority.
     */
    const TYPE_PRUNE = "PRUNE";

    /**
     * Back up ONE ORPHAN maildir — mail on disk with no ViMbAdmin mailbox row
     * (e.g. an account deleted in the panel but its mail left behind). The
     * runner briefly inserts a temp (inactive) mailbox row so doveadm can
     * resolve the user, repairs + backs the maildir up, records an archive row
     * (autoprune off), then removes the temp row. Enqueued by the Maintenance
     * "scan for unmanaged maildirs" action; low priority.
     */
    const TYPE_BACKUP_ORPHAN = "BACKUP_ORPHAN";

    public static $TYPES = [
        self::TYPE_REPAIR       => "Repair / optimize",
        self::TYPE_OPTIMIZE     => "Repair / optimize",
        self::TYPE_ARCHIVE      => "Archive (backup, keep account)",
        self::TYPE_DELETE       => "Delete (backup, remove account)",
        self::TYPE_QUOTA_RECALC => "Quota recalc",
    ];

    // ---- statuses -------------------------------------------------------
    const STATUS_PENDING   = "PENDING";
    const STATUS_RUNNING   = "RUNNING";
    const STATUS_DONE      = "DONE";
    const STATUS_FAILED    = "FAILED";
    const STATUS_CANCELLED = "CANCELLED";

    public static $STATUSES = [
        self::STATUS_PENDING   => "Pending",
        self::STATUS_RUNNING   => "Running",
        self::STATUS_DONE      => "Done",
        self::STATUS_FAILED    => "Failed",
        self::STATUS_CANCELLED => "Cancelled",
    ];

    /** @var integer */
    private $id;

    /** @var string */
    private $type;

    /** @var string */
    private $username;

    /** @var string */
    private $status;

    /** @var integer */
    private $priority = 0;

    /** @var \DateTime */
    private $created_at;

    /** @var \DateTime|null */
    private $started_at;

    /** @var \DateTime|null */
    private $finished_at;

    /** @var string|null */
    private $log;

    /** @var string|null */
    private $data;

    /** @var \Entities\Domain|null */
    private $Domain;

    /** @var \Entities\Admin|null */
    private $RequestedBy;

    public function getId()                 { return $this->id; }

    public function getType()               { return $this->type; }
    public function setType( $v )           { $this->type = $v; return $this; }

    public function getUsername()           { return $this->username; }
    public function setUsername( $v )       { $this->username = $v; return $this; }

    public function getStatus()             { return $this->status; }
    public function setStatus( $v )         { $this->status = $v; return $this; }

    public function getPriority()           { return $this->priority; }
    public function setPriority( $v )       { $this->priority = (int) $v; return $this; }

    public function getCreatedAt()          { return $this->created_at; }
    public function setCreatedAt( $v )      { $this->created_at = $v; return $this; }

    public function getStartedAt()          { return $this->started_at; }
    public function setStartedAt( $v )      { $this->started_at = $v; return $this; }

    public function getFinishedAt()         { return $this->finished_at; }
    public function setFinishedAt( $v )     { $this->finished_at = $v; return $this; }

    public function getLog()                { return $this->log; }
    public function setLog( $v )            { $this->log = $v; return $this; }

    /**
     * Append a timestamped line to the task log.
     *
     * @param string $line
     * @return MailboxTask
     */
    public function appendLog( $line )
    {
        $this->log = (string) $this->log . '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $line . "\n";
        return $this;
    }

    public function getData()               { return $this->data; }
    public function setData( $v )           { $this->data = $v; return $this; }

    public function getDomain()             { return $this->Domain; }
    public function setDomain( ?\Entities\Domain $v = null )     { $this->Domain = $v; return $this; }

    public function getRequestedBy()        { return $this->RequestedBy; }
    public function setRequestedBy( ?\Entities\Admin $v = null ) { $this->RequestedBy = $v; return $this; }

    /**
     * @return string Human-readable type label.
     */
    public function getTypeLabel()
    {
        return isset( self::$TYPES[ $this->type ] ) ? self::$TYPES[ $this->type ] : $this->type;
    }

    /**
     * @return string Human-readable status label.
     */
    public function getStatusLabel()
    {
        return isset( self::$STATUSES[ $this->status ] ) ? self::$STATUSES[ $this->status ] : $this->status;
    }
}
