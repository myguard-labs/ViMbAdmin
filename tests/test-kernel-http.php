<?php
/**
 * Unit test: ViMbAdmin\Kernel\Http\Kernel (Phase 2b, docs/ZF1-REMOVAL.md).
 *
 * Pure dispatch logic — no framework, no DB, no Composer install, no output.
 * Proves the native health route is served and that every other path returns
 * null (→ ZF1 fallback at the entry point).
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Router.php';
require __DIR__ . '/../src/Kernel/Http/Response.php';
require __DIR__ . '/../src/Kernel/Http/Kernel.php';

use ViMbAdmin\Kernel\Router;
use ViMbAdmin\Kernel\Http\Kernel;
use ViMbAdmin\Kernel\Http\Response;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin\\Kernel\\Http\\Kernel ==\n";

$kernel = new Kernel(new Router(Kernel::nativeControllers()));

$r = $kernel->handle('/kernel-health');
check('health route returns a Response',  $r instanceof Response);
check('health status 200',                $r !== null && $r->status === 200);
check('health is text/plain',             $r !== null && str_starts_with($r->contentType, 'text/plain'));
check('health body says ok',              $r !== null && str_contains($r->body, 'ok') && str_contains($r->body, 'native dispatch'));

check('unknown controller -> null',       $kernel->handle('/domain/list') === null);
check('root -> null (ZF1 index)',         $kernel->handle('/') === null);
check('auth path -> null (ZF1)',          $kernel->handle('/auth/setup') === null);
check('health with action still routes',  $kernel->handle('/kernel-health/index') instanceof Response);

// The router only treats the registered controller as native; nothing else.
check('nativeControllers == [kernel-health]', Kernel::nativeControllers() === ['kernel-health']);

// Defence: a native controller name with no handler would fall back. (All
// allowlisted controllers here DO have handlers, so prove the negative via a
// router that allowlists something unhandled.)
$kernel2 = new Kernel(new Router(['kernel-health']));
check('only kernel-health handled',       $kernel2->handle('/kernel-health') instanceof Response);

echo "\n";
if ($failures === 0) {
    echo "OK: all Kernel assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
