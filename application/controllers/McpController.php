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

    // ---- write abilities (scope: write) --------------------------------

    /** @param array<string,mixed> $params */
    private function _domainCreate(array $params): array
    {
        return $this->_mutations()->domainCreate($params);
    }

    /** @param array<string,mixed> $params */
    private function _domainDelete(array $params): array
    {
        return $this->_mutations()->domainDelete($params);
    }

    /** @param array<string,mixed> $params */
    private function _mailboxCreate(array $params): array
    {
        return $this->_mutations()->mailboxCreate($params);
    }

    /** @param array<string,mixed> $params */
    private function _mailboxDelete(array $params): array
    {
        return $this->_mutations()->mailboxDelete($params);
    }

    /** @param array<string,mixed> $params */
    private function _aliasCreate(array $params): array
    {
        return $this->_mutations()->aliasCreate($params);
    }

    /** @param array<string,mixed> $params */
    private function _aliasDelete(array $params): array
    {
        return $this->_mutations()->aliasDelete($params);
    }

    /** @param array<string,mixed> $params */
    private function _mailboxArchive(array $params): array
    {
        return $this->_mutations()->mailboxArchive($params);
    }

    /** @return array<string,mixed> */
    private static function _mailboxArchiveResult(bool $queued, string $username): array
    {
        return ViMbAdmin_Mcp_MutationOperations::mailboxArchiveResult($queued, $username);
    }

    /** @param array<string,mixed> $params */
    private function _archiveRestore(array $params): array
    {
        return $this->_mutations()->archiveRestore($params);
    }

    /** @param array<string,mixed> $params */
    private function _archiveDelete(array $params): array
    {
        return $this->_mutations()->archiveDelete($params);
    }

    /** @param array<string,mixed> $params */
    private function _archiveState(array $params, string $operation): array
    {
        return $this->_mutations()->archiveState($params, $operation);
    }

    private function _mutations(): ViMbAdmin_Mcp_MutationOperations
    {
        return new ViMbAdmin_Mcp_MutationOperations($this->em(), $this->options(), $this->_token);
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

}
