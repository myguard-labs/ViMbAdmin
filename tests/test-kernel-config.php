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

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native INI config loader ==\n";

// --- dotted-key nesting -----------------------------------------------------
$cfg = IniConfig::parse("[user]\nresources.doctrine2.connection.options.driver = 'pdo_mysql'\n", 'user');
check('dotted keys nest into arrays',
    ($cfg['resources']['doctrine2']['connection']['options']['driver'] ?? null) === 'pdo_mysql');

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
check('inherited parent key retained', ($prod['a']['x'] ?? null) === '1');
check('child overrides parent key',    ($prod['a']['y'] ?? null) === '9');
check('parent scalar inherited',       ($prod['shared'] ?? null) === 'base');
check('child-only key present',        ($prod['only']['prod'] ?? null) === '1'); // 'yes'->'1' (NORMAL)

$base = IniConfig::parse($ini, 'user');
check('base section has no child-only key', !isset($base['only']));
check('base section keeps own value',       ($base['a']['y'] ?? null) === '2');

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
check('transitive: nearest ancestor wins', ($dev['level'] ?? null) === 'production');
check('transitive: own key present',        ($dev['note'] ?? null) === 'dev');

// --- constant concatenation (APPLICATION_PATH "/x") -------------------------
$cc = IniConfig::parse("[user]\nincludePaths.library = APPLICATION_PATH \"/../library\"\n", 'user');
check('constant + quoted string concatenated',
    ($cc['includePaths']['library'] ?? null) === '/app/../library');

// --- boolean coercion mirrors Zend (true->'1', false->'') -------------------
$bools = IniConfig::parse("[user]\nf.on = true\nf.off = false\n", 'user');
check('true coerces to "1"',  ($bools['f']['on'] ?? null) === '1');
check('false coerces to ""',  ($bools['f']['off'] ?? null) === '');

// --- error cases ------------------------------------------------------------
$threw = false;
try { IniConfig::parse("[user]\nx=1\n", 'missing'); } catch (\RuntimeException) { $threw = true; }
check('unknown requested section throws', $threw);

$threw = false;
try { IniConfig::parse("[child : ghost]\nx=1\n", 'child'); } catch (\RuntimeException) { $threw = true; }
check('extending an unknown section throws', $threw);

$threw = false;
try { IniConfig::parse("[a : b : c]\nx=1\n", 'a'); } catch (\RuntimeException) { $threw = true; }
check('more than one parent throws', $threw);

// --- section-less base layer (flattened config) ----------------------------
$flat = "a.b = 1\nshared = base\nlist[] = x\nlist[] = y\n";
$g1 = IniConfig::parse($flat, 'production'); // no such section -> base only
check('flat file loads under any env',        ($g1['a']['b'] ?? null) === '1');
check('flat file: scalar global present',     ($g1['shared'] ?? null) === 'base');
check('flat file: key[] append nests as list',($g1['list'] ?? null) === ['x', 'y']);

$mixed = "base.k = G\nshared = global\n[docker : production]\nshared = docker\n[production]\nshared = prod\n";
$d = IniConfig::parse($mixed, 'docker');
check('globals form the base under a section', ($d['base']['k'] ?? null) === 'G');
check('section overrides a global key',        ($d['shared'] ?? null) === 'docker');

// --- smoke test against the real shipped dist file --------------------------
$distPath = __DIR__ . '/../application/configs/application.ini.dist';
$dist = IniConfig::load($distPath, 'production');
check('flat dist is env-independent (docker == production)',
    IniConfig::load($distPath, 'docker') === $dist);
check('dist: doctrine2 driver resolved',
    ($dist['resources']['doctrine2']['connection']['options']['driver'] ?? null) === 'pdo_mysql');
check('dist: APPLICATION_PATH expanded in a path key',
    is_string($dist['resources']['doctrine2']['proxies_path'] ?? null)
        && str_starts_with((string) $dist['resources']['doctrine2']['proxies_path'], '/app/'));
check('dist: removed legacy bootstrap config stays absent',
    !isset($dist['bootstrap']));

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
