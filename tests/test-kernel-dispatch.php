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
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';
require __DIR__ . '/../src/Kernel/Http/Response.php';
require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/NativeResources.php';
require __DIR__ . '/../library/ViMbAdmin/Demo.php';
require __DIR__ . '/../src/Kernel/Mail/Mailer.php';
require __DIR__ . '/../src/Kernel/Container.php';
require __DIR__ . '/../src/Kernel/Mvc/AbstractController.php';
require __DIR__ . '/../src/Kernel/Mvc/Dispatcher.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Mvc\Dispatcher;
use ViMbAdmin\Kernel\NativeResources;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

// --- fakes ------------------------------------------------------------- //

final class ArraySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}

/** Stand-in for \Entities\Admin at the strict Auth identity boundary. */
final class AdminFake
{
    public function __construct(private int $id, private bool $super, private bool $active = true)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getUsername(): string
    {
        return "admin{$this->id}@example.test";
    }
    public function getSuper(): bool
    {
        return $this->super;
    }
    public function getActive(): bool
    {
        return $this->active;
    }
}

/** Fake Doctrine EM: getResource('doctrine2') returns this. */
final class EmFake
{
    public function ping(): string
    {
        return 'em-ok';
    }
}

/** Fake native resource holder: only getResource() is used by the Container. */
final class BootstrapFake
{
    public function __construct(private EmFake $em)
    {
    }
    public function getResource(string $name): mixed
    {
        return $name === 'doctrine2' ? $this->em : null;
    }
    /** Real Bootstrap exposes getOptions(); AbstractController::admin() reads
     *  resources.session.idle_timeout through it. Empty options = idle disabled. */
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
}

/** Legacy-compatible proxy whose bootstrap methods are exposed through __call(). */
final class DynamicBootstrapFake
{
    /** @param array<string,mixed> $options */
    public function __construct(private object $resource, private array $options)
    {
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return match ($method) {
            'getOptions' => $this->options,
            'getResource' => $arguments[0] === 'dynamic' ? $this->resource : null,
            default => null,
        };
    }
}

final class InvalidOptionsBootstrapFake
{
    public function getOptions(): string
    {
        return 'not-an-array';
    }
    public function getResource(string $name): mixed
    {
        return match ($name) {
            default => null,
        };
    }
}

final class MailerShapeBootstrapFake
{
    /** @param array<string,mixed> $options */
    public function __construct(private array $options)
    {
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
    public function getResource(string $name): mixed
    {
        return null;
    }
}

/** Resource holder for idle-timeout boundary tests. */
final class IdleBootstrapFake
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EmFake $em,
        private array $options,
        private mixed $session,
    ) {
    }

    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->em,
            'namespace' => $this->session,
            default => null,
        };
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

/** A native controller exercising every AbstractController helper. */
final class ProbeController extends AbstractController
{
    public function showAction(): Response
    {
        $admin = $this->admin();
        $entityManager = $this->em();
        return $this->json([
            'type'  => $this->param('type', 'DEFAULT'),
            'admin' => $admin instanceof AdminFake ? $admin->getId() : null,
            'em'    => $entityManager instanceof EmFake ? $entityManager->ping() : null,
        ]);
    }

    public function missingResponseAction(): string
    {
        return 'not a Response';
    }
}

// --- harness ----------------------------------------------------------- //

final class TestKernelDispatchHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelDispatchHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestKernelDispatchHarnessState::$count++;
    }
}

echo "== Phase 3 native dispatch (Container + Dispatcher + AbstractController) ==\n";

$admin = new AdminFake(7, true);
$auth  = new Auth(new ArraySession(['identity' => ['id' => 7]]), fn (int $id) => $id === 7 ? $admin : null);
$em    = new EmFake();
$container = new Container(new BootstrapFake($em), $auth);

// Container facade ----------------------------------------------------- //
check('container->entityManager() returns the doctrine2 resource', $container->entityManager() === $em);
check('container->auth() returns the Auth service', $container->auth() === $auth);
check('container->getResource() passthrough', $container->getResource('doctrine2') === $em);

