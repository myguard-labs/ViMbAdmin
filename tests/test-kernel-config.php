<?php

/**
 * Unit test: the native INI config loader (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Exercises the three Zend_Config_Ini transforms the loader must reproduce —
 * section inheritance, dotted-key nesting, constant concatenation — over small
 * in-memory fixtures, then smoke-tests it against the shipped
 * application.ini.dist to prove it parses the real file's structure.
 *
 * Pure logic, no framework, no DB. Exit 0 = all passed, 1 = a failure.
 */

define('APPLICATION_PATH', '/app');

require __DIR__ . '/../src/Kernel/Config/IniConfig.php';

use ViMbAdmin\Kernel\Config\IniConfig;

final class IniConfigTestState
{
    public static int $failures = 0;
}

function configCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        IniConfigTestState::$failures++;
    }
}

/**
 * @param array<string,mixed> $config
 * @param non-empty-list<string> $path
 */
function configValue(array $config, array $path): mixed
{
    $value = $config;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

echo "== native INI config loader ==\n";

// --- dotted-key nesting -----------------------------------------------------
$cfg = IniConfig::parse("[user]\nresources.doctrine2.connection.options.driver = 'pdo_mysql'\n", 'user');
configCheck(
    'dotted keys nest into arrays',
    configValue($cfg, ['resources', 'doctrine2', 'connection', 'options', 'driver']) === 'pdo_mysql'
);

// --- section inheritance (child overrides parent, parent keys retained) -----
$ini = <<<INI
[user]
a.x = 1
a.y = 2
shared = base
[production : user]
a.y = 9
only.prod = yes
INI;
$prod = IniConfig::parse($ini, 'production');
configCheck('inherited parent key retained', configValue($prod, ['a', 'x']) === '1');
configCheck('child overrides parent key', configValue($prod, ['a', 'y']) === '9');
configCheck('parent scalar inherited', ($prod['shared'] ?? null) === 'base');
configCheck('child-only key present', configValue($prod, ['only', 'prod']) === '1'); // 'yes'->'1' (NORMAL)

$base = IniConfig::parse($ini, 'user');
configCheck('base section has no child-only key', !isset($base['only']));
configCheck('base section keeps own value', configValue($base, ['a', 'y']) === '2');

// --- transitive inheritance chain (development : production : user) ---------
$chain = <<<INI
[user]
level = user
[production : user]
level = production
[development : production]
note = dev
INI;
$dev = IniConfig::parse($chain, 'development');
configCheck('transitive: nearest ancestor wins', ($dev['level'] ?? null) === 'production');
configCheck('transitive: own key present', ($dev['note'] ?? null) === 'dev');

// --- constant concatenation (APPLICATION_PATH "/x") -------------------------
$cc = IniConfig::parse("[user]\nincludePaths.library = APPLICATION_PATH \"/../library\"\n", 'user');
configCheck(
    'constant + quoted string concatenated',
    configValue($cc, ['includePaths', 'library']) === '/app/../library'
);

// --- boolean coercion mirrors Zend (true->'1', false->'') -------------------
$bools = IniConfig::parse("[user]\nf.on = true\nf.off = false\n", 'user');
configCheck('true coerces to "1"', configValue($bools, ['f', 'on']) === '1');
configCheck('false coerces to ""', configValue($bools, ['f', 'off']) === '');

// --- error cases ------------------------------------------------------------
$threw = false;
try {
    IniConfig::parse("[user]\nx=1\n", 'missing');
} catch (\RuntimeException) {
    $threw = true;
}
configCheck('unknown requested section throws', $threw);

$threw = false;
try {
    IniConfig::parse("[child : ghost]\nx=1\n", 'child');
} catch (\RuntimeException) {
    $threw = true;
}
configCheck('extending an unknown section throws', $threw);

$threw = false;
try {
    IniConfig::parse("[a : b : c]\nx=1\n", 'a');
} catch (\RuntimeException) {
    $threw = true;
}
configCheck('more than one parent throws', $threw);

foreach ([
    "[user]\nmail = scalar\nmail.host = nested\n",
    "[user]\nmail.host = nested\nmail = scalar\n",
] as $collision) {
    $message = '';
    try {
        IniConfig::parse($collision, 'user');
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }
    configCheck(
        'scalar/nested dotted-key collision rejects both key orderings and names both keys',
        str_contains($message, "'mail'") && str_contains($message, "'mail.host'")
    );
}

foreach ([
    "[user]\nmail[] = first\nmail.host = nested\n",
    "[user]\nmail.host = nested\nmail[] = first\n",
] as $collision) {
    $message = '';
    try {
        IniConfig::parse($collision, 'user');
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }
    configCheck(
        'list/namespace dotted-key collision rejects both parser-preserved orderings and names both keys',
        str_contains($message, "'mail'") && str_contains($message, "'mail.host'")
    );
}

// --- section-less base layer (flattened config) ----------------------------
$flat = "a.b = 1\nshared = base\nlist[] = x\nlist[] = y\n";
$g1 = IniConfig::parse($flat, 'production'); // no such section -> base only
configCheck('flat file loads under any env', configValue($g1, ['a', 'b']) === '1');
configCheck('flat file: scalar global present', ($g1['shared'] ?? null) === 'base');
configCheck('flat file: key[] append nests as list', ($g1['list'] ?? null) === ['x', 'y']);

$mixed = "base.k = G\nshared = global\n[docker : production]\nshared = docker\n[production]\nshared = prod\n";
$d = IniConfig::parse($mixed, 'docker');
configCheck('globals form the base under a section', configValue($d, ['base', 'k']) === 'G');
configCheck('section overrides a global key', ($d['shared'] ?? null) === 'docker');

// --- smoke test against the real shipped dist file --------------------------
$distPath = __DIR__ . '/../application/configs/application.ini.dist';
$dist = IniConfig::load($distPath, 'production');
configCheck(
    'flat dist is env-independent (docker == production)',
    IniConfig::load($distPath, 'docker') === $dist
);
configCheck(
    'dist: doctrine2 driver resolved',
    configValue($dist, ['resources', 'doctrine2', 'connection', 'options', 'driver']) === 'pdo_mysql'
);
configCheck(
    'dist: APPLICATION_PATH expanded in a path key',
    is_string($dist['temporary_directory'] ?? null)
        && str_starts_with((string) $dist['temporary_directory'], '/app/')
);
configCheck(
    'dist: removed legacy bootstrap config stays absent',
    !isset($dist['bootstrap'])
);

echo IniConfigTestState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . IniConfigTestState::$failures . " FAILED\n";
exit(IniConfigTestState::$failures === 0 ? 0 : 1);
