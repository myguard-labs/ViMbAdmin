<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\AdminPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\AdminPreference::class)]
#[ORM\Table(name: 'admin_pref')]
#[ORM\UniqueConstraint(name: 'IX_AdminPreference_1', columns: ['Admin_id', 'attribute', 'ix'])]
class AdminPreference
{
    /**
     * @var string $attribute
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $attribute = null;

    /**
     * @var integer $ix
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private ?int $ix = null;

    /**
     * @var string $op
     */
    #[ORM\Column(type: 'string', length: 2, options: ['default' => ':='])]
    private ?string $op = null;

    /**
     * @var string $value
     */
    #[ORM\Column(type: 'text')]
    private ?string $value = null;

    /**
     * @var integer $expire
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private ?int $expire = null;

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
    private $Preferences;


    /**
     * Set attribute
     *
     * @param string $attribute
     * @return AdminPreference
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
     * @return AdminPreference
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
     * @return AdminPreference
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
     * @return AdminPreference
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
     * @return AdminPreference
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
     * Set Preferences
     *
     * @param Entities\Admin $preferences
     * @return AdminPreference
     */
    public function setPreferences(?\Entities\Admin $preferences = null)
    {
        $this->Preferences = $preferences;
    
        return $this;
    }

    /**
     * Get Preferences
     *
     * @return Entities\Admin 
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }
    /**
     * @var Entities\Admin
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Admin::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Admin_id', referencedColumnName: 'id')]
    private ?\Entities\Admin $Admin = null;


    /**
     * Set Admin
     *
     * @param Entities\Admin $admin
     * @return AdminPreference
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
}
