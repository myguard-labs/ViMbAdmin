<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Archive
 */
#[ORM\Entity(repositoryClass: \Repositories\Archive::class)]
#[ORM\Table(name: 'archive')]
class Archive
{
    const STATUS_PENDING_ARCHIVE = "PENDING_ARCHIVE";
    const STATUS_ARCHIVING       = "ARCHIVING";
    const STATUS_ARCHIVED        = "ARCHIVED";
    const STATUS_PENDING_RESTORE = "PENDING_RESTORE";
    const STATUS_RESTORING       = "RESTORING";
    const STATUS_RESTORED        = "RESTORED";
    const STATUS_PENDING_DELETE  = "PENDING_DELETE";
    const STATUS_DELETING        = "DELETING";
    const STATUS_DELETED         = "DELETED";

    public static $ARCHIVE_STATUS = [
        self::STATUS_PENDING_ARCHIVE  => "Pending Archive",
        self::STATUS_ARCHIVING        => "Archiving",
        self::STATUS_ARCHIVED         => "Archived",
        self::STATUS_PENDING_RESTORE  => "Pending Restore",
        self::STATUS_RESTORING        => "Restoring",
        self::STATUS_RESTORED         => "Restored",
        self::STATUS_PENDING_DELETE   => "Pending Delete",
        self::STATUS_DELETING         => "Deleting",
        self::STATUS_DELETED          => "Deleted"
        
    ];

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', unique: true)]
    private ?string $username = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    private ?string $status = null;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $archived_at = null;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $status_changed_at = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $homedir_server = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $homedir_file = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $homedir_orig_size = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $homedir_size = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $maildir_server = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $maildir_file = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $maildir_orig_size = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $maildir_size = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'text')]
    private ?string $data = null;

    /**
     * @var boolean
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $autoprune = false;

    /**
     * @var integer
     */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * @var \Entities\Domain
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class, inversedBy: 'Archives')]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id')]
    private ?\Entities\Domain $Domain = null;

    /**
     * @var \Entities\Admin
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Admin::class, inversedBy: 'Archives')]
    #[ORM\JoinColumn(name: 'Admin_id', referencedColumnName: 'id')]
    private ?\Entities\Admin $ArchivedBy = null;


    /**
     * Set username
     *
     * @param string $username
     * @return Archive
     */
    public function setUsername($username)
    {
        $this->username = $username;
    
        return $this;
    }

    /**
     * Get username
     *
     * @return string 
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set status
     *
     * @param string $status
     * @return Archive
     */
    public function setStatus($status)
    {
        $this->status = $status;
    
        return $this;
    }

    /**
     * Get status
     *
     * @return string 
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set archived_at
     *
     * @param \DateTime $archivedAt
     * @return Archive
     */
    public function setArchivedAt($archivedAt)
    {
        $this->archived_at = $archivedAt;
    
        return $this;
    }

    /**
     * Get archived_at
     *
     * @return \DateTime 
     */
    public function getArchivedAt()
    {
        return $this->archived_at;
    }

    /**
     * Set status_changed_at
     *
     * @param \DateTime $statusChangedAt
     * @return Archive
     */
    public function setStatusChangedAt($statusChangedAt)
    {
        $this->status_changed_at = $statusChangedAt;
    
        return $this;
    }

    /**
     * Get status_changed_at
     *
     * @return \DateTime 
     */
    public function getStatusChangedAt()
    {
        return $this->status_changed_at;
    }

    /**
     * Set homedir_server
     *
     * @param string $homedirServer
     * @return Archive
     */
    public function setHomedirServer($homedirServer)
    {
        $this->homedir_server = $homedirServer;
    
        return $this;
    }

    /**
     * Get homedir_server
     *
     * @return string 
     */
    public function getHomedirServer()
    {
        return $this->homedir_server;
    }

    /**
     * Set homedir_file
     *
     * @param string $homedirFile
     * @return Archive
     */
    public function setHomedirFile($homedirFile)
    {
        $this->homedir_file = $homedirFile;
    
        return $this;
    }

    /**
     * Get homedir_file
     *
     * @return string 
     */
    public function getHomedirFile()
    {
        return $this->homedir_file;
    }

    /**
     * Set homedir_orig_size
     *
     * @param integer $homedirOrigSize
     * @return Archive
     */
    public function setHomedirOrigSize($homedirOrigSize)
    {
        $this->homedir_orig_size = $homedirOrigSize;
    
        return $this;
    }

    /**
     * Get homedir_orig_size
     *
     * @return integer 
     */
    public function getHomedirOrigSize()
    {
        return $this->homedir_orig_size;
    }

    /**
     * Set homedir_size
     *
     * @param integer $homedirSize
     * @return Archive
     */
    public function setHomedirSize($homedirSize)
    {
        $this->homedir_size = $homedirSize;
    
        return $this;
    }

    /**
     * Get homedir_size
     *
     * @return integer 
     */
    public function getHomedirSize()
    {
        return $this->homedir_size;
    }

    /**
     * Set maildir_server
     *
     * @param string $maildirServer
     * @return Archive
     */
    public function setMaildirServer($maildirServer)
    {
        $this->maildir_server = $maildirServer;
    
        return $this;
    }

    /**
     * Get maildir_server
     *
     * @return string 
     */
    public function getMaildirServer()
    {
        return $this->maildir_server;
    }

    /**
     * Set maildir_file
     *
     * @param string $maildirFile
     * @return Archive
     */
    public function setMaildirFile($maildirFile)
    {
        $this->maildir_file = $maildirFile;
    
        return $this;
    }

    /**
     * Get maildir_file
     *
     * @return string 
     */
    public function getMaildirFile()
    {
        return $this->maildir_file;
    }

    /**
     * Set maildir_orig_size
     *
     * @param integer $maildirOrigSize
     * @return Archive
     */
    public function setMaildirOrigSize($maildirOrigSize)
    {
        $this->maildir_orig_size = $maildirOrigSize;
    
        return $this;
    }

    /**
     * Get maildir_orig_size
     *
     * @return integer 
     */
    public function getMaildirOrigSize()
    {
        return $this->maildir_orig_size;
    }

    /**
     * Set maildir_size
     *
     * @param integer $maildirSize
     * @return Archive
     */
    public function setMaildirSize($maildirSize)
    {
        $this->maildir_size = $maildirSize;
    
        return $this;
    }

    /**
     * Get maildir_size
     *
     * @return integer 
     */
    public function getMaildirSize()
    {
        return $this->maildir_size;
    }

    /**
     * Set data
     *
     * @param string $data
     * @return Archive
     */
    public function setData($data)
    {
        $this->data = $data;
    
        return $this;
    }

    /**
     * Get data
     *
     * @return string 
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Set autoprune
     *
     * @param boolean $autoprune
     * @return Archive
     */
    public function setAutoprune($autoprune)
    {
        $this->autoprune = (bool) $autoprune;

        return $this;
    }

    /**
     * Get autoprune
     *
     * @return boolean
     */
    public function getAutoprune()
    {
        return (bool) $this->autoprune;
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set Domain
     *
     * @param \Entities\Domain $domain
     * @return Archive
     */
    public function setDomain(?\Entities\Domain $domain = null)
    {
        $this->Domain = $domain;
    
        return $this;
    }

    /**
     * Get Domain
     *
     * @return \Entities\Domain 
     */
    public function getDomain()
    {
        return $this->Domain;
    }

    /**
     * Set ArchivedBy
     *
     * @param \Entities\Admin $archivedBy
     * @return Archive
     */
    public function setArchivedBy(?\Entities\Admin $archivedBy = null)
    {
        $this->ArchivedBy = $archivedBy;
    
        return $this;
    }

    /**
     * Get ArchivedBy
     *
     * @return \Entities\Admin 
     */
    public function getArchivedBy()
    {
        return $this->ArchivedBy;
    }
}
