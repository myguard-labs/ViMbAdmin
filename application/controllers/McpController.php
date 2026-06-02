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
            $token = $auth->authenticate( $_SERVER, $this->_scopeFor( $method ) );

            // Destructive methods are additionally per-token rate-limited.
            if( $this->_isDestructive( $method ) )
                $this->_rateLimiter()->hit( $token->getId() );
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
                // read
                case 'ping':            $result = $this->_ping();                  break;
                case 'domains.list':    $result = $this->_domainsList();           break;
                case 'mailboxes.list':  $result = $this->_mailboxesList( $params ); break;
                case 'aliases.list':    $result = $this->_aliasesList( $params );   break;
                // write
                case 'domain.create':   $result = $this->_domainCreate( $params );  break;
                case 'domain.delete':   $result = $this->_domainDelete( $params );  break;
                case 'mailbox.create':  $result = $this->_mailboxCreate( $params ); break;
                case 'mailbox.delete':  $result = $this->_mailboxDelete( $params ); break;
                case 'alias.create':    $result = $this->_aliasCreate( $params );   break;
                case 'alias.delete':    $result = $this->_aliasDelete( $params );   break;
                // destructive (archive queue)
                case 'mailbox.archive': $result = $this->_mailboxArchive( $params );break;
                case 'archive.restore': $result = $this->_archiveState( $params, \Entities\Archive::STATUS_PENDING_RESTORE ); break;
                case 'archive.delete':  $result = $this->_archiveState( $params, \Entities\Archive::STATUS_PENDING_DELETE );  break;
                default:
                    return $this->_rpcError( $id, -32601, "unknown method '{$method}'" );
            }
        }
        catch( ViMbAdmin_Mcp_Exception $e )
        {
            return $this->_rpcError( $id, -32602, $e->getMessage() );
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

    // ---- write abilities (scope: write) --------------------------------

    private function _domainCreate( array $params )
    {
        $name = $this->_str( $params, 'domain', true );
        if( $this->getD2EM()->getRepository( '\\Entities\\Domain' )->findOneBy( [ 'domain' => $name ] ) )
            throw new ViMbAdmin_Mcp_Exception( 'domain already exists' );

        $d = new \Entities\Domain();
        $d->setDomain( $name );
        $d->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $d->setTransport( $this->_str( $params, 'transport' ) ?: ( $this->_options['defaults']['domain']['transport'] ?? 'virtual' ) );
        $d->setQuota(        (int) ( $params['quota']        ?? ( $this->_options['defaults']['domain']['quota']     ?? 0 ) ) );
        $d->setMaxQuota(     (int) ( $params['maxquota']     ?? ( $this->_options['defaults']['domain']['maxquota']  ?? 0 ) ) );
        $d->setMaxMailboxes( (int) ( $params['max_mailboxes']?? ( $this->_options['defaults']['domain']['mailboxes']?? 0 ) ) );
        $d->setMaxAliases(   (int) ( $params['max_aliases']  ?? ( $this->_options['defaults']['domain']['aliases']  ?? 0 ) ) );
        $d->setBackupmx( false );
        $d->setMailboxCount( 0 );
        $d->setAliasCount( 0 );
        $d->setCreated( new \DateTime() );

        $em = $this->getD2EM();
        $em->persist( $d );
        $em->flush();
        return [ 'created' => true, 'domain' => $d->getDomain(), 'id' => $d->getId() ];
    }

    private function _domainDelete( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $name   = $domain->getDomain();
        $this->getD2EM()->getRepository( '\\Entities\\Domain' )->purge( $domain );
        return [ 'deleted' => true, 'domain' => $name ];
    }

    private function _mailboxCreate( array $params )
    {
        $domain    = $this->_requireDomain( $params );
        $localPart = $this->_str( $params, 'local_part', true );
        $password  = $this->_str( $params, 'password', true );
        $username  = $localPart . '@' . $domain->getDomain();

        $repo = $this->getD2EM()->getRepository( '\\Entities\\Mailbox' );
        if( !$repo->isUnique( $username ) )
            throw new ViMbAdmin_Mcp_Exception( 'mailbox already exists' );

        $m = new \Entities\Mailbox();
        $m->setLocalPart( $localPart );
        $m->setUsername( $username );
        $m->setName( $this->_str( $params, 'name' ) ?: $username );
        $m->setDomain( $domain );
        $m->setUid( $this->_options['defaults']['mailbox']['uid'] );
        $m->setGid( $this->_options['defaults']['mailbox']['gid'] );
        $m->formatHomedir( $this->_options['defaults']['mailbox']['homedir'] );
        $m->formatMaildir( $this->_options['defaults']['mailbox']['maildir'] );
        $m->setQuota( (int) ( $params['quota'] ?? 0 ) );
        $m->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $m->setDeletePending( false );
        $m->setCreated( new \DateTime() );
        $m->setPassword( OSS_Auth_Password::hash( $password, [
            'pwhash'    => $this->_options['defaults']['mailbox']['password_scheme'],
            'pwsalt'    => $this->_options['defaults']['mailbox']['password_salt'] ?? null,
            'pwdovecot' => $this->_options['defaults']['mailbox']['dovecot_pw_binary'] ?? null,
            'username'  => $username,
        ] ) );

        $em = $this->getD2EM();
        $em->persist( $m );

        if( ( $this->_options['mailboxAliases'] ?? 0 ) == 1 )
        {
            $a = new \Entities\Alias();
            $a->setAddress( $username );
            $a->setGoto( $username );
            $a->setDomain( $domain );
            $a->setActive( true );
            $a->setCreated( new \DateTime() );
            $em->persist( $a );
            $domain->setAliasCount( $domain->getAliasCount() + 1 );
        }
        $domain->setMailboxCount( $domain->getMailboxCount() + 1 );
        $em->flush();
        return [ 'created' => true, 'username' => $username ];
    }

    private function _mailboxDelete( array $params )
    {
        $m = $this->_requireMailbox( $params );
        $username = $m->getUsername();
        $domain   = $m->getDomain();
        $this->getD2EM()->getRepository( '\\Entities\\Mailbox' )->purgeMailbox( $m, null, true );
        $domain->setMailboxCount( max( 0, $domain->getMailboxCount() - 1 ) );
        $this->getD2EM()->flush();
        return [ 'deleted' => true, 'username' => $username ];
    }

    private function _aliasCreate( array $params )
    {
        $domain  = $this->_requireDomain( $params );
        $address = $this->_str( $params, 'address', true );
        $goto    = $this->_str( $params, 'goto', true );
        if( strpos( $address, '@' ) === false )
            $address .= '@' . $domain->getDomain();

        $repo = $this->getD2EM()->getRepository( '\\Entities\\Alias' );
        if( $repo->findOneBy( [ 'address' => $address ] ) )
            throw new ViMbAdmin_Mcp_Exception( 'alias already exists' );

        $a = new \Entities\Alias();
        $a->setAddress( $address );
        $a->setGoto( $goto );
        $a->setDomain( $domain );
        $a->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $a->setCreated( new \DateTime() );
        $em = $this->getD2EM();
        $em->persist( $a );
        $domain->setAliasCount( $domain->getAliasCount() + 1 );
        $em->flush();
        return [ 'created' => true, 'address' => $address ];
    }

    private function _aliasDelete( array $params )
    {
        $address = $this->_str( $params, 'address', true );
        $a = $this->getD2EM()->getRepository( '\\Entities\\Alias' )->findOneBy( [ 'address' => $address ] );
        if( !$a )
            throw new ViMbAdmin_Mcp_Exception( 'unknown alias' );
        $domain = $a->getDomain();
        $em = $this->getD2EM();
        $em->remove( $a );
        if( $domain )
            $domain->setAliasCount( max( 0, $domain->getAliasCount() - 1 ) );
        $em->flush();
        return [ 'deleted' => true, 'address' => $address ];
    }

    // ---- destructive: archive queue (scope: write, rate-limited) --------

    private function _mailboxArchive( array $params )
    {
        $m  = $this->_requireMailbox( $params );
        $em = $this->getD2EM();

        // Serialize the mailbox the same way the panel does, so the archive
        // worker (PHP or the SQL helper) can read homedir/maildir from `data`.
        $ser  = new OSS_Doctrine2_EntitySerializer( $em );
        $data = serialize( [ 'mailbox' => [ 'className' => get_class( $m ), 'params' => $ser->toArray( $m ) ] ] );

        $archive = new \Entities\Archive();
        $archive->setArchivedBy( null );
        $archive->setArchivedAt( new \DateTime() );
        $archive->setDomain( $m->getDomain() );
        $archive->setUsername( $m->getUsername() );
        $archive->setStatus( \Entities\Archive::STATUS_PENDING_ARCHIVE );
        $archive->setStatusChangedAt( new \DateTime() );
        $archive->setData( $data );
        $em->persist( $archive );

        $username = $m->getUsername();
        $domain   = $m->getDomain();
        $em->getRepository( '\\Entities\\Mailbox' )->purgeMailbox( $m, null, false );
        $domain->setMailboxCount( max( 0, $domain->getMailboxCount() - 1 ) );
        $em->flush();
        return [ 'queued' => 'PENDING_ARCHIVE', 'username' => $username ];
    }

    private function _archiveState( array $params, $status )
    {
        $username = $this->_str( $params, 'username', true );
        $archive  = $this->getD2EM()->getRepository( '\\Entities\\Archive' )->findOneBy( [ 'username' => $username ] );
        if( !$archive )
            throw new ViMbAdmin_Mcp_Exception( 'no archive for that username' );
        $archive->setStatus( $status );
        $archive->setStatusChangedAt( new \DateTime() );
        $this->getD2EM()->flush();
        return [ 'queued' => $status, 'username' => $username ];
    }

    // ---- lookup + param helpers ----------------------------------------

    /**
     * @return \Entities\Domain
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireDomain( array $params )
    {
        $name   = $this->_str( $params, 'domain', true );
        $domain = $this->getD2EM()->getRepository( '\\Entities\\Domain' )->findOneBy( [ 'domain' => $name ] );
        if( !$domain )
            throw new ViMbAdmin_Mcp_Exception( 'unknown domain' );
        return $domain;
    }

    /**
     * @return \Entities\Mailbox
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireMailbox( array $params )
    {
        $username = $this->_str( $params, 'username', true );
        $m = $this->getD2EM()->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $username ] );
        if( !$m )
            throw new ViMbAdmin_Mcp_Exception( 'unknown mailbox' );
        return $m;
    }

    private function _str( array $params, $key, $required = false )
    {
        $v = isset( $params[ $key ] ) ? trim( (string) $params[ $key ] ) : '';
        if( $v === '' && $required )
            throw new ViMbAdmin_Mcp_Exception( "param \"{$key}\" required" );
        return $v;
    }

    // ---- scope / rate-limit routing ------------------------------------

    private function _writeMethods()
    {
        return [ 'domain.create','domain.delete','mailbox.create','mailbox.delete',
                 'alias.create','alias.delete','mailbox.archive','archive.restore','archive.delete' ];
    }

    private function _destructiveMethods()
    {
        return [ 'mailbox.delete','domain.delete','mailbox.archive','archive.restore','archive.delete' ];
    }

    private function _scopeFor( $method )
    {
        return in_array( $method, $this->_writeMethods(), true ) ? 'write' : 'read';
    }

    private function _isDestructive( $method )
    {
        return in_array( $method, $this->_destructiveMethods(), true );
    }

    private function _rateLimiter()
    {
        $rl = $this->_options['mcp']['ratelimit']['destructive'] ?? [];
        return new ViMbAdmin_Mcp_RateLimit( [
            'statedir' => $this->_options['mcp']['ratelimit']['statedir'] ?? null,
            'max'      => $rl['max']    ?? 10,
            'window'   => $rl['window'] ?? 3600,
        ] );
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
