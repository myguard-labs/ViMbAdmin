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
        'src/Kernel/Cli/CliKernel.php' => '/return Bootstrap::boot\(/',
        'bin/vimbtool.php' => '/(?=.*\$kernel->boot\(\))(?=.*\$kernel->run\()/s',
        'src/Kernel/Router.php' => '/native entry point can reject it/',
        'src/Kernel/Mvc/AbstractController.php' => '/(?=.*native view\/session\/mailer\s+\* resources)(?=.*new Csrf\(new MagicPropertyStorage\(\$this->container->session\(\)\)\))/s',
        'src/Kernel/Controller/AdminController.php' => '/(?=.*shared session key read by the native action handlers)(?=.*side-feature remains removed)/s',
        'src/Kernel/Controller/MaintenanceController.php' => '/CLI schema-update command is native/',
        'src/Kernel/Controller/ArchiveController.php' => '/(?=.*attempts to enqueue\s+\* repair as a best-effort follow-up)(?=.*REPAIR enqueue is attempted as a best-effort)/s',
        'src/Kernel/Controller/DomainController.php' => '/Index redirects to\s+\* the native list action and search uses the native list-data endpoint/',
        'src/Kernel/Controller/AuthController.php' => '/(?=.*security-salt-not-yet-configured path renders the native setup-salt view)(?=.*SessionNamespace\s+\W*retains the compatible identity slot)/s',
        'tests/test-kernel-router.php' => '/empty allowlist produces no match/',
        'tests/test-kernel-http.php' => '/obsolete list-search\s+-> false/',
    ],
    'native mailbox capabilities' => [
        'src/Kernel/Controller/MailboxController.php' => '/Settings-email\s+\* delivery is native\. Create-time welcome email remains intentionally\s+\* removed/',
    ],
    'native session ownership' => [
        'src/Kernel/Session/MagicPropertyStorage.php' => '/Adapts the native \{@see SessionNamespace\}/',
        'src/Kernel/Session/NativeSessionStorage.php' => '/kernel\'s session data does not collide/',
        'src/Kernel/Session/SessionNamespace.php' => '/new MagicPropertyStorage\(new SessionNamespace\(\'ViMbAdmin_Auth\'\)\)/',
        'src/Kernel/Session/SessionStorage.php' => '/Current implementations/',
        'src/Kernel/Bootstrap.php' => '/(?=.*new SessionNamespace\(\'Application\'\))(?=.*new SessionNamespace\(\$authNs\))(?=.*migrateAuthNamespace\(\$_SESSION, true\))(?=.*OSS_Runtime::configure\()/s',
        'tests/test-kernel-session-adapter.php' => '/shape exposed by the native SessionNamespace/',
        'tests/test-kernel-session-namespace.php' => '/new SessionNamespace\(\'ViMbAdmin_Auth\'\)/',
    ],
    'orm mapping and loading' => [
        'README.md' => '/attribute entity mappings/',
        'docs/ORM3-UPGRADE.md' => '/Doctrine\'s `AttributeDriver`/',
        'public/index.php' => '/Bootstrap::boot\(APPLICATION_PATH/',
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

$legacyOwners = '(?:ZF1|Zend|legacy)(?![-A-Za-z0-9_])';
$outboundDelegation = '(?:fall(?:s|ing)?\s+(?:back|through)|delegat(?:e[sd]?|ing)|forward(?:s|ed|ing)?|rout(?:e[sd]?|ing))';
$inboundDelegation = '(?:fall(?:s|ing)?\s+(?:back|through)|delegates?|forwards?|routes?\s+(?:unmatched|unknown|requests|them|it))';
$legacyDelegationPattern = '/(?:'
    . '\b' . $legacyOwners . '\b[^.\n]{0,100}\b' . $inboundDelegation . '\b'
    . '|\b' . $outboundDelegation . '\b[^,;.\n]{0,50}\b(?:to|through|into)\s+(?:the\s+)?' . $legacyOwners . '\b'
    . ')/i';
$historicalOrNegatedPattern = '/\b(?:never|does not|no longer|formerly|historical)\b/i';
$detectDelegation = static function (string $text) use ($legacyDelegationPattern, $historicalOrNegatedPattern): ?string {
    foreach (preg_split('/[.;]/', $text) ?: [] as $sentence) {
        $normalized = preg_replace('/\R+(?=\s*(?:to|through|into)\b)/i', ' ', $sentence);
        $normalized = is_string($normalized) ? $normalized : $sentence;
        foreach (preg_split('/\s+(?:but|and|while)\s+/i', $normalized) ?: [] as $clause) {
            if (preg_match($legacyDelegationPattern, $clause, $match) === 1
                && preg_match($historicalOrNegatedPattern, $clause) !== 1) {
                return trim($match[0]);
            }
        }
    }
    return null;
};
$delegationCases = [
    'Unmatched routes route to Zend.' => true,
    'Unmatched routes are routed to the Zend front controller.' => true,
    'Unmatched routes are forwarded to ZF1.' => true,
    'Unmatched routes delegate to legacy dispatch.' => true,
    'Unmatched routes fall back to Zend.' => true,
    'Unmatched routes fall through to Zend.' => true,
    'The historical router formerly routed to Zend.' => false,
    'The action never falls through to ZF1.' => false,
    'Unknown controllers route requests to ZF1; they no longer boot native resources.' => true,
    'Router routes native requests and rejects legacy routes.' => false,
    'The health route never falls through to ZF1, but unknown controllers route requests to ZF1.' => true,
    'The router routes requests to native controllers, not legacy ZF1.' => false,
    'The native router mirrors ZF1 behavior and forwards unknown routes to ZF1.' => true,
    'Unknown controllers route requests to ZF1 and native routes never delegate to ZF1.' => true,
    'The native router routes requests through the legacy-layout native controller namespace.' => false,
    'The router does not route requests to Zend.' => false,
    'The router never routes requests to Zend or delegates to ZF1.' => false,
    'The router does not route requests to Zend while forwarding unknown routes to ZF1.' => true,
    "Unmatched routes are forwarded\nto Zend." => true,
    "The router does not forward requests\nto Zend." => false,
];
foreach ($delegationCases as $phrase => $expected) {
    $detected = $detectDelegation($phrase) !== null;
    if ($detected !== $expected) {
        failContract('legacy-delegation matcher', $expected ? 'reject' : 'allow', $phrase);
    }
}
$ownershipFiles = array_keys($positive);
foreach ($ownershipFiles as $file) {
    $contents = (string) file_get_contents($root . '/' . $file);
    $delegation = $detectDelegation($contents);
    if ($delegation !== null) {
        failContract($file, 'no live delegation to removed ZF1 ownership', $delegation);
    }
}

// ---------------------------------------------------------------------------
// Documented install/ops claims vs the shipped source (DOCS-COV-01).
// Every check works on an in-memory snapshot so seeded variants of the docs
// can be fed through the very same function (negative controls below).
// ---------------------------------------------------------------------------

/**
 * @param list<string> $paths
 * @return array<string,string>
 */
function snapshotFiles(string $root, array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        $contents = file_get_contents($root . '/' . $path);
        if (!is_string($contents)) {
            failContract($path, 'readable file', 'unreadable');
        }
        $files[$path] = $contents;
    }
    return $files;
}

