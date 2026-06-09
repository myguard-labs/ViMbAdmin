<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\DatabaseVersion
 */
class DatabaseVersion
{
    /**
     * @var integer $version
     */
    private ?int $version = null;

    /**
     * @var integer $id
     */
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
    private ?string $name = null;

    /**
     * @var \DateTime
     */
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
