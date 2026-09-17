<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';
require __DIR__ . '/../application/Repositories/McpToken.php';
require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/Auth.php';

final class McpAuthEntityManager
{
    public int $flushes = 0;

    public function __construct(private Repositories\McpToken $repository)
    {
    }

    public function getRepository(string $class): Repositories\McpToken
    {
        if ($class !== '\\Entities\\McpToken') {
            throw new RuntimeException("unexpected repository {$class}");
        }
        return $this->repository;
    }

    public function flush(): void
    {
        $this->flushes++;
    }
}

/** @return Repositories\McpToken */
function mcpAuthRepository(?Entities\McpToken $token): Repositories\McpToken
{
    return new class ($token) extends Repositories\McpToken {
        public function __construct(private ?Entities\McpToken $token)
        {
        }
        public function findByHash($hash): ?Entities\McpToken
        {
            return $this->token;
        }
    };
}

function mcpAuthToken(string $raw, string $scope = 'read', ?string $allowedIps = null): Entities\McpToken
{
    return (new Entities\McpToken())
        ->setName('test')
        ->setTokenHash(hash('sha256', $raw))
        ->setScope($scope)
        ->setAllowedIps($allowedIps)
        ->setCreated(new DateTime());
}

/**
 * Build the unit under test without requiring a database-backed implementation
 * of Doctrine's broad EntityManagerInterface in this focused test.
 *
 * @param array{mode?: scalar|null, proxies?: string|list<string>} $trustedProxy
 */
function mcpAuth(McpAuthEntityManager $em, array $trustedProxy = []): ViMbAdmin_Mcp_Auth
{
    $reflection = new ReflectionClass(ViMbAdmin_Mcp_Auth::class);
    $auth = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('_em')->setValue($auth, $em);
    $reflection->getProperty('_proxyMode')->setValue($auth, (string) ($trustedProxy['mode'] ?? 'auto'));
    $proxies = $trustedProxy['proxies'] ?? [];
    $reflection->getProperty('_proxies')->setValue($auth, is_array($proxies) ? $proxies : [$proxies]);
    return $auth;
}

final class McpAuthAssertions
{
    public static int $failures = 0;
}

function mcpAuthCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        McpAuthAssertions::$failures++;
    }
}

/** @param array<string,mixed> $server */
function mcpAuthDenied(string $label, ViMbAdmin_Mcp_Auth $auth, array $server, int $code): void
{
    try {
        $auth->authenticate($server);
        mcpAuthCheck($label, false);
    } catch (ViMbAdmin_Mcp_Exception $e) {
        mcpAuthCheck($label, $e->getCode() === $code);
    }
}

echo "== MCP authentication ==\n";

$raw = 'valid_token-1';
$token = mcpAuthToken($raw, 'read write', '203.0.113.0/24');
$em = new McpAuthEntityManager(mcpAuthRepository($token));
$auth = mcpAuth($em, ['mode' => 'on', 'proxies' => ['10.0.0.0/8']]);
$server = [
    'HTTP_AUTHORIZATION' => "Bearer {$raw}",
    'REMOTE_ADDR' => '10.0.0.2',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.5',
];
mcpAuthCheck('valid bearer, scope, trusted proxy and IP allowlist authenticate', $auth->authenticate($server, 'write') === $token);
mcpAuthCheck('successful authentication updates last-used timestamp', $token->getLastUsedAt() instanceof DateTime && $em->flushes === 1);
mcpAuthCheck('trusted proxy selects the right-most untrusted client IP', $auth->clientIp($server) === '203.0.113.5');

$untrusted = mcpAuth($em, ['mode' => 'on', 'proxies' => ['10.0.0.0/8']]);
mcpAuthCheck(
    'untrusted direct peer cannot spoof client IP through X-Forwarded-For',
    $untrusted->clientIp(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']) === '192.0.2.10'
);

mcpAuthDenied('missing bearer is denied', mcpAuth(new McpAuthEntityManager(mcpAuthRepository(null))), ['REMOTE_ADDR' => '203.0.113.5'], 401);
mcpAuthDenied('malformed bearer is denied', mcpAuth($em), ['HTTP_AUTHORIZATION' => 'Basic nope', 'REMOTE_ADDR' => '203.0.113.5'], 401);
mcpAuthDenied('container-shaped bearer is denied before regex matching', mcpAuth($em), ['HTTP_AUTHORIZATION' => ['Bearer', $raw], 'REMOTE_ADDR' => '203.0.113.5'], 401);
mcpAuthDenied('unknown bearer is denied', mcpAuth(new McpAuthEntityManager(mcpAuthRepository(null))), ['HTTP_AUTHORIZATION' => 'Bearer unknown', 'REMOTE_ADDR' => '203.0.113.5'], 401);
$missingHash = (new Entities\McpToken())
    ->setName('malformed-row')
    ->setScope('read')
    ->setCreated(new DateTime());
mcpAuthDenied(
    'token row with a missing hash is denied without a type error',
    mcpAuth(new McpAuthEntityManager(mcpAuthRepository($missingHash))),
    ['HTTP_AUTHORIZATION' => "Bearer {$raw}", 'REMOTE_ADDR' => '203.0.113.5'],
    401
);

$revoked = mcpAuthToken($raw);
$revoked->setRevoked(true);
mcpAuthDenied('revoked token is denied', mcpAuth(new McpAuthEntityManager(mcpAuthRepository($revoked))), ['HTTP_AUTHORIZATION' => "Bearer {$raw}", 'REMOTE_ADDR' => '203.0.113.5'], 403);

$readOnly = mcpAuthToken($raw, 'read');
try {
    mcpAuth(new McpAuthEntityManager(mcpAuthRepository($readOnly)))
        ->authenticate(['HTTP_AUTHORIZATION' => "Bearer {$raw}", 'REMOTE_ADDR' => '203.0.113.5'], 'write');
    mcpAuthCheck('missing scope is denied', false);
} catch (ViMbAdmin_Mcp_Exception $e) {
    mcpAuthCheck('missing scope is denied', $e->getCode() === 403);
}

$restricted = mcpAuthToken($raw, 'read', '203.0.113.0/24');
mcpAuthDenied(
    'source outside token IP allowlist is denied',
    mcpAuth(new McpAuthEntityManager(mcpAuthRepository($restricted))),
    ['HTTP_AUTHORIZATION' => "Bearer {$raw}", 'REMOTE_ADDR' => '198.51.100.7'],
    403
);

$failureCount = McpAuthAssertions::$failures;
echo $failureCount === 0 ? "\nALL PASSED\n" : "\n{$failureCount} FAILED\n";
exit(min(1, $failureCount));
