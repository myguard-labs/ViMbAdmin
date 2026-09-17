<?php

/**
 * Unit test: the framework-free bootstrap's pure pieces (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * Bootstrap::boot() itself needs a real DB + Smarty + session, so it is
 * exercised in the image when the native-bootstrap flag is wired. Here we cover
 * the parts that ARE pure: the base-URL derivation OSS_Utils::genUrl depends on,
 * and the NativeResources holder the Container reads its resources through.
 *
 * No framework, no DB. Exit 0 = pass, 1 = fail.
 */

require __DIR__ . '/../src/Kernel/NativeResources.php';
require __DIR__ . '/../src/Kernel/Config/IniConfig.php';
require __DIR__ . '/../src/Kernel/Doctrine/EntityManagerFactory.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';
require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Session/SessionNamespace.php';
require __DIR__ . '/../src/Kernel/View/SmartyView.php';
require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../src/Kernel/Bootstrap.php';

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\NativeResources;

ob_start();

final class BootstrapAdminRepository
{
    /** @param array<int,object> $admins */
    public function __construct(
        private array $admins,
        private ?Throwable $error = null,
        private mixed $invalid = null,
    ) {
    }

    public function find(int $id): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->invalid ?? ($this->admins[$id] ?? null);
    }
}

final class FailingSessionHandler extends SessionHandler
{
    public function open(string $path, string $name): bool
    {
        return false;
    }
}

final class BootstrapObjectManager
{
    public ?string $requestedClass = null;

    public function __construct(private mixed $repository)
    {
    }

    public function getRepository(string $className): mixed
    {
        $this->requestedClass = $className;
        return $this->repository;
    }
}

final class TestKernelBootstrapHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelBootstrapHarnessState::$count;
function kernelBootstrapCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestKernelBootstrapHarnessState::$count++;
    }
}

function bootstrapAdminLoader(object $manager): callable
{
    $loader = (new ReflectionMethod(Bootstrap::class, 'adminLoader'))->invoke(null, $manager);
    if (!is_callable($loader)) {
        throw new RuntimeException('bootstrap admin loader is not callable');
    }

    return $loader;
}

echo "== native bootstrap (pure pieces) ==\n";

// --- baseUrl() mirrors the ZF1 front controller's getBaseUrl() --------------
$_SERVER['SCRIPT_NAME'] = '/index.php';
kernelBootstrapCheck("docroot install yields '' base", Bootstrap::baseUrl() === '');

$_SERVER['SCRIPT_NAME'] = '/vimb/index.php';
kernelBootstrapCheck("sub-path install yields '/vimb'", Bootstrap::baseUrl() === '/vimb');

$_SERVER['SCRIPT_NAME'] = '/a/b/index.php';
kernelBootstrapCheck('nested sub-path preserved', Bootstrap::baseUrl() === '/a/b');

unset($_SERVER['SCRIPT_NAME']);
kernelBootstrapCheck('missing SCRIPT_NAME yields empty base', Bootstrap::baseUrl() === '');

// --- reverse-proxy sub-path: prefix is stripped before PHP, so SCRIPT_NAME
//     can't reveal it. Config (1) and X-Forwarded-Prefix (2) must win. --------
$_SERVER['SCRIPT_NAME'] = '/index.php';                       // proxy stripped /vimbadmin
$cfg = ['resources' => ['frontController' => ['baseUrl' => '/vimbadmin']]];  // ZF1 key casing (what the host ini uses)
kernelBootstrapCheck('config baseUrl overrides stripped SCRIPT_NAME', Bootstrap::baseUrl($cfg) === '/vimbadmin');
kernelBootstrapCheck('lowercase frontcontroller.baseurl also accepted', Bootstrap::baseUrl(['resources' => ['frontcontroller' => ['baseurl' => 'vimbadmin/']]]) === '/vimbadmin');

