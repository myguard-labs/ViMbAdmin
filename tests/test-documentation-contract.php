<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$decoded = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded)) {
    throw new RuntimeException('composer.lock must decode to an object.');
}
/** @var array{packages?: list<array{name?: mixed, version?: mixed}>} $lock */
$lock = $decoded;
/** @var array<string,string> $versions */
$versions = [];
foreach ($lock['packages'] ?? [] as $package) {
    if (is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
        $versions[$package['name']] = ltrim($package['version'], 'v');
    }
}

$guide = (string) file_get_contents($root . '/docs/ORM3-UPGRADE.md');
$changelog = (string) file_get_contents($root . '/CHANGELOG');
$phpstan = (string) file_get_contents($root . '/phpstan.neon');

/** @return never */
function failContract(string $surface, string $expected, string $observed): void
{
    fwrite(STDERR, "{$surface}: expected {$expected}; observed {$observed}.\n");
    exit(1);
}

$orm = $versions['doctrine/orm'] ?? '<missing>';
$dbal = $versions['doctrine/dbal'] ?? '<missing>';
$versionPatterns = [
    'docs/ORM3-UPGRADE.md' => '/\bORM\s+([0-9]+(?:\.[0-9]+)*)\s+and\s+DBAL\s+([0-9]+(?:\.[0-9]+)*)/',
    'CHANGELOG' => '/\bDoctrine ORM\s+2\.8\s+->\s+([0-9]+(?:\.[0-9]+)*)\s+\(\+ DBAL\s+([0-9]+(?:\.[0-9]+)*)\)/',
];
foreach ($versionPatterns as $surface => $pattern) {
    $contents = $surface === 'CHANGELOG' ? $changelog : $guide;
    if (preg_match($pattern, $contents, $match) !== 1) {
        failContract($surface, "ORM {$orm} paired with DBAL {$dbal}", '<version pair absent>');
    }
    if ($match[1] !== $orm || $match[2] !== $dbal) {
        failContract($surface, "ORM {$orm} paired with DBAL {$dbal}", "ORM {$match[1]} paired with DBAL {$match[2]}");
    }
}

$configuredLevel = preg_match('/^\s*level:\s*([0-9]+)\s*$/m', $phpstan, $levelMatch) === 1
    ? $levelMatch[1] : '<missing>';
if ($configuredLevel === '<missing>') {
    failContract('phpstan.neon', 'numeric PHPStan level', '<missing>');
}
foreach (['docs/ORM3-UPGRADE.md' => $guide, 'CHANGELOG' => $changelog] as $surface => $contents) {
    if (preg_match('/PHPStan(?:\s+is\s+enforced\s+at)?\s+level\s+' . preg_quote($configuredLevel, '/') . '\b/', $contents) !== 1) {
        failContract($surface, "PHPStan level {$configuredLevel}", 'matching level absent');
    }
}

$positiveByCategory = [
    'native routing and actions' => [
        'src/Kernel/Cli/CliKernel.php' => '/rejects unregistered names/',
        'src/Kernel/Router.php' => '/native entry point can reject it/',
        'src/Kernel/Mvc/AbstractController.php' => '/links minted here validate in the native action handlers/',
        'src/Kernel/Controller/AdminController.php' => '/shared session key read by the native action handlers/',
        'src/Kernel/Controller/MaintenanceController.php' => '/CLI schema-update command is native/',
        'src/Kernel/Controller/ArchiveController.php' => '/attempts to enqueue\s+\* repair as a best-effort follow-up/',
        'src/Kernel/Controller/DomainController.php' => '/Index redirects to\s+\* the native list action and list-search is served by the native data endpoint/',
        'src/Kernel/Controller/AuthController.php' => '/security-salt-not-yet-configured path renders the native setup-salt view/',
    ],
    'native mailbox capabilities' => [
        'src/Kernel/Controller/MailboxController.php' => '/Settings-email\s+\* delivery is native; create-time welcome email remains intentionally removed/',
    ],
    'native session ownership' => [
        'src/Kernel/Session/MagicPropertyStorage.php' => '/Adapts the native \{@see SessionNamespace\}/',
    ],
    'orm mapping and loading' => [
        'src/Kernel/Doctrine/EntityManagerFactory.php' => '/Build the direct PSR-6 pool/',
        'bin/generate-proxies.php' => '/proxy classes from attribute mappings/',
        'tests/test-schema-no-pending.php' => '/SchemaTool::createSchema\(\) from attribute mappings/',
    ],
];
/** @var array<string,string> $positive */
$positive = [];
foreach ($positiveByCategory as $category => $invariants) {
    foreach ($invariants as $file => $pattern) {
        $positive[$file] = $pattern;
        if (preg_match($pattern, (string) file_get_contents($root . '/' . $file)) !== 1) {
            failContract($file, "{$category} ownership matching {$pattern}", 'invariant absent');
        }
    }
}

$legacyDelegationPattern = '/(?:'
    . '\b(?:ZF1|Zend|legacy)\b[^.\n]{0,100}\b(?:falls?\s+back|delegates?|forwards?|routes?\s+(?:unmatched|unknown|requests|them|it)|still\s+serv\w*|stays?\s+on)\b'
    . '|\b(?:falls?\s+back|delegated|forwarded|routed)\b[^.\n]{0,50}\b(?:to|through)\b[^.\n]{0,50}\b(?:ZF1|Zend|legacy)\b'
    . ')/i';
$ownershipFiles = array_keys($positive);
foreach ($ownershipFiles as $file) {
    $contents = (string) file_get_contents($root . '/' . $file);
    if (preg_match($legacyDelegationPattern, $contents, $match) === 1) {
        failContract($file, 'no live delegation to removed ZF1 ownership', trim(preg_replace('/\s+/', ' ', $match[0]) ?? $match[0]));
    }
}

echo "OK: documentation matches shipped ownership and configuration.\n";
echo "ALL PASSED\n";
