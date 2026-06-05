<?php
/**
 * Unit test: ViMbAdmin\Kernel\Router (Phase 2 of docs/ZF1-REMOVAL.md).
 *
 * The router is pure, dependency-free logic, so this runs with no framework, no
 * database and no Composer install — it requires the two source files directly.
 *
 * Covers the path-decode scheme (controller/action defaults, /key/value tail,
 * dangling key, empty-segment filtering, urldecode, case normalisation), the
 * ZF1-compatible class/method inflection, and the opt-in native allowlist that
 * gates match() (empty allowlist => everything falls back to ZF1 => null).
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Router.php';

use ViMbAdmin\Kernel\Router;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin\\Kernel\\Router::parse ==\n";

$p = Router::parse('');
check('"" -> index/index/[]',            $p['controller'] === 'index' && $p['action'] === 'index' && $p['params'] === []);
$p = Router::parse('/');
check('"/" -> index/index/[]',           $p['controller'] === 'index' && $p['action'] === 'index' && $p['params'] === []);
$p = Router::parse('/domain');
check('"/domain" -> domain/index',       $p['controller'] === 'domain' && $p['action'] === 'index');
$p = Router::parse('/domain/list');
check('"/domain/list" -> domain/list',   $p['controller'] === 'domain' && $p['action'] === 'list' && $p['params'] === []);
$p = Router::parse('/mailbox/edit/id/5');
check('edit/id/5 -> {id:"5"}',           $p['controller'] === 'mailbox' && $p['action'] === 'edit' && $p['params'] === ['id' => '5']);
$p = Router::parse('/mailbox/add/did/3/x/y');
check('two key/value pairs',             $p['params'] === ['did' => '3', 'x' => 'y']);
$p = Router::parse('/domain/admins/did');
check('dangling key -> null',            $p['params'] === ['did' => null]);
$p = Router::parse('//domain//list//');
check('empty segments filtered',         $p['controller'] === 'domain' && $p['action'] === 'list' && $p['params'] === []);
$p = Router::parse('/domain/edit/note/hello%20world');
check('urldecode value',                 $p['params'] === ['note' => 'hello world']);
$p = Router::parse('/Domain/List');
check('controller/action lower-cased',   $p['controller'] === 'domain' && $p['action'] === 'list');

echo "== inflection (ZF1-compatible) ==\n";
check('controllerClass two-factor',      Router::controllerClass('two-factor') === 'TwoFactorController');
check('controllerClass index',           Router::controllerClass('index') === 'IndexController');
check('controllerClass mailbox',         Router::controllerClass('mailbox') === 'MailboxController');
check('actionMethod cli-run',            Router::actionMethod('cli-run') === 'cliRunAction');
check('actionMethod index',              Router::actionMethod('index') === 'indexAction');
check('actionMethod ajax-toggle-active', Router::actionMethod('ajax-toggle-active') === 'ajaxToggleActiveAction');
check('actionMethod cli-reset-totp',     Router::actionMethod('cli-reset-totp') === 'cliResetTotpAction');

echo "== match() + native allowlist ==\n";
$empty = new Router([]);
check('empty allowlist -> null (fallback)', $empty->match('/domain/list') === null);
check('empty allowlist isNative false',     $empty->isNative('domain') === false);

$r = new Router(['domain', 'two-factor']);
check('isNative case-insensitive',          $r->isNative('Domain') === true);
$m = $r->match('/domain/edit/id/7');
check('native match returns RouteMatch',    $m instanceof \ViMbAdmin\Kernel\RouteMatch);
check('match controllerClass',              $m !== null && $m->controllerClass === 'DomainController');
check('match actionMethod',                 $m !== null && $m->actionMethod === 'editAction');
check('match params',                       $m !== null && $m->params === ['id' => '7']);
check('non-native controller -> null',      $r->match('/mailbox/list') === null);
$m2 = $r->match('/two-factor');
check('dashed native controller matches',   $m2 !== null && $m2->controllerClass === 'TwoFactorController' && $m2->actionMethod === 'indexAction');

echo "\n";
if ($failures === 0) {
    echo "OK: all Router assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
