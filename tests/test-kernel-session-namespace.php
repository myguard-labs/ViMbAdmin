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

final class TestKernelSessionNamespaceHarnessState
{
    public static int $count = 0;
}

$failures =& TestKernelSessionNamespaceHarnessState::$count;
function check(string $label, bool $ok): void {

    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { TestKernelSessionNamespaceHarnessState::$count++; }
}

function sessionValue(string $namespace, string $key): mixed {
    $values = $_SESSION[$namespace] ?? null;
    return is_array($values) ? ($values[$key] ?? null) : null;
}

function sessionHasKey(string $namespace, string $key): bool {
    $values = $_SESSION[$namespace] ?? null;
    return is_array($values) && array_key_exists($key, $values);
}

function sessionIdentical(mixed $actual, mixed $expected): bool {
    return $actual === $expected;
}

echo "== native session namespace ==\n";

$_SESSION = ['Application' => [], 'ViMbAdmin_Auth' => []];

$app = new SessionNamespace('Application');

// --- magic property read/write/isset/unset ---------------------------------
check('absent property reads null', $app->__get('domain') === null);
check('absent property not set',    !$app->__isset('domain'));

$app->__set('domain', 'example.com');
check('set writes through to $_SESSION slot',
    sessionValue('Application', 'domain') === 'example.com');
check('get reads the value back', $app->__get('domain') === 'example.com');
check('isset true after set',     $app->__isset('domain'));

$app->__unset('domain');
check('unset clears the value', !$app->__isset('domain') && $app->__get('domain') === null);
check('unset removes the $_SESSION key',
    !sessionHasKey('Application', 'domain'));

// --- namespaces are isolated ------------------------------------------------
$app->__set('flashMessages', ['hi']);
$auth = new SessionNamespace('ViMbAdmin_Auth');
$auth->__set('storage', ['id' => 1, 'username' => 'admin@example.com']);
check('Application namespace unaffected by ViMbAdmin_Auth write',
    $app->__get('flashMessages') === ['hi']);
check('ViMbAdmin_Auth namespace stored separately',
    sessionHasKey('ViMbAdmin_Auth', 'storage')
        && is_array(sessionValue('ViMbAdmin_Auth', 'storage'))
        && sessionValue('ViMbAdmin_Auth', 'storage')['id'] === 1
        && !sessionHasKey('Application', 'storage'));

// --- the integration that matters: wrap in MagicPropertyStorage ------------
// This is how Auth storage adapts the current ViMbAdmin_Auth namespace slot.
$store = new MagicPropertyStorage(new SessionNamespace('ViMbAdmin_Auth'));
$stored = $store->get('storage');
check('storage->get sees the magic-property value',
    is_array($stored) && ($stored['username'] ?? null) === 'admin@example.com');
check('storage->has true for present key', $store->has('storage'));
$store->set('token', 'abc');
check('storage->set writes through magic property',
    sessionValue('ViMbAdmin_Auth', 'token') === 'abc');
$store->remove('token');
check('storage->remove clears it', !$store->has('token'));

// --- default namespace is 'Application' ------------------------------------
$default = new SessionNamespace();
$default->__set('x', 1);
check("default namespace is 'Application'",
    sessionValue('Application', 'x') === 1);

echo sessionIdentical($failures, 0) ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit(sessionIdentical($failures, 0) ? 0 : 1);
