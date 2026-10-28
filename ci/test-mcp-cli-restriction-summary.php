<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Entities\McpToken;
use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenGenerateCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenListCommand;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/application/Entities/McpToken.php';
require dirname(__DIR__) . '/application/Repositories/McpToken.php';

// Exercise real token generation, but never emit a bearer or its stored hash.
ob_start(static fn (string $text): string => preg_replace('/\b[a-f0-9]{64}\b/', '[REDACTED]', $text) ?? '[redaction failed]');

/**
 * @param array<string,mixed> $args
 * @return array{int,string}
 */
function restrictionSummaryRun(CliCommand $command, Container $container, array $args): array
{
    ob_start();
    try {
        $status = $command->run($container, $args);
        $output = ob_get_contents();
        if (!is_string($output)) {
            throw new RuntimeException('Cannot capture CLI summary output');
        }
        return [$status, $output];
    } finally {
        ob_end_clean();
    }
}

function restrictionSummaryIpAllowed(
    ViMbAdmin_Mcp_Auth $auth,
    #[SensitiveParameter] string $bearer,
    string $ip,
): bool {
    try {
        $auth->authenticate(['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer, 'REMOTE_ADDR' => $ip]);
        return true;
    } catch (ViMbAdmin_Mcp_Exception $error) {
        if ($error->getCode() !== 403) {
            throw $error;
        }
        return false;
    }
}

$checks = 0;
$failures = 0;
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    ++$checks;
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
};

// Expected output and policy values are fixed independently of the summaries.
$cases = [
    'null' => [null, null, null, null, 'any', 'all', true, true],
    'empty' => ['', '', null, null, 'any', 'all', true, true],
    'zero' => ['0', '0', '0', '0', '0', '0', false, false],
    'ordinary' => ['192.0.2.1', 'example.com', '192.0.2.1', 'example.com', '192.0.2.1', 'example.com', true, false],
    'nonstring' => [[], false, null, null, 'any', 'all', true, true],
];

try {
    foreach ($cases as $label => [$ip, $domain, $storedIp, $storedDomain, $displayIp, $displayDomain, $matchAllowed, $otherAllowed]) {
        // The actual ORM, repository and SQLite schema prove persisted-row reachability.
        $config = ORMSetup::createAttributeMetadataConfiguration([], true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new EntityManager($connection, $config);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(McpToken::class)]);
        $bootstrap = new class ($manager) {
            public function __construct(private readonly EntityManager $manager)
            {
            }

            public function getResource(string $name): EntityManager
            {
                if ($name !== 'doctrine2') {
                    throw new LogicException('Unexpected CLI resource');
                }
                return $this->manager;
            }
        };
        $session = new class () implements SessionStorage {
            public function has(string $key): bool
            {
                return false;
            }

            public function get(string $key): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value): void
            {
                throw new LogicException('CLI summaries must not write a session');
            }

            public function remove(string $key): void
            {
                throw new LogicException('CLI summaries must not write a session');
            }
        };
        $container = new Container($bootstrap, new Auth($session, static fn (int $id): null => null));
        $name = 'restriction-' . $label;
        [$status, $generated] = restrictionSummaryRun(new McpTokenGenerateCommand(), $container, [
            'name' => $name,
            'ip' => $ip,
            'domains' => $domain,
        ]);
        $summary = explode("\n", $generated)[0];
        $matched = preg_match('/TOKEN \(shown once, store it now\):\n\n    ([a-f0-9]{64})\n/', $generated, $match);
        if ($matched !== 1) {
            throw new RuntimeException('Generated token was not captured');
        }
        $bearer = $match[1];
        unset($generated, $match);
        $manager->clear();
        $token = $manager->getRepository(McpToken::class)->findOneBy(['name' => $name]);
        if (!$token instanceof McpToken) {
            throw new RuntimeException('Generated token was not persisted');
        }
        $check("{$label}: generation succeeds", $status === 0);
        $check("{$label}: stored IP is unchanged", $token->getAllowedIps() === $storedIp);
        $check("{$label}: stored domains are unchanged", $token->getAllowedDomains() === $storedDomain);
        $check("{$label}: generated bearer matches stored hash", $token->getTokenHash() === hash('sha256', $bearer));
        $check("{$label}: generate IP display", str_contains($summary, ' IPs: ' . $displayIp . '.'));
        $check("{$label}: generate domain display", str_contains($summary, ' Domains: ' . $displayDomain . '.'));

        [$listStatus, $listed] = restrictionSummaryRun(new McpTokenListCommand(), $container, []);
        $lines = explode("\n", trim($listed));
        $columns = preg_split('/\s+/', trim($lines[1] ?? ''));
        $check("{$label}: list succeeds", $listStatus === 0 && str_starts_with($listed, 'ID   NAME'));
        $check("{$label}: list IP display", is_array($columns) && ($columns[3] ?? null) === $displayIp);
        $check("{$label}: list domain display", is_array($columns) && ($columns[4] ?? null) === $displayDomain);

        $auth = new ViMbAdmin_Mcp_Auth($manager);
        $check("{$label}: IP match policy is unchanged", restrictionSummaryIpAllowed($auth, $bearer, '192.0.2.1') === $matchAllowed);
        $check("{$label}: other IP policy is unchanged", restrictionSummaryIpAllowed($auth, $bearer, '198.51.100.7') === $otherAllowed);
        $check("{$label}: domain match policy is unchanged", $token->allowsDomain('example.com') === $matchAllowed);
        $check("{$label}: other domain policy is unchanged", $token->allowsDomain('other.example') === $otherAllowed);
        $check("{$label}: authentication retains restrictions", $token->getAllowedIps() === $storedIp && $token->getAllowedDomains() === $storedDomain);

        [$invalidStatus, $invalidOutput] = restrictionSummaryRun(new McpTokenGenerateCommand(), $container, ['name' => []]);
        $check("{$label}: malformed required name is rejected", $invalidStatus === 1 && $invalidOutput === "ERROR: --name is required\n");
        $check("{$label}: rejected input adds no token", $manager->getRepository(McpToken::class)->count([]) === 1);
        unset($bearer, $token, $auth);
        $manager->clear();
        $connection->close();
    }
} catch (Throwable $error) {
    ++$failures;
    echo '  FAIL fixture: ' . get_class($error) . ': '
        . (preg_replace('/\b[a-f0-9]{64}\b/', '[REDACTED]', $error->getMessage()) ?? '[redaction failed]') . "\n";
}

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "{$failures} FAILED ({$checks} checks)\n";
exit($failures === 0 ? 0 : 1);
