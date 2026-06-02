<?php
/**
 * MCP adapter endpoint.
 *
 * A small JSON-RPC 2.0 endpoint at /mcp that exposes read abilities over the
 * ViMbAdmin database to an MCP client. Authentication is a bearer token
 * (SHA-256 hash stored in mcp_token) plus an optional per-token IP/CIDR
 * allowlist; the Angie/nginx vhost is expected to enforce a coarse IP
 * allowlist in front of this as the primary network barrier.
 *
 * Tokens are managed from the CLI:
 *   ./bin/vimbtool.php -a mcp.cli-token-generate --name=agent1 [--scope="read"] [--ip="10.0.0.0/8"] [--days=365]
 *   ./bin/vimbtool.php -a mcp.cli-token-list
 *   ./bin/vimbtool.php -a mcp.cli-token-revoke --name=agent1     (or --id=N)
 *
 * This controller is intentionally NOT session-authenticated; it never calls
 * authorise(). Web access is bearer-only; cli-* actions run under vimbtool.
 */
class McpController extends ViMbAdmin_Controller_Action
{
    public function preDispatch()
    {
        // No session auth here. Web action authenticates via bearer; the
        // cli-* actions run from vimbtool.
        $this->_helper->viewRenderer->setNoRender( true );
        try { $this->_helper->layout()->disableLayout(); } catch( \Exception $e ) {}
    }

    public function init()
    {
        // Override the base init's skin/csrf/view wiring (not needed for JSON),
        // but keep the essential bit it does: make options available (getD2EM
        // and _mcpEnabled rely on Zend_Registry 'options').
        $this->_options = $this->getBootstrap()->getOptions();
        Zend_Registry::set( 'options', $this->_options );
    }

    // =====================================================================
    //  Web endpoint  (POST /mcp, JSON-RPC 2.0)
    // =====================================================================

    public function indexAction()
    {
        if( !$this->_mcpEnabled() )
            return $this->_http( 404, 'mcp disabled' );

        if( strtoupper( $this->getRequest()->getMethod() ) !== 'POST' )
            return $this->_http( 405, 'POST required' );

        $body = file_get_contents( 'php://input' );
        $req  = json_decode( $body, true );
        if( !is_array( $req ) || !isset( $req['method'] ) )
            return $this->_rpcError( null, -32700, 'parse error' );

        $id     = isset( $req['id'] ) ? $req['id'] : null;
        $method = (string) $req['method'];
        $params = isset( $req['params'] ) && is_array( $req['params'] ) ? $req['params'] : [];

        // ---- authenticate (bearer + ip + scope) -------------------------
        try
        {
            $auth  = new ViMbAdmin_Mcp_Auth( $this->getD2EM() );
            $scope = ( strpos( $method, '.' ) === false || substr( $method, -5 ) === '.list' || $method === 'ping' )
                   ? 'read' : 'write';
            $token = $auth->authenticate( $_SERVER, $scope );
        }
        catch( ViMbAdmin_Mcp_Exception $e )
        {
            return $this->_http( (int) $e->getCode() ?: 403, $e->getMessage(), $id );
        }

        // ---- dispatch ---------------------------------------------------
        try
        {
            switch( $method )
            {
                case 'ping':            $result = $this->_ping();                  break;
                case 'domains.list':    $result = $this->_domainsList();           break;
                case 'mailboxes.list':  $result = $this->_mailboxesList( $params ); break;
                case 'aliases.list':    $result = $this->_aliasesList( $params );   break;
                default:
                    return $this->_rpcError( $id, -32601, "unknown method '{$method}'" );
            }
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( 'MCP ' . $method . ': ' . $e->getMessage() );
            return $this->_rpcError( $id, -32603, 'internal error' );
        }

        return $this->_rpcResult( $id, $result );
    }

    // ---- abilities -----------------------------------------------------

    private function _ping()
    {
        return [ 'pong' => true, 'time' => gmdate( 'c' ) ];
    }

    private function _domainsList()
    {
        $out = [];
        foreach( $this->getD2EM()->getRepository( '\\Entities\\Domain' )->findAll() as $d )
            $out[] = [
                'id'        => $d->getId(),
                'domain'    => $d->getDomain(),
                'active'    => (bool) $d->getActive(),
                'transport' => $d->getTransport(),
                'quota'     => $d->getQuota(),
                'maxquota'  => $d->getMaxQuota(),
                'mailboxes' => $d->getMailboxCount(),
                'aliases'   => $d->getAliasCount(),
            ];
        return [ 'domains' => $out ];
    }

    private function _mailboxesList( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $out = [];
        foreach( $this->getD2EM()->getRepository( '\\Entities\\Mailbox' )->findBy( [ 'Domain' => $domain ] ) as $m )
            $out[] = [
                'username'   => $m->getUsername(),
                'name'       => $m->getName(),
                'active'     => (bool) $m->getActive(),
                'quota'      => $m->getQuota(),
                'local_part' => $m->getLocalPart(),
                'homedir'    => $m->getHomedir(),
                'maildir'    => $m->getMaildir(),
            ];
        return [ 'domain' => $domain->getDomain(), 'mailboxes' => $out ];
    }

    private function _aliasesList( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $out = [];
        foreach( $this->getD2EM()->getRepository( '\\Entities\\Alias' )->findBy( [ 'Domain' => $domain ] ) as $a )
            $out[] = [
                'address' => $a->getAddress(),
                'goto'    => $a->getGoto(),
                'active'  => (bool) $a->getActive(),
            ];
        return [ 'domain' => $domain->getDomain(), 'aliases' => $out ];
    }

