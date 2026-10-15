<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';
require __DIR__ . '/../application/Repositories/McpToken.php';
require __DIR__ . '/../library/ViMbAdmin/Identity.php';
require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/Auth.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/RateLimit.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;

final class MaintenanceMcpAuthRepository extends Repositories\McpToken
{
    public function __construct(private readonly ?Entities\McpToken $token)
    {
    }

    public function findByHash($hash): ?Entities\McpToken
    {
        return $this->token;
    }
}

final class MaintenanceMcpAuthRepositoryFactory implements RepositoryFactory
{
    public function __construct(private readonly MaintenanceMcpAuthRepository $repository)
    {
    }

    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        if (ltrim($entityName, '\\') !== Entities\McpToken::class) {
            throw new LogicException("unexpected repository {$entityName}");
        }

        return $this->repository;
    }
}

final class MaintenanceMcpAuthEntityManager extends EntityManager
{
    public int $flushes = 0;

    public function __construct(MaintenanceMcpAuthRepository $repository)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
            $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
        }
        $configuration->setRepositoryFactory(new MaintenanceMcpAuthRepositoryFactory($repository));
        parent::__construct(DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'serverVersion' => '8.0',
        ], $configuration), $configuration);
    }

    public function flush(): void
    {
        $this->flushes++;
    }
}

final class MaintenanceMcpAuthAssertions
{
    public static int $failures = 0;
}

function maintenanceMcpAuthCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MaintenanceMcpAuthAssertions::$failures++;
    }
}

/** @param array<string,mixed> $server */
function maintenanceMcpAuthDenied(
    string $label,
    ViMbAdmin_Mcp_Auth $auth,
    array $server,
    string $scope,
    int $expectedCode,
): void {
    try {
        $auth->authenticate($server, $scope);
        maintenanceMcpAuthCheck($label, false);
    } catch (ViMbAdmin_Mcp_Exception $error) {
        maintenanceMcpAuthCheck($label, $error->getCode() === $expectedCode);
    }
}

function maintenanceMcpRateLimitDenied(
    string $label,
    ViMbAdmin_Mcp_RateLimit $limiter,
    int $tokenId,
    int $expectedCode,
): void {
    try {
        $limiter->hit($tokenId);
        maintenanceMcpAuthCheck($label, false);
    } catch (ViMbAdmin_Mcp_Exception $error) {
        maintenanceMcpAuthCheck($label, $error->getCode() === $expectedCode);
    }
}

function maintenanceMcpRemoveFixtureDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

echo "== maintenance MCP authentication fixture ==\n";

$rawToken = 'maintenance_fixture_token-1';
$token = (new Entities\McpToken())
    ->setName('maintenance fixture')
    ->setTokenHash(hash('sha256', $rawToken))
    ->setScope('read write')
    ->setAllowedIps('203.0.113.0/24')
    ->setAllowedDomains('example.test, second.test')
    ->setCreated(new DateTime());
$entityManager = new MaintenanceMcpAuthEntityManager(new MaintenanceMcpAuthRepository($token));
$auth = new ViMbAdmin_Mcp_Auth($entityManager);
$validServer = [
    'HTTP_AUTHORIZATION' => "Bearer {$rawToken}",
    'REMOTE_ADDR' => '203.0.113.9',
];

$authenticated = $auth->authenticate($validServer, 'write');
maintenanceMcpAuthCheck(
    'valid bearer from an allowed IP with the requested scope is accepted',
    $authenticated === $token,
);
maintenanceMcpAuthCheck(
    'successful authentication records token use through the fixture',
    $token->getLastUsedAt() instanceof DateTime && $entityManager->flushes === 1,
);

maintenanceMcpAuthDenied(
    'unknown bearer is denied',
    $auth,
    ['HTTP_AUTHORIZATION' => 'Bearer unknown', 'REMOTE_ADDR' => '203.0.113.9'],
    'read',
    401,
);
maintenanceMcpAuthDenied(
    'missing bearer cannot bypass authentication',
    $auth,
    ['REMOTE_ADDR' => '203.0.113.9'],
    'read',
    401,
);
maintenanceMcpAuthDenied(
    'source outside the token IP allowlist is denied',
    $auth,
    ['HTTP_AUTHORIZATION' => "Bearer {$rawToken}", 'REMOTE_ADDR' => '198.51.100.9'],
    'read',
    403,
);

$readToken = (new Entities\McpToken())
    ->setName('read-only fixture')
    ->setTokenHash(hash('sha256', $rawToken))
    ->setScope('read')
    ->setCreated(new DateTime());
$readAuth = new ViMbAdmin_Mcp_Auth(
    new MaintenanceMcpAuthEntityManager(new MaintenanceMcpAuthRepository($readToken)),
);
maintenanceMcpAuthDenied(
    'token without the requested scope is denied',
    $readAuth,
    $validServer,
    'write',
    403,
);

maintenanceMcpAuthCheck(
    'authenticated token accepts an allowed domain case-insensitively',
    $authenticated->allowsDomain('EXAMPLE.TEST'),
);
maintenanceMcpAuthCheck(
    'authenticated token denies a domain outside its allowlist',
    !$authenticated->allowsDomain('outside.test'),
);

$rateLimitDirectory = sys_get_temp_dir() . '/vimbadmin-maintenance-mcp-auth-' . bin2hex(random_bytes(8));
try {
    $limiter = new ViMbAdmin_Mcp_RateLimit([
        'statedir' => $rateLimitDirectory,
        'max' => 1,
        'window' => 3600,
    ]);
    $limiter->hit(41);
    maintenanceMcpAuthCheck(
        'first destructive request is recorded within the rate limit',
        count(glob($rateLimitDirectory . '/*.json') ?: []) === 1,
    );
    maintenanceMcpRateLimitDenied(
        'destructive request above the token rate limit is denied',
        $limiter,
        41,
        429,
    );
} finally {
    maintenanceMcpRemoveFixtureDirectory($rateLimitDirectory);
}

$failures = MaintenanceMcpAuthAssertions::$failures;
echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
