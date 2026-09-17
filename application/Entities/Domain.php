<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Domain
 */
#[ORM\Entity(repositoryClass: \Repositories\Domain::class)]
#[ORM\Table(name: 'domain')]
#[ORM\Index(name: 'IX_Domain_active', columns: ['active'])]
#[ORM\UniqueConstraint(name: 'IX_Domain_1', columns: ['domain'])]
class Domain
{
    /** @use \OSS_Doctrine2_WithPreferences<\Entities\DomainPreference> */
    use \OSS_Doctrine2_WithPreferences;

    /** @return class-string<\Entities\DomainPreference> */
    protected function _getPreferenceEntityClassname()
    {
        return \Entities\DomainPreference::class;
    }

    protected function _getPreferenceEntityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        $entityManager = \OSS_Runtime::entityManager();
        if (!$entityManager instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \UnexpectedValueException('Runtime entity manager does not implement Doctrine ORM EntityManagerInterface');
        }

        return $entityManager;
    }

    /**
     * @param string $attribute
     * @param bool $withIndex
     * @param bool $ignoreExpired
     * @return array<int, mixed>|false
     */
    public function getIndexedPreference($attribute, $withIndex = false, $ignoreExpired = true)
    {
        return $this->_getIndexedPreference($attribute, $withIndex, $ignoreExpired);
    }

    /**
     * @param string $attribute
     * @param int|null $index
     * @param bool $ignoreExpired
     * @return array<int|string, mixed>|false
     */
    public function getAssocPreference($attribute, $index = null, $ignoreExpired = true)
    {
        return $this->_getAssocPreference($attribute, $index, $ignoreExpired);
    }

    /**
     * @param array<int|string, mixed> $config
     * @param string $key
     * @param string $value
     * @return array<int|string, mixed>
     */
    private function _processKey($config, $key, $value)
    {
        return $this->_processPreferenceKey($config, $key, $value);
    }

    /**
     * @var string $domain
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $domain = null;

    /**
     * @var string $description
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * @var integer $quota
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $quota = null;

    /**
     * @var string $transport
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => 'virtual'])]
    private ?string $transport = null;

    /**
     * @var boolean $backupmx
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $backupmx = null;

    /**
     * @var boolean $active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 1])]
    private ?bool $active = null;

    /**
     * @var string $homedir
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $homedir = null;

    /**
     * @var string $maildir
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $maildir = null;

    /**
     * @var integer $uid
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $uid = null;

    /**
     * @var integer $gid
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $gid = null;

    /**
     * @var \DateTime $created
     */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $created = null;

    /**
     * @var \DateTime $modified
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $modified = null;

    /**
     * @var integer $id
     */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    private function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @var integer $max_quota
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $max_quota = null;

    /**
     * @var integer $max_aliases
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private ?int $max_aliases = null;

    /**
     * @var integer $max_mailboxes
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private ?int $max_mailboxes = null;

    /**
     * @var int|null $alias_count
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $alias_count = null;

    /**
     * @var int|null $mailbox_count
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $mailbox_count = null;

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection<int, \Entities\Mailbox>
     */
    #[ORM\OneToMany(targetEntity: \Entities\Mailbox::class, mappedBy: 'Domain')]
    private $Mailboxes;

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection<int, \Entities\Alias>
     */
    #[ORM\OneToMany(targetEntity: \Entities\Alias::class, mappedBy: 'Domain')]
    private $Aliases;

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection<int, \Entities\Log>
     */
    #[ORM\OneToMany(targetEntity: \Entities\Log::class, mappedBy: 'Domain')]
    private $Logs;

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection<int, \Entities\Admin>
     */
    #[ORM\ManyToMany(targetEntity: \Entities\Admin::class, mappedBy: 'Domains')]
    private $Admins;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->Mailboxes = new \Doctrine\Common\Collections\ArrayCollection();
        $this->Aliases = new \Doctrine\Common\Collections\ArrayCollection();
        $this->Logs = new \Doctrine\Common\Collections\ArrayCollection();
        $this->Admins = new \Doctrine\Common\Collections\ArrayCollection();
        $this->Preferences = new \Doctrine\Common\Collections\ArrayCollection();
        $this->Archives = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Set domain
     *
     * @param string $domain
     * @return Domain
     */
    public function setDomain($domain)
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * Get domain
     *
     * @return string|null
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /** Return the name for operations that require an initialized domain. */
    public function requiredDomainName(): string
    {
        if ($this->domain === null) {
            throw new \LogicException('Domain name cannot be null.');
        }

        return $this->domain;
    }

    /**
     * Set description
     *
     * @param string $description
     * @return Domain
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     *
     * @return string|null
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Get aliases
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\Alias>
     */
    public function getAliases()
    {
        return $this->Aliases;
    }

    /**
     * Get mailboxes
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\Mailbox>
     */
    public function getMailboxes()
    {
        return $this->Mailboxes;
    }

    /**
     * Set quota
     *
     * @param integer $quota
     * @return Domain
     */
    public function setQuota($quota)
    {
        $this->quota = $quota;

        return $this;
    }

    /**
     * Get quota
     *
     * @return integer|null
     */
    public function getQuota()
    {
        return $this->quota;
    }

    /** Return the configured quota for operations that require an initialized domain. */
    public function requiredQuota(): int
    {
        if ($this->quota === null) {
            throw new \LogicException('Domain quota cannot be null.');
        }

        return $this->quota;
    }

    /**
     * Set transport
     *
     * @param string $transport
     * @return Domain
     */
    public function setTransport($transport)
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * Get transport
     *
     * @return string|null
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * Set backupmx
     *
     * @param boolean $backupmx
     * @return Domain
     */
    public function setBackupmx($backupmx)
    {
        $this->backupmx = $backupmx;

        return $this;
    }

    /**
     * Get backupmx
     *
     * @return boolean|null
     */
    public function getBackupmx()
    {
        return $this->backupmx;
    }

    /**
     * Set active
     *
     * @param boolean $active
     * @return Domain
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active
     *
     * @return boolean|null
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set homedir
     *
     * @param string $homedir
     * @return Domain
     */
    public function setHomedir($homedir)
    {
        $this->homedir = $homedir;

        return $this;
    }

    /**
     * Get homedir
     *
     * @return string|null
     */
    public function getHomedir()
    {
        return $this->homedir;
    }

    /**
     * Set maildir
     *
     * @param string $maildir
     * @return Domain
     */
    public function setMaildir($maildir)
    {
        $this->maildir = $maildir;

        return $this;
    }

    /**
     * Get maildir
     *
     * @return string|null
     */
    public function getMaildir()
    {
        return $this->maildir;
    }

    /**
     * Set uid
     *
     * @param integer $uid
     * @return Domain
     */
    public function setUid($uid)
    {
        $this->uid = $uid;

        return $this;
    }

    /**
     * Get uid
     *
     * @return integer|null
     */
    public function getUid()
    {
        return $this->uid;
    }

    /**
     * Set gid
     *
     * @param integer $gid
     * @return Domain
     */
    public function setGid($gid)
    {
        $this->gid = $gid;

        return $this;
    }

    /**
     * Get gid
     *
     * @return integer|null
     */
    public function getGid()
    {
        return $this->gid;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Domain
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime|null
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set modified
     *
     * @param \DateTime $modified
     * @return Domain
     */
    public function setModified($modified)
    {
        $this->modified = $modified;

        return $this;
    }

    /**
     * Get modified
     *
     * @return \DateTime|null
     */
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * Get id
     *
     * @return integer|null
     */
    public function getId()
    {
        return $this->id;
    }

    /** Return the persistence identity for operations that require a stored domain. */
    public function requiredId(): int
    {
        if ($this->id === null) {
            throw new \LogicException('Domain id cannot be null.');
        }

        return $this->id;
    }

    /**
     * Add Mailboxes
     *
     * @param \Entities\Mailbox $mailboxes
     * @return Domain
     */
    public function addMailbox(\Entities\Mailbox $mailboxes)
    {
        $this->Mailboxes[] = $mailboxes;

        return $this;
    }

    /**
     * Remove Mailboxes
     *
     * @param \Entities\Mailbox $mailboxes
     * @return void
     */
    public function removeMailbox(\Entities\Mailbox $mailboxes)
    {
        $this->Mailboxes->removeElement($mailboxes);
    }

    /**
     * Add Aliases
     *
     * @param \Entities\Alias $aliases
     * @return Domain
     */
    public function addAlias(\Entities\Alias $aliases)
    {
        $this->Aliases[] = $aliases;

        return $this;
    }

    /**
     * Remove Aliases
     *
     * @param \Entities\Alias $aliases
     * @return void
     */
    public function removeAlias(\Entities\Alias $aliases)
    {
        $this->Aliases->removeElement($aliases);
    }

    /**
     * Add Logs
     *
     * @param \Entities\Log $logs
     * @return Domain
     */
    public function addLog(\Entities\Log $logs)
    {
        $this->Logs[] = $logs;

        return $this;
    }

    /**
     * Remove Logs
     *
     * @param \Entities\Log $logs
     * @return void
     */
    public function removeLog(\Entities\Log $logs)
    {
        $this->Logs->removeElement($logs);
    }

    /**
     * Get Logs
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\Log>
     */
    public function getLogs()
    {
        return $this->Logs;
    }

    /**
     * Add Admins
     *
     * @param \Entities\Admin $admins
     * @return Domain
     */
    public function addAdmin(\Entities\Admin $admins)
    {
        $this->Admins[] = $admins;

        return $this;
    }

    /**
     * Remove Admins
     *
     * @param \Entities\Admin $admins
     * @return void
     */
    public function removeAdmin(\Entities\Admin $admins)
    {
        $this->Admins->removeElement($admins);
    }

    /**
     * Get Admins
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\Admin>
     */
    public function getAdmins()
    {
        return $this->Admins;
    }



    /**
     * Set max_aliases
     *
     * @param integer $maxAliases
     * @return Domain
     */
    public function setMaxAliases($maxAliases)
    {
        $this->max_aliases = $maxAliases;

        return $this;
    }

    /**
     * Get max_aliases
     *
     * @return integer|null
     */
    public function getMaxAliases()
    {
        return $this->max_aliases;
    }

    /**
     * Set max_mailboxes
     *
     * @param integer $maxMailboxes
     * @return Domain
     */
    public function setMaxMailboxes($maxMailboxes)
    {
        $this->max_mailboxes = $maxMailboxes;

        return $this;
    }

    /**
     * Get max_mailboxes
     *
     * @return integer|null
     */
    public function getMaxMailboxes()
    {
        return $this->max_mailboxes;
    }



    /**
     * Set max_quota
     *
     * @param integer $maxQuota
     * @return Domain
     */
    public function setMaxQuota($maxQuota)
    {
        $this->max_quota = $maxQuota;

        return $this;
    }

    /**
     * Get max_quota
     *
     * @return integer|null
     */
    public function getMaxQuota()
    {
        return $this->max_quota;
    }



    /**
     * Set alias_count
     *
     * @param int|null $aliasCount
     * @return Domain
     */
    public function setAliasCount($aliasCount)
    {
        $this->alias_count = $aliasCount;
        return $this;
    }

    /**
     * Get alias_count
     *
     * @return int|null
     */
    public function getAliasCount()
    {
        return $this->alias_count;
    }

    /**
     * Increase alias_count
     *
     * @return void
     */
    public function increaseAliasCount()
    {
        $this->alias_count += 1;
    }

    /**
     * Decrease alias_count
     *
     * @return void
     */
    public function decreaseAliasCount()
    {
        if ($this->alias_count > 0) {
            $this->alias_count -= 1;
        }
    }



    /**
     * Set mailbox_count
     *
     * @param int|null $mailboxCount
     * @return Domain
     */
    public function setMailboxCount($mailboxCount)
    {
        $this->mailbox_count = $mailboxCount;
        return $this;
    }

    /**
     * Get mailbox_count
     *
     * @return int|null
     */
    public function getMailboxCount()
    {
        return $this->mailbox_count;
    }

    /**
     * Increase mailbox_count
     *
     * @return void
     */
    public function increaseMailboxCount()
    {
        $this->mailbox_count += 1;
    }

    /**
     * Decrease mailbox_count
     *
     * @return void
     */
    public function decreaseMailboxCount()
    {
        if ($this->mailbox_count > 0) {
            $this->mailbox_count -= 1;
        }
    }

    /**
     * Compatibility alias for the historical generated method name.
     *
     * @param \Entities\Mailbox $mailboxes
     * @return Domain
     * @deprecated Use addMailbox().
     */
    public function addMailboxe(\Entities\Mailbox $mailboxes)
    {
        return $this->addMailbox($mailboxes);
    }

    /**
     * Compatibility alias for the historical generated method name.
     *
     * @param \Entities\Mailbox $mailboxes
     * @return void
     * @deprecated Use removeMailbox().
     */
    public function removeMailboxe(\Entities\Mailbox $mailboxes)
    {
        $this->removeMailbox($mailboxes);
    }

    /**
     * Compatibility alias for the historical generated method name.
     *
     * @param \Entities\Alias $aliases
     * @return Domain
     * @deprecated Use addAlias().
     */
    public function addAliase(\Entities\Alias $aliases)
    {
        return $this->addAlias($aliases);
    }

    /**
     * Compatibility alias for the historical generated method name.
     *
     * @param \Entities\Alias $aliases
     * @return void
     * @deprecated Use removeAlias().
     */
    public function removeAliase(\Entities\Alias $aliases)
    {
        $this->removeAlias($aliases);
    }
    /**
     * @var \Doctrine\Common\Collections\Collection<int, \Entities\DomainPreference>
     */
    #[ORM\OneToMany(targetEntity: \Entities\DomainPreference::class, mappedBy: 'Domain')]
    private $Preferences;


    /**
     * Add Preferences
     *
     * @param \Entities\DomainPreference $preferences
     * @return Domain
     */
    public function addPreference(\Entities\DomainPreference $preferences)
    {
        $this->Preferences[] = $preferences;

        return $this;
    }

    /**
     * Remove Preferences
     *
     * @param \Entities\DomainPreference $preferences
     * @return void
     */
    public function removePreference(\Entities\DomainPreference $preferences)
    {
        $this->Preferences->removeElement($preferences);
    }

    /**
     * Get Preferences
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\DomainPreference>
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection<int, \Entities\Archive>
     */
    #[ORM\OneToMany(targetEntity: \Entities\Archive::class, mappedBy: 'Domain')]
    private $Archives;


    /**
     * Add Archives
     *
     * @param \Entities\Archive $archives
     * @return Domain
     */
    public function addArchive(\Entities\Archive $archives)
    {
        $this->Archives[] = $archives;

        return $this;
    }

    /**
     * Remove Archives
     *
     * @param \Entities\Archive $archives
     * @return void
     */
    public function removeArchive(\Entities\Archive $archives)
    {
        $this->Archives->removeElement($archives);
    }

    /**
     * Get Archives
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\Archive>
     */
    public function getArchives()
    {
        return $this->Archives;
    }
}
