<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\MailboxPreference
 */
class MailboxPreference
{
    /**
     * @var string $attribute
     */
    private ?string $attribute = null;

    /**
     * @var integer $ix
     */
    private ?int $ix = null;

    /**
     * @var string $op
     */
    private ?string $op = null;

    /**
     * @var string $value
     */
    private ?string $value = null;

    /**
     * @var integer $expire
     */
    private ?int $expire = null;

    /**
     * @var integer $id
     */
    private ?int $id = null;

    /**
     * @var Entities\Mailbox
     */
    private ?\Entities\Mailbox $Mailbox = null;


    /**
     * Set attribute
     *
     * @param string $attribute
     * @return MailboxPreference
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
     * @return MailboxPreference
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
     * @return MailboxPreference
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
     * @return MailboxPreference
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
     * @return MailboxPreference
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
     * Set Mailbox
     *
     * @param Entities\Mailbox $mailbox
     * @return MailboxPreference
     */
    public function setMailbox(?\Entities\Mailbox $mailbox = null)
    {
        $this->Mailbox = $mailbox;
    
        return $this;
    }

    /**
     * Get Mailbox
     *
     * @return Entities\Mailbox 
     */
    public function getMailbox()
    {
        return $this->Mailbox;
    }
}
