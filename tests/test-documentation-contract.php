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
        'src/Kernel/Mvc/AbstractController.php' => '/native view\/session\/mailer\s+\* resources/',
        'src/Kernel/Controller/AdminController.php' => '/(?=.*shared session key read by the native action handlers)(?=.*side-feature remains removed)/s',
        'src/Kernel/Controller/MaintenanceController.php' => '/CLI schema-update command is native/',
        'src/Kernel/Controller/ArchiveController.php' => '/(?=.*attempts to enqueue\s+\* repair as a best-effort follow-up)(?=.*REPAIR enqueue is attempted as a best-effort)/s',
        'src/Kernel/Controller/DomainController.php' => '/Index redirects to\s+\* the native list action and search uses the native list-data endpoint/',
        'src/Kernel/Controller/AuthController.php' => '/(?=.*security-salt-not-yet-configured path renders the native setup-salt view)(?=.*SessionNamespace\s+\W*retains the compatible identity slot)/s',
        'tests/test-kernel-router.php' => '/empty allowlist produces no match/',
        'tests/test-kernel-http.php' => '/obsolete list-search\s+-> false/',
        'application/views/domain/js/list.js' => "/'ajax': vmDomainServerData.*controller='domain' action='list-data'/",
        'application/views/alias/js/list.js' => "/'ajax': vmAliasServerData.*controller='alias' action='list-data'/",
        'application/views/mailbox/js/list.js' => "/'ajax': vmMailboxServerData.*controller='mailbox' action='list-data'/",
    ],
    'native mailbox capabilities' => [
        'src/Kernel/Controller/MailboxController.php' => '/Settings-email\s+\* delivery is native\. Create-time welcome email remains intentionally\s+\* removed/',
    ],
    'native session ownership' => [
        'src/Kernel/Session/MagicPropertyStorage.php' => '/Adapts the native \{@see SessionNamespace\}/',
        'src/Kernel/Session/NativeSessionStorage.php' => '/kernel\'s session data does not collide/',
        'tests/test-kernel-session-adapter.php' => '/shape exposed by the native SessionNamespace/',
        'tests/test-kernel-session-namespace.php' => '/native Auth storage adapts its compatible namespace slot/',
    ],
    'orm mapping and loading' => [
        'README.md' => '/attribute entity mappings/',
        'docs/ORM3-UPGRADE.md' => '/Doctrine\'s `AttributeDriver`/',
        'src/Kernel/Bootstrap.php' => '/EntityManagerFactory::create\(\$options\)/',
        'src/Kernel/Doctrine/EntityManagerFactory.php' => '/(?=.*entity manager owned by the native Container)(?=.*Build the direct PSR-6 pool)/s',
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

$forbiddenCurrent = [
    'application/views/domain/js/list.js' => "/function\s+getEntries|action='list-search'/",
    'application/views/alias/js/list.js' => "/function\s+getEntries|action='list-search'/",
    'application/views/mailbox/js/list.js' => "/function\s+getEntries|action='list-search'/",
];
foreach ($forbiddenCurrent as $file => $pattern) {
    if (preg_match($pattern, (string) file_get_contents($root . '/' . $file), $match) === 1) {
        failContract($file, "obsolete route absent ({$pattern})", $match[0]);
    }
}

$legacyOwners = '(?:ZF1|Zend|legacy)';
$outboundDelegation = '(?:fall(?:s|ing)?\s+(?:back|through)|delegat(?:e[sd]?|ing)|forward(?:s|ed|ing)?|rout(?:e[sd]?|ing))';
$inboundDelegation = '(?:fall(?:s|ing)?\s+(?:back|through)|delegates?|forwards?|routes?\s+(?:unmatched|unknown|requests|them|it))';
$legacyDelegationPattern = '/(?:'
    . '\b' . $legacyOwners . '\b[^.\n]{0,100}\b' . $inboundDelegation . '\b'
    . '|\b' . $outboundDelegation . '\b[^.\n]{0,50}(?:\bto\b[^.\n]{0,50})?\b' . $legacyOwners . '\b'
    . ')/i';
$historicalOrNegatedPattern = '/\b(?:never|no longer|formerly|historical|mirrors?)\b/i';
$delegationCases = [
    'Unmatched routes route to Zend.' => true,
    'Unmatched routes are routed to the Zend front controller.' => true,
    'Unmatched routes are forwarded to ZF1.' => true,
    'Unmatched routes delegate to legacy dispatch.' => true,
    'Unmatched routes fall back to Zend.' => true,
    'Unmatched routes fall through to Zend.' => true,
    'The historical router formerly routed to Zend.' => false,
    'The action never falls through to ZF1.' => false,
];
foreach ($delegationCases as $phrase => $expected) {
    $detected = preg_match($legacyDelegationPattern, $phrase) === 1
        && preg_match($historicalOrNegatedPattern, $phrase) !== 1;
    if ($detected !== $expected) {
        failContract('legacy-delegation matcher', $expected ? 'reject' : 'allow', $phrase);
    }
}
$ownershipFiles = array_keys($positive);
foreach ($ownershipFiles as $file) {
    $contents = (string) file_get_contents($root . '/' . $file);
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (preg_match($legacyDelegationPattern, $line, $match) === 1
            && preg_match($historicalOrNegatedPattern, $line) !== 1) {
            failContract($file, 'no live delegation to removed ZF1 ownership', trim($match[0]));
        }
    }
}

echo "OK: documentation matches shipped ownership and configuration.\n";
echo "ALL PASSED\n";
