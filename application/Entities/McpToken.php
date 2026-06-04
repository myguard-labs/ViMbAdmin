<?php

namespace Entities;

/**
 * Entities\McpToken
 *
 * An API token for the MCP adapter. Only the SHA-256 *hash* of the token is
 * stored -- the raw token is shown once at generation and never persisted.
 * Tokens are scoped, can be IP/CIDR restricted, expired and revoked.
 */
class McpToken
{
    /** @var integer */
    private $id;

    /** @var string  Human label for the token (e.g. "agent1"). */
    private $name;

    /** @var string  hex sha256 of the raw token. */
    private $token_hash;

    /** @var string  space/comma separated scopes (e.g. "read" or "read write"). */
    private $scope = 'read';

    /** @var string|null  space/comma separated IP/CIDR allowlist; null = any (rely on the edge). */
    private $allowed_ips;

    /** @var string|null  space/comma separated domain allowlist; null = all domains. */
    private $allowed_domains;

    /** @var \DateTime */
    private $created;

    /** @var \DateTime|null */
    private $expires_at;

    /** @var \DateTime|null */
    private $last_used_at;

    /** @var boolean */
    private $revoked = false;

    public function getId()                 { return $this->id; }

    public function getName()               { return $this->name; }
    public function setName( $v )           { $this->name = $v; return $this; }

    public function getTokenHash()          { return $this->token_hash; }
    public function setTokenHash( $v )      { $this->token_hash = $v; return $this; }

    public function getScope()              { return $this->scope; }
    public function setScope( $v )          { $this->scope = $v; return $this; }

    public function getAllowedIps()         { return $this->allowed_ips; }
    public function setAllowedIps( $v )     { $this->allowed_ips = ( $v === '' ? null : $v ); return $this; }

    public function getAllowedDomains()     { return $this->allowed_domains; }
    public function setAllowedDomains( $v ) { $this->allowed_domains = ( $v === '' ? null : $v ); return $this; }

    /**
     * May this token operate on $domain? Empty/null allowlist => all domains.
     * Matching is case-insensitive exact (no wildcards).
     */
    public function allowsDomain( $domain )
    {
        $list = trim( (string) $this->allowed_domains );
        if( $list === '' )
            return true;
        $domain = strtolower( (string) $domain );
        foreach( preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $d )
            if( strtolower( $d ) === $domain )
                return true;
        return false;
    }

    public function getCreated()            { return $this->created; }
    public function setCreated( $v )        { $this->created = $v; return $this; }

    public function getExpiresAt()          { return $this->expires_at; }
    public function setExpiresAt( $v )      { $this->expires_at = $v; return $this; }

    public function getLastUsedAt()         { return $this->last_used_at; }
    public function setLastUsedAt( $v )     { $this->last_used_at = $v; return $this; }

    public function getRevoked()            { return (bool) $this->revoked; }
    public function setRevoked( $v )        { $this->revoked = (bool) $v; return $this; }

    /**
     * A scope string contains "*" or the requested scope token.
     */
    public function hasScope( $want )
    {
        $have = preg_split( '/[\s,]+/', (string) $this->scope, -1, PREG_SPLIT_NO_EMPTY );
        return in_array( '*', $have, true ) || in_array( $want, $have, true );
    }

    /**
     * True if the token is usable right now (not revoked, not expired).
     */
    public function isActive( ?\DateTime $now = null )
    {
        if( $this->revoked )
            return false;
        if( $this->expires_at !== null )
        {
            $now = $now ?: new \DateTime();
            if( $this->expires_at < $now )
                return false;
        }
        return true;
    }
}
