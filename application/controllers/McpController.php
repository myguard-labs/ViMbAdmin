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
 *   ./bin/vimbtool.php -a mcp.cli-token-generate --name=agent1 [--scope="read"] [--ip="10.0.0.0/8"] [--domains="a.com b.com"] [--days=365]
 *   ./bin/vimbtool.php -a mcp.cli-token-list
 *   ./bin/vimbtool.php -a mcp.cli-token-revoke --name=agent1     (or --id=N)
 *
 * This controller is intentionally NOT session-authenticated; it never calls
 * authorise(). Web access is bearer-only; cli-* actions run under vimbtool.
 */
class McpController extends \ViMbAdmin\Kernel\Mvc\AbstractController
{
    /** @var \Entities\McpToken|null  the authenticated token for this request */
    private $_token = null;

    // =====================================================================
    //  Web endpoint  (POST /mcp, JSON-RPC 2.0)
    // =====================================================================

    public function indexAction()
    {
        if( !$this->_mcpEnabled() )
            return $this->_http( 404, 'mcp disabled' );

        if( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'POST' )
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
            $auth  = new ViMbAdmin_Mcp_Auth( $this->em(), $this->options()['trustedproxy'] ?? [] );
            $token = $this->_token = $auth->authenticate( $_SERVER, $this->_scopeFor( $method ) );

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
            error_log( 'MCP ' . $method . ': ' . $e->getMessage() );
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
        foreach( $this->em()->getRepository( '\\Entities\\Domain' )->findAll() as $d )
        {
            if( $this->_token && !$this->_token->allowsDomain( $d->getDomain() ) )
                continue;
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
        }
        return [ 'domains' => $out ];
    }

    private function _mailboxesList( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $out = [];
        foreach( $this->em()->getRepository( '\\Entities\\Mailbox' )->findBy( [ 'Domain' => $domain ] ) as $m )
            $out[] = [
                'username'   => $m->getUsername(),
                'name'       => $m->getName(),
                'active'     => (bool) $m->getActive(),
                'quota'      => $m->getQuota(),
                'local_part' => $m->getLocalPart(),
            ];
        return [ 'domain' => $domain->getDomain(), 'mailboxes' => $out ];
    }

    private function _aliasesList( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $out = [];
        foreach( $this->em()->getRepository( '\\Entities\\Alias' )->findBy( [ 'Domain' => $domain ] ) as $a )
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
        $this->_validate( $name, \ViMbAdmin\Kernel\Form\Validators::hostname(), 'domain' );
        // Bind the per-token domain allowlist to creation too: a token scoped to
        // specific domains must not be able to create one outside that list.
        $this->_assertDomainAllowed( $name );
        if( $this->em()->getRepository( '\\Entities\\Domain' )->findOneBy( [ 'domain' => $name ] ) )
            throw new ViMbAdmin_Mcp_Exception( 'domain already exists' );

        $d = new \Entities\Domain();
        $d->setDomain( $name );
        $d->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $d->setTransport( $this->_str( $params, 'transport' ) ?: ( $this->options()['defaults']['domain']['transport'] ?? 'virtual' ) );
        $d->setQuota(        (int) ( $params['quota']        ?? ( $this->options()['defaults']['domain']['quota']     ?? 0 ) ) );
        $d->setMaxQuota(     (int) ( $params['maxquota']     ?? ( $this->options()['defaults']['domain']['maxquota']  ?? 0 ) ) );
        $d->setMaxMailboxes( (int) ( $params['max_mailboxes']?? ( $this->options()['defaults']['domain']['mailboxes']?? 0 ) ) );
        $d->setMaxAliases(   (int) ( $params['max_aliases']  ?? ( $this->options()['defaults']['domain']['aliases']  ?? 0 ) ) );
        $d->setBackupmx( false );
        $d->setMailboxCount( 0 );
        $d->setAliasCount( 0 );
        $d->setCreated( new \DateTime() );

        $em = $this->em();
        $em->persist( $d );
        $em->flush();
        return [ 'created' => true, 'domain' => $d->getDomain(), 'id' => $d->getId() ];
    }

    private function _domainDelete( array $params )
    {
        $domain = $this->_requireDomain( $params );
        $name   = $domain->getDomain();
        $this->em()->getRepository( '\\Entities\\Domain' )->purge( $domain );
        return [ 'deleted' => true, 'domain' => $name ];
    }

