<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

require_once __DIR__ . '/PreferenceScalarAccessors.php';

/**
 * DomainPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\DomainPreference::class)]
#[ORM\Table(name: 'domain_pref')]
#[ORM\Index(name: 'IX_DomainPreference_1', columns: ['Domain_id', 'attribute', 'ix'])]
class DomainPreference
{
    use PreferenceScalarAccessors;

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

    protected function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @var \Entities\Domain
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Domain::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Domain_id', referencedColumnName: 'id')]
    private ?\Entities\Domain $Domain = null;


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
     * @return \Entities\Domain|null
     */
    public function getDomain()
    {
        return $this->Domain;
    }
}
