<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/** Durable ownership survives connection loss and ambiguous remote completion. */
#[ORM\Entity]
#[ORM\Table(name: 'resource_claim')]
class ResourceClaim
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $resource_key;

    #[ORM\Column(type: 'string', length: 64)]
    private string $owner_token;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[ORM\Column(type: 'string', length: 16)]
    private string $phase;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $created_at;
    public function __construct(string $key, string $token, string $username, string $phase, \DateTime $createdAt)
    {
        $this->resource_key = $key;
        $this->owner_token = $token;
        $this->username = $username;
        $this->phase = $phase;
        $this->created_at = $createdAt;
    }

    public function getResourceKey(): string
    {
        return $this->resource_key;
    }
    public function getOwnerToken(): string
    {
        return $this->owner_token;
    }
    public function getUsername(): string
    {
        return $this->username;
    }
    public function getPhase(): string
    {
        return $this->phase;
    }
    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
