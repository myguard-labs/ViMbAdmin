<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

require_once __DIR__ . '/PreferenceScalarAccessors.php';

/**
 * Entities\AdminPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\AdminPreference::class)]
#[ORM\Table(name: 'admin_pref')]
#[ORM\UniqueConstraint(name: 'IX_AdminPreference_1', columns: ['Admin_id', 'attribute', 'ix'])]
class AdminPreference
{
    use PreferenceScalarAccessors;

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

    protected function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @var \Entities\Admin|null
     */
    private $Preferences;


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
     * Set Preferences
     *
     * @param \Entities\Admin|null $preferences
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
     * @return \Entities\Admin|null
     */
    public function getPreferences()
    {
        return $this->Preferences;
    }
    /**
     * @var \Entities\Admin|null
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Admin::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Admin_id', referencedColumnName: 'id')]
    private ?\Entities\Admin $Admin = null;


    /**
     * Set Admin
     *
     * @param \Entities\Admin|null $admin
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
     * @return \Entities\Admin|null
     */
    public function getAdmin()
    {
        return $this->Admin;
    }
}
