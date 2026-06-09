<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * AliasPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\AliasPreference::class)]
#[ORM\Table(name: 'alias_pref')]
#[ORM\Index(name: 'IX_AliasPreference_1', columns: ['Alias_id', 'attribute', 'ix'])]
class AliasPreference
{
    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $attribute = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private ?int $ix = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 2, options: ['default' => ':='])]
    private ?string $op = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'text')]
    private ?string $value = null;

    /**
     * @var integer
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $expire = null;

    /**
     * @var integer
     */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * @var \Entities\Alias
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Alias::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Alias_id', referencedColumnName: 'id')]
    private ?\Entities\Alias $Alias = null;


    /**
     * Set attribute
     *
     * @param string $attribute
     * @return AliasPreference
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
     * @return AliasPreference
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
     * @return AliasPreference
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
     * @return AliasPreference
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
     * @return AliasPreference
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
     * Set Alias
     *
     * @param \Entities\Alias $alias
     * @return AliasPreference
     */
    public function setAlias(?\Entities\Alias $alias = null)
    {
        $this->Alias = $alias;
    
        return $this;
    }

    /**
     * Get Alias
     *
     * @return \Entities\Alias 
     */
    public function getAlias()
    {
        return $this->Alias;
    }
}
