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

    public function indexAction(): \ViMbAdmin\Kernel\Http\Response
    {
        return $this->_dispatcher()->dispatch(
            $this->_mcpEnabled(),
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            static fn (): string|false => file_get_contents('php://input'),
            $_SERVER,
        );
    }

    // ---- abilities -----------------------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _ping(array $params): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->ping($params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _domainsList(array $params): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->domainsList($this->em(), $this->_token, $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _mailboxesList(array $params): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->mailboxesList($this->em(), $this->_token, $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function _aliasesList(array $params): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->aliasesList($this->em(), $this->_token, $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{int,int}
     */
    private function _listBounds(array $params): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->listBounds($params);
    }

    /**
     * @return array{limit:int,offset:int,returned:int,has_more:bool,next_offset:int|null}
     */
    private function _pagination(int $fetched, int $returned, int $limit, int $offset): array
    {
        return (new ViMbAdmin_Mcp_ReadOperations())->pagination($fetched, $returned, $limit, $offset);
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

    /** @param array<string,mixed> $options */
    private static function _domainDefaultQuotaBytes(array $options, string $key): int
    {
        [$found, $value] = ViMbAdmin_Mcp_Input::option($options, 'defaults', 'domain', $key);
        $value = $found ? $value : 0;
        $name = 'configuration defaults.domain.' . $key;
        if (!(is_int($value) && $value >= 0)
            && !(is_float($value) && is_finite($value) && $value >= 0)
            && !(is_string($value) && preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value) === 1)) {
            throw new ViMbAdmin_Mcp_Exception("{$name} must be a non-negative number");
        }

        $multiplier = strtoupper(ViMbAdmin_Mcp_Input::optionString(
            $options,
            OSS_Filter_FileSize::SIZE_KILOBYTES,
            'defaults',
            'quota',
            'multiplier'
        ));
        if (!array_key_exists($multiplier, OSS_Filter_FileSize::$SIZE_MULTIPLIERS)) {
            throw new ViMbAdmin_Mcp_Exception('configuration defaults.quota.multiplier is unsupported');
        }
        $bytes = (new OSS_Filter_FileSize($multiplier))->filter((string) $value);
        if ((!is_int($bytes) && !is_float($bytes)) || !is_finite((float) $bytes)
            || $bytes < 0 || $bytes >= PHP_INT_MAX) {
            throw new ViMbAdmin_Mcp_Exception("{$name} produced an invalid byte value");
        }
        return (int) $bytes;
    }

    /** @param array<string,mixed> $params
     *  @return array{created:bool,domain:string,id:int} */
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
            self::_domainDefaultQuotaBytes($options, 'quota')
        ));
        $d->setMaxQuota(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'maxquota',
            self::_domainDefaultQuotaBytes($options, 'maxquota')
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
        $password  = $this->_requiredExactStr($params, 'password');
        $username  = $localPart . '@' . $domain->requiredDomainName();

        $repo = $this->_mailboxRepository();
        if (!$repo->isUnique($username)) {
            throw new ViMbAdmin_Mcp_DomainException('mailbox already exists');
        }

        $m = new \Entities\Mailbox();
        $m->setLocalPart($localPart);
        $m->setUsername($username);
        $m->setName($this->_str($params, 'name') ?: $username);
        $m->setQuota(ViMbAdmin_Mcp_Input::optionalInteger($params, 'quota', 0));
        $m->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $m->setPassword($password);

        try {
            ViMbAdmin_Service_Mailbox::applyCreationPolicy($m, $domain);
        } catch (ViMbAdmin_Service_Exception $e) {
            throw new ViMbAdmin_Mcp_DomainException($e->getMessage(), 0, $e);
        }

        $options = $this->options();
        $passwordScheme = ViMbAdmin_Mcp_Input::string(
            ViMbAdmin_Mcp_Input::option($options, 'defaults', 'mailbox', 'password_scheme')[1],
            'configuration defaults.mailbox.password_scheme',
            true
        );
        $mailboxAliases = ViMbAdmin_Mcp_Input::optionBoolean($options, false, 'mailboxAliases');
        (new ViMbAdmin_Service_Mailbox($this->em()))->createWithPolicy(
            $m,
            $domain,
            $passwordScheme,
            $mailboxAliases,
            ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
        );
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
        ViMbAdmin_ResourceClaim::withMailbox($this->em(), $username, function () use ($m): void {
            ViMbAdmin_Recipients::atomic($this->em(), function () use ($m): void {
                $this->_mailboxRepository()->purgeMailbox($m, null, true);
                $this->em()->flush();
            });
        });
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
            $this->_validateEmail($address, 'address');
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

        $countsTowardCapacity = $address != $goto;
        if ($countsTowardCapacity && $domain->getMaxAliases() != 0
            && $domain->getAliasCount() >= $domain->getMaxAliases()) {
            throw new ViMbAdmin_Mcp_DomainException('You have used all of your allocated aliases.');
        }

        $a = new \Entities\Alias();
        $a->setAddress($address);
        $a->setGoto($goto);
        $a->setDomain($domain);
        $a->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $a->setCreated(new \DateTime());
        $em = $this->em();
        $em->persist($a);
        if ($countsTowardCapacity) {
            $domain->increaseAliasCount();
        }
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
        return ViMbAdmin_Recipients::atomic($this->em(), function () use ($a, $domain, $address): array {
            ViMbAdmin_Recipients::guardDelete($this->em(), $a);
            $identity = $this->requiredAliasIdentity($a);
            $em = $this->em();
            $em->remove($a);
            if ($identity['address'] != $identity['goto']) {
                $domain->decreaseAliasCount();
            }
            $em->flush();
            return [ 'deleted' => true, 'address' => $address ];
        });
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
        } else {
            $this->_validateEmail($username, 'username');
            $this->_assertDomainAllowed(substr($username, strrpos($username, '@') + 1));
        }

        $claim = ViMbAdmin_ResourceClaim::acquire($em->getConnection(), $username);
        $operationError = null;
        try {
            $dest    = $archive->getMaildirFile();
            $doveadm = ViMbAdmin_Doveadm::fromOptions($this->options());

            if ($operation === 'delete') {
                $claim->guardArchive($archive);
                // delete the backup files + the archive row.
                if ($dest) {
                    $claim->remote(fn () => $doveadm->fsDelete($dest));
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

                $claim->guardArchive($archive);
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
            $claim->guardArchive($archive);
            if ($dest) {
                $claim->remote(fn () => $doveadm->restoreFrom($username, $dest));
                $claim->remote(fn () => $doveadm->fsDelete($dest));
            }
            $em->remove($archive);
            $em->flush();
            return [ 'restored' => $username ];
        } catch (\Throwable $error) {
            $operationError = $error;
            $claim->retainOnFailure($error);
            throw $error;
        } finally {
            try {
                $claim->finish();
            } catch (\Throwable $cleanupError) {
                if ($operationError === null) {
                    throw $cleanupError;
                }
                error_log('MCP archive resource cleanup failed after rejection: ' . $cleanupError->getMessage());
            }
        }

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
    private function _requiredExactStr(array $params, string $key): string
    {
        if (!array_key_exists($key, $params)) {
            throw new ViMbAdmin_Mcp_Exception("param \"{$key}\" required");
        }

        return ViMbAdmin_Mcp_Input::exactString($params[$key], "param \"{$key}\"", true);
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
    private function _methodDefinition(string $method, mixed $id): array
    {
        return ViMbAdmin_Mcp_Dispatcher::definition($this->_methodTable(), $method, $id);
    }

    private function _dispatcher(): ViMbAdmin_Mcp_Dispatcher
    {
        $dispatcher = new ViMbAdmin_Mcp_Dispatcher(
            $this->_methodTable(),
            function (array $server, string $scope): \Entities\McpToken {
                $options = $this->options();
                [$trustedProxyFound, $trustedProxyValue] = ViMbAdmin_Mcp_Input::option($options, 'trustedproxy');
                $trustedProxy = ViMbAdmin_Mcp_Input::trustedProxy(
                    $trustedProxyFound ? $trustedProxyValue : null,
                );
                $auth = new ViMbAdmin_Mcp_Auth($this->em(), $trustedProxy);
                $token = $auth->authenticate(
                    ViMbAdmin_Mcp_Input::map($server, 'server environment'),
                    $scope,
                );
                $this->_token = $token;
                return $token;
            },
            function (\Entities\McpToken $token): void {
                $this->_rateLimiter()->hit($token->getId());
            },
        );
        return $dispatcher;
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

    private function _protocolError(ViMbAdmin_Mcp_ProtocolException $error): \ViMbAdmin\Kernel\Http\Response
    {
        return ViMbAdmin_Mcp_Dispatcher::protocolError($error);
    }

    private function _applicationError(mixed $id, ViMbAdmin_Mcp_Exception $error): \ViMbAdmin\Kernel\Http\Response
    {
        return ViMbAdmin_Mcp_Dispatcher::applicationError($id, $error);
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
