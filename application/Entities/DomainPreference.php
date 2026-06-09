<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * DomainPreference
 */
class DomainPreference
{
    /**
     * @var string
     */
    private ?string $attribute = null;

    /**
     * @var integer
     */
    private ?int $ix = null;

    /**
     * @var string
     */
    private ?string $op = null;

    /**
     * @var string
     */
    private ?string $value = null;

    /**
     * @var integer
     */
    private ?int $expire = null;

    /**
     * @var integer
     */
    private ?int $id = null;

    /**
     * @var \Entities\Domain
     */
    private ?\Entities\Domain $Domain = null;


    /**
     * Set attribute
     *
     * @param string $attribute
     * @return DomainPreference
     */
    public function setAttribute($attribute)
    {
        $this->attribute = $attribute;
    
        return $this;
    }

    /**
     * Get attribute
     *
     * @return string 
     */
    public function getAttribute()
    {
        return $this->attribute;
    }

    /**
     * Set ix
     *
     * @param integer $ix
     * @return DomainPreference
     */
    public function setIx($ix)
    {
        $this->ix = $ix;
    
        return $this;
    }

    /**
     * Get ix
     *
     * @return integer 
     */
    public function getIx()
    {
        return $this->ix;
    }

    /**
     * Set op
     *
     * @param string $op
     * @return DomainPreference
     */
    public function setOp($op)
    {
        $this->op = $op;
    
        return $this;
    }

    /**
     * Get op
     *
     * @return string 
     */
    public function getOp()
    {
        return $this->op;
    }

    /**
     * Set value
     *
     * @param string $value
     * @return DomainPreference
     */
    public function setValue($value)
    {
        $this->value = $value;
    
        return $this;
    }

    /**
     * Get value
     *
     * @return string 
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set expire
     *
     * @param integer $expire
     * @return DomainPreference
     */
    public function setExpire($expire)
    {
        $this->expire = $expire;
    
        return $this;
    }

    /**
     * Get expire
     *
     * @return integer 
     */
    public function getExpire()
    {
        return $this->expire;
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
     * @return DomainPreference
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
}
