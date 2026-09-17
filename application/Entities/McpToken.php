<?php

namespace Entities;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\McpToken
 *
 * An API token for the MCP adapter. Only the SHA-256 *hash* of the token is
 * stored -- the raw token is shown once at generation and never persisted.
 * Tokens are scoped, can be IP/CIDR restricted, expired and revoked.
 */
#[ORM\Entity(repositoryClass: \Repositories\McpToken::class)]
#[ORM\Table(name: 'mcp_token')]
#[ORM\Index(name: 'mcp_token_hash_idx', columns: ['token_hash'])]
class McpToken
{
    /** @var integer */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    protected function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /** @var string  Human label for the token (e.g. "agent1"). */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    /** @var string  hex sha256 of the raw token. */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private ?string $token_hash = null;

    /** @var string  space/comma separated scopes (e.g. "read" or "read write"). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $scope = 'read';

    /** @var string|null  space/comma separated IP/CIDR allowlist; null = any (rely on the edge). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowed_ips = null;

    /** @var string|null  space/comma separated domain allowlist; null = all domains. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allowed_domains = null;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $created = null;

    /** @var \DateTime|null */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $expires_at = null;

    /** @var \DateTime|null */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $last_used_at = null;

    /** @var boolean */
    #[ORM\Column(type: 'boolean')]
    private bool $revoked = false;

    /** @return int|null */
    public function getId()
    {
        return $this->id;
    }

    /** @return string|null */
    public function getName()
    {
        return $this->name;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setName($v)
    {
        $this->name = $v;
        return $this;
    }

    /** @return string|null */
    public function getTokenHash()
    {
        return $this->token_hash;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setTokenHash($v)
    {
        $this->token_hash = $v;
        return $this;
    }

    /** @return string */
    public function getScope()
    {
        return $this->scope;
    }
    /**
     * @param string $v
     * @return $this
     */
    public function setScope($v)
    {
        $this->scope = $v;
        return $this;
    }

    /** @return string|null */
    public function getAllowedIps()
    {
        return $this->allowed_ips;
    }
    /**
     * @param string|null $v
     * @return $this
     */
    public function setAllowedIps($v)
    {
        $this->allowed_ips = ($v === '' ? null : $v);
        return $this;
    }

    /** @return string|null */
    public function getAllowedDomains()
    {
        return $this->allowed_domains;
    }
    /**
     * @param string|null $v
     * @return $this
     */
    public function setAllowedDomains($v)
    {
        $this->allowed_domains = ($v === '' ? null : $v);
        return $this;
    }

    /**
     * May this token operate on $domain? Empty/null allowlist => all domains.
     * Matching is case-insensitive exact (no wildcards).
     */
    /**
     * @param string $domain
     * @return bool
     */
    public function allowsDomain($domain)
    {
        $list = trim((string) $this->allowed_domains);
        if ($list === '') {
            return true;
        }
        $domain = \ViMbAdmin_Identity::canonical((string) $domain);
        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            if (\ViMbAdmin_Identity::canonical($d) === $domain) {
                return true;
            }
        }
        return false;
    }

    /** @return \DateTime|null */
    public function getCreated()
    {
        return $this->created;
    }
    /**
     * @param \DateTime $v
     * @return $this
     */
    public function setCreated($v)
    {
        $this->created = $v;
        return $this;
    }

    /** @return \DateTime|null */
    public function getExpiresAt()
    {
        return $this->expires_at;
    }
    /**
     * @param \DateTime|null $v
     * @return $this
     */
    public function setExpiresAt($v)
    {
        $this->expires_at = $v;
        return $this;
    }

    /** @return \DateTime|null */
    public function getLastUsedAt()
    {
        return $this->last_used_at;
    }
    /**
     * @param \DateTime|null $v
     * @return $this
     */
    public function setLastUsedAt($v)
    {
        $this->last_used_at = $v;
        return $this;
    }

    /** @return bool */
    public function getRevoked()
    {
        return (bool) $this->revoked;
    }
    /**
     * @param bool $v
     * @return $this
     */
    public function setRevoked($v)
    {
        $this->revoked = (bool) $v;
        return $this;
    }

    /**
     * A scope string contains "*" or the requested scope token.
     */
    /**
     * @param string $want
     * @return bool
     */
    public function hasScope($want)
    {
        $have = preg_split('/[\s,]+/', (string) $this->scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array('*', $have, true) || in_array($want, $have, true);
    }

    /**
     * True if the token is usable right now (not revoked, not expired).
     */
    /** @return bool */
    public function isActive(?\DateTime $now = null)
    {
        if ($this->revoked) {
            return false;
        }
        if ($this->expires_at !== null) {
            $now = $now ?: new DateTime();
            if ($this->expires_at < $now) {
                return false;
            }
        }
        return true;
    }
}