$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/vimbadmin';
kernelBootstrapCheck('trusted X-Forwarded-Prefix used when no config', Bootstrap::baseUrl() === '/vimbadmin');
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
kernelBootstrapCheck('untrusted X-Forwarded-Prefix is ignored', Bootstrap::baseUrl() === '');
foreach (['0.0.0.1', '240.0.0.1', '::', 'ff02::1'] as $reservedPeer) {
    $_SERVER['REMOTE_ADDR'] = $reservedPeer;
    kernelBootstrapCheck("reserved peer {$reservedPeer} cannot supply X-Forwarded-Prefix", Bootstrap::baseUrl() === '');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
$trustedProxy = ['trustedproxy' => ['mode' => 'on', 'proxies' => ['192.0.2.0/24']]];
kernelBootstrapCheck('explicit trusted proxy may supply prefix', Bootstrap::baseUrl($trustedProxy) === '/vimbadmin');
$scalarTrustedProxy = ['trustedproxy' => ['mode' => 'on', 'proxies' => '192.0.2.0/24']];
kernelBootstrapCheck('scalar trusted proxy may supply prefix', Bootstrap::baseUrl($scalarTrustedProxy) === '/vimbadmin');
$invalidProxyShapes = [
    'associative trusted proxy configuration is rejected' => ['edge' => '192.0.2.0/24'],
    'mixed trusted proxy list is rejected' => ['192.0.2.0/24', 123],
];
foreach ($invalidProxyShapes as $label => $invalidProxies) {
    $invalidProxyShapeRejected = false;
    try {
        Bootstrap::baseUrl(['trustedproxy' => ['mode' => 'on', 'proxies' => $invalidProxies]]);
    } catch (LogicException) {
        $invalidProxyShapeRejected = true;
    }
    kernelBootstrapCheck($label, $invalidProxyShapeRejected);
}
kernelBootstrapCheck('off mode ignores prefix', Bootstrap::baseUrl(['trustedproxy' => ['mode' => 'off']]) === '');
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/safe/../admin';
kernelBootstrapCheck('parent dot segment in X-Forwarded-Prefix is rejected', Bootstrap::baseUrl() === '');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/safe/./admin';
kernelBootstrapCheck('current dot segment in X-Forwarded-Prefix is rejected', Bootstrap::baseUrl() === '');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = "/evil\r\nSet-Cookie: x"; // header-injection attempt
kernelBootstrapCheck('malformed X-Forwarded-Prefix is rejected', Bootstrap::baseUrl() === '');
unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
unset($_SERVER['REMOTE_ADDR']);
kernelBootstrapCheck('config still wins over present SCRIPT_NAME dir', Bootstrap::baseUrl($cfg) === '/vimbadmin');

$malformedBaseUrlRejected = false;
try {
    Bootstrap::baseUrl(['resources' => ['frontController' => ['baseUrl' => ['nested']]]]);
} catch (LogicException $e) {
    $malformedBaseUrlRejected = $e->getMessage() === 'resources.frontController.baseUrl must be a string';
}
kernelBootstrapCheck('malformed configured base URL fails closed before URL construction', $malformedBaseUrlRejected);

$malformedSkinRejected = false;
try {
    (new ReflectionMethod(Bootstrap::class, 'skinCss'))->invoke(null, __DIR__ . '/../application', [
        'resources' => ['smarty' => ['skin' => ['nested']]],
    ]);
} catch (LogicException $e) {
    $malformedSkinRejected = $e->getMessage() === 'resources.smarty.skin must be a string';
}
kernelBootstrapCheck('malformed skin configuration fails closed before filesystem lookup', $malformedSkinRejected);

// --- Sparse session configuration retains application-level hard defaults. --
$sessionStartRejected = false;
session_set_save_handler(new FailingSessionHandler(), true);
try {
    (new ReflectionMethod(Bootstrap::class, 'startSession'))->invoke(null);
} catch (RuntimeException $e) {
    $sessionStartRejected = $e->getMessage() === 'Unable to start session';
}
session_set_save_handler(new SessionHandler(), true);
kernelBootstrapCheck('session startup failure aborts bootstrap', $sessionStartRejected);
$configureSession = new ReflectionMethod(Bootstrap::class, 'configureSession');
$sessionFile = tempnam(sys_get_temp_dir(), 'vimbadmin-session-file-');
$nonDirectoryRejected = false;
try {
    $configureSession->invoke(null, ['resources' => ['session' => ['save_path' => $sessionFile]]]);
} catch (RuntimeException $e) {
    $nonDirectoryRejected = $e->getMessage() === 'Session save path is not a writable directory';
}
@unlink($sessionFile);
kernelBootstrapCheck('session save path rejects a non-directory', $nonDirectoryRejected);
$unwritablePathRejected = false;
try {
    $configureSession->invoke(null, ['resources' => ['session' => ['save_path' => '/proc/1/vimbadmin-session']]]);
} catch (RuntimeException $e) {
    $unwritablePathRejected = $e->getMessage() === 'Session save path is not a writable directory';
}
kernelBootstrapCheck('session save path rejects an unwritable location', $unwritablePathRejected);
$sessionKeys = ['use_strict_mode', 'use_only_cookies', 'cookie_httponly', 'cookie_secure', 'cookie_samesite'];
$originalSessionValues = [];
foreach ($sessionKeys as $key) {
    $originalSessionValues[$key] = (string) ini_get('session.' . $key);
}
$configureSession->invoke(null, []);
kernelBootstrapCheck('sparse session config defaults use_only_cookies on', ini_get('session.use_only_cookies') === '1');
kernelBootstrapCheck('sparse session config defaults cookie_httponly on', ini_get('session.cookie_httponly') === '1');
kernelBootstrapCheck('sparse session config defaults cookie_secure on', ini_get('session.cookie_secure') === '1');
kernelBootstrapCheck('sparse session config defaults cookie_samesite to Lax', ini_get('session.cookie_samesite') === 'Lax');
$configureSession->invoke(null, ['resources' => ['session' => [
    'use_only_cookies' => false,
    'cookie_httponly' => false,
    'cookie_secure' => false,
    'cookie_samesite' => 'Strict',
]]]);
kernelBootstrapCheck(
    'explicit session cookie overrides are preserved',
    ini_get('session.use_only_cookies') === ''
    && ini_get('session.cookie_httponly') === ''
    && ini_get('session.cookie_secure') === ''
    && ini_get('session.cookie_samesite') === 'Strict'
);
foreach ($originalSessionValues as $key => $value) {
    ini_set('session.' . $key, $value);
}

// --- NativeResources presents the Container's bootstrap shape ---------------
$em      = new stdClass();
$view    = new stdClass();
$session = new stdClass();
$options = ['resources' => ['smarty' => ['skin' => '']], 'footer' => ['hide' => '1']];

$res = new NativeResources($options, $em, $view, $session);
kernelBootstrapCheck('getResource(doctrine2) returns the EM', $res->getResource('doctrine2') === $em);
kernelBootstrapCheck('getResource(smarty) returns the view', $res->getResource('smarty') === $view);
kernelBootstrapCheck('getResource(namespace) returns session', $res->getResource('namespace') === $session);
kernelBootstrapCheck('unknown resource returns null', $res->getResource('mailer') === null);
kernelBootstrapCheck('getOptions returns the options array', $res->getOptions() === $options);

// --- boot-time auth wiring validates the persistence boundary --------------
$admin = new stdClass();
$manager = new BootstrapObjectManager(new BootstrapAdminRepository([7 => $admin]));
$loader = bootstrapAdminLoader($manager);
kernelBootstrapCheck('admin loader requests the production entity class', $manager->requestedClass === '\\Entities\\Admin');
kernelBootstrapCheck('admin loader returns the requested admin', $loader(7) === $admin);
kernelBootstrapCheck('admin loader preserves a missing admin result', $loader(8) === null);

$wrongManagerRejected = false;
try {
    bootstrapAdminLoader(new stdClass());
} catch (LogicException $e) {
    $wrongManagerRejected = $e->getMessage() === 'Native bootstrap requires a Doctrine object manager.';
}
kernelBootstrapCheck('bootstrap rejects a missing object-manager API', $wrongManagerRejected);

$wrongRepositoryRejected = false;
try {
    bootstrapAdminLoader(new BootstrapObjectManager(new stdClass()));
} catch (LogicException $e) {
    $wrongRepositoryRejected = $e->getMessage() === 'Native bootstrap requires an admin repository.';
}
kernelBootstrapCheck('bootstrap rejects a missing admin-repository API', $wrongRepositoryRejected);

$repositoryErrorPropagated = false;
$repositoryError = new RuntimeException('admin lookup failed');
$loader = bootstrapAdminLoader(new BootstrapObjectManager(new BootstrapAdminRepository([], $repositoryError)));
try {
    $loader(7);
} catch (RuntimeException $e) {
    $repositoryErrorPropagated = $e === $repositoryError;
}
kernelBootstrapCheck('admin repository errors propagate unchanged', $repositoryErrorPropagated);

$invalidAdminRejected = false;
$loader = bootstrapAdminLoader(new BootstrapObjectManager(new BootstrapAdminRepository([], null, 'not-an-admin')));
try {
    $loader(7);
} catch (LogicException $e) {
    $invalidAdminRejected = $e->getMessage() === 'Admin repository returned an invalid value.';
}
kernelBootstrapCheck('bootstrap rejects a non-object admin result', $invalidAdminRejected);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
ob_end_flush();
exit($failures === 0 ? 0 : 1);
