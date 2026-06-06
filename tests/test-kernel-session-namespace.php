<?php
/**
 * Unit test: the native session namespace (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Proves SessionNamespace gives the ZF1-namespace magic-property shape over a
 * slice of $_SESSION, that two namespaces stay isolated, and — the point of the
 * class — that wrapping it in MagicPropertyStorage yields the SessionStorage
 * port the Auth/Csrf services already consume, with no adapter change.
 *
 * Pure $_SESSION manipulation, no framework, no DB. Exit 0 = pass, 1 = fail.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Session/SessionNamespace.php';

use ViMbAdmin\Kernel\Session\MagicPropertyStorage;
use ViMbAdmin\Kernel\Session\SessionNamespace;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native session namespace ==\n";

$_SESSION = [];

$app = new SessionNamespace('Application');

// --- magic property read/write/isset/unset ---------------------------------
check('absent property reads null', $app->domain === null);
check('absent property not set',    !isset($app->domain));

$app->domain = 'example.com';
check('set writes through to $_SESSION slot',
    ($_SESSION['Application']['domain'] ?? null) === 'example.com');
check('get reads the value back', $app->domain === 'example.com');
check('isset true after set',     isset($app->domain));

unset($app->domain);
check('unset clears the value', !isset($app->domain) && $app->domain === null);
check('unset removes the $_SESSION key',
    !array_key_exists('domain', $_SESSION['Application'] ?? []));

// --- namespaces are isolated ------------------------------------------------
$app->flashMessages = ['hi'];
$auth = new SessionNamespace('Zend_Auth');
$auth->storage = ['id' => 1, 'username' => 'admin@example.com'];
check('Application namespace unaffected by Zend_Auth write',
    $app->flashMessages === ['hi']);
check('Zend_Auth namespace stored separately',
    ($_SESSION['Zend_Auth']['storage']['id'] ?? null) === 1
        && ($_SESSION['Application']['storage'] ?? null) === null);

// --- the integration that matters: wrap in MagicPropertyStorage ------------
// This is exactly how the Auth bridge will be built once the ZF1 namespace is
// gone: MagicPropertyStorage(new SessionNamespace('Zend_Auth')).
$store = new MagicPropertyStorage(new SessionNamespace('Zend_Auth'));
check('storage->get sees the magic-property value',
    $store->get('storage')['username'] === 'admin@example.com');
check('storage->has true for present key', $store->has('storage'));
$store->set('token', 'abc');
check('storage->set writes through magic property',
    ($_SESSION['Zend_Auth']['token'] ?? null) === 'abc');
$store->remove('token');
check('storage->remove clears it', !$store->has('token'));

// --- default namespace is 'Application' ------------------------------------
$default = new SessionNamespace();
$default->x = 1;
check("default namespace is 'Application'",
    ($_SESSION['Application']['x'] ?? null) === 1);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
