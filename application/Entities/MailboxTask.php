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
#[ORM\Entity(repositoryClass: \Repositories\MailboxTask::class)]
#[ORM\Table(name: 'mailbox_task')]
#[ORM\Index(name: 'mailbox_task_status_idx', columns: ['status'])]
#[ORM\Index(name: 'mailbox_task_username_type_status_idx', columns: ['username', 'type', 'status'])]
#[ORM\UniqueConstraint(name: 'mailbox_task_open_unique', columns: ['username', 'type', 'open_task'])]
class MailboxTask
{
    // ---- task types -----------------------------------------------------
    /** force-resync + index + purge (non-destructive repair/optimize). */
    public const TYPE_REPAIR  = "REPAIR";
    /** Alias kept for clarity; handled identically to REPAIR. */
    public const TYPE_OPTIMIZE = "OPTIMIZE";
    /** doveadm backup, then empty the mail store. KEEPS the mailbox row. */
    public const TYPE_ARCHIVE = "ARCHIVE";
    /** doveadm backup, empty the mail store, then REMOVE the mailbox row. */
    public const TYPE_DELETE  = "DELETE";
    /** doveadm quota recalc only (non-destructive; refresh quota usage). */
    public const TYPE_QUOTA_RECALC = "QUOTA_RECALC";

    /**
     * Measure the real on-disk (zstd-compressed) size of an archive backup via
     * the doveadm REST fs-walk and store it on the archive row. Enqueued at low
     * priority after an ARCHIVE/DELETE backup; runs in the background.
     */
    public const TYPE_MEASURE_SIZE = "MEASURE_SIZE";

    /**
     * Prune ONE expired autoprune archive: remove its /backups maildir (doveadm
     * fs delete) and its archive row. Enqueued (one per expired backup) by the
     * queue runner's periodic autoprune sweep; lowest priority.
     */
    public const TYPE_PRUNE = "PRUNE";

    /**
     * Back up ONE ORPHAN maildir — mail on disk with no ViMbAdmin mailbox row
     * (e.g. an account deleted in the panel but its mail left behind). The
     * runner briefly inserts a temp (inactive) mailbox row so doveadm can
     * resolve the user, repairs + backs the maildir up, records an archive row
     * (autoprune off), then removes the temp row. Enqueued by the Maintenance
     * "scan for unmanaged maildirs" action; low priority.
     */
    public const TYPE_BACKUP_ORPHAN = "BACKUP_ORPHAN";
    /** Discover unmanaged maildirs for later UI confirmation. */
    public const TYPE_SCAN_ORPHANS = "SCAN_ORPHANS";

    /** @var array<string, string> */
    public static $TYPES = [
        self::TYPE_REPAIR       => "Repair / optimize",
        self::TYPE_OPTIMIZE     => "Repair / optimize",
        self::TYPE_ARCHIVE      => "Archive (backup, keep account)",
        self::TYPE_DELETE       => "Delete (backup, remove account)",
        self::TYPE_QUOTA_RECALC => "Quota recalc",
        self::TYPE_SCAN_ORPHANS => "Scan unmanaged maildirs",
    ];

    // ---- statuses -------------------------------------------------------
    public const STATUS_PENDING   = "PENDING";
    public const STATUS_RUNNING   = "RUNNING";
    public const STATUS_DONE      = "DONE";
    public const STATUS_FAILED    = "FAILED";
    public const STATUS_CANCELLED = "CANCELLED";

    /** Statuses represented by a non-null generated open_task marker. */
    public const OPEN_STATUSES = [ self::STATUS_PENDING, self::STATUS_RUNNING ];

    /** @var array<string, string> */
    public static $STATUSES = [
        self::STATUS_PENDING   => "Pending",
        self::STATUS_RUNNING   => "Running",
        self::STATUS_DONE      => "Done",
        self::STATUS_FAILED    => "Failed",
        self::STATUS_CANCELLED => "Cancelled",
    ];

    /** @var integer */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    protected function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /** @var string */
    #[ORM\Column(type: 'string', length: 32)]
    private ?string $type = null;

    /** @var string */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $username = null;

    /** @var string */
    #[ORM\Column(type: 'string', length: 32)]
    private ?string $status = null;

