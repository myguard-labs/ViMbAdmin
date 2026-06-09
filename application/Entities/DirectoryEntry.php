<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * DirectoryEntry
 */
class DirectoryEntry
{
    /**
     * @var string
     */
    private ?string $businessCategory = null;

    /**
     * @var string
     */
    private ?string $carLicense = null;

    /**
     * @var string
     */
    private ?string $departmentNumber = null;

    /**
     * @var string
     */
    private ?string $displayName = null;

    /**
     * @var string
     */
    private ?string $employeeNumber = null;

    /**
     * @var string
     */
    private ?string $employeeType = null;

    /**
     * @var string
     */
    private ?string $homePhone = null;

    /**
     * @var string
     */
    private ?string $homePostalAddress = null;

    /**
     * @var string
     */
    private ?string $initials = null;

    /**
     * @var \stdClass
     */
    private mixed $jpegPhoto = null;

    /**
     * @var string
     */
    private ?string $labeledURI = null;

    /**
     * @var string
     */
    private ?string $mail = null;

    /**
     * @var string
     */
    private ?string $manager = null;

    /**
     * @var string
     */
    private ?string $mobile = null;

    /**
     * @var string
     */
    private ?string $o = null;

    /**
     * @var string
     */
    private ?string $pager = null;

    /**
     * @var string
     */
    private ?string $preferredLanguage = null;

    /**
     * @var string
     */
    private ?string $roomNumber = null;

    /**
     * @var string
     */
    private ?string $secretary = null;

    /**
     * @var string
     */
    private ?string $personalTitle = null;

    /**
     * @var string
     */
    private ?string $sn = null;

    /**
     * @var string
     */
    private ?string $ou = null;

    /**
     * @var string
     */
    private ?string $title = null;

    /**
     * @var string
     */
    private ?string $facsimileTelephoneNumber = null;

    /**
     * @var string
     */
    private ?string $givenName = null;

    /**
     * @var string
     */
    private ?string $telephoneNumber = null;

    /**
     * @var \DateTime
     */
    private ?\DateTime $vimb_created = null;

    /**
     * @var \DateTime
     */
    private ?\DateTime $vimb_update = null;

    /**
     * @var integer
     */
    private ?int $id = null;

    /**
     * @var \Entities\Mailbox
     */
    private ?\Entities\Mailbox $Mailbox = null;


    /**
     * Set businessCategory
     *
     * @param string $businessCategory
     * @return DirectoryEntry
     */
    public function setBusinessCategory($businessCategory)
    {
        $this->businessCategory = $businessCategory;
    
        return $this;
    }

    /**
     * Get businessCategory
     *
     * @return string 
     */
    public function getBusinessCategory()
    {
        return $this->businessCategory;
    }

    /**
     * Set carLicense
     *
     * @param string $carLicense
     * @return DirectoryEntry
     */
    public function setCarLicense($carLicense)
    {
        $this->carLicense = $carLicense;
    
        return $this;
    }

    /**
     * Get carLicense
     *
     * @return string 
     */
    public function getCarLicense()
    {
        return $this->carLicense;
    }

    /**
     * Set departmentNumber
     *
     * @param string $departmentNumber
     * @return DirectoryEntry
     */
    public function setDepartmentNumber($departmentNumber)
    {
        $this->departmentNumber = $departmentNumber;
    
        return $this;
    }

    /**
     * Get departmentNumber
     *
     * @return string 
     */
    public function getDepartmentNumber()
    {
        return $this->departmentNumber;
    }

    /**
     * Set displayName
     *
     * @param string $displayName
     * @return DirectoryEntry
     */
    public function setDisplayName($displayName)
    {
        $this->displayName = $displayName;
    
        return $this;
    }

    /**
     * Get displayName
     *
     * @return string 
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * Set employeeNumber
     *
     * @param string $employeeNumber
     * @return DirectoryEntry
     */
    public function setEmployeeNumber($employeeNumber)
    {
        $this->employeeNumber = $employeeNumber;
    
        return $this;
    }

    /**
     * Get employeeNumber
     *
     * @return string 
     */
    public function getEmployeeNumber()
    {
        return $this->employeeNumber;
    }

    /**
     * Set employeeType
     *
     * @param string $employeeType
     * @return DirectoryEntry
     */
    public function setEmployeeType($employeeType)
    {
        $this->employeeType = $employeeType;
    
        return $this;
    }

