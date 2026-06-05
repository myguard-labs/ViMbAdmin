<?php
/**
 * Unit test: the Phase 3 native-dispatch core — Container + Mvc\Dispatcher +
 * Mvc\AbstractController (docs/ZF1-REMOVAL.md). Pure logic over fakes: a fake
 * bootstrap (getResource), a fake entity manager, an in-memory Auth, and a tiny
 * test controller. No framework, no DB.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';
require __DIR__ . '/../src/Kernel/Http/Response.php';
require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Container.php';
require __DIR__ . '/../src/Kernel/Mvc/AbstractController.php';
require __DIR__ . '/../src/Kernel/Mvc/Dispatcher.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Mvc\Dispatcher;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

// --- fakes ------------------------------------------------------------- //

final class ArraySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

/** Stand-in for \Entities\Admin (only getSuper()/getId() are used here). */
final class AdminFake
{
    public function __construct(private int $id, private bool $super) {}
    public function getId(): int { return $this->id; }
    public function getSuper(): bool { return $this->super; }
}

/** Fake Doctrine EM: getResource('doctrine2') returns this. */
final class EmFake
{
    public function ping(): string { return 'em-ok'; }
}

/** Fake ZF1 bootstrap: only getResource() is used by the Container. */
final class BootstrapFake
{
    public function __construct(private EmFake $em) {}
    public function getResource(string $name): mixed
    {
        return $name === 'doctrine2' ? $this->em : null;
    }
}

/** A native controller exercising every AbstractController helper. */
final class ProbeController extends AbstractController
{
    public function showAction(): Response
    {
        return $this->json([
            'type'  => $this->param('type', 'DEFAULT'),
            'admin' => $this->admin()?->getId(),
            'em'    => $this->em()->ping(),
        ]);
    }

    public function missingResponseAction(): string
    {
        return 'not a Response';
    }
}

// --- harness ----------------------------------------------------------- //

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== Phase 3 native dispatch (Container + Dispatcher + AbstractController) ==\n";

$admin = new AdminFake(7, true);
$auth  = new Auth(new ArraySession(['identity' => ['id' => 7]]), fn(int $id) => $id === 7 ? $admin : null);
$em    = new EmFake();
$container = new Container(new BootstrapFake($em), $auth);

// Container facade ----------------------------------------------------- //
check('container->entityManager() returns the doctrine2 resource', $container->entityManager() === $em);
check('container->auth() returns the Auth service',                $container->auth() === $auth);
check('container->getResource() passthrough',                      $container->getResource('doctrine2') === $em);

$dispatcher = new Dispatcher($container, ['probe' => ProbeController::class]);

// Happy path: probe/show/type/HELLO ------------------------------------ //
$match = new RouteMatch('probe', 'show', 'ProbeController', 'showAction', ['type' => 'HELLO']);
$resp  = $dispatcher->dispatch($match);
check('dispatch returns a Response',          $resp instanceof Response);
check('content-type is JSON',                 $resp !== null && str_contains($resp->contentType, 'application/json'));
check('status 200',                           $resp !== null && $resp->status === 200);
$body = $resp !== null ? json_decode($resp->body, true) : null;
check('param() decoded from the route',       is_array($body) && $body['type'] === 'HELLO');
check('admin() resolved via the container',   is_array($body) && $body['admin'] === 7);
check('em() reached the doctrine2 resource',  is_array($body) && $body['em'] === 'em-ok');

// param default -------------------------------------------------------- //
$resp2 = $dispatcher->dispatch(new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []));
$body2 = $resp2 !== null ? json_decode($resp2->body, true) : null;
check('param() falls back to its default',    is_array($body2) && $body2['type'] === 'DEFAULT');

// Fallbacks → null ----------------------------------------------------- //
check('unknown controller → null (ZF1 fallback)',
    $dispatcher->dispatch(new RouteMatch('nope', 'show', 'NopeController', 'showAction', [])) === null);
check('unknown action on a native controller → null',
    $dispatcher->dispatch(new RouteMatch('probe', 'gone', 'ProbeController', 'goneAction', [])) === null);
check('action not returning a Response → null',
    $dispatcher->dispatch(new RouteMatch('probe', 'missing-response', 'ProbeController', 'missingResponseAction', [])) === null);

// Anonymous admin ------------------------------------------------------ //
$anon = new Dispatcher(
    new Container(new BootstrapFake($em), new Auth(new ArraySession([]), fn(int $id) => null)),
    ['probe' => ProbeController::class],
);
$respAnon = $anon->dispatch(new RouteMatch('probe', 'show', 'ProbeController', 'showAction', ['type' => 'X']));
$bodyAnon = $respAnon !== null ? json_decode($respAnon->body, true) : null;
check('admin() is null when unauthenticated', is_array($bodyAnon) && $bodyAnon['admin'] === null);

echo "\n";
if ($failures === 0) {
    echo "OK: all dispatch assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
