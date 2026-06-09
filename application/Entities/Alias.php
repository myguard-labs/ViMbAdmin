<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Alias
 */
#[ORM\Entity(repositoryClass: \Repositories\Alias::class)]
#[ORM\Table(name: 'alias')]
#[ORM\Index(name: 'IX_Alias_active', columns: ['active'])]
#[ORM\UniqueConstraint(name: 'IX_Address_1', columns: ['address'])]
class Alias
{
    use \OSS_Doctrine2_WithPreferences;

    /**
     * @var string $address
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private ?string $address = null;

    /**
     * @var string $goto
     */
    #[ORM\Column(type: 'text')]
    private ?string $goto = null;

    /**
     * @var boolean $active
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 1])]
    private ?bool $active = null;

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
     * @var Entities\Domain
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class, inversedBy: 'Aliases')]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id')]
    private ?\Entities\Domain $Domain = null;


    /**
     * Set address
     *
     * @param string $address
     * @return Alias
     */
    public function setAddress($address)
    {
        $this->address = $address;
    
        return $this;
    }

    /**
     * Get address
     *
     * @return string 
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set goto
     *
     * @param string $goto
     * @return Alias
     */
    public function setGoto($goto)
    {
        $this->goto = $goto;
    
        return $this;
    }

    /**
     * Get goto
     *
     * @return string 
     */
    public function getGoto()
    {
        return $this->goto;
    }

    /**
     * Set active
     *
     * @param boolean $active
     * @return Alias
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
     * Set created
     *
     * @param \DateTime $created
     * @return Alias
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
     * @return Alias
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
     * Set Domain
     *
     * @param Entities\Domain $domain
     * @return Alias
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
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: \Entities\AliasPreference::class, mappedBy: 'Alias')]
    private $Preferences;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->Preferences = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * Add Preferences
     *
     * @param \Entities\AliasPreference $preferences
     * @return Alias
     */
    public function addPreference(\Entities\AliasPreference $preferences)
    {
        $this->Preferences[] = $preferences;
    
        return $this;
    }

    /**
     * Remove Preferences
     *
     * @param \Entities\AliasPreference $preferences
     */
    public function removePreference(\Entities\AliasPreference $preferences)
    {
        $this->Preferences->removeElement($preferences);
    }

    /**
     * Get Preferences
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }
}