/** @return string[] controller/Command source files by directory glob */
function globSources(string $root, string $pattern): array
{
    $found = glob($root . '/' . $pattern);
    return $found === false ? [] : $found;
}

/**
 * @param list<string> $longFlags Parsed getopt long options, without colons.
 * @param list<string> $shortFlags Parsed getopt short options taking no argument.
 * @return list<string>
 */
function documentationGlobalFlagIssues(string $readme, array $longFlags, array $shortFlags): array
{
    if (preg_match_all('/\*\*Global flags\*\*[^\r\n]*\R(.*?)\*\*Actions:\*\*/s', $readme, $tables) !== 1) {
        return ['README.md global flags table: expected exactly one section before Actions'];
    }
    $issues = [];
    $documentedLong = [];
    $documentedShort = [];
    preg_match_all('/^\|\s*([^|]+)\|[^\r\n]*\r?$/m', $tables[1][0], $rows);
    foreach ($rows[1] as $cell) {
        if (trim($cell) === 'Flag' || preg_match('/^[-: ]+$/D', $cell) === 1) {
            continue;
        }
        if (preg_match('/^`-([a-z])`,\s*`--([a-z][a-z-]*)`\s*$/D', $cell, $flags) !== 1) {
            $issues[] = 'README.md global flags table: malformed flag row ' . trim($cell);
            continue;
        }
        $documentedShort[] = $flags[1];
        $documentedLong[] = $flags[2];
        if (!in_array($flags[1], $shortFlags, true)) {
            $issues[] = "README.md global flags table: '-{$flags[1]}' is not a no-argument getopt option";
        }
        if (!in_array($flags[2], $longFlags, true)) {
            $issues[] = "README.md global flags table: '--{$flags[2]}' is not in the getopt spec";
        }
    }
    // These four CLI-wide switches must remain documented, including both aliases.
    sort($documentedLong);
    sort($documentedShort);
    sort($shortFlags);
    if ($documentedLong !== ['copyright', 'debug', 'help', 'verbose'] || $documentedShort !== $shortFlags) {
        $issues[] = 'README.md global flags table: missing, extra or duplicate global flag';
    }
    return $issues;
}

