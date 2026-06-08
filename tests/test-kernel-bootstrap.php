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
require __DIR__ . '/../src/Kernel/Bootstrap.php';

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\NativeResources;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native bootstrap (pure pieces) ==\n";

// --- baseUrl() mirrors the ZF1 front controller's getBaseUrl() --------------
$_SERVER['SCRIPT_NAME'] = '/index.php';
check("docroot install yields '' base", Bootstrap::baseUrl() === '');

$_SERVER['SCRIPT_NAME'] = '/vimb/index.php';
check("sub-path install yields '/vimb'", Bootstrap::baseUrl() === '/vimb');

$_SERVER['SCRIPT_NAME'] = '/a/b/index.php';
check('nested sub-path preserved', Bootstrap::baseUrl() === '/a/b');

unset($_SERVER['SCRIPT_NAME']);
check('missing SCRIPT_NAME yields empty base', Bootstrap::baseUrl() === '');

// --- reverse-proxy sub-path: prefix is stripped before PHP, so SCRIPT_NAME
//     can't reveal it. Config (1) and X-Forwarded-Prefix (2) must win. --------
$_SERVER['SCRIPT_NAME'] = '/index.php';                       // proxy stripped /vimbadmin
$cfg = ['resources' => ['frontcontroller' => ['baseurl' => '/vimbadmin']]];
check('config baseurl overrides stripped SCRIPT_NAME', Bootstrap::baseUrl($cfg) === '/vimbadmin');
check('config baseurl is slash-normalised', Bootstrap::baseUrl(['resources' => ['frontcontroller' => ['baseurl' => 'vimbadmin/']]]) === '/vimbadmin');

$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/vimbadmin';
check('X-Forwarded-Prefix used when no config', Bootstrap::baseUrl() === '/vimbadmin');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = "/evil\r\nSet-Cookie: x"; // header-injection attempt
check('malformed X-Forwarded-Prefix is rejected', Bootstrap::baseUrl() === '');
unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
check('config still wins over present SCRIPT_NAME dir', Bootstrap::baseUrl($cfg) === '/vimbadmin');

// --- NativeResources presents the Container's bootstrap shape ---------------
$em      = new stdClass();
$view    = new stdClass();
$session = new stdClass();
$options = ['resources' => ['smarty' => ['skin' => '']], 'footer' => ['hide' => '1']];

$res = new NativeResources($options, $em, $view, $session);
check('getResource(doctrine2) returns the EM', $res->getResource('doctrine2') === $em);
check('getResource(smarty) returns the view',  $res->getResource('smarty') === $view);
check('getResource(namespace) returns session', $res->getResource('namespace') === $session);
check('unknown resource returns null',          $res->getResource('mailer') === null);
check('getOptions returns the options array',   $res->getOptions() === $options);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