$nativeOptions = ['footer' => ['hide' => '1']];
$nativeSession = new stdClass();
$nativeView = new stdClass();
$native = new Container(
    new NativeResources($nativeOptions, $em, $nativeView, $nativeSession),
    $auth,
);
check('container->options() reads native resources', $native->options() === $nativeOptions);
check('container->session() reads the native namespace', $native->session() === $nativeSession);
check('container preserves an unknown native resource', $native->getResource('missing') === null);

$dynamicResource = new stdClass();
$dynamic = new Container(new DynamicBootstrapFake($dynamicResource, ['legacy' => true]), $auth);
check('container preserves dynamic legacy options lookup', $dynamic->options() === ['legacy' => true]);
check('container preserves dynamic legacy resource lookup', $dynamic->getResource('dynamic') === $dynamicResource);

$missingOptionsRejected = false;
try {
    (new Container(new stdClass(), $auth))->options();
} catch (Error $e) {
    $missingOptionsRejected = str_contains($e->getMessage(), 'stdClass::getOptions()');
}
check('container rejects a missing options API', $missingOptionsRejected);

$missingResourceRejected = false;
try {
    (new Container(new stdClass(), $auth))->getResource('doctrine2');
} catch (Error $e) {
    $missingResourceRejected = str_contains($e->getMessage(), 'stdClass::getResource()');
}
check('container rejects a missing resource API', $missingResourceRejected);

$wrongOptionsRejected = false;
try {
    (new Container(new InvalidOptionsBootstrapFake(), $auth))->options();
} catch (TypeError) {
    $wrongOptionsRejected = true;
}
check('container rejects a non-array options return', $wrongOptionsRejected);

check(
    'container mailer preserves the absent transport default',
    get_debug_type((new Container(new BootstrapFake($em), $auth))->mailer()) === 'ViMbAdmin\\Kernel\\Mail\\Mailer'
);
$malformedMailerOptions = [
    ['resources' => null],
    ['resources' => ['mail' => null]],
    ['resources' => ['mail' => ['transport' => null]]],
];
foreach ($malformedMailerOptions as $index => $options) {
    $rejected = false;
    try {
        (new Container(new MailerShapeBootstrapFake($options), $auth))->mailer();
    } catch (TypeError) {
        $rejected = true;
    }
    check('container mailer rejects malformed nested option shape ' . $index, $rejected);
}

$dispatcher = new Dispatcher($container, ['probe' => ProbeController::class]);

// Happy path: probe/show/type/HELLO ------------------------------------ //
$match = new RouteMatch('probe', 'show', 'ProbeController', 'showAction', ['type' => 'HELLO']);
$resp  = $dispatcher->dispatch($match);
check('dispatch returns a Response', $resp instanceof Response);
check('content-type is JSON', $resp !== null && str_contains($resp->contentType, 'application/json'));
check('status 200', $resp !== null && $resp->status === 200);
$body = $resp !== null ? json_decode($resp->body, true) : null;
check('param() decoded from the route', is_array($body) && $body['type'] === 'HELLO');
check('admin() resolved via the container', is_array($body) && $body['admin'] === 7);
check('em() reached the doctrine2 resource', is_array($body) && $body['em'] === 'em-ok');

// param default -------------------------------------------------------- //
$resp2 = $dispatcher->dispatch(new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []));
$body2 = $resp2 !== null ? json_decode($resp2->body, true) : null;
check('param() falls back to its default', is_array($body2) && $body2['type'] === 'DEFAULT');

// Unhandled dispatches → null ----------------------------------------- //
check(
    'unknown controller → null',
    $dispatcher->dispatch(new RouteMatch('nope', 'show', 'NopeController', 'showAction', [])) === null
);
check(
    'unknown action on a native controller → null',
    $dispatcher->dispatch(new RouteMatch('probe', 'gone', 'ProbeController', 'goneAction', [])) === null
);
check(
    'action not returning a Response → null',
    $dispatcher->dispatch(new RouteMatch('probe', 'missing-response', 'ProbeController', 'missingResponseAction', [])) === null
);