/**
 * @param array<string,string> $f snapshot from claimSnapshot()
 * @return list<string> human readable contradictions; empty means consistent
 */
function documentationClaimIssues(array $f): array
{
    $issues = [];
    $composer = json_decode($f['composer.json'], true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode($f['composer.lock'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($composer) || !is_array($lock)) {
        return ['composer.json/composer.lock must decode to objects'];
    }
    $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $locked = [];
    foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
        if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
            $locked[$package['name']] = ltrim($package['version'], 'v');
        }
    }

    // 1. PHP floor (composer.json) vs every document that states it.
    $floor = is_string($require['php'] ?? null) && preg_match('/^>=\s*([0-9]+(?:\.[0-9]+){1,2})$/', $require['php'], $m) === 1
        ? $m[1] : null;
    if ($floor === null) {
        $issues[] = 'composer.json require.php must be a ">=X.Y.Z" floor';
    } else {
        $quoted = preg_quote($floor, '/');
        $floorClaims = [
            'README.md' => '/PHP \*\*' . $quoted . '\+\*\* with/',
            'docs/MIGRATION.md' => '/\*\*PHP ' . $quoted . '\*\* or newer/',
            'contrib/php-fpm/vimbadmin.conf' => '/PHP ' . $quoted . '\+/',
        ];
        foreach ($floorClaims as $path => $pattern) {
            if (preg_match($pattern, $f[$path]) !== 1) {
                $issues[] = "{$path}: PHP floor claim does not match composer.json floor {$floor}";
            }
        }
        $config = is_array($composer['config'] ?? null) ? $composer['config'] : [];
        $platformSet = is_array($config['platform'] ?? null) ? $config['platform'] : [];
        $platform = $platformSet['php'] ?? null;
        if ($platform !== $floor) {
            $issues[] = 'composer.json config.platform.php must equal the floor ' . $floor;
        }
    }

    // 2. Tested minors: CI matrix (ci.yml) vs README and the max claimed range.
    preg_match_all("/^\s*-\s*php:\s*'([0-9]+\.[0-9]+)'/m", $f['.github/workflows/ci.yml'], $matrix);
    $tested = array_values(array_unique($matrix[1]));
    sort($tested, SORT_NATURAL);
    if ($tested === []) {
        $issues[] = '.github/workflows/ci.yml: no PHP matrix minors found';
    } elseif (preg_match('/Tested PHP minors:\s*([0-9., ]+)\./', $f['README.md'], $m) !== 1) {
        $issues[] = 'README.md: "Tested PHP minors:" line absent';
    } else {
        $documented = array_map('trim', explode(',', $m[1]));
        sort($documented, SORT_NATURAL);
        if ($documented !== $tested) {
            $issues[] = 'README.md tested PHP minors [' . implode(', ', $documented)
                . '] differ from ci.yml matrix [' . implode(', ', $tested) . ']';
        }
        if ($floor !== null && preg_match('/PHP \*\*' . preg_quote($floor, '/') . ' (?:→|->) ([0-9.]+)\*\*/u', $f['README.md'], $range) === 1
            && $range[1] !== end($tested)) {
            $issues[] = "README.md claims PHP range up to {$range[1]}; newest tested minor is " . end($tested);
        }
    }

    // 3. CLI actions and flags against bin/vimbtool.php and the command classes.
    $actions = [];
    foreach ($f as $path => $contents) {
        if (str_starts_with($path, 'src/Kernel/Cli/Command/')
            && preg_match("/function name\(\): string\s*\{\s*return '([a-z]+\.cli-[a-z-]+)';/", $contents, $m) === 1) {
            $actions[] = $m[1];
        }
    }
    sort($actions);
    $tool = $f['bin/vimbtool.php'];
    $helpBlock = preg_match('/Actions:.*?exit\(0\)/s', $tool, $hb) === 1 ? $hb[0] : '';
    preg_match_all('/\b([a-z]+\.cli-[a-z-]+)\b/', $helpBlock, $help);
    $helpActions = array_values(array_unique($help[1]));
    sort($helpActions);
    if ($actions === [] || $helpActions !== $actions) {
        $issues[] = 'bin/vimbtool.php --help action list [' . implode(', ', $helpActions)
            . '] differs from command classes [' . implode(', ', $actions) . ']';
    }
    $flags = [];
    $shortFlags = [];
    if (preg_match("/getopt\('([^']*)',\s*\[(.*?)\]\)/s", $tool, $m) === 1) {
        preg_match_all("/'([a-z][a-z-]*):{0,2}'/", $m[2], $long);
        $flags = array_values(array_unique($long[1]));
        preg_match_all('/([a-z])(?!:)/', $m[1], $short);
        $shortFlags = $short[1];
    } else {
        $issues[] = 'bin/vimbtool.php: getopt() specification not found';
    }
    $issues = array_merge($issues, documentationGlobalFlagIssues($f['README.md'], $flags, $shortFlags));
    $cliDocs = ['README.md', 'contrib/cron/README.md', 'contrib/cron/crontab.example', 'docs/MIGRATION.md', 'docs/mcp-auth.md'];
    $documentedActions = [];
    foreach ($cliDocs as $path) {
        $text = preg_replace('/\\\\\R\s*/', ' ', $f[$path]) ?? $f[$path];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/-a help\b`?+(?! is (?:rejected|an unknown))/', $line) === 1) {
                $issues[] = "{$path}: documents `-a help`, which vimbtool rejects as an unknown action";
            }
            if (!str_contains($line, 'vimbtool.php')) {
                continue;
            }
            if (preg_match_all('/(?<![\w-])-a\s+([a-z]+\.cli-[a-z-]+)/', $line, $am) > 0) {
                foreach ($am[1] as $action) {
                    $documentedActions[$action] = true;
                    if (!in_array($action, $actions, true)) {
                        $issues[] = "{$path}: documented CLI action '{$action}' is not a registered command";
                    }
                }
            }
            if (preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $line, $fm) > 0) {
                foreach ($fm[1] as $flag) {
                    if (!in_array($flag, $flags, true)) {
                        $issues[] = "{$path}: documented CLI flag '--{$flag}' is not in the vimbtool getopt spec";
                    }
                }
            }
        }
    }
    // README action table: row names and the flags in each row.
    $tableActions = [];
    foreach (preg_split('/\R/', $f['README.md']) ?: [] as $line) {
        if (preg_match('/^\|\s*`([a-z]+\.cli-[a-z-]+)`\s*\|/', $line, $row) === 1) {
            $tableActions[] = $row[1];
            preg_match_all('/--([a-z][a-z-]*)/', $line, $rowFlags);
            foreach ($rowFlags[1] as $flag) {
                if (!in_array($flag, $flags, true)) {
                    $issues[] = "README.md action table: '--{$flag}' for {$row[1]} is not in the getopt spec";
                }
            }
        }
    }
    $tableActions = array_values(array_unique($tableActions));
    sort($tableActions);
    if ($tableActions !== $actions) {
        $issues[] = 'README.md CLI action table [' . implode(', ', $tableActions)
            . '] differs from command classes [' . implode(', ', $actions) . ']';
    }

    // 4. Routes in prose vs NATIVE_CONTROLLERS and the controller action methods.
    preg_match_all("/'([a-z]+)'\s*=>\s*(?:\\\\)?([A-Za-z]+Controller)::class/", $f['src/Kernel/Http/Kernel.php'], $map, PREG_SET_ORDER);
    $controllers = [];
    foreach ($map as $row) {
        $controllers[$row[1]] = $row[2];
    }
    $routeNames = implode('|', array_keys($controllers));
    $controllerSource = static function (string $class) use ($f): ?string {
        foreach (['src/Kernel/Controller/', 'application/controllers/'] as $dir) {
            if (isset($f[$dir . $class . '.php'])) {
                return $f[$dir . $class . '.php'];
            }
        }
        return null;
    };
    foreach (['README.md', 'contrib/cron/README.md', 'docs/MIGRATION.md', 'docs/mcp-auth.md'] as $path) {
        if ($controllers === []) {
            $issues[] = 'src/Kernel/Http/Kernel.php: NATIVE_CONTROLLERS map not found';
            break;
        }
        preg_match_all('~(?<![A-Za-z0-9_.:/-])/(' . $routeNames . ')(?:/([a-z0-9]+(?:-[a-z0-9]+)*))?(?![A-Za-z0-9_/-])~', $f[$path], $routes, PREG_SET_ORDER);
        foreach ($routes as $route) {
            $source = $controllerSource($controllers[$route[1]]);
            if ($source === null) {
                $issues[] = "{$path}: route {$route[0]} controller source {$controllers[$route[1]]} is not in the snapshot";
            } elseif (($route[2] ?? '') !== '') {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $route[2])))) . 'Action';
                if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source) !== 1) {
                    $issues[] = "{$path}: route {$route[0]} has no {$controllers[$route[1]]}::{$method}()";
                }
            }
        }
        // Routes of unknown controllers that look like one we document.
        if (preg_match_all('~`/(?!(?:' . $routeNames . ')[/`])[a-z-]+(?:/[a-z-]+)?`~', $f[$path], $unknown) > 0
            && $path === 'README.md') {
            foreach ($unknown[0] as $candidate) {
                if (preg_match('~two-factor|queue|trigger|mcp~', $candidate) === 1) {
                    $issues[] = "{$path}: route {$candidate} names no native controller";
                }
            }
        }
    }

    // 5. Dependency line and extension lists vs composer.json / composer.lock.
    $readme = preg_replace('/\s+/', ' ', $f['README.md']) ?? $f['README.md'];
    if (preg_match('/doctrine\/orm ([0-9]+\.[0-9]+), DBAL ([0-9]+), symfony\/cache ([0-9.\/]+), Smarty ([0-9]+), robthree\/twofactorauth ([0-9]+), bacon\/bacon-qr-code ([0-9]+)\)/', $readme, $m) !== 1) {
        $issues[] = 'README.md: "Current release lines (doctrine/orm ..., bacon/bacon-qr-code N)" line absent or reworded';
    } else {
        $minor = static fn (string $v): string => implode('.', array_slice(explode('.', $v), 0, 2));
        $major = static fn (string $v): string => explode('.', $v)[0];
        $expect = [
            'doctrine/orm' => [$m[1], $minor($locked['doctrine/orm'] ?? '<missing>')],
            'doctrine/dbal' => [$m[2], $major($locked['doctrine/dbal'] ?? '<missing>')],
            'smarty/smarty' => [$m[4], $major($locked['smarty/smarty'] ?? '<missing>')],
            'robthree/twofactorauth' => [$m[5], $major($locked['robthree/twofactorauth'] ?? '<missing>')],
            'bacon/bacon-qr-code' => [$m[6], $major($locked['bacon/bacon-qr-code'] ?? '<missing>')],
        ];
        foreach ($expect as $package => [$documented, $actual]) {
            if ($documented !== $actual) {
                $issues[] = "README.md documents {$package} {$documented}; composer.lock has {$actual}";
            }
        }
        $cacheRequire = is_string($require['symfony/cache'] ?? null) ? $require['symfony/cache'] : '';
        preg_match_all('/\^?([0-9]+(?:\.[0-9]+)?)/', $cacheRequire, $cm);
        $cacheLines = array_map(static fn (string $v): string => preg_replace('/\.0$/', '', $v) ?? $v, $cm[1]);
        if (explode('/', $m[3]) !== $cacheLines) {
            $issues[] = "README.md documents symfony/cache {$m[3]}; composer.json requires " . ($cacheRequire === '' ? '<missing>' : $cacheRequire);
        }
    }
    $composerExt = [];
    foreach (array_keys($require) as $name) {
        if (str_starts_with((string) $name, 'ext-')) {
            $composerExt[] = substr((string) $name, 4);
        }
    }
    sort($composerExt);
    $readmeExt = [];
    if (preg_match('/PHP \*\*[0-9.]+\+\*\* with (.*?)\(2FA secrets/s', $f['README.md'], $m) === 1) {
        preg_match_all('/`([a-z_0-9]+)`/', $m[1], $names);
        $readmeExt = $names[1];
    }
    sort($readmeExt);
    // pdo is implied by pdo_mysql and json is always compiled in: both may be unlisted in prose.
    $prose = array_values(array_diff($composerExt, ['pdo', 'json']));
    if ($readmeExt !== $prose) {
        $issues[] = 'README.md extension list [' . implode(', ', $readmeExt) . '] differs from composer.json [' . implode(', ', $prose) . ']';
    }
    $fpmExt = [];
    if (preg_match('/Required PHP extensions:\s*(.*?)\.\s*(?:\R;\s*\R|$)/s', $f['contrib/php-fpm/vimbadmin.conf'], $m) === 1) {
        $list = preg_replace('/\(.*?\)/s', '', (string) preg_replace('/\R;\s*/', ' ', $m[1])) ?? '';
        foreach (preg_split('/\s*,\s*(?:and\s+)?|\s+and\s+/', $list) ?: [] as $name) {
            $name = strtolower(trim($name));
            if ($name !== '') {
                $fpmExt[] = $name;
            }
        }
    }
    sort($fpmExt);
    $fpmExpected = array_values(array_diff($composerExt, ['pdo']));
    if ($fpmExt !== $fpmExpected) {
        $issues[] = 'contrib/php-fpm/vimbadmin.conf extension list [' . implode(', ', $fpmExt) . '] differs from composer.json [' . implode(', ', $fpmExpected) . ']';
    }
    $aptFor = [
        'pdo_mysql' => 'php-mysql', 'mbstring' => 'php-mbstring', 'intl' => 'php-intl', 'gettext' => 'php-gettext',
        'dom' => 'php-xml', 'curl' => 'php-curl', 'gd' => 'php-gd', 'sodium' => 'php-sodium',
    ];
    $cron = preg_replace('/\\\\\R\s*/', ' ', $f['contrib/cron/README.md']) ?? '';
    if (preg_match('/apt-get install[^\n]*/', $cron, $m) !== 1) {
        $issues[] = 'contrib/cron/README.md: apt-get install line absent';
    } else {
        $apt = preg_split('/\s+/', $m[0]) ?: [];
        foreach ($aptFor as $extension => $package) {
            if (in_array($extension, $composerExt, true) && !in_array($package, $apt, true)) {
                $issues[] = "contrib/cron/README.md: apt line lacks {$package} for required ext-{$extension}";
            }
        }
        foreach (['bcmath', 'gmp'] as $extra) {
            if (in_array('php-' . $extra, $apt, true) && !in_array($extra, $composerExt, true)) {
                $issues[] = "contrib/cron/README.md: apt line installs php-{$extra}, which composer.json does not require";
            }
        }
    }

    return $issues;
}

$claimPaths = [
    'composer.json', 'composer.lock', '.github/workflows/ci.yml', 'README.md', 'docs/MIGRATION.md',
    'docs/mcp-auth.md', 'contrib/php-fpm/vimbadmin.conf', 'contrib/cron/README.md', 'contrib/cron/crontab.example',
    'bin/vimbtool.php', 'src/Kernel/Http/Kernel.php',
];
foreach (array_merge(globSources($root, 'src/Kernel/Cli/Command/*.php'), globSources($root, 'src/Kernel/Controller/*.php'), globSources($root, 'application/controllers/*.php')) as $absolute) {
    $claimPaths[] = substr($absolute, strlen($root) + 1);
}
$claims = snapshotFiles($root, $claimPaths);
$claimIssues = documentationClaimIssues($claims);
if ($claimIssues !== []) {
    failContract('documentation claims', 'no contradiction with source', implode(' | ', $claimIssues));
}

// Negative controls: each seeded document variant must be reported, then the
// restored snapshot must be clean again (asserted above and again below).
$seed = static function (string $path, string $from, string $to) use ($claims): array {
    if (!str_contains($claims[$path], $from)) {
        failContract('control ' . $path, "seed anchor '{$from}'", 'anchor absent: the control would be vacuous');
    }
    $variant = $claims;
    $variant[$path] = str_replace($from, $to, $claims[$path]);
    return documentationClaimIssues($variant);
};
$ormLine = implode('.', array_slice(explode('.', $orm), 0, 2));
$controls = [
    'changed PHP floor' => [['README.md', 'PHP **8.2.0+** with', 'PHP **8.1.0+** with'], 'PHP floor claim'],
    'changed tested minors' => [['README.md', 'Tested PHP minors: 8.3, 8.4, 8.5.', 'Tested PHP minors: 8.2, 8.3, 8.4.'], 'tested PHP minors'],
    'bogus global flag' => [['README.md', '`--debug`', '`--bogus-global`'], "'--bogus-global'"],
    'missing global flag' => [['README.md', '| `-d`, `--debug` | debug output |', ''], 'missing, extra or duplicate global flag'],
    'bogus short global flag' => [['README.md', '`-d`, `--debug`', '`-z`, `--debug`'], "'-z'"],
    'missing global alias' => [['README.md', '`-d`, `--debug`', '`--debug`'], 'malformed flag row'],
    'duplicate global flag' => [['README.md', '| `-d`, `--debug` | debug output |', "| `-d`, `--debug` | debug output |\n| `-d`, `--debug` | debug output |"], 'missing, extra or duplicate global flag'],
    'missing global table' => [['README.md', '**Global flags**', '**Options**'], 'expected exactly one section'],
    'global flag removed from getopt' => [['bin/vimbtool.php', "'debug',", ''], "'--debug'"],
    'nonexistent CLI flag' => [['README.md', '--once', '--bogus-flag'], "'--bogus-flag'"],
    'nonexistent CLI action' => [['contrib/cron/crontab.example', '-a queue.cli-run', '-a queue.cli-runn'], "'queue.cli-runn'"],
    'working `-a help` claim' => [['README.md', '(`-a help` is rejected', '(`-a help` is accepted'], 'documents `-a help`'],
    'wrong route' => [['README.md', '/admin/two-factor', '/admin/two-factors'], 'two-factors'],
    'unknown controller route' => [['README.md', '`/admin/two-factor`', '`/admins/two-factor`'], 'names no native controller'],
    'changed ORM minor' => [['README.md', 'doctrine/orm ' . $ormLine . ',', 'doctrine/orm 0.0,'], 'doctrine/orm 0.0'],
    'README extension list drift' => [['README.md', '`curl`, ', ''], 'extension list'],
    'FPM extension list drift' => [['contrib/php-fpm/vimbadmin.conf', 'gd, curl, ', ''], 'extension list'],
    'cron apt drops php-gd' => [['contrib/cron/README.md', 'php-gd ', ''], 'php-gd'],
    'cron apt adds php-gmp' => [['contrib/cron/README.md', 'php-sodium', 'php-sodium php-gmp'], 'php-gmp'],
];
foreach ($controls as $label => [$args, $expected]) {
    $found = implode(' | ', $seed(...$args));
    if ($found === '' || !str_contains($found, $expected)) {
        failContract('control ' . $label, "reported issue containing '{$expected}'", $found === '' ? 'documentation still accepted' : $found);
    }
}
foreach ([
    'CRLF and whitespace' => str_replace(["\n", '`-d`, `--debug`'], ["\r\n", '`-d`,    `--debug`'], $claims['README.md']),
    'reordered rows' => str_replace(
        "| `-d`, `--debug` | debug output |\n| `-h`, `--help` | usage + action list |",
        "| `-h`, `--help` | usage + action list |\n| `-d`, `--debug` | debug output |",
        $claims['README.md']
    ),
] as $label => $readme) {
    $variant = $claims;
    $variant['README.md'] = $readme;
    $found = documentationClaimIssues($variant);
    if ($found !== []) {
        failContract('global flags boundary ' . $label, 'valid table accepted', implode(' | ', $found));
    }
}
if (documentationClaimIssues($claims) !== []) {
    failContract('documentation claims', 'restored snapshot clean', 'dirty after controls');
}

echo "OK: documentation matches shipped ownership and configuration.\n";
echo "ALL PASSED\n";