    /**
     * @return \Entities\Domain
     * @throws RuntimeException
     */
    private function _requireDomain( array $params )
    {
        $name = isset( $params['domain'] ) ? trim( (string) $params['domain'] ) : '';
        if( $name === '' )
            throw new RuntimeException( 'param "domain" required' );
        $domain = $this->getD2EM()->getRepository( '\\Entities\\Domain' )->findOneBy( [ 'domain' => $name ] );
        if( !$domain )
            throw new RuntimeException( 'unknown domain' );
        return $domain;
    }

    // ---- helpers -------------------------------------------------------

    private function _mcpEnabled()
    {
        return isset( $this->_options['mcp']['enabled'] ) && $this->_options['mcp']['enabled'];
    }

    private function _json( $payload, $httpStatus = 200 )
    {
        $this->getResponse()
             ->setHttpResponseCode( $httpStatus )
             ->setHeader( 'Content-Type', 'application/json', true )
             ->setBody( json_encode( $payload ) );
    }

    private function _rpcResult( $id, $result )
    {
        $this->_json( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ] );
    }

    private function _rpcError( $id, $code, $message, $httpStatus = 200 )
    {
        $this->_json( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], $httpStatus );
    }

    /** Auth/transport-level failure: HTTP status + JSON-RPC error envelope. */
    private function _http( $status, $message, $id = null )
    {
        if( $status === 401 )
            $this->getResponse()->setHeader( 'WWW-Authenticate', 'Bearer', true );
        $this->_rpcError( $id, -32000, $message, $status );
    }

    // =====================================================================
    //  CLI token management  (vimbtool -a mcp.cli-token-*)
    // =====================================================================

    public function cliTokenGenerateAction()
    {
        $name = $this->_cliOpt( 'name' );
        if( $name === null )
            return $this->_cliDie( "ERROR: --name is required\n" );

        $em = $this->getD2EM();
        if( $em->getRepository( '\\Entities\\McpToken' )->findByName( $name ) )
            return $this->_cliDie( "ERROR: a token named '{$name}' already exists (revoke it first)\n" );

        $raw  = bin2hex( random_bytes( 32 ) );
        $tok  = new \Entities\McpToken();
        $tok->setName( $name );
        $tok->setTokenHash( hash( 'sha256', $raw ) );
        $tok->setScope( $this->_cliOpt( 'scope' ) ?: 'read' );
        $tok->setAllowedIps( $this->_cliOpt( 'ip' ) ?: null );
        $tok->setCreated( new \DateTime() );
        $tok->setRevoked( false );

        $days = $this->_cliOpt( 'days' );
        if( $days !== null && (int) $days > 0 )
            $tok->setExpiresAt( ( new \DateTime() )->modify( '+' . (int) $days . ' days' ) );

        $em->persist( $tok );
        $em->flush();

        echo "MCP token '{$name}' created. Scope: {$tok->getScope()}.";
        echo $tok->getAllowedIps() ? " IPs: {$tok->getAllowedIps()}." : " IPs: any.";
        echo $tok->getExpiresAt() ? " Expires: " . $tok->getExpiresAt()->format( 'Y-m-d' ) . ".\n" : " No expiry.\n";
        echo "\n  TOKEN (shown once, store it now):\n\n    {$raw}\n\n";
        echo "Use it as:  Authorization: Bearer {$raw}\n";
    }

    public function cliTokenListAction()
    {
        $tokens = $this->getD2EM()->getRepository( '\\Entities\\McpToken' )->findBy( [], [ 'id' => 'ASC' ] );
        if( !$tokens )
        {
            echo "No MCP tokens.\n";
            return;
        }
        printf( "%-4s %-20s %-12s %-20s %-10s %-19s\n", 'ID', 'NAME', 'SCOPE', 'IPS', 'STATE', 'LAST USED' );
        foreach( $tokens as $t )
        {
            printf( "%-4d %-20s %-12s %-20s %-10s %-19s\n",
                $t->getId(), $t->getName(), $t->getScope(),
                $t->getAllowedIps() ?: 'any',
                $t->getRevoked() ? 'revoked' : ( $t->isActive() ? 'active' : 'expired' ),
                $t->getLastUsedAt() ? $t->getLastUsedAt()->format( 'Y-m-d H:i:s' ) : '-' );
        }
    }

    public function cliTokenRevokeAction()
    {
        $em   = $this->getD2EM();
        $repo = $em->getRepository( '\\Entities\\McpToken' );
        $id   = $this->_cliOpt( 'id' );
        $name = $this->_cliOpt( 'name' );

        $tok = $id !== null ? $repo->find( (int) $id )
             : ( $name !== null ? $repo->findByName( $name ) : null );

        if( !$tok )
            return $this->_cliDie( "ERROR: token not found (use --name or --id; see mcp.cli-token-list)\n" );

        $tok->setRevoked( true );
        $em->flush();
        echo "Revoked MCP token '{$tok->getName()}' (id {$tok->getId()}).\n";
    }

    // ---- cli helpers ---------------------------------------------------

    private function _cliOpt( $name )
    {
        $v = $this->getRequest()->getParam( $name, null );
        return ( $v === null || $v === '' ) ? null : $v;
    }

    private function _cliDie( $msg )
    {
        fwrite( STDERR, $msg );
        exit( 1 );
    }
}