// Anonymous admin ------------------------------------------------------ //
$anon = new Dispatcher(
    new Container(new BootstrapFake($em), new Auth(new ArraySession([]), fn (int $id) => null)),
    ['probe' => ProbeController::class],
);
$respAnon = $anon->dispatch(new RouteMatch('probe', 'show', 'ProbeController', 'showAction', ['type' => 'X']));
$bodyAnon = $respAnon !== null ? json_decode($respAnon->body, true) : null;
check('admin() is null when unauthenticated', is_array($bodyAnon) && $bodyAnon['admin'] === null);

// Idle-timeout session boundary --------------------------------------- //
$idleOptions = ['resources' => ['session' => ['idle_timeout' => 60]]];
$freshSession = (object) ['timeOfLastAction' => time() - 5];
$freshAuth = new Auth(
    new ArraySession(['identity' => ['id' => 7]]),
    fn (int $id) => $id === 7 ? $admin : null,
);
$freshDispatcher = new Dispatcher(
    new Container(new IdleBootstrapFake($em, $idleOptions, $freshSession), $freshAuth),
    ['probe' => ProbeController::class],
);
$beforeRefresh = time();
$freshResponse = $freshDispatcher->dispatch(
    new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []),
);
$freshBody = $freshResponse !== null ? json_decode($freshResponse->body, true) : null;
check(
    'authenticated admin survives within the idle timeout',
    is_array($freshBody) && $freshBody['admin'] === 7
);
check(
    'authenticated request refreshes its last-action timestamp',
    is_int($freshSession->timeOfLastAction)
        && $freshSession->timeOfLastAction >= $beforeRefresh
        && $freshSession->timeOfLastAction <= time()
);

$expiredSession = (object) ['timeOfLastAction' => time() - 61];
$expiredIdentity = new ArraySession(['identity' => ['id' => 7]]);
$expiredAuth = new Auth($expiredIdentity, fn (int $id) => $id === 7 ? $admin : null);
$expiredDispatcher = new Dispatcher(
    new Container(new IdleBootstrapFake($em, $idleOptions, $expiredSession), $expiredAuth),
    ['probe' => ProbeController::class],
);
$expiredResponse = $expiredDispatcher->dispatch(
    new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []),
);
$expiredBody = $expiredResponse !== null ? json_decode($expiredResponse->body, true) : null;
check(
    'expired authenticated session returns no admin',
    is_array($expiredBody) && $expiredBody['admin'] === null
);
check('expired authenticated session clears the identity', !$expiredIdentity->has('identity'));

$anonymousMalformedDispatcher = new Dispatcher(
    new Container(
        new IdleBootstrapFake($em, $idleOptions, 'malformed-session'),
        new Auth(new ArraySession([]), fn (int $id) => null),
    ),
    ['probe' => ProbeController::class],
);
$anonymousMalformedResponse = $anonymousMalformedDispatcher->dispatch(
    new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []),
);
$anonymousMalformedBody = $anonymousMalformedResponse !== null
    ? json_decode($anonymousMalformedResponse->body, true)
    : null;
check(
    'anonymous request does not touch a malformed session resource',
    is_array($anonymousMalformedBody) && $anonymousMalformedBody['admin'] === null
);

$malformedRejected = false;
try {
    $malformedDispatcher = new Dispatcher(
        new Container(
            new IdleBootstrapFake($em, $idleOptions, 'malformed-session'),
            new Auth(
                new ArraySession(['identity' => ['id' => 7]]),
                fn (int $id) => $id === 7 ? $admin : null,
            ),
        ),
        ['probe' => ProbeController::class],
    );
    $malformedDispatcher->dispatch(
        new RouteMatch('probe', 'show', 'ProbeController', 'showAction', []),
    );
} catch (TypeError) {
    $malformedRejected = true;
}
check('authenticated request rejects a malformed session resource', $malformedRejected);

echo "\n";
if ($failures === 0) {
    echo "OK: all dispatch assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
