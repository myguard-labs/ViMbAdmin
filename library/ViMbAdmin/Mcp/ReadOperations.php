<?php

/** Stateless implementations of the MCP read abilities. */
final class ViMbAdmin_Mcp_ReadOperations
{
    private const LIST_MAX = 200;

    /** @param array<string,mixed> $params
     *  @return array{pong:true,time:string} */
    public function ping(array $params): array
    {
        unset($params);
        return [ 'pong' => true, 'time' => gmdate('c') ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function domainsList(object $entityManager, ?\Entities\McpToken $token, array $params): array
    {
        [$limit, $offset] = $this->listBounds($params);
        $criteria = [];
        if ($token) {
            $allowed = trim((string) $token->getAllowedDomains());
            if ($allowed !== '') {
                $criteria['domain'] = array_map(
                    static fn (string $domain): string => ViMbAdmin_Identity::canonical($domain),
                    preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) ?: []
                );
            }
        }

        $repository = $entityManager->getRepository('\\Entities\\Domain');
        $query = $repository->createQueryBuilder('d')->orderBy('d.domain', 'ASC')
            ->setFirstResult($offset)->setMaxResults($limit + 1);
        if (isset($criteria['domain'])) {
            $query->andWhere('LOWER(d.domain) IN (:allowed)')->setParameter('allowed', $criteria['domain']);
        }
        $domains = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $query->getQuery()->getResult(),
            \Entities\Domain::class,
            'MCP domain list query'
        );
        $out = [];
        foreach (array_slice($domains, 0, $limit) as $domain) {
            $domainName = $domain->requiredDomainName();
            if ($token && !$token->allowsDomain($domainName)) {
                continue;
            }
            $out[] = [
                'id' => $domain->requiredId(),
                'domain' => $domainName,
                'active' => (bool) $domain->getActive(),
                'transport' => $domain->getTransport(),
                'quota' => $domain->requiredQuota(),
                'maxquota' => $domain->getMaxQuota(),
                'mailboxes' => $domain->getMailboxCount(),
                'aliases' => $domain->getAliasCount(),
            ];
        }
        return [
            'domains' => $out,
            'pagination' => $this->pagination(count($domains), count($out), $limit, $offset),
        ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function mailboxesList(object $entityManager, ?\Entities\McpToken $token, array $params): array
    {
        $domain = $this->requireDomain($entityManager, $token, $params);
        [$limit, $offset] = $this->listBounds($params);
        $domainName = $domain->requiredDomainName();
        $mailboxes = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $entityManager->getRepository('\\Entities\\Mailbox')->findBy(
                [ 'Domain' => $domain ],
                [ 'username' => 'ASC' ],
                $limit + 1,
                $offset
            ),
            \Entities\Mailbox::class,
            'MCP mailbox list query'
        );
        $out = [];
        foreach (array_slice($mailboxes, 0, $limit) as $mailbox) {
            $out[] = [
                'username' => $mailbox->requiredUsername(),
                'name' => $mailbox->getName(),
                'active' => (bool) $mailbox->getActive(),
                'quota' => $mailbox->getQuota(),
                'local_part' => $mailbox->getLocalPart(),
            ];
        }
        return [
            'domain' => $domainName,
            'mailboxes' => $out,
            'pagination' => $this->pagination(count($mailboxes), count($out), $limit, $offset),
        ];
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed> */
    public function aliasesList(object $entityManager, ?\Entities\McpToken $token, array $params): array
    {
        $domain = $this->requireDomain($entityManager, $token, $params);
        [$limit, $offset] = $this->listBounds($params);
        $domainName = $domain->requiredDomainName();
        $aliases = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $entityManager->getRepository('\\Entities\\Alias')->findBy(
                [ 'Domain' => $domain ],
                [ 'address' => 'ASC' ],
                $limit + 1,
                $offset
            ),
            \Entities\Alias::class,
            'MCP alias list query'
        );
        $out = [];
        foreach (array_slice($aliases, 0, $limit) as $alias) {
            $identity = self::requiredAliasIdentity($alias);
            $out[] = [
                'address' => $identity['address'],
                'goto' => $identity['goto'],
                'active' => (bool) $alias->getActive(),
            ];
        }
        return [
            'domain' => $domainName,
            'aliases' => $out,
            'pagination' => $this->pagination(count($aliases), count($out), $limit, $offset),
        ];
    }

    /** @param array<string,mixed> $params
     *  @return array{int,int} */
    public function listBounds(array $params): array
    {
        $limit = ViMbAdmin_Mcp_Input::optionalInteger($params, 'limit', 100);
        $offset = ViMbAdmin_Mcp_Input::optionalInteger($params, 'offset', 0);
        if ($limit < 1 || $limit > self::LIST_MAX) {
            throw new ViMbAdmin_Mcp_Exception('param "limit" must be between 1 and ' . self::LIST_MAX);
        }
        return [ $limit, $offset ];
    }

    /** @return array{limit:int,offset:int,returned:int,has_more:bool,next_offset:int|null} */
    public function pagination(int $fetched, int $returned, int $limit, int $offset): array
    {
        $hasMore = $fetched > $limit;
        return [
            'limit' => $limit,
            'offset' => $offset,
            'returned' => $returned,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $limit : null,
        ];
    }

    /** @param array<string,mixed> $params */
    private function requireDomain(object $entityManager, ?\Entities\McpToken $token, array $params): \Entities\Domain
    {
        if (!array_key_exists('domain', $params)) {
            throw new ViMbAdmin_Mcp_Exception('param "domain" required');
        }
        $name = ViMbAdmin_Mcp_Input::identity($params['domain'], 'param "domain"', true);
        $domain = $entityManager->getRepository('\\Entities\\Domain')->findOneBy([ 'domain' => $name ]);
        if (!$domain instanceof \Entities\Domain) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
        if ($token && !$token->allowsDomain($domain->requiredDomainName())) {
            throw new ViMbAdmin_Mcp_DomainException('unknown domain');
        }
        return $domain;
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
}
