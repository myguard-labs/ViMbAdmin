<?php
/**
 * Per-token sliding-window rate limiter for the MCP adapter.
 *
 * Used to cap destructive operations (archive / restore / delete) so a
 * compromised or buggy client can't mass-destroy mailboxes. File-based state
 * (one small JSON file per token+bucket) under var/ -- no extra service.
 */
class ViMbAdmin_Mcp_RateLimit
{
    /** @var string */
    private $_dir;
    /** @var int */
    private $_max;
    /** @var int */
    private $_window;

    /**
     * @param array $opts  ['statedir'=>..,'max'=>..,'window'=>..]
     */
    public function __construct( array $opts = [] )
    {
        $this->_dir    = isset( $opts['statedir'] ) && $opts['statedir']
                       ? rtrim( $opts['statedir'], '/' )
                       : sys_get_temp_dir() . '/vimbadmin-mcp-ratelimit';
        $this->_max    = isset( $opts['max'] )    ? (int) $opts['max']    : 10;
        $this->_window = isset( $opts['window'] ) ? (int) $opts['window'] : 3600;
    }

    /**
     * Record one destructive hit for $tokenId and throw if the limit is now
     * exceeded inside the window. Call this BEFORE doing the destructive work.
     *
     * @throws ViMbAdmin_Mcp_Exception (429) when over the limit
     */
    public function hit( $tokenId, $bucket = 'destructive' )
    {
        if( $this->_max <= 0 )           // 0/neg disables the limiter
            return;

        $now  = time();
        $file = $this->_file( $tokenId, $bucket );

        // The whole read-modify-write must be atomic, otherwise two concurrent
        // destructive calls can each read a sub-limit count and both proceed,
        // letting a compromised client slip past the cap. Hold an exclusive
        // flock on the state file for the entire check+record.
        $fh = @fopen( $file, 'c+' );
        if( $fh === false )
            return;  // fail-open on FS error (limiter is best-effort)

        try
        {
            if( !flock( $fh, LOCK_EX ) )
                return;

            $raw  = stream_get_contents( $fh );
            $hits = json_decode( (string) $raw, true );
            if( !is_array( $hits ) )
                $hits = [];

            // drop entries outside the window
            $hits = array_values( array_filter( $hits, function( $t ) use ( $now ) {
                return ( $now - (int) $t ) < $this->_window;
            } ) );

            if( count( $hits ) >= $this->_max )
                throw new ViMbAdmin_Mcp_Exception(
                    "rate limit: max {$this->_max} destructive operations per {$this->_window}s", 429 );

            $hits[] = $now;

            ftruncate( $fh, 0 );
            rewind( $fh );
            fwrite( $fh, json_encode( $hits ) );
            fflush( $fh );
        }
        finally
        {
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    // ---- internals -----------------------------------------------------

    private function _file( $tokenId, $bucket )
    {
        if( !is_dir( $this->_dir ) )
            @mkdir( $this->_dir, 0750, true );
        return $this->_dir . '/' . (int) $tokenId . '-' . preg_replace( '/[^a-z0-9]/', '', $bucket ) . '.json';
    }
}
