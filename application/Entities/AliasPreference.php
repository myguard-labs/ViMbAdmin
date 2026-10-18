<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

require_once __DIR__ . '/PreferenceScalarAccessors.php';

/**
 * AliasPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\AliasPreference::class)]
#[ORM\Table(name: 'alias_pref')]
#[ORM\Index(name: 'IX_AliasPreference_1', columns: ['Alias_id', 'attribute', 'ix'])]
class AliasPreference
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
     * @var \Entities\Alias
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Alias::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Alias_id', referencedColumnName: 'id')]
    private ?\Entities\Alias $Alias = null;


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
     * @return \Entities\Alias|null
     */
    public function getAlias()
    {
        return $this->Alias;
    }
}