    /**
     * Nullable generated discriminator for the open-task unique constraint.
     * MariaDB permits multiple NULLs in a unique index, so terminal rows keep
     * their full history while PENDING/RUNNING rows conflict per username/type.
     */
    #[ORM\Column(
        name: 'open_task',
        type: 'boolean',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "TINYINT(1) GENERATED ALWAYS AS (IF(`status` IN ('PENDING', 'RUNNING') OR `abandoned` = 1, 1, NULL)) STORED",
        generated: 'ALWAYS',
    )]
    private ?bool $open_task = null;

    /** Failed after its owner lease disappeared; blocks automatic retry/dedupe. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $abandoned = false;

    /** @var integer */
    #[ORM\Column(type: 'integer')]
    private int $priority = 0;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $created_at = null;

    /** @var \DateTime|null */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $started_at = null;

    /** @var \DateTime|null */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $finished_at = null;

    /** @var string|null */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $log = null;

    /** @var string|null */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $data = null;

    /** @var \Entities\Domain|null */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class)]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\Entities\Domain $Domain = null;

    /** @var \Entities\Admin|null */
    #[ORM\ManyToOne(targetEntity: \Entities\Admin::class)]
    #[ORM\JoinColumn(name: 'Admin_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\Entities\Admin $RequestedBy = null;

    /** The runner lease that owns this task while it is RUNNING. */
    #[ORM\ManyToOne(targetEntity: \Entities\QueueRunner::class)]
    #[ORM\JoinColumn(name: 'QueueRunner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\Entities\QueueRunner $Runner = null;

    /** @return int|null */
    public function getId()
    {
        return $this->id;
    }

    /** @return string|null */
    public function getType()
    {
        return $this->type;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setType($v)
    {
        $this->type = $v;
        return $this;
    }

    /** @return string|null */
    public function getUsername()
    {
        return $this->username;
    }
    public function requiredUsername(): string
    {
        if ($this->username === null) {
            throw new \LogicException('Mailbox task username cannot be null.');
        }

        return $this->username;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setUsername($v)
    {
        $this->username = $v;
        return $this;
    }

    /** @return string|null */
    public function getStatus()
    {
        return $this->status;
    }
    /** @return bool */
    public function isOpen()
    {
        return $this->open_task === true;
    }
    /** @return bool */
    public function isAbandoned()
    {
        return $this->abandoned;
    }
    /** @return $this */
    public function setAbandoned(bool $v)
    {
        $this->abandoned = $v;
        $this->open_task = $this->abandoned || in_array($this->status, self::OPEN_STATUSES, true) ? true : null;
        return $this;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setStatus($v)
    {
        $this->status = $v;
        $this->open_task = $this->abandoned || in_array($v, self::OPEN_STATUSES, true) ? true : null;
        return $this;
    }

    /** @return int */
    public function getPriority()
    {
        return $this->priority;
    }
    /**
     * @param int $v
     * @return $this
     */
    public function setPriority($v)
    {
        $this->priority = (int) $v;
        return $this;
    }

    /** @return \DateTime|null */
    public function getCreatedAt()
    {
        return $this->created_at;
    }
    /**
     * @param \DateTime $v
     * @return $this
     */
    public function setCreatedAt($v)
    {
        $this->created_at = $v;
        return $this;
    }

    /** @return \DateTime|null */
    public function getStartedAt()
    {
        return $this->started_at;
    }
    /**
     * @param \DateTime|null $v
     * @return $this
     */
    public function setStartedAt($v)
    {
        $this->started_at = $v;
        return $this;
    }

    /** @return \DateTime|null */
    public function getFinishedAt()
    {
        return $this->finished_at;
    }
    /**
     * @param \DateTime|null $v
     * @return $this
     */
    public function setFinishedAt($v)
    {
        $this->finished_at = $v;
        return $this;
    }

    /** @return string|null */
    public function getLog()
    {
        return $this->log;
    }
    /**
     * @param string|null $v
     * @return $this
     */
    public function setLog($v)
    {
        $this->log = $v;
        return $this;
    }

    /**
     * Append a timestamped line to the task log.
     *
     * @param string $line
     * @return MailboxTask
     */
    public function appendLog($line)
    {
        $this->log = (string) $this->log . '[' . gmdate('Y-m-d H:i:s') . '] ' . $line . "\n";
        return $this;
    }

    /** @return string|null */
    public function getData()
    {
        return $this->data;
    }
    /**
     * @param string|null $v
     * @return $this
     */
    public function setData($v)
    {
        $this->data = $v;
        return $this;
    }

    /** @return \Entities\Domain|null */
    public function getDomain()
    {
        return $this->Domain;
    }
    /** @return $this */
    public function setDomain(?\Entities\Domain $v = null)
    {
        $this->Domain = $v;
        return $this;
    }

    /** @return \Entities\Admin|null */
    public function getRequestedBy()
    {
        return $this->RequestedBy;
    }
    /** @return $this */
    public function setRequestedBy(?\Entities\Admin $v = null)
    {
        $this->RequestedBy = $v;
        return $this;
    }

    /** @return \Entities\QueueRunner|null */
    public function getRunner()
    {
        return $this->Runner;
    }
    /** @return $this */
    public function setRunner(?\Entities\QueueRunner $v = null)
    {
        $this->Runner = $v;
        return $this;
    }

    /**
     * @return string|null Human-readable type label, or null before hydration.
     */
    public function getTypeLabel()
    {
        return isset(self::$TYPES[ $this->type ]) ? self::$TYPES[ $this->type ] : $this->type;
    }

    /**
     * @return string|null Human-readable status label, or null before hydration.
     */
    public function getStatusLabel()
    {
        return isset(self::$STATUSES[ $this->status ]) ? self::$STATUSES[ $this->status ] : $this->status;
    }
}
