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
    private const LIST_MAX = 200;
    private const LIST_MAX_OFFSET = 10000;
    /** @var \Entities\McpToken|null  the authenticated token for this request */
    private $_token = null;

    // =====================================================================
    //  Web endpoint  (POST /mcp, JSON-RPC 2.0)
    // =====================================================================

    public function indexAction(): \ViMbAdmin\Kernel\Http\Response
    {
        if (!$this->_mcpEnabled()) {
            return $this->_http(404, 'mcp disabled');
        }

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!is_string($requestMethod) || strtoupper($requestMethod) !== 'POST') {
            return $this->_http(405, 'POST required');
        }

        $body = file_get_contents('php://input');
        if (!is_string($body)) {
            return $this->_rpcError(null, -32700, 'parse error');
        }
        try {
            $req = ViMbAdmin_Mcp_Request::parse($body);
            $id = $req['id'];
            $method = $req['method'];
            $params = $req['params'];
            $definition = $this->_methodDefinition($method);
        } catch (ViMbAdmin_Mcp_ProtocolException $e) {
            return $this->_protocolError($e);
        }

        // ---- authenticate (bearer + ip + scope) -------------------------
        try {
            $options = $this->options();
            [$trustedProxyFound, $trustedProxyValue] = ViMbAdmin_Mcp_Input::option($options, 'trustedproxy');
            $trustedProxy = ViMbAdmin_Mcp_Input::trustedProxy(
                $trustedProxyFound ? $trustedProxyValue : null
            );
            $server = ViMbAdmin_Mcp_Input::map($_SERVER, 'server environment');
            $auth  = new ViMbAdmin_Mcp_Auth($this->em(), $trustedProxy);
            $token = $this->_token = $auth->authenticate($server, $definition['scope']);

            // Destructive methods are additionally per-token rate-limited.
            if ($definition['destructive']) {
                $this->_rateLimiter()->hit($token->getId());
            }
        } catch (ViMbAdmin_Mcp_Exception $e) {
            return $this->_http((int) $e->getCode() ?: 403, $e->getMessage(), $id);
        }

        // ---- dispatch ---------------------------------------------------
        try {
            $result = ($definition['handler'])($params);
        } catch (ViMbAdmin_Mcp_DomainException $e) {
            return $this->_applicationError($id, $e);
        } catch (ViMbAdmin_Mcp_Exception $e) {
            return $this->_applicationError($id, $e);
        } catch (\Throwable $e) {
            error_log('MCP ' . $method . ': ' . $e->getMessage());
            return $this->_rpcError($id, -32603, 'internal error');
        }

        return $this->_rpcResult($id, $result);
    }

    // ---- abilities -----------------------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _ping(array $params): array
    {
        unset($params);
        return [ 'pong' => true, 'time' => gmdate('c') ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _domainsList(array $params): array
    {
        [$limit, $offset] = $this->_listBounds($params);
        $criteria = [];
        if ($this->_token) {
            $allowed = trim((string) $this->_token->getAllowedDomains());
            if ($allowed !== '') {
                $criteria['domain'] = array_map(
                    static fn (string $domain): string => ViMbAdmin_Identity::canonical($domain),
                    preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) ?: []
                );
            }
        }
        $out = [];
        $repository = $this->em()->getRepository('\\Entities\\Domain');
        $query = $repository->createQueryBuilder('d')->orderBy('d.domain', 'ASC')
            ->setFirstResult($offset)->setMaxResults($limit);
        if (isset($criteria['domain'])) {
            $query->andWhere('LOWER(d.domain) IN (:allowed)')->setParameter('allowed', $criteria['domain']);
        }
        $domains = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $query->getQuery()->getResult(),
            \Entities\Domain::class,
            'MCP domain list query'
        );
        foreach ($domains as $d) {
            $domainName = $d->requiredDomainName();
            if ($this->_token && !$this->_token->allowsDomain($domainName)) {
                continue;
            }
            $out[] = [
                'id'        => $d->requiredId(),
                'domain'    => $domainName,
                'active'    => (bool) $d->getActive(),
                'transport' => $d->getTransport(),
                'quota'     => $d->requiredQuota(),
                'maxquota'  => $d->getMaxQuota(),
                'mailboxes' => $d->getMailboxCount(),
                'aliases'   => $d->getAliasCount(),
            ];
        }
        return [ 'domains' => $out ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _mailboxesList(array $params): array
    {
        $domain = $this->_requireDomain($params);
        [$limit, $offset] = $this->_listBounds($params);
        $domainName = $domain->requiredDomainName();
        $out = [];
        $mailboxes = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $this->em()->getRepository('\\Entities\\Mailbox')->findBy(
                [ 'Domain' => $domain ],
                [ 'username' => 'ASC' ],
                $limit,
                $offset
            ),
            \Entities\Mailbox::class,
            'MCP mailbox list query'
        );
        foreach ($mailboxes as $m) {
            $out[] = [
                'username'   => $m->requiredUsername(),
                'name'       => $m->getName(),
                'active'     => (bool) $m->getActive(),
                'quota'      => $m->getQuota(),
                'local_part' => $m->getLocalPart(),
            ];
        }
        return [ 'domain' => $domainName, 'mailboxes' => $out ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _aliasesList(array $params): array
    {
        $domain = $this->_requireDomain($params);
        [$limit, $offset] = $this->_listBounds($params);
        $domainName = $domain->requiredDomainName();
        $out = [];
        $aliases = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $this->em()->getRepository('\\Entities\\Alias')->findBy(
                [ 'Domain' => $domain ],
                [ 'address' => 'ASC' ],
                $limit,
                $offset
            ),
            \Entities\Alias::class,
            'MCP alias list query'
        );
        foreach ($aliases as $a) {
            $identity = $this->requiredAliasIdentity($a);
            $out[] = [
                'address' => $identity['address'],
                'goto'    => $identity['goto'],
                'active'  => (bool) $a->getActive(),
            ];
        }
        return [ 'domain' => $domainName, 'aliases' => $out ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{int,int}
     */
    private function _listBounds(array $params): array
    {
        $limit = ViMbAdmin_Mcp_Input::optionalInteger($params, 'limit', 100);
        $offset = ViMbAdmin_Mcp_Input::optionalInteger($params, 'offset', 0);
        if ($limit < 1 || $limit > self::LIST_MAX) {
            throw new ViMbAdmin_Mcp_Exception('param "limit" must be between 1 and ' . self::LIST_MAX);
        }
        if ($offset > self::LIST_MAX_OFFSET) {
            throw new ViMbAdmin_Mcp_Exception('param "offset" must be at most ' . self::LIST_MAX_OFFSET);
        }
        return [ $limit, $offset ];
    }

    /** @return array{address:string,goto:string} */
    private function requiredAliasIdentity(\Entities\Alias $alias): array
    {
        $address = $alias->getAddress();
        if ($address === null) {
            throw new \LogicException('Alias address cannot be null.');
        }

        $goto = $alias->getGoto();
        if ($goto === null) {
            throw new \LogicException('Alias goto cannot be null.');
        }

        return [ 'address' => $address, 'goto' => $goto ];
    }

    // ---- write abilities (scope: write) --------------------------------

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _domainCreate(array $params): array
    {
        $name = $this->_identity($params, 'domain');
        $this->_validate($name, \ViMbAdmin\Kernel\Form\Validators::hostname(), 'domain');
        // Bind the per-token domain allowlist to creation too: a token scoped to
        // specific domains must not be able to create one outside that list.
        $this->_assertDomainAllowed($name);
        if ($this->em()->getRepository('\\Entities\\Domain')->findOneBy([ 'domain' => $name ])) {
            throw new ViMbAdmin_Mcp_DomainException('domain already exists');
        }

        $d = new \Entities\Domain();
        $options = $this->options();
        $d->setDomain($name);
        $d->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $transport = $this->_str($params, 'transport');
        $d->setTransport($transport !== '' ? $transport : ViMbAdmin_Mcp_Input::optionString(
            $options,
            'virtual',
            'defaults',
            'domain',
            'transport'
        ));
        $d->setQuota(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'quota',
            ViMbAdmin_Mcp_Input::optionInteger($options, 0, 'defaults', 'domain', 'quota')
        ));
        $d->setMaxQuota(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'maxquota',
            ViMbAdmin_Mcp_Input::optionInteger($options, 0, 'defaults', 'domain', 'maxquota')
        ));
        $d->setMaxMailboxes(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'max_mailboxes',
            ViMbAdmin_Mcp_Input::optionInteger($options, 0, 'defaults', 'domain', 'mailboxes')
        ));
        $d->setMaxAliases(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'max_aliases',
            ViMbAdmin_Mcp_Input::optionInteger($options, 0, 'defaults', 'domain', 'aliases')
        ));
        $d->setBackupmx(false);
        $d->setMailboxCount(0);
        $d->setAliasCount(0);
        $d->setCreated(new \DateTime());
        $createdDomainName = $d->requiredDomainName();

        $em = $this->em();
        $em->persist($d);
        $em->flush();
        return [ 'created' => true, 'domain' => $createdDomainName, 'id' => $d->requiredId() ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _domainDelete(array $params): array
    {
        $domain = $this->_requireDomain($params);
        $name   = $domain->requiredDomainName();
        $this->_domainRepository()->purge($domain);
        return [ 'deleted' => true, 'domain' => $name ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _mailboxCreate(array $params): array
    {
        $domain    = $this->_requireDomain($params);
        $localPart = $this->_identity($params, 'local_part');
        $this->_validate($localPart, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'local_part');
        $password  = $this->_str($params, 'password', true);
        $username  = $localPart . '@' . $domain->requiredDomainName();

        $repo = $this->_mailboxRepository();
        if (!$repo->isUnique($username)) {
            throw new ViMbAdmin_Mcp_DomainException('mailbox already exists');
        }

        $m = new \Entities\Mailbox();
        $m->setLocalPart($localPart);
        $m->setUsername($username);
        $m->setName($this->_str($params, 'name') ?: $username);
        $m->setDomain($domain);
        $m->setQuota(ViMbAdmin_Mcp_Input::optionalInteger($params, 'quota', 0));
        $m->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $m->setDeletePending(false);
        $m->setCreated(new \DateTime());
        $m->setPassword(OSS_Auth_Password::hash($password, [
            'pwhash'    => ViMbAdmin_Mcp_Input::string(
                ViMbAdmin_Mcp_Input::option($this->options(), 'defaults', 'mailbox', 'password_scheme')[1],
                'configuration defaults.mailbox.password_scheme',
                true
            ),
            'username'  => $username,
        ]));

        $em = $this->em();
        $em->persist($m);

        // Auto mailbox-alias (address -> address). Reuse an existing alias with
        // that address rather than inserting a duplicate (which would violate
        // the unique key and roll the whole create back -- e.g. an orphan alias
        // left by an earlier failed attempt).
        if (ViMbAdmin_Mcp_Input::optionBoolean($this->options(), false, 'mailboxAliases')
            && !$em->getRepository('\\Entities\\Alias')->findOneBy([ 'address' => $username ])) {
            $a = new \Entities\Alias();
            $a->setAddress($username);
            $a->setGoto($username);
            $a->setDomain($domain);
            $a->setActive(true);
            $a->setCreated(new \DateTime());
            $em->persist($a);
            $domain->setAliasCount($domain->getAliasCount() + 1);
        }
        $domain->setMailboxCount($domain->getMailboxCount() + 1);
        $em->flush();
        return [ 'created' => true, 'username' => $username ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _mailboxDelete(array $params): array
    {
        $m = $this->_requireMailbox($params);
        $username = $m->requiredUsername();
        $this->_mailboxRepository()->purgeMailbox($m, null, true);
        $this->em()->flush();
        return [ 'deleted' => true, 'username' => $username ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _aliasCreate(array $params): array
    {
        $domain  = $this->_requireDomain($params);
        $address = $this->_identity($params, 'address');
        $goto    = $this->_identity($params, 'goto');
        if (strpos($address, '@') === false) {
            $this->_validate($address, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'address local part');
            $address .= '@' . $domain->requiredDomainName();
        } else {
            $this->_validateEmail($address, 'address');
            $addressDomain = substr($address, strrpos($address, '@') + 1);
            if (strcasecmp($addressDomain, $domain->requiredDomainName()) !== 0) {
                throw new ViMbAdmin_Mcp_Exception('address domain must match the authorized domain');
            }
        }
        $this->_validateEmail($goto, 'goto');

        $repo = $this->em()->getRepository('\\Entities\\Alias');
        if ($repo->findOneBy([ 'address' => $address ])) {
            throw new ViMbAdmin_Mcp_DomainException('alias already exists');
        }

        $a = new \Entities\Alias();
        $a->setAddress($address);
        $a->setGoto($goto);
        $a->setDomain($domain);
        $a->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $a->setCreated(new \DateTime());
        $em = $this->em();
        $em->persist($a);
        $domain->setAliasCount($domain->getAliasCount() + 1);
        $em->flush();
        return [ 'created' => true, 'address' => $address ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _aliasDelete(array $params): array
    {
        $address = $this->_identity($params, 'address');
        $a = $this->em()->getRepository('\\Entities\\Alias')->findOneBy([ 'address' => $address ]);
        if (!$a) {
            throw new ViMbAdmin_Mcp_DomainException('unknown alias');
        }
        $domain = $a->getDomain();
        if (!$domain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown alias');
        }
        $this->_assertDomainAllowed($domain->requiredDomainName());
        $em = $this->em();
        $em->remove($a);
        $domain->setAliasCount(max(0, $domain->getAliasCount() - 1));
        $em->flush();
        return [ 'deleted' => true, 'address' => $address ];
    }

    // ---- destructive: archive queue (scope: write, rate-limited) --------

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _mailboxArchive(array $params): array
    {
        $m  = $this->_requireMailbox($params);
        $em = $this->em();
        $username = $m->requiredUsername();

        // Queue a real ARCHIVE task (doveadm backup -> empty store, keep
        // account), exactly like the panel button. The runner records the
        // archive row + backup; we don't serialise/purge here.
        $task = ViMbAdmin_MailboxQueue::enqueue($em, $m, \Entities\MailboxTask::TYPE_ARCHIVE, null);
        $em->flush();

        // (The queue is drained only by the external cron; no in-app trigger.)

        return self::_mailboxArchiveResult($task !== null, $username);
    }

    /** @return array<string,mixed> */
    private static function _mailboxArchiveResult(bool $queued, string $username): array
    {
        return $queued
            ? [ 'queued' => 'ARCHIVE', 'username' => $username ]
            : [ 'queued' => false, 'already_queued' => true, 'username' => $username ];
    }

    /**
     * Restore or delete an existing archive. These map onto the immediate
     * ArchiveController actions; over MCP we perform the equivalent directly.
     * $operation is either "restore" or "delete".
     */
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _archiveRestore(array $params): array
    {
        return $this->_archiveState($params, 'restore');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _archiveDelete(array $params): array
    {
        return $this->_archiveState($params, 'delete');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _archiveState(array $params, string $operation): array
    {
        if ($operation !== 'restore' && $operation !== 'delete') {
            throw new ViMbAdmin_Mcp_DomainException('unknown archive operation');
        }

        $username = $this->_identity($params, 'username');
        $em       = $this->em();
        $archive  = $em->getRepository('\\Entities\\Archive')->findOneBy([ 'username' => $username ]);
        if (!$archive) {
            throw new ViMbAdmin_Mcp_DomainException('no archive for that username');
        }
        $archiveDomain = $archive->getDomain();
        if ($archiveDomain) {
            $this->_assertDomainAllowed($archiveDomain->requiredDomainName());
        }

        $dest    = $archive->getMaildirFile();
        $doveadm = ViMbAdmin_Doveadm::fromOptions($this->options());

        if ($operation === 'delete') {
            // delete the backup files + the archive row.
            if ($dest) {
                $doveadm->fsDelete($dest);
            }
            $em->remove($archive);
            $em->flush();
            return [ 'deleted' => $username ];
        }

        // restore: recreate the mailbox from the snapshot if it's gone, sync the
        // mail back, then drop the backup + row.
        $mailbox = $em->getRepository('\\Entities\\Mailbox')->findOneBy([ 'username' => $username ]);
        if (!$mailbox) {
            $archiveDomain = $archive->requiredDomain();
            $archiveData = $archive->getData();
            $snap = is_string($archiveData) ? json_decode($archiveData, true) : null;
            if (!is_array($snap) || !array_key_exists('mailbox', $snap)) {
                throw new ViMbAdmin_Mcp_DomainException('no mailbox snapshot stored with this archive — cannot restore');
            }
            try {
                $mb = ViMbAdmin_Mcp_Input::mailboxSnapshot($snap['mailbox']);
            } catch (ViMbAdmin_Mcp_Exception $e) {
                throw new ViMbAdmin_Mcp_DomainException($e->getMessage(), 0, $e);
            }
            $expectedSnapshotUsername = $mb['local_part'] . '@' . $archiveDomain->requiredDomainName();
            if ($mb['username'] !== $username || $mb['username'] !== $expectedSnapshotUsername) {
                throw new ViMbAdmin_Mcp_DomainException('archive mailbox snapshot identity mismatch');
            }

            $mailbox = new \Entities\Mailbox();
            $mailbox->setUsername($mb['username'])->setLocalPart($mb['local_part']);
            if ($mb['name'] !== null) {
                $mailbox->setName($mb['name']);
            }
            $mailbox->setPassword($mb['password'])
                    ->setQuota($mb['quota'])->setActive($mb['active'])
                    ->setDomain($archiveDomain)->setCreated(new \DateTime());
            $archiveDomain->increaseMailboxCount();
            $em->persist($mailbox);
            $em->flush();
        }
        if ($dest) {
            $doveadm->restoreFrom($username, $dest);
            $doveadm->fsDelete($dest);
        }
        $em->remove($archive);
        $em->flush();
        return [ 'restored' => $username ];
    }

    // ---- lookup + param helpers ----------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return \Entities\Domain
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireDomain(array $params)
    {
        $name   = $this->_identity($params, 'domain');
        $domain = $this->em()->getRepository('\\Entities\\Domain')->findOneBy([ 'domain' => $name ]);
        if (!$domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
        $this->_assertDomainAllowed($domain->requiredDomainName());
        return $domain;
    }

    /**
     * Enforce the token's per-token domain allowlist. Empty allowlist = all
     * domains. Reports "unknown domain" rather than "forbidden" so a token
     * can't enumerate which domains exist outside its scope.
     *
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _assertDomainAllowed(string $domain): void
    {
        if ($this->_token && !$this->_token->allowsDomain($domain)) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return \Entities\Mailbox
     * @throws ViMbAdmin_Mcp_Exception
     */
    private function _requireMailbox(array $params)
    {
        $username = $this->_identity($params, 'username');
        $m = $this->em()->getRepository('\\Entities\\Mailbox')->findOneBy([ 'username' => $username ]);
        if (!$m) {
            throw new ViMbAdmin_Mcp_DomainException('unknown mailbox');
        }
        $mailboxDomain = $m->getDomain();
        if (!$mailboxDomain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown mailbox');
        }
        $this->_assertDomainAllowed($mailboxDomain->requiredDomainName());
        return $m;
    }

    /** @param array<string,mixed> $params */
    private function _str(array $params, string $key, bool $required = false): string
    {
        if (!array_key_exists($key, $params)) {
            if ($required) {
                throw new ViMbAdmin_Mcp_Exception("param \"{$key}\" required");
            }
            return '';
        }

        return ViMbAdmin_Mcp_Input::string($params[$key], "param \"{$key}\"", $required);
    }

    /** @param array<string,mixed> $params */
    private function _identity(array $params, string $key): string
    {
        if (!array_key_exists($key, $params)) {
            throw new ViMbAdmin_Mcp_Exception("param \"{$key}\" required");
        }

        return ViMbAdmin_Mcp_Input::identity($params[$key], "param \"{$key}\"", true);
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
    private function _validate(string $value, callable $validator, string $label): string
    {
        $err = $validator($value);
        if ($err !== null) {
            throw new ViMbAdmin_Mcp_Exception("invalid {$label}: {$err}");
        }
        return $value;
    }

    /** Validate a full email address (localpart@hostname) shape for MCP input. */
    private function _validateEmail(string $addr, string $label): string
    {
        $at = strrpos($addr, '@');
        if ($at === false || $at === 0 || $at === strlen($addr) - 1) {
            throw new ViMbAdmin_Mcp_Exception("invalid {$label}: must be local@domain");
        }
        $this->_validate(substr($addr, 0, $at), \ViMbAdmin\Kernel\Form\Validators::localPart(), "{$label} local part");
        $this->_validate(substr($addr, $at + 1), \ViMbAdmin\Kernel\Form\Validators::hostname(), "{$label} domain");
        return $addr;
    }

    // ---- scope / rate-limit routing ------------------------------------

    /**
     * The authoritative MCP ability table. Its closures are both the dispatch
     * targets and static references that keep handler coverage verifiable.
     *
     * @return array<string,array{
     *     handler:\Closure(array<string,mixed>):array<string,mixed>,
     *     scope:'read'|'write',
     *     destructive:bool
     * }>
     */
    private function _methodTable(): array
    {
        return [
            'ping'            => [ 'handler' => $this->_ping(...),           'scope' => 'read',  'destructive' => false ],
            'domains.list'    => [ 'handler' => $this->_domainsList(...),    'scope' => 'read',  'destructive' => false ],
            'mailboxes.list'  => [ 'handler' => $this->_mailboxesList(...),  'scope' => 'read',  'destructive' => false ],
            'aliases.list'    => [ 'handler' => $this->_aliasesList(...),    'scope' => 'read',  'destructive' => false ],
            'domain.create'   => [ 'handler' => $this->_domainCreate(...),   'scope' => 'write', 'destructive' => false ],
            'domain.delete'   => [ 'handler' => $this->_domainDelete(...),   'scope' => 'write', 'destructive' => true  ],
            'mailbox.create'  => [ 'handler' => $this->_mailboxCreate(...),  'scope' => 'write', 'destructive' => false ],
            'mailbox.delete'  => [ 'handler' => $this->_mailboxDelete(...),  'scope' => 'write', 'destructive' => true  ],
            'alias.create'    => [ 'handler' => $this->_aliasCreate(...),    'scope' => 'write', 'destructive' => false ],
            'alias.delete'    => [ 'handler' => $this->_aliasDelete(...),    'scope' => 'write', 'destructive' => false ],
            'mailbox.archive' => [ 'handler' => $this->_mailboxArchive(...), 'scope' => 'write', 'destructive' => true  ],
            'archive.restore' => [ 'handler' => $this->_archiveRestore(...), 'scope' => 'write', 'destructive' => true  ],
            'archive.delete'  => [ 'handler' => $this->_archiveDelete(...),  'scope' => 'write', 'destructive' => true  ],
        ];
    }

    /**
     * @return array{
     *     handler:\Closure(array<string,mixed>):array<string,mixed>,
     *     scope:'read'|'write',
     *     destructive:bool
     * }
     */
    private function _methodDefinition(string $method): array
    {
        $table = $this->_methodTable();
        if (!array_key_exists($method, $table)) {
            throw new ViMbAdmin_Mcp_ProtocolException("unknown method '{$method}'", -32601);
        }

        return $table[$method];
    }

    private function _rateLimiter(): ViMbAdmin_Mcp_RateLimit
    {
        $options = $this->options();
        [$stateDirFound, $stateDir] = ViMbAdmin_Mcp_Input::option($options, 'mcp', 'ratelimit', 'statedir');
        return new ViMbAdmin_Mcp_RateLimit([
            'statedir' => $stateDirFound
                ? ViMbAdmin_Mcp_Input::string($stateDir, 'configuration mcp.ratelimit.statedir', true)
                : null,
            'max'      => ViMbAdmin_Mcp_Input::optionInteger($options, 10, 'mcp', 'ratelimit', 'destructive', 'max'),
            'window'   => ViMbAdmin_Mcp_Input::optionInteger($options, 3600, 'mcp', 'ratelimit', 'destructive', 'window'),
        ]);
    }

    // ---- helpers -------------------------------------------------------

    private function _mcpEnabled(): bool
    {
        try {
            return ViMbAdmin_Mcp_Input::optionBoolean($this->options(), false, 'mcp', 'enabled');
        } catch (ViMbAdmin_Mcp_Exception $e) {
            error_log('MCP configuration rejected: ' . $e->getMessage());
            return false;
        }
    }

    private function _json(mixed $payload, int $httpStatus = 200): \ViMbAdmin\Kernel\Http\Response
    {
        return $this->json($payload, $httpStatus);
    }

    private function _rpcResult(mixed $id, mixed $result): \ViMbAdmin\Kernel\Http\Response
    {
        return $this->_json([ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ]);
    }

    private function _rpcError(mixed $id, int $code, string $message, int $httpStatus = 200): \ViMbAdmin\Kernel\Http\Response
    {
        return $this->_json([ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], $httpStatus);
    }

    private function _protocolError(ViMbAdmin_Mcp_ProtocolException $error): \ViMbAdmin\Kernel\Http\Response
    {
        if (!$error->shouldRespond()) {
            return new \ViMbAdmin\Kernel\Http\Response('', 400, 'text/plain; charset=utf-8');
        }

        return $this->_rpcError($error->rpcId(), $error->rpcCode(), $error->getMessage());
    }

    private function _applicationError(mixed $id, ViMbAdmin_Mcp_Exception $error): \ViMbAdmin\Kernel\Http\Response
    {
        $code = $error instanceof ViMbAdmin_Mcp_DomainException ? -32010 : -32602;
        return $this->_rpcError($id, $code, $error->getMessage());
    }

    /** Auth/transport-level failure: HTTP status + JSON-RPC error envelope. */
    private function _http(int $status, string $message, mixed $id = null): \ViMbAdmin\Kernel\Http\Response
    {
        $response = $this->_rpcError($id, -32000, $message, $status);
        if ($status !== 401) {
            return $response;
        }

        return new \ViMbAdmin\Kernel\Http\Response(
            $response->body,
            $response->status,
            $response->contentType,
            [ 'WWW-Authenticate' => 'Bearer' ]
        );
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        return $this->container->options();
    }

    protected function em(): \Doctrine\ORM\EntityManager
    {
        $em = parent::em();
        if (!$em instanceof \Doctrine\ORM\EntityManager) {
            throw new \LogicException('Doctrine entity manager resource has an invalid type');
        }
        return $em;
    }

    private function _domainRepository(): \Repositories\Domain
    {
        $repo = $this->em()->getRepository('\\Entities\\Domain');
        if (!$repo instanceof \Repositories\Domain) {
            throw new \LogicException('Domain repository has an invalid type');
        }
        return $repo;
    }

    private function _mailboxRepository(): \Repositories\Mailbox
    {
        $repo = $this->em()->getRepository('\\Entities\\Mailbox');
        if (!$repo instanceof \Repositories\Mailbox) {
            throw new \LogicException('Mailbox repository has an invalid type');
        }
        return $repo;
    }
}
