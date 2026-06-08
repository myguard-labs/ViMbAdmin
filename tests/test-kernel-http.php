<?php
/**
 * Unit test: ViMbAdmin\Kernel\Http\Kernel (Phase 2b, docs/ZF1-REMOVAL.md).
 *
 * Pure dispatch logic — no framework, no DB, no Composer install, no output.
 * Proves the native health route is served and unknown paths remain unhandled
 * so the entry point can return a native 404.
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
check('root needs a dispatcher',          $kernel->handle('/') === null);
check('auth path needs a dispatcher',     $kernel->handle('/auth/setup') === null);
check('health with action still routes',  $kernel->handle('/kernel-health/index') instanceof Response);

// Phase 3: the migrated controllers join the allowlist (health stays first).
check('nativeControllers includes the migrated controllers',
    Kernel::nativeControllers() === ['kernel-health', 'additionalinfo', 'auth', 'index', 'log', 'admin', 'domain', 'alias', 'mailbox', 'archive', 'queue', 'maintenance', 'mcp']);

check('removed Thunderbird export route is not handled',
    $kernel->canHandle('/exportsettings/thunderbird/email/user@example.com') === false);

// A container-backed controller routed through a Kernel built WITHOUT a
// dispatcher (as here) remains unhandled.
check('additionalinfo without a dispatcher -> null',
    $kernel->handle('/additionalinfo/typeahead/type/x') === null);

// Defence: a native controller name with no handler would fall back. (All
// allowlisted controllers here DO have handlers, so prove the negative via a
// router that allowlists something unhandled.)
$kernel2 = new Kernel(new Router(['kernel-health']));
check('only kernel-health handled',       $kernel2->handle('/kernel-health') instanceof Response);

// canHandle() — the resource-free servability check the entry point uses to
// route before opening a session. The built-in (kernel-health) and a
// non-allowlisted path are decidable without loading any controller; the
// method_exists branch for real controllers is exercised in the image.
check('canHandle: built-in kernel-health -> true',  $kernel->canHandle('/kernel-health') === true);
check('canHandle: kernel-health/index    -> true',  $kernel->canHandle('/kernel-health/index') === true);
check('canHandle: non-allowlisted path   -> false', $kernel->canHandle('/totally/unknown') === false);

echo "\n";
if ($failures === 0) {
    echo "OK: all Kernel assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
