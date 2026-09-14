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
foreach (['docs/ORM3-UPGRADE.md' => $guide, 'CHANGELOG' => $changelog] as $surface => $contents) {
    if (preg_match('/PHPStan(?: is enforced at)?\s+level\s+' . preg_quote($configuredLevel, '/') . '\b/', $contents) !== 1) {
        failContract($surface, "PHPStan level {$configuredLevel}", 'matching level absent');
    }
}

$positive = [
    'src/Kernel/Cli/CliKernel.php' => '/rejects unregistered names/',
    'src/Kernel/Router.php' => '/native entry point can reject it/',
    'src/Kernel/Controller/MaintenanceController.php' => '/CLI schema-update command is native/',
    'src/Kernel/Controller/ArchiveController.php' => '/`restore` recreates a\s+\* missing mailbox/',
    'src/Kernel/Controller/MailboxController.php' => '/Settings-email\s+\* delivery is native; create-time welcome email remains intentionally removed/',
    'src/Kernel/Session/MagicPropertyStorage.php' => '/Adapts the native \{@see SessionNamespace\}/',
    'src/Kernel/Controller/DomainController.php' => '/Index redirects to\s+\* the native list action and list-search is served by the native data endpoint/',
    'src/Kernel/Controller/AuthController.php' => '/security-salt-not-yet-configured path renders the native setup-salt view/',
];
foreach ($positive as $file => $pattern) {
    if (preg_match($pattern, (string) file_get_contents($root . '/' . $file)) !== 1) {
        failContract($file, "current ownership matching {$pattern}", 'invariant absent');
    }
}

$ownershipFiles = array_keys($positive);
foreach ($ownershipFiles as $file) {
    $contents = (string) file_get_contents($root . '/' . $file);
    if (preg_match('/(?:\b(?:ZF1|Zend|legacy)\b.{0,100}\b(?:fall\w*\s+back|delegat\w*|still\s+serv\w*|stays?\s+on)\b|\b(?:fall\w*\s+back|delegat\w*)\b.{0,100}\b(?:ZF1|Zend|legacy)\b)/is', $contents, $match) === 1) {
        failContract($file, 'no live delegation to removed ZF1 ownership', trim(preg_replace('/\s+/', ' ', $match[0]) ?? $match[0]));
    }
}

if ($configuredLevel === '<missing>') {
    failContract('phpstan.neon', 'numeric PHPStan level', '<missing>');
}

echo "OK: documentation matches shipped ownership and configuration.\n";
echo "ALL PASSED\n";