    /**
     * Get employeeType
     *
     * @return string 
     */
    public function getEmployeeType()
    {
        return $this->employeeType;
    }

    /**
     * Set homePhone
     *
     * @param string $homePhone
     * @return DirectoryEntry
     */
    public function setHomePhone($homePhone)
    {
        $this->homePhone = $homePhone;
    
        return $this;
    }

    /**
     * Get homePhone
     *
     * @return string 
     */
    public function getHomePhone()
    {
        return $this->homePhone;
    }

    /**
     * Set homePostalAddress
     *
     * @param string $homePostalAddress
     * @return DirectoryEntry
     */
    public function setHomePostalAddress($homePostalAddress)
    {
        $this->homePostalAddress = $homePostalAddress;
    
        return $this;
    }

    /**
     * Get homePostalAddress
     *
     * @return string 
     */
    public function getHomePostalAddress()
    {
        return $this->homePostalAddress;
    }

    /**
     * Set initials
     *
     * @param string $initials
     * @return DirectoryEntry
     */
    public function setInitials($initials)
    {
        $this->initials = $initials;
    
        return $this;
    }

    /**
     * Get initials
     *
     * @return string 
     */
    public function getInitials()
    {
        return $this->initials;
    }

    /**
     * Set jpegPhoto
     *
     * @param \stdClass $jpegPhoto
     * @return DirectoryEntry
     */
    public function setJpegPhoto($jpegPhoto)
    {
        $this->jpegPhoto = $jpegPhoto;
    
        return $this;
    }

    /**
     * Get jpegPhoto
     *
     * @return \stdClass 
     */
    public function getJpegPhoto()
    {
        return $this->jpegPhoto;
    }

    /**
     * Set labeledURI
     *
     * @param string $labeledURI
     * @return DirectoryEntry
     */
    public function setLabeledURI($labeledURI)
    {
        $this->labeledURI = $labeledURI;
    
        return $this;
    }

    /**
     * Get labeledURI
     *
     * @return string 
     */
    public function getLabeledURI()
    {
        return $this->labeledURI;
    }

    /**
     * Set mail
     *
     * @param string $mail
     * @return DirectoryEntry
     */
    public function setMail($mail)
    {
        $this->mail = $mail;
    
        return $this;
    }

    /**
     * Get mail
     *
     * @return string 
     */
    public function getMail()
    {
        return $this->mail;
    }

    /**
     * Set manager
     *
     * @param string $manager
     * @return DirectoryEntry
     */
    public function setManager($manager)
    {
        $this->manager = $manager;
    
        return $this;
    }

    /**
     * Get manager
     *
     * @return string 
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Set mobile
     *
     * @param string $mobile
     * @return DirectoryEntry
     */
    public function setMobile($mobile)
    {
        $this->mobile = $mobile;
    
        return $this;
    }

    /**
     * Get mobile
     *
     * @return string 
     */
    public function getMobile()
    {
        return $this->mobile;
    }

    /**
     * Set o
     *
     * @param string $o
     * @return DirectoryEntry
     */
    public function setO($o)
    {
        $this->o = $o;
    
        return $this;
    }

    /**
     * Get o
     *
     * @return string 
     */
    public function getO()
    {
        return $this->o;
    }

    /**
     * Set pager
     *
     * @param string $pager
     * @return DirectoryEntry
     */
    public function setPager($pager)
    {
        $this->pager = $pager;
    
        return $this;
    }

    /**
     * Get pager
     *
     * @return string 
     */
    public function getPager()
    {
        return $this->pager;
    }

    /**
     * Set preferredLanguage
     *
     * @param string $preferredLanguage
     * @return DirectoryEntry
     */
    public function setPreferredLanguage($preferredLanguage)
    {
        $this->preferredLanguage = $preferredLanguage;
    
        return $this;
    }

    /**
     * Get preferredLanguage
     *
     * @return string 
     */
    public function getPreferredLanguage()
    {
        return $this->preferredLanguage;
    }

    /**
     * Set roomNumber
     *
     * @param string $roomNumber
     * @return DirectoryEntry
     */
    public function setRoomNumber($roomNumber)
    {
        $this->roomNumber = $roomNumber;
    
        return $this;
    }

