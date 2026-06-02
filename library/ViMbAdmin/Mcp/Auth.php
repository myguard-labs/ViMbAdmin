<?php
/**
 * MCP adapter authentication.
 *
 * Two independent layers (the Angie/nginx vhost is expected to enforce a
 * coarse IP allowlist in front of this; this is the in-application second
 * line + identity/scope binding):
 *
 *   1. Bearer token  -- only the SHA-256 hash is stored (Entities\McpToken).
 *   2. Per-token IP/CIDR allowlist (optional; reuses the same matching logic
 *      as the brute-force whitelist).
 *
 * Plus scope checking. The raw token never touches the database.
 */
class ViMbAdmin_Mcp_Auth
{
    /** @var \Doctrine\ORM\EntityManager */
    private $_em;
    /** @var string */
    private $_proxyMode = 'auto';
    /** @var array */
    private $_proxies = [];

    /**
     * @param object $em
     * @param array  $trustedProxy  ['mode'=>'auto|off|on','proxies'=>[...]]
     */
    public function __construct( $em, array $trustedProxy = [] )
    {
        $this->_em = $em;
        if( isset( $trustedProxy['mode'] ) )
            $this->_proxyMode = (string) $trustedProxy['mode'];
        if( isset( $trustedProxy['proxies'] ) )
            $this->_proxies = is_array( $trustedProxy['proxies'] )
                ? $trustedProxy['proxies'] : [ $trustedProxy['proxies'] ];
    }

    /**
     * Authenticate a request. Returns the McpToken on success, or throws
     * ViMbAdmin_Mcp_Exception (with an HTTP-ish code) on any failure.
     *
     * @param array  $server  typically $_SERVER
     * @param string $scope   required scope for the call (e.g. "read")
     * @return \Entities\McpToken
     * @throws ViMbAdmin_Mcp_Exception
     */
    public function authenticate( array $server, $scope = 'read' )
    {
        $raw = $this->_bearer( $server );
        if( $raw === null )
            throw new ViMbAdmin_Mcp_Exception( 'missing or malformed Authorization: Bearer header', 401 );

        $hash  = hash( 'sha256', $raw );
        $token = $this->_em->getRepository( '\\Entities\\McpToken' )->findByHash( $hash );

        // Constant-time-ish: always do a comparison even on miss.
        if( $token === null || !hash_equals( $token->getTokenHash(), $hash ) )
            throw new ViMbAdmin_Mcp_Exception( 'invalid token', 401 );

        if( !$token->isActive() )
            throw new ViMbAdmin_Mcp_Exception( 'token revoked or expired', 403 );

        if( !$token->hasScope( $scope ) )
            throw new ViMbAdmin_Mcp_Exception( "token lacks required scope '{$scope}'", 403 );

        $ip = $this->clientIp( $server );
        if( !$this->_ipAllowed( $token, $ip ) )
            throw new ViMbAdmin_Mcp_Exception( "source IP {$ip} not allowed for this token", 403 );

        // touch last_used_at (best effort)
        try
        {
            $token->setLastUsedAt( new \DateTime() );
            $this->_em->flush();
        }
        catch( \Throwable $e ) { /* non-fatal */ }

        return $token;
    }

    /**
     * Resolve the client IP per the trusted-proxy policy (default 'auto').
     */
    public function clientIp( array $server )
    {
        return ViMbAdmin_Net::clientIp( $server, $this->_proxyMode, $this->_proxies );
    }

    // ---- internals -----------------------------------------------------

    private function _bearer( array $server )
    {
        $h = null;
        if( isset( $server['HTTP_AUTHORIZATION'] ) )
            $h = $server['HTTP_AUTHORIZATION'];
        elseif( isset( $server['REDIRECT_HTTP_AUTHORIZATION'] ) )
            $h = $server['REDIRECT_HTTP_AUTHORIZATION'];

        if( $h === null || !preg_match( '/^\s*Bearer\s+([A-Za-z0-9._\-]+)\s*$/', $h, $m ) )
            return null;

        return $m[1];
    }

    /**
     * Empty/null allowlist => any IP (the edge is the gate). Otherwise the IP
     * must match one of the space/comma-separated IP or CIDR entries.
     */
    private function _ipAllowed( \Entities\McpToken $token, $ip )
    {
        $list = trim( (string) $token->getAllowedIps() );
        if( $list === '' )
            return true;

        foreach( preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $entry )
            if( ViMbAdmin_Net::ipInCidr( $ip, $entry ) )
                return true;
        return false;
    }
}
