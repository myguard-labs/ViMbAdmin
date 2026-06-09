<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\DatabaseVersion
 */
#[ORM\Entity(repositoryClass: \Repositories\DatabaseVersion::class)]
#[ORM\Table(name: 'dbversion')]
class DatabaseVersion
{
    /**
     * @var integer $version
     */
    #[ORM\Column(type: 'integer')]
    private ?int $version = null;

    /**
     * @var integer $id
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;


    /**
     * Set version
     *
     * @param integer $version
     * @return DatabaseVersion
     */
    public function setVersion($version)
    {
        $this->version = $version;
    
        return $this;
    }

    /**
     * Get version
     *
     * @return integer 
     */
    public function getVersion()
    {
        return $this->version;
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
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $applied_on = null;


    /**
     * Set name
     *
     * @param string $name
     * @return DatabaseVersion
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
     * Set applied_on
     *
     * @param \DateTime $appliedOn
     * @return DatabaseVersion
     */
    public function setAppliedOn($appliedOn)
    {
        $this->applied_on = $appliedOn;
    
        return $this;
    }

    /**
     * Get applied_on
     *
     * @return \DateTime 
     */
    public function getAppliedOn()
    {
        return $this->applied_on;
    }
}