    /**
     * Get roomNumber
     *
     * @return string 
     */
    public function getRoomNumber()
    {
        return $this->roomNumber;
    }

    /**
     * Set secretary
     *
     * @param string $secretary
     * @return DirectoryEntry
     */
    public function setSecretary($secretary)
    {
        $this->secretary = $secretary;
    
        return $this;
    }

    /**
     * Get secretary
     *
     * @return string 
     */
    public function getSecretary()
    {
        return $this->secretary;
    }

    /**
     * Set personalTitle
     *
     * @param string $personalTitle
     * @return DirectoryEntry
     */
    public function setPersonalTitle($personalTitle)
    {
        $this->personalTitle = $personalTitle;
    
        return $this;
    }

    /**
     * Get personalTitle
     *
     * @return string 
     */
    public function getPersonalTitle()
    {
        return $this->personalTitle;
    }

    /**
     * Set sn
     *
     * @param string $sn
     * @return DirectoryEntry
     */
    public function setSn($sn)
    {
        $this->sn = $sn;
    
        return $this;
    }

    /**
     * Get sn
     *
     * @return string 
     */
    public function getSn()
    {
        return $this->sn;
    }

    /**
     * Set ou
     *
     * @param string $ou
     * @return DirectoryEntry
     */
    public function setOu($ou)
    {
        $this->ou = $ou;
    
        return $this;
    }

    /**
     * Get ou
     *
     * @return string 
     */
    public function getOu()
    {
        return $this->ou;
    }

    /**
     * Set title
     *
     * @param string $title
     * @return DirectoryEntry
     */
    public function setTitle($title)
    {
        $this->title = $title;
    
        return $this;
    }

    /**
     * Get title
     *
     * @return string 
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set facsimileTelephoneNumber
     *
     * @param string $facsimileTelephoneNumber
     * @return DirectoryEntry
     */
    public function setFacsimileTelephoneNumber($facsimileTelephoneNumber)
    {
        $this->facsimileTelephoneNumber = $facsimileTelephoneNumber;
    
        return $this;
    }

    /**
     * Get facsimileTelephoneNumber
     *
     * @return string 
     */
    public function getFacsimileTelephoneNumber()
    {
        return $this->facsimileTelephoneNumber;
    }

    /**
     * Set givenName
     *
     * @param string $givenName
     * @return DirectoryEntry
     */
    public function setGivenName($givenName)
    {
        $this->givenName = $givenName;
    
        return $this;
    }

    /**
     * Get givenName
     *
     * @return string 
     */
    public function getGivenName()
    {
        return $this->givenName;
    }

    /**
     * Set telephoneNumber
     *
     * @param string $telephoneNumber
     * @return DirectoryEntry
     */
    public function setTelephoneNumber($telephoneNumber)
    {
        $this->telephoneNumber = $telephoneNumber;
    
        return $this;
    }

    /**
     * Get telephoneNumber
     *
     * @return string 
     */
    public function getTelephoneNumber()
    {
        return $this->telephoneNumber;
    }

    /**
     * Set vimb_created
     *
     * @param \DateTime $vimbCreated
     * @return DirectoryEntry
     */
    public function setVimbCreated($vimbCreated)
    {
        $this->vimb_created = $vimbCreated;
    
        return $this;
    }

    /**
     * Get vimb_created
     *
     * @return \DateTime 
     */
    public function getVimbCreated()
    {
        return $this->vimb_created;
    }

    /**
     * Set vimb_update
     *
     * @param \DateTime $vimbUpdate
     * @return DirectoryEntry
     */
    public function setVimbUpdate($vimbUpdate)
    {
        $this->vimb_update = $vimbUpdate;
    
        return $this;
    }

    /**
     * Get vimb_update
     *
     * @return \DateTime 
     */
    public function getVimbUpdate()
    {
        return $this->vimb_update;
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
     * @param \Entities\Mailbox $mailbox
     * @return DirectoryEntry
     */
    public function setMailbox(\Entities\Mailbox $mailbox)
    {
        $this->Mailbox = $mailbox;
    
        return $this;
    }

    /**
     * Get Mailbox
     *
     * @return \Entities\Mailbox 
     */
    public function getMailbox()
    {
        return $this->Mailbox;
    }
}