    private function _mailboxCreate( array $params )
    {
        $domain    = $this->_requireDomain( $params );
        $localPart = $this->_str( $params, 'local_part', true );
        $this->_validate( $localPart, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'local_part' );
        $password  = $this->_str( $params, 'password', true );
        $username  = $localPart . '@' . $domain->getDomain();

        $repo = $this->em()->getRepository( '\\Entities\\Mailbox' );
        if( !$repo->isUnique( $username ) )
            throw new ViMbAdmin_Mcp_Exception( 'mailbox already exists' );

        $m = new \Entities\Mailbox();
        $m->setLocalPart( $localPart );
        $m->setUsername( $username );
        $m->setName( $this->_str( $params, 'name' ) ?: $username );
        $m->setDomain( $domain );
        $m->setQuota( (int) ( $params['quota'] ?? 0 ) );
        $m->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $m->setDeletePending( false );
        $m->setCreated( new \DateTime() );
        $m->setPassword( OSS_Auth_Password::hash( $password, [
            'pwhash'    => $this->options()['defaults']['mailbox']['password_scheme'],
            'username'  => $username,
        ] ) );

        $em = $this->em();
        $em->persist( $m );

        // Auto mailbox-alias (address -> address). Reuse an existing alias with
        // that address rather than inserting a duplicate (which would violate
        // the unique key and roll the whole create back -- e.g. an orphan alias
        // left by an earlier failed attempt).
        if( ( $this->options()['mailboxAliases'] ?? 0 ) == 1
            && !$em->getRepository( '\\Entities\\Alias' )->findOneBy( [ 'address' => $username ] ) )
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
        $this->em()->getRepository( '\\Entities\\Mailbox' )->purgeMailbox( $m, null, true );
        $domain->setMailboxCount( max( 0, $domain->getMailboxCount() - 1 ) );
        $this->em()->flush();
        return [ 'deleted' => true, 'username' => $username ];
    }

    private function _aliasCreate( array $params )
    {
        $domain  = $this->_requireDomain( $params );
        $address = $this->_str( $params, 'address', true );
        $goto    = $this->_str( $params, 'goto', true );
        if( strpos( $address, '@' ) === false )
        {
            $this->_validate( $address, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'address local part' );
            $address .= '@' . $domain->getDomain();
        }
        else
            $this->_validateEmail( $address, 'address' );
        $this->_validateEmail( $goto, 'goto' );

        $repo = $this->em()->getRepository( '\\Entities\\Alias' );
        if( $repo->findOneBy( [ 'address' => $address ] ) )
            throw new ViMbAdmin_Mcp_Exception( 'alias already exists' );

        $a = new \Entities\Alias();
        $a->setAddress( $address );
        $a->setGoto( $goto );
        $a->setDomain( $domain );
        $a->setActive( isset( $params['active'] ) ? (bool) $params['active'] : true );
        $a->setCreated( new \DateTime() );
        $em = $this->em();
        $em->persist( $a );
        $domain->setAliasCount( $domain->getAliasCount() + 1 );
        $em->flush();
        return [ 'created' => true, 'address' => $address ];
    }

    private function _aliasDelete( array $params )
    {
        $address = $this->_str( $params, 'address', true );
        $a = $this->em()->getRepository( '\\Entities\\Alias' )->findOneBy( [ 'address' => $address ] );
        if( !$a )
            throw new ViMbAdmin_Mcp_Exception( 'unknown alias' );
        $domain = $a->getDomain();
        if( $domain )
            $this->_assertDomainAllowed( $domain->getDomain() );
        $em = $this->em();
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
        $em = $this->em();
        $username = $m->getUsername();

        // Queue a real ARCHIVE task (doveadm backup -> empty store, keep
        // account), exactly like the panel button. The runner records the
        // archive row + backup; we don't serialise/purge here.
        ViMbAdmin_MailboxQueue::enqueue( $em, $m, \Entities\MailboxTask::TYPE_ARCHIVE, null );
        $em->flush();

        // (The queue is drained only by the external cron; no in-app trigger.)

        return [ 'queued' => \Entities\MailboxTask::TYPE_ARCHIVE, 'username' => $username ];
    }

