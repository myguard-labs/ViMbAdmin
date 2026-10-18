<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

require_once __DIR__ . '/PreferenceScalarAccessors.php';

/**
 * Entities\MailboxPreference
 */
#[ORM\Entity(repositoryClass: \Repositories\MailboxPreference::class)]
#[ORM\Table(name: 'mailbox_pref')]
#[ORM\Index(name: 'IX_MailboxPreference_1', columns: ['Mailbox_id', 'attribute', 'ix'])]
class MailboxPreference
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
     * @var \Entities\Mailbox|null
     */
    #[ORM\ManyToOne(targetEntity: \Entities\Mailbox::class, inversedBy: 'Preferences')]
    #[ORM\JoinColumn(name: 'Mailbox_id', referencedColumnName: 'id')]
    private ?\Entities\Mailbox $Mailbox = null;


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
     * Set Mailbox
     *
     * @param \Entities\Mailbox|null $mailbox
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
     * @return \Entities\Mailbox|null
     */
    public function getMailbox()
    {
        return $this->Mailbox;
    }
}
