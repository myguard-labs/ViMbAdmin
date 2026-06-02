<?php
/**
 * ViMbAdmin brute-force login protection.
 *
 * Tracks failed-login pressure per source IP and locks a source out for a
 * cooldown window once it crosses a threshold. State is kept as one small
 * JSON file per IP under a state directory (no DB coupling, survives across
 * requests, trivially clearable).
 *
 * Model: every login POST is counted as a pending attempt in _preLogin();
 * a successful auth (password + 2FA) clears the counter. While the counter
 * for a source is at/over the threshold within the window, logins from that
 * source are refused with HTTP 429 -- unless the source IP is allowlisted.
 *
 * Configuration (application.ini, [bruteforce] -> $opts):
 *   bruteforce.enabled      = 1
 *   bruteforce.max_attempts = 5         ; failures before lock
 *   bruteforce.window       = 900       ; seconds the counter accumulates over
 *   bruteforce.lockout      = 900       ; seconds a source stays locked
 *   bruteforce.statedir     = "/opt/vimbadmin/var/bruteforce"
 *   bruteforce.whitelist[]  = "127.0.0.1"
 *   bruteforce.whitelist[]  = "10.0.0.0/8"
 *
 * @package ViMbAdmin
 */
class ViMbAdmin_BruteForce
{
    private $_enabled   = true;
    private $_max       = 5;
    private $_window    = 900;
    private $_lockout   = 900;
    private $_statedir  = null;
    private $_whitelist = [];

    /**
     * @param mixed $em   Unused (kept for call-site compatibility).
     * @param array $opts [bruteforce] options from application.ini.
     */
    public function __construct( $em = null, array $opts = [] )
    {
        if( isset( $opts['enabled'] ) )      $this->_enabled = (bool) $opts['enabled'];
        if( isset( $opts['max_attempts'] ) ) $this->_max     = (int) $opts['max_attempts'];
        if( isset( $opts['window'] ) )       $this->_window  = (int) $opts['window'];
        if( isset( $opts['lockout'] ) )      $this->_lockout = (int) $opts['lockout'];

        $this->_statedir = !empty( $opts['statedir'] )
            ? rtrim( $opts['statedir'], '/' )
            : sys_get_temp_dir() . '/vimbadmin-bruteforce';

        if( isset( $opts['whitelist'] ) )
            $this->_whitelist = is_array( $opts['whitelist'] ) ? $opts['whitelist'] : [ $opts['whitelist'] ];
    }

    // ---- public API ----------------------------------------------------

    /**
     * Abort the request with 429 if the request's source IP is currently
     * locked out. Call early in the login flow (_preLogin).
     *
     * @throws never returns when locked (sends 429 + exits)
     */
    public function assertNotLocked( $request )
    {
        if( !$this->_enabled )
            return;

        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return;

        $rec = $this->_load( $ip );
        if( $rec['locked_until'] > time() )
        {
            header( 'HTTP/1.1 429 Too Many Requests' );
            header( 'Retry-After: ' . max( 1, $rec['locked_until'] - time() ) );
            echo 'Too many failed login attempts. Try again later.';
            exit;
        }
    }

    /**
     * Record one failed attempt for (username, source IP). Locks the source
     * when it crosses the threshold inside the window.
     */
    public function record( $username, $request )
    {
        if( !$this->_enabled )
            return;

        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return;

        $rec = $this->_load( $ip );

        // reset the counter if the window has elapsed since the first hit
        if( $rec['first'] === 0 || ( time() - $rec['first'] ) > $this->_window )
        {
            $rec['first']    = time();
            $rec['attempts'] = 0;
        }

        $rec['attempts']++;
        $rec['last'] = time();

        if( $rec['attempts'] >= $this->_max )
            $rec['locked_until'] = time() + $this->_lockout;

        $this->_save( $ip, $rec );
    }

    /** Clear the counter for a source after a fully successful login. */
    public function clear( $username, $request )
    {
        if( !$this->_enabled )
            return;
        $this->_delete( $this->_ip( $request ) );
    }

    /** Is this source currently locked? (no side effects) */
    public function isLocked( $request )
    {
        if( !$this->_enabled )
            return false;
        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return false;
        return $this->_load( $ip )['locked_until'] > time();
    }

    // ---- storage (one JSON file per IP under the state dir) ------------

    private function _file( $ip )
    {
        // Hash the IP so the filename is filesystem-safe and doesn't leak the
        // raw address in a directory listing.
        return $this->_statedir . '/' . hash( 'sha256', $ip ) . '.json';
    }

    private function _ensureDir()
    {
        if( !is_dir( $this->_statedir ) )
            @mkdir( $this->_statedir, 0750, true );
    }

    private function _load( $ip )
    {
        $default = [ 'attempts' => 0, 'first' => 0, 'last' => 0, 'locked_until' => 0 ];

        $f = $this->_file( $ip );
        if( is_readable( $f ) )
        {
            $d = json_decode( (string) @file_get_contents( $f ), true );
            if( is_array( $d ) )
                return array_merge( $default, $d );
        }
        return $default;
    }

    private function _save( $ip, array $rec )
    {
        $this->_ensureDir();
        $f   = $this->_file( $ip );
        $tmp = $f . '.' . getmypid() . '.tmp';
        if( @file_put_contents( $tmp, json_encode( $rec ), LOCK_EX ) !== false )
            @rename( $tmp, $f );   // atomic replace
    }

    private function _delete( $ip )
    {
        @unlink( $this->_file( $ip ) );
    }

    // ---- helpers -------------------------------------------------------

    private function _ip( $request )
    {
        // Trust REMOTE_ADDR. If you terminate TLS at a trusted proxy, map the
        // real client IP into REMOTE_ADDR there (e.g. Angie realip), not here.
        return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    private function _isWhitelisted( $ip )
    {
        foreach( $this->_whitelist as $entry )
        {
            $entry = trim( $entry );
            if( $entry === '' )
                continue;
            if( strpos( $entry, '/' ) !== false )
            {
                if( $this->_inCidr( $ip, $entry ) )
                    return true;
            }
            elseif( $ip === $entry )
            {
                return true;
            }
        }
        return false;
    }

    private function _inCidr( $ip, $cidr )
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
