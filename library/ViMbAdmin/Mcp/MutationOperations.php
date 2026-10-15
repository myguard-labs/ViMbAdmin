<?php

/** Request-scoped implementations of the MCP mutation abilities. */
final class ViMbAdmin_Mcp_MutationOperations
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly \Doctrine\ORM\EntityManager $em,
        private readonly array $options,
        private readonly ?\Entities\McpToken $token,
    ) {
    }

    /** @param array<string,mixed> $params
     *  @return array{created:bool,domain:string,id:int} */
    public function domainCreate(array $params): array
    {
        $name = $this->identity($params, 'domain');
        $this->validate($name, \ViMbAdmin\Kernel\Form\Validators::hostname(), 'domain');
        $this->assertDomainAllowed($name);
        if ($this->em->getRepository('\\Entities\\Domain')->findOneBy([ 'domain' => $name ])) {
            throw new ViMbAdmin_Mcp_DomainException('domain already exists');
        }

        $domain = new \Entities\Domain();
        $domain->setDomain($name);
        $domain->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $transport = $this->string($params, 'transport');
        $domain->setTransport($transport !== '' ? $transport : ViMbAdmin_Mcp_Input::optionString(
            $this->options,
            'virtual',
            'defaults',
            'domain',
            'transport'
        ));
        $domain->setQuota(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'quota',
            self::domainDefaultQuotaBytes($this->options, 'quota')
        ));
        $domain->setMaxQuota(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'maxquota',
            self::domainDefaultQuotaBytes($this->options, 'maxquota')
        ));
        $domain->setMaxMailboxes(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'max_mailboxes',
            ViMbAdmin_Mcp_Input::optionInteger($this->options, 0, 'defaults', 'domain', 'mailboxes')
        ));
        $domain->setMaxAliases(ViMbAdmin_Mcp_Input::optionalInteger(
            $params,
            'max_aliases',
            ViMbAdmin_Mcp_Input::optionInteger($this->options, 0, 'defaults', 'domain', 'aliases')
        ));
        $domain->setBackupmx(false);
        $domain->setMailboxCount(0);
        $domain->setAliasCount(0);
        $domain->setCreated(new \DateTime());
        $createdDomainName = $domain->requiredDomainName();
        $this->em->persist($domain);
        $this->em->flush();
        return [ 'created' => true, 'domain' => $createdDomainName, 'id' => $domain->requiredId() ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function domainDelete(array $params): array
    {
        $domain = $this->requireDomain($params);
        $name = $domain->requiredDomainName();
        $this->domainRepository()->purge($domain);
        return [ 'deleted' => true, 'domain' => $name ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function mailboxCreate(array $params): array
    {
        $domain = $this->requireDomain($params);
        $localPart = $this->identity($params, 'local_part');
        $this->validate($localPart, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'local_part');
        $password = $this->requiredExactString($params, 'password');
        $username = $localPart . '@' . $domain->requiredDomainName();
        if (!$this->mailboxRepository()->isUnique($username)) {
            throw new ViMbAdmin_Mcp_DomainException('mailbox already exists');
        }

        $mailbox = new \Entities\Mailbox();
        $mailbox->setLocalPart($localPart);
        $mailbox->setUsername($username);
        $mailbox->setName($this->string($params, 'name') ?: $username);
        $mailbox->setQuota(ViMbAdmin_Mcp_Input::optionalInteger($params, 'quota', 0));
        $mailbox->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $mailbox->setPassword($password);
        try {
            ViMbAdmin_Service_Mailbox::applyCreationPolicy($mailbox, $domain);
        } catch (ViMbAdmin_Service_Exception $error) {
            throw new ViMbAdmin_Mcp_DomainException($error->getMessage(), 0, $error);
        }
        $passwordScheme = ViMbAdmin_Mcp_Input::string(
            ViMbAdmin_Mcp_Input::option($this->options, 'defaults', 'mailbox', 'password_scheme')[1],
            'configuration defaults.mailbox.password_scheme',
            true
        );
        $mailboxAliases = ViMbAdmin_Mcp_Input::optionBoolean($this->options, false, 'mailboxAliases');
        (new ViMbAdmin_Service_Mailbox($this->em))->createWithPolicy(
            $mailbox,
            $domain,
            $passwordScheme,
            $mailboxAliases,
            ViMbAdmin_Service_MailboxCreationPolicy::mcp(),
        );
        return [ 'created' => true, 'username' => $username ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function mailboxDelete(array $params): array
    {
        $mailbox = $this->requireMailbox($params);
        $username = $mailbox->requiredUsername();
        ViMbAdmin_ResourceClaim::withMailbox($this->em, $username, function () use ($mailbox): void {
            ViMbAdmin_Recipients::atomic($this->em, function () use ($mailbox): void {
                $this->mailboxRepository()->purgeMailbox($mailbox, null, true);
                $this->em->flush();
            });
        });
        return [ 'deleted' => true, 'username' => $username ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function aliasCreate(array $params): array
    {
        $domain = $this->requireDomain($params);
        $address = $this->identity($params, 'address');
        $goto = $this->identity($params, 'goto');
        if (strpos($address, '@') === false) {
            $this->validate($address, \ViMbAdmin\Kernel\Form\Validators::localPart(), 'address local part');
            $address .= '@' . $domain->requiredDomainName();
            $this->validateEmail($address, 'address');
        } else {
            $this->validateEmail($address, 'address');
            $addressDomain = substr($address, strrpos($address, '@') + 1);
            if (strcasecmp($addressDomain, $domain->requiredDomainName()) !== 0) {
                throw new ViMbAdmin_Mcp_Exception('address domain must match the authorized domain');
            }
        }
        $this->validateEmail($goto, 'goto');

        $repository = $this->em->getRepository('\\Entities\\Alias');
        if ($repository->findOneBy([ 'address' => $address ])) {
            throw new ViMbAdmin_Mcp_DomainException('alias already exists');
        }
        $countsTowardCapacity = $address != $goto;
        if ($countsTowardCapacity && $domain->getMaxAliases() != 0
            && $domain->getAliasCount() >= $domain->getMaxAliases()) {
            throw new ViMbAdmin_Mcp_DomainException('You have used all of your allocated aliases.');
        }

        $alias = new \Entities\Alias();
        $alias->setAddress($address);
        $alias->setGoto($goto);
        $alias->setDomain($domain);
        $alias->setActive(ViMbAdmin_Mcp_Input::optionalBoolean($params, 'active', true));
        $alias->setCreated(new \DateTime());
        $this->em->persist($alias);
        if ($countsTowardCapacity) {
            $domain->increaseAliasCount();
        }
        $this->em->flush();
        return [ 'created' => true, 'address' => $address ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function aliasDelete(array $params): array
    {
        $address = $this->identity($params, 'address');
        $alias = $this->em->getRepository('\\Entities\\Alias')->findOneBy([ 'address' => $address ]);
        if (!$alias) {
            throw new ViMbAdmin_Mcp_DomainException('unknown alias');
        }
        $domain = $alias->getDomain();
        if (!$domain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown alias');
        }
        $this->assertDomainAllowed($domain->requiredDomainName());
        return ViMbAdmin_Recipients::atomic($this->em, function () use ($alias, $domain, $address): array {
            ViMbAdmin_Recipients::guardDelete($this->em, $alias);
            $identity = self::requiredAliasIdentity($alias);
            $this->em->remove($alias);
            if ($identity['address'] != $identity['goto']) {
                $domain->decreaseAliasCount();
            }
            $this->em->flush();
            return [ 'deleted' => true, 'address' => $address ];
        });
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function mailboxArchive(array $params): array
    {
        $mailbox = $this->requireMailbox($params);
        $username = $mailbox->requiredUsername();
        $task = ViMbAdmin_MailboxQueue::enqueue($this->em, $mailbox, \Entities\MailboxTask::TYPE_ARCHIVE, null);
        $this->em->flush();
        return self::mailboxArchiveResult($task !== null, $username);
    }

    /** @return array<string,mixed> */
    public static function mailboxArchiveResult(bool $queued, string $username): array
    {
        return $queued
            ? [ 'queued' => 'ARCHIVE', 'username' => $username ]
            : [ 'queued' => false, 'already_queued' => true, 'username' => $username ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function archiveRestore(array $params): array
    {
        return $this->archiveState($params, 'restore');
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function archiveDelete(array $params): array
    {
        return $this->archiveState($params, 'delete');
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function archiveState(array $params, string $operation): array
    {
        if ($operation !== 'restore' && $operation !== 'delete') {
            throw new ViMbAdmin_Mcp_DomainException('unknown archive operation');
        }
        $username = $this->identity($params, 'username');
        $archive = $this->em->getRepository('\\Entities\\Archive')->findOneBy([ 'username' => $username ]);
        if (!$archive) {
            throw new ViMbAdmin_Mcp_DomainException('no archive for that username');
        }
        $archiveDomain = $archive->getDomain();
        if ($archiveDomain) {
            $this->assertDomainAllowed($archiveDomain->requiredDomainName());
        } else {
            $this->validateEmail($username, 'username');
            $this->assertDomainAllowed(substr($username, strrpos($username, '@') + 1));
        }

        $claim = ViMbAdmin_ResourceClaim::acquire($this->em->getConnection(), $username);
        $operationError = null;
        try {
            $destination = $archive->getMaildirFile();
            $doveadm = ViMbAdmin_Doveadm::fromOptions($this->options);
            if ($operation === 'delete') {
                $claim->guardArchive($archive);
                if ($destination) {
                    $claim->remote(fn () => $doveadm->fsDelete($destination));
                }
                $this->em->remove($archive);
                $this->em->flush();
                return [ 'deleted' => $username ];
            }

            $mailbox = $this->em->getRepository('\\Entities\\Mailbox')->findOneBy([ 'username' => $username ]);
            if (!$mailbox) {
                $archiveDomain = $archive->requiredDomain();
                $archiveData = $archive->getData();
                $snapshot = is_string($archiveData) ? json_decode($archiveData, true) : null;
                if (!is_array($snapshot) || !array_key_exists('mailbox', $snapshot)) {
                    throw new ViMbAdmin_Mcp_DomainException('no mailbox snapshot stored with this archive — cannot restore');
                }
                try {
                    $mailboxData = ViMbAdmin_Mcp_Input::mailboxSnapshot($snapshot['mailbox']);
                } catch (ViMbAdmin_Mcp_Exception $error) {
                    throw new ViMbAdmin_Mcp_DomainException($error->getMessage(), 0, $error);
                }
                $expectedUsername = $mailboxData['local_part'] . '@' . $archiveDomain->requiredDomainName();
                if ($mailboxData['username'] !== $username || $mailboxData['username'] !== $expectedUsername) {
                    throw new ViMbAdmin_Mcp_DomainException('archive mailbox snapshot identity mismatch');
                }
                $claim->guardArchive($archive);
                $mailbox = new \Entities\Mailbox();
                $mailbox->setUsername($mailboxData['username'])->setLocalPart($mailboxData['local_part']);
                if ($mailboxData['name'] !== null) {
                    $mailbox->setName($mailboxData['name']);
                }
                $mailbox->setPassword($mailboxData['password'])
                    ->setQuota($mailboxData['quota'])->setActive($mailboxData['active'])
                    ->setDomain($archiveDomain)->setCreated(new \DateTime());
                $archiveDomain->increaseMailboxCount();
                $this->em->persist($mailbox);
                $this->em->flush();
            }
            $claim->guardArchive($archive);
            if ($destination) {
                $claim->remote(fn () => $doveadm->restoreFrom($username, $destination));
                $claim->remote(fn () => $doveadm->fsDelete($destination));
            }
            $this->em->remove($archive);
            $this->em->flush();
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

    /** @param array<string,mixed> $options */
    private static function domainDefaultQuotaBytes(array $options, string $key): int
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

    /** @param array<string,mixed> $params */
    private function requireDomain(array $params): \Entities\Domain
    {
        $name = $this->identity($params, 'domain');
        $domain = $this->em->getRepository('\\Entities\\Domain')->findOneBy([ 'domain' => $name ]);
        if (!$domain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
        $this->assertDomainAllowed($domain->requiredDomainName());
        return $domain;
    }

    private function assertDomainAllowed(string $domain): void
    {
        if ($this->token && !$this->token->allowsDomain($domain)) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
    }

    /** @param array<string,mixed> $params */
    private function requireMailbox(array $params): \Entities\Mailbox
    {
        $username = $this->identity($params, 'username');
        $mailbox = $this->em->getRepository('\\Entities\\Mailbox')->findOneBy([ 'username' => $username ]);
        if (!$mailbox instanceof \Entities\Mailbox) {
            throw new ViMbAdmin_Mcp_DomainException('unknown mailbox');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown mailbox');
        }
        $this->assertDomainAllowed($domain->requiredDomainName());
        return $mailbox;
    }

    /** @param array<string,mixed> $params */
    private function string(array $params, string $key, bool $required = false): string
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
    private function requiredExactString(array $params, string $key): string
    {
        if (!array_key_exists($key, $params)) {
            throw new ViMbAdmin_Mcp_Exception("param \"{$key}\" required");
        }
        return ViMbAdmin_Mcp_Input::exactString($params[$key], "param \"{$key}\"", true);
    }

    /** @param array<string,mixed> $params */
    private function identity(array $params, string $key): string
    {
        if (!array_key_exists($key, $params)) {
            throw new ViMbAdmin_Mcp_Exception("param \"{$key}\" required");
        }
        return ViMbAdmin_Mcp_Input::identity($params[$key], "param \"{$key}\"", true);
    }

    /** @param callable(mixed):?string $validator */
    private function validate(string $value, callable $validator, string $label): string
    {
        $error = $validator($value);
        if ($error !== null) {
            throw new ViMbAdmin_Mcp_Exception("invalid {$label}: {$error}");
        }
        return $value;
    }

    private function validateEmail(string $address, string $label): string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            throw new ViMbAdmin_Mcp_Exception("invalid {$label}: must be local@domain");
        }
        $this->validate(substr($address, 0, $at), \ViMbAdmin\Kernel\Form\Validators::localPart(), "{$label} local part");
        $this->validate(substr($address, $at + 1), \ViMbAdmin\Kernel\Form\Validators::hostname(), "{$label} domain");
        return $address;
    }

    /** @return array{address:string,goto:string} */
    private static function requiredAliasIdentity(\Entities\Alias $alias): array
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

    private function domainRepository(): \Repositories\Domain
    {
        $repository = $this->em->getRepository('\\Entities\\Domain');
        if (!$repository instanceof \Repositories\Domain) {
            throw new \LogicException('Domain repository has an invalid type');
        }
        return $repository;
    }

    private function mailboxRepository(): \Repositories\Mailbox
    {
        $repository = $this->em->getRepository('\\Entities\\Mailbox');
        if (!$repository instanceof \Repositories\Mailbox) {
            throw new \LogicException('Mailbox repository has an invalid type');
        }
        return $repository;
    }
}
