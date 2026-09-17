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
    /** @use \OSS_Doctrine2_WithPreferences<\Entities\AliasPreference> */
    use \OSS_Doctrine2_WithPreferences;

    /** @return class-string<\Entities\AliasPreference> */
    protected function _getPreferenceEntityClassname()
    {
        return \Entities\AliasPreference::class;
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

    private function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @var \Entities\Domain|null
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
     * @return string|null
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
     * @return string|null
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
     * @return boolean|null
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

    /**
     * Set Domain
     *
     * @param \Entities\Domain|null $domain
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
     * @return \Entities\Domain|null
     */
    public function getDomain()
    {
        return $this->Domain;
    }
    /** @var \Doctrine\Common\Collections\Collection<int, \Entities\AliasPreference> */
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
    public function removePreference(\Entities\AliasPreference $preferences): void
    {
        $this->Preferences->removeElement($preferences);
    }

    /**
     * Get Preferences
     *
     * @return \Doctrine\Common\Collections\Collection<int, \Entities\AliasPreference>
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }
}
