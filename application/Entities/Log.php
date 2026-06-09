<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Log
 */
#[ORM\Entity(repositoryClass: \Repositories\Log::class)]
#[ORM\Table(name: 'log')]
#[ORM\Index(name: 'IX_Log_timestamp', columns: ['timestamp'])]
class Log
{
    const ACTION_ARCHIVE_REQUEST      = 'ARCHIVE_REQUEST';
    const ACTION_ARCHIVE_RESTORE      = 'ARCHIVE_RESTORE';
    const ACTION_ARCHIVE_REQUEST_CANCEL = 'ARCHIVE_REQUEST_CANCEL';
    const ACTION_ARCHIVE_RESTORE_CANCEL = 'ARCHIVE_RESTORE_CANCEL';
    const ACTION_ARCHIVE_DELETE_CANCEL = 'ARCHIVE_DELETE_CANCEL';
    const ACTION_DOMAIN_ADD           = 'DOMAIN_ADD';
    const ACTION_DOMAIN_EDIT          = 'DOMAIN_EDIT';
    const ACTION_DOMAIN_ACTIVATE      = 'DOMAIN_ACTIVATE';
    const ACTION_DOMAIN_DEACTIVATE    = 'DOMAIN_DEACTIVATE';
    const ACTION_MAILBOX_ADD          = 'MAILBOX_ADD';
    const ACTION_MAILBOX_EDIT         = 'MAILBOX_EDIT';
    const ACTION_MAILBOX_ACTIVATE     = 'MAILBOX_ACTIVATE';
    const ACTION_MAILBOX_DEACTIVATE   = 'MAILBOX_DEACTIVATE';
    const ACTION_MAILBOX_PURGE        = 'MAILBOX_PURGE';
    const ACTION_MAILBOX_PW_CHANGE    = 'MAILBOX_PW_CHANGE';
    const ACTION_ALIAS_ADD            = 'ALIAS_ADD';
    const ACTION_ALIAS_EDIT           = 'ALIAS_EDIT';
    const ACTION_ALIAS_ACTIVATE       = 'ALIAS_ACTIVATE';
    const ACTION_ALIAS_DEACTIVATE     = 'ALIAS_DEACTIVATE';
    const ACTION_ALIAS_DELETE         = 'ALIAS_DELETE';
    const ACTION_ADMIN_ADD            = 'ADMIN_ADD';
    const ACTION_ADMIN_ACTIVATE       = 'ADMIN_ACTIVE';
    const ACTION_ADMIN_DEACTIVATE     = 'ADMIN_DEACTIVE';
    const ACTION_ADMIN_SUPER          = 'ADMIN_SUPER';
    const ACTION_ADMIN_NORMAL         = 'ADMIN_NORMAL';
    const ACTION_ADMIN_PURGE          = 'ADMIN_PURGE';
    const ACTION_ADMIN_PW_CHANGE      = 'ADMIN_PW_CHANGE';
    const ACTION_ADMIN_TO_DOMAIN_ADD  = 'ADMIN_TO_DOMAIN_ADD';
    const ACTION_ADMIN_TO_DOMAIN_REMOVE  = 'ADMIN_TO_DOMAIN_REMOVE';
    const ACTION_MAINTENANCE          = 'MAINTENANCE';
    /**
     * @var string $action
     */
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $action = null;

    /**
     * @var string $data
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $data = null;

    /**
     * @var \DateTime $timestamp
     */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $timestamp = null;

    /**
     * @var integer $id
     */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * @var Entities\Admin
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Admin::class, inversedBy: 'Logs')]
    #[ORM\JoinColumn(name: 'Admin_id', referencedColumnName: 'id')]
    private ?\Entities\Admin $Admin = null;

    /**
     * @var Entities\Domain
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class, inversedBy: 'Logs')]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id')]
    private ?\Entities\Domain $Domain = null;


    /**
     * Set action
     *
     * @param string $action
     * @return Log
     */
    public function setAction($action)
    {
        $this->action = $action;
    
        return $this;
    }

    /**
     * Get action
     *
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Set data
     *
     * @param string $data
     * @return Log
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
     * Set timestamp
     *
     * @param \DateTime $timestamp
     * @return Log
     */
    public function setTimestamp($timestamp)
    {
        $this->timestamp = $timestamp;
    
        return $this;
    }

    /**
     * Get timestamp
     *
     * @return \DateTime
     */
    public function getTimestamp()
    {
        return $this->timestamp;
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
     * Set Admin
     *
     * @param Entities\Admin $admin
     * @return Log
     */
    public function setAdmin(?\Entities\Admin $admin = null)
    {
        $this->Admin = $admin;
    
        return $this;
    }

    /**
     * Get Admin
     *
     * @return Entities\Admin
     */
    public function getAdmin()
    {
        return $this->Admin;
    }

    /**
     * Set Domain
     *
     * @param Entities\Domain $domain
     * @return Log
     */
    public function setDomain(?\Entities\Domain $domain = null)
    {
        $this->Domain = $domain;
    
        return $this;
    }

    /**
     * Get Domain
     *
     * @return Entities\Domain
     */
    public function getDomain()
    {
        return $this->Domain;
    }
}
