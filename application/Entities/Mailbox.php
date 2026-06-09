<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Mailbox
 */
#[ORM\Entity(repositoryClass: \Repositories\Mailbox::class)]
#[ORM\Table(name: 'mailbox')]
#[ORM\Index(name: 'IX_Mailbox_active', columns: ['active'])]
#[ORM\UniqueConstraint(name: 'IX_Username_mailbox', columns: ['username'])]
class Mailbox
{
    use \OSS_Doctrine2_WithPreferences;

    /**
     * @var string $username
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $username = null;

    /**
     * @var string $password
     */
    #[ORM\Column(type: 'string')]
    private ?string $password = null;

    /**
     * @var string $name
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    /**
     * @var string $alt_email
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $alt_email = null;

    /**
     * @var integer $quota
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $quota = null;

    /**
     * @var string $local_part
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $local_part = null;

    /**
     * @var boolean $active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $active = null;

    /**
     * @var string $access_restriction
     */
    #[ORM\Column(type: 'string', length: 100, options: ['default' => 'ALL'])]
    private string $access_restriction = 'ALL';

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

    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    #[ORM\OneToMany(targetEntity: \Entities\MailboxPreference::class, mappedBy: 'Mailbox')]
    private $Preferences;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->Preferences = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Set username
     *
     * @param string $username
     * @return Mailbox
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
     * Set password
     *
     * @param string $password
     * @return Mailbox
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set name
     *
     * @param string $name
     * @return Mailbox
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set alt_email
     *
     * @param string $altEmail
     * @return Mailbox
     */
    public function setAltEmail($altEmail)
    {
        $this->alt_email = $altEmail;

        return $this;
    }

    /**
     * Get alt_email
     *
     * @return string
     */
    public function getAltEmail()
    {
        return $this->alt_email;
    }

    /**
     * Set quota
     *
     * @param integer $quota
     * @return Mailbox
     */
    public function setQuota($quota)
    {
        $this->quota = $quota;

        return $this;
    }

    /**
     * Get quota
     *
     * @return integer
     */
    public function getQuota()
    {
        return $this->quota;
    }

    /**
     * Set local_part
     *
     * @param string $localPart
     * @return Mailbox
     */
    public function setLocalPart($localPart)
    {
        $this->local_part = $localPart;

        return $this;
    }

    /**
     * Get local_part
     *
     * @return string
     */
    public function getLocalPart()
    {
        return $this->local_part;
    }

    /**
     * Set active
     *
     * @param boolean $active
     * @return Mailbox
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active
     *
     * @return boolean
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set access_restriction
     *
     * @param string $accessRestriction
     * @return Mailbox
     */
    public function setAccessRestriction($accessRestriction)
    {
        $this->access_restriction = $accessRestriction;

        return $this;
    }

    /**
     * Get access_restriction
     *
     * @return string
     */
    public function getAccessRestriction()
    {
        return $this->access_restriction;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Mailbox
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set modified
     *
     * @param \DateTime $modified
     * @return Mailbox
     */
    public function setModified($modified)
    {
        $this->modified = $modified;

        return $this;
    }

    /**
     * Get modified
     *
     * @return \DateTime
     */
    public function getModified()
    {
        return $this->modified;
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
     * Add Preferences
     *
     * @param Entities\MailboxPreference $preferences
     * @return Mailbox
     */
    public function addPreference(\Entities\MailboxPreference $preferences)
    {
        $this->Preferences[] = $preferences;

        return $this;
    }

    /**
     * Remove Preferences
     *
     * @param Entities\MailboxPreference $preferences
     */
    public function removePreference(\Entities\MailboxPreference $preferences)
    {
        $this->Preferences->removeElement($preferences);
    }

    /**
     * Get Preferences
     *
     * @return Doctrine\Common\Collections\Collection
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }

    /**
     * @var Entities\Domain
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class, inversedBy: 'Mailboxes')]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id')]
    private ?\Entities\Domain $Domain = null;


    /**
     * Set Domain
     *
     * @param Entities\Domain $domain
     * @return Mailbox
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

    /**
     * Add Preferences
     *
     * @param Entities\MailboxPreference $preferences
     * @return Mailbox
     */
    public function addMailboxPreference(\Entities\MailboxPreference $preferences)
    {
        $this->Preferences[] = $preferences;
        return $this;
    }

    /**
     * Replaces the following characters in the $str parameter:
     *
     * %u - the local part of the username (email address)
     * %d - the domain part of the username (email address)
     * %m - the username (email address)
     *
     * @param string $email An email address used to extract the domain name
     * @param string $str The format string
     * @return string The newly created maildir (also set in the object)
     */
    public static function substitute( $email, $str )
    {
        list( $un, $dn ) = explode( '@', $email );

        $str = str_replace ( '%atmail', substr( $email, 0, 1 ) . '/' . substr( $email, 1, 1 ) . '/' . $email, $str );
        $str = str_replace ( '%u',      $un,    $str );
        $str = str_replace ( '%d',      $dn,    $str );
        $str = str_replace ( '%m',      $email, $str );

        return $str;
    }

    /**
     * @var \Entities\DirectoryEntry
     */
    #[ORM\OneToOne(targetEntity: \Entities\DirectoryEntry::class, mappedBy: 'Mailbox')]
    private ?\Entities\DirectoryEntry $DirectoryEntry = null;


    /**
     * Set DirectoryEntry
     *
     * @param \Entities\DirectoryEntry $directoryEntry
     * @return Mailbox
     */
    public function setDirectoryEntry(?\Entities\DirectoryEntry $directoryEntry = null)
    {
        $this->DirectoryEntry = $directoryEntry;

        return $this;
    }

    /**
     * Get DirectoryEntry
     *
     * @return \Entities\DirectoryEntry
     */
    public function getDirectoryEntry()
    {
        return $this->DirectoryEntry;
    }
    /**
     * @var boolean
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => 0])]
    private ?bool $delete_pending = null;


    /**
     * Set delete_pending
     *
     * @param boolean $deletePending
     *
     * @return Mailbox
     */
    public function setDeletePending($deletePending)
    {
        $this->delete_pending = $deletePending;

        return $this;
    }

    /**
     * Get delete_pending
     *
     * @return boolean
     */
    public function getDeletePending()
    {
        return $this->delete_pending;
    }
}