    /**
     * Restore or delete an existing archive. These map onto the immediate
     * ArchiveController actions; over MCP we perform the equivalent directly.
     * $status is TYPE_RESTORE / TYPE_DELETE intent.
     */
    private function _archiveState( array $params, $status )
    {
        $username = $this->_str( $params, 'username', true );
        $em       = $this->em();
        $archive  = $em->getRepository( '\\Entities\\Archive' )->findOneBy( [ 'username' => $username ] );
        if( !$archive )
            throw new ViMbAdmin_Mcp_Exception( 'no archive for that username' );
        if( $archive->getDomain() )
            $this->_assertDomainAllowed( $archive->getDomain()->getDomain() );

        $dest    = $archive->getMaildirFile();
        $doveadm = ViMbAdmin_Doveadm::fromOptions( $this->options() );

        if( $status === \Entities\Archive::STATUS_PENDING_DELETE )
        {
            // delete the backup files + the archive row.
            if( $dest )
                $doveadm->fsDelete( $dest );
            $em->remove( $archive );
            $em->flush();
            return [ 'deleted' => $username ];
        }

        // restore: recreate the mailbox from the snapshot if it's gone, sync the
        // mail back, then drop the backup + row.
        $mailbox = $em->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $username ] );
        if( !$mailbox )
        {
            $snap = json_decode( (string) $archive->getData(), true );
            $mb   = ( is_array( $snap ) && isset( $snap['mailbox'] ) ) ? $snap['mailbox'] : null;
            if( !$mb )
                throw new ViMbAdmin_Mcp_Exception( 'no mailbox snapshot stored with this archive — cannot restore' );

            $mailbox = new \Entities\Mailbox();
            $mailbox->setUsername( $mb['username'] )->setLocalPart( $mb['local_part'] )
                    ->setName( $mb['name'] )->setPassword( $mb['password'] )
                    ->setQuota( $mb['quota'] )->setActive( $mb['active'] )
                    ->setDomain( $archive->getDomain() )->setCreated( new \DateTime() );
            $archive->getDomain()->increaseMailboxCount();
            $em->persist( $mailbox );
            $em->flush();
        }
        if( $dest )
        {
            $doveadm->restoreFrom( $username, $dest );
            $doveadm->fsDelete( $dest );
        }
        $em->remove( $archive );
        $em->flush();
        return [ 'restored' => $username ];
    }

    // ---- lookup + param helpers ----------------------------------------

    /**
     * @return \Entities\Domain
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireDomain( array $params )
    {
        $name   = $this->_str( $params, 'domain', true );
        $domain = $this->em()->getRepository( '\\Entities\\Domain' )->findOneBy( [ 'domain' => $name ] );
        if( !$domain )
            throw new ViMbAdmin_Mcp_Exception( 'unknown domain' );
        $this->_assertDomainAllowed( $domain->getDomain() );
        return $domain;
    }

    /**
     * Enforce the token's per-token domain allowlist. Empty allowlist = all
     * domains. Reports "unknown domain" rather than "forbidden" so a token
     * can't enumerate which domains exist outside its scope.
     *
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _assertDomainAllowed( $domain )
    {
        if( $this->_token && !$this->_token->allowsDomain( $domain ) )
            throw new ViMbAdmin_Mcp_Exception( 'unknown domain' );
    }

    /**
     * @return \Entities\Mailbox
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireMailbox( array $params )
    {
        $username = $this->_str( $params, 'username', true );
        $m = $this->em()->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $username ] );
        if( !$m )
            throw new ViMbAdmin_Mcp_Exception( 'unknown mailbox' );
        if( $m->getDomain() )
            $this->_assertDomainAllowed( $m->getDomain()->getDomain() );
        return $m;
    }

    private function _str( array $params, $key, $required = false )
    {
        $v = isset( $params[ $key ] ) ? trim( (string) $params[ $key ] ) : '';
        if( $v === '' && $required )
            throw new ViMbAdmin_Mcp_Exception( "param \"{$key}\" required" );
        return $v;
    }

    /**
     * Run a value through one of the kernel form validators (pure callables that
     * return null on success or an error string). The web forms validate every
     * created local_part / domain / address; the MCP create path MUST enforce the
     * same shape, or a crafted value (path-traversal "../", '/', spaces, control
     * chars) flows unvalidated into the Dovecot maildir/backup paths
     * (QueueRunner::backupDest %d/%u, removeMaildirHome) and mail routing keys.
     *
     * @param callable(mixed):?string $validator
     * @throws ViMbAdmin_Mcp_Exception on a validation miss
     */
    private function _validate( string $value, callable $validator, string $label )
    {
        $err = $validator( $value );
        if( $err !== null )
            throw new ViMbAdmin_Mcp_Exception( "invalid {$label}: {$err}" );
        return $value;
    }

    /** Validate a full email address (localpart@hostname) shape for MCP input. */
    private function _validateEmail( string $addr, string $label )
    {
        $at = strrpos( $addr, '@' );
        if( $at === false || $at === 0 || $at === strlen( $addr ) - 1 )
            throw new ViMbAdmin_Mcp_Exception( "invalid {$label}: must be local@domain" );
        $this->_validate( substr( $addr, 0, $at ), \ViMbAdmin\Kernel\Form\Validators::localPart(), "{$label} local part" );
        $this->_validate( substr( $addr, $at + 1 ), \ViMbAdmin\Kernel\Form\Validators::hostname(), "{$label} domain" );
        return $addr;
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
        $rl = $this->options()['mcp']['ratelimit']['destructive'] ?? [];
        return new ViMbAdmin_Mcp_RateLimit( [
            'statedir' => $this->options()['mcp']['ratelimit']['statedir'] ?? null,
            'max'      => $rl['max']    ?? 10,
            'window'   => $rl['window'] ?? 3600,
        ] );
    }

    // ---- helpers -------------------------------------------------------

    private function _mcpEnabled()
    {
        return isset( $this->options()['mcp']['enabled'] ) && $this->options()['mcp']['enabled'];
    }

    private function _json( $payload, $httpStatus = 200 )
    {
        return $this->json( $payload, $httpStatus );
    }

    private function _rpcResult( $id, $result )
    {
        return $this->_json( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ] );
    }

    private function _rpcError( $id, $code, $message, $httpStatus = 200 )
    {
        return $this->_json( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], $httpStatus );
    }

    /** Auth/transport-level failure: HTTP status + JSON-RPC error envelope. */
    private function _http( $status, $message, $id = null )
    {
        $response = $this->_rpcError( $id, -32000, $message, $status );
        if( $status !== 401 )
            return $response;

        return new \ViMbAdmin\Kernel\Http\Response(
            $response->body,
            $response->status,
            $response->contentType,
            [ 'WWW-Authenticate' => 'Bearer' ]
        );
    }

    private function options(): array
    {
        return $this->container->options();
    }
}
