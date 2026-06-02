<?php
/**
 * Network helpers: resolve the real client IP behind a reverse proxy, and
 * IPv4/IPv6 CIDR matching. Shared by the brute-force limiter and the MCP
 * adapter so IP allowlisting/lockout sees the actual client, not the proxy.
 */
class ViMbAdmin_Net
{
    /**
     * Resolve the client IP from $server ($_SERVER), honouring a trusted-proxy
     * policy. X-Forwarded-For is client-controllable, so it is ONLY consulted
     * when the direct peer (REMOTE_ADDR) is itself a trusted proxy, and we then
     * take the right-most address in the chain that is NOT a trusted proxy
     * (the address the trusted proxy actually received the request from). This
     * defeats XFF spoofing -- prepended fake entries sit to the left and are
     * never selected.
     *
     * @param array  $server   typically $_SERVER
     * @param string $mode     'auto' (default) | 'off' | 'on'
     *                          - off : always REMOTE_ADDR (ignore XFF)
     *                          - auto: trust XFF only if REMOTE_ADDR is a
     *                                  private/loopback address (a local proxy)
     *                          - on  : trust XFF only if REMOTE_ADDR is in $proxies
     * @param array  $proxies  IP/CIDR list of trusted proxies (mode 'on')
     * @return string
     */
    public static function clientIp( array $server, $mode = 'auto', array $proxies = [] )
    {
        $remote = isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '0.0.0.0';
        $mode   = strtolower( (string) $mode );

        if( $mode === 'off' || $mode === '0' || $mode === 'false' )
            return $remote;

        $xff = '';
        if( isset( $server['HTTP_X_FORWARDED_FOR'] ) )
            $xff = $server['HTTP_X_FORWARDED_FOR'];
        if( $xff === '' )
            return $remote;

        $trusted = function( $ip ) use ( $mode, $proxies ) {
            if( $mode === 'auto' )
                return self::isPrivate( $ip );
            foreach( $proxies as $p ) {
                $p = trim( (string) $p );
                if( $p !== '' && self::ipInCidr( $ip, $p ) )
                    return true;
            }
            return false;
        };

        // Only peel XFF if the request actually came through a trusted proxy.
        if( !$trusted( $remote ) )
            return $remote;

        $chain = array_map( 'trim', explode( ',', $xff ) );
        for( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
            $ip = $chain[ $i ];
            if( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) && !$trusted( $ip ) )
                return $ip;
        }
        return $remote;
    }

    /**
     * RFC1918 / loopback / link-local / unique-local (fc00::/7).
     */
    public static function isPrivate( $ip )
    {
        if( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false )
            return false;                       // it's a normal public IP
        return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;  // valid but private/reserved
    }

    /**
     * Match $ip against a single IP or CIDR (IPv4 + IPv6).
     */
    public static function ipInCidr( $ip, $cidr )
    {
        list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
        if( $bits === null )
            return $ip === $subnet;
        $bits = (int) $bits;

        $ipBin     = @inet_pton( $ip );
        $subnetBin = @inet_pton( $subnet );
        if( $ipBin === false || $subnetBin === false || strlen( $ipBin ) !== strlen( $subnetBin ) )
            return false;

        $bytes = intdiv( $bits, 8 );
        $rem   = $bits % 8;

        if( $bytes > 0 && strncmp( $ipBin, $subnetBin, $bytes ) !== 0 )
            return false;
        if( $rem === 0 )
            return true;

        $mask = chr( 0xff << ( 8 - $rem ) & 0xff );
        return ( ( $ipBin[ $bytes ] & $mask ) === ( $subnetBin[ $bytes ] & $mask ) );
    }
}
