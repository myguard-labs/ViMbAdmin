<?php

/**
 * Unit test: ViMbAdmin\Kernel\Security\Auth (Phase 5, docs/ZF1-REMOVAL.md).
 * Pure logic over the SessionStorage port + an admin-loader callable — no
 * framework, no DB. Models the ZF1 identity (array with `id`) and the super flag.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';

use ViMbAdmin\Kernel\Session\SessionStorage;
use ViMbAdmin\Kernel\Security\Auth;

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

/** Stand-in for the active administrator identity contract. */
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
        return "u{$this->id}@x";
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

final class NullUsernameAdminFake
{
    public function getId(): int
    {
        return 13;
    }
    public function getUsername(): null
    {
        return null;
    }
    public function getSuper(): bool
    {
        return false;
    }
    public function getActive(): bool
    {
        return true;
    }
}

final class EmptyUsernameAdminFake
{
    public function getId(): int
    {
        return 14;
    }
    public function getUsername(): string
    {
        return '';
    }
    public function getSuper(): bool
    {
        return false;
    }
    public function getActive(): bool
    {
        return true;
    }
}

final class TestKernelAuthHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelAuthHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestKernelAuthHarnessState::$count++;
    }
}

/** @param array<int,object> $table */
function loaderFor(array $table, int &$calls): callable
{
    return function (int $id) use ($table, &$calls): ?object {
        $calls++;
        return $table[$id] ?? null;
    };
}

echo "== ViMbAdmin\\Kernel\\Security\\Auth ==\n";

$normal = new AdminFake(5, false);
$super  = new AdminFake(9, true);
$inactive = new AdminFake(10, true, false);

// --- authenticated normal admin --------------------------------------- //
$calls = 0;
$a = new Auth(new ArraySession(['identity' => ['id' => 5, 'username' => 'u@x']]), loaderFor([5 => $normal], $calls));
check('identity returns the array', $a->identity() === ['id' => 5, 'username' => 'u@x']);
check('isAuthenticated true', $a->isAuthenticated() === true);
check('admin() loads the entity', $a->admin() === $normal);
check('admin() caches (1 load)', ($a->admin() === $normal && $calls === 1));
check('isSuper false for normal', $a->isSuper() === false);
check('isAuthorised() true', $a->isAuthorised() === true);
check('isAuthorised(super) false', $a->isAuthorised(true) === false);

// --- authenticated super admin ---------------------------------------- //
$calls = 0;
$s = new Auth(new ArraySession(['identity' => ['id' => 9]]), loaderFor([9 => $super], $calls));
check('super isSuper true', $s->isSuper() === true);
check('super isAuthorised(super) true', $s->isAuthorised(true) === true);

// --- deactivated admin revokes a previously valid identity ------------ //
$inactiveSession = new ArraySession(['identity' => ['id' => 10]]);
$inactiveAuth = new Auth($inactiveSession, loaderFor([10 => $inactive], $calls));
check('inactive identity is initially present', $inactiveAuth->isAuthenticated() === true);
check('inactive admin is denied on first reload', $inactiveAuth->admin() === null);
check(
    'inactive admin reload revokes the session identity',
    $inactiveSession->has('identity') === false && $inactiveAuth->isAuthenticated() === false
);

// --- not authenticated (no identity) ---------------------------------- //
$calls = 0;
$g = new Auth(new ArraySession([]), loaderFor([5 => $normal], $calls));
check('no identity -> null', $g->identity() === null);
check('not authenticated', $g->isAuthenticated() === false);
check('admin() null, loader not called', $g->admin() === null && $calls === 0);
check('isSuper false', $g->isSuper() === false);
check('isAuthorised false', $g->isAuthorised() === false);
check('isAuthorised(super) false', $g->isAuthorised(true) === false);

// --- identity present but admin gone (stale session) ------------------ //
$calls = 0;
$x = new Auth(new ArraySession(['identity' => ['id' => 77]]), loaderFor([], $calls));
check('stale: authenticated by session', $x->isAuthenticated() === true);
check('stale: admin() null', $x->admin() === null);
check('stale: isAuthorised false', $x->isAuthorised() === false);

// --- malformed identity (no id) --------------------------------------- //
$m = new Auth(new ArraySession(['identity' => ['username' => 'no-id']]), loaderFor([5 => $normal], $calls));
check('no id -> not authenticated', $m->isAuthenticated() === false);
$malformedCalls = 0;
$malformed = new Auth(new ArraySession(['identity' => 'not-an-array']), loaderFor([5 => $normal], $malformedCalls));
check('malformed identity -> null', $malformed->identity() === null);
check(
    'malformed identity is rejected before loading',
    $malformed->isAuthenticated() === false && $malformed->admin() === null && $malformedCalls === 0
);
$badIdCalls = 0;
$badId = new Auth(new ArraySession(['identity' => ['id' => ['5']]]), loaderFor([5 => $normal], $badIdCalls));
check(
    'malformed identity id is rejected before loading',
    $badId->isAuthenticated() === false && $badId->admin() === null && $badIdCalls === 0
);
$stringIdCalls = 0;
$stringId = new Auth(new ArraySession(['identity' => ['id' => '5']]), loaderFor([5 => $normal], $stringIdCalls));
check(
    'numeric-string identity id remains compatible',
    $stringId->isAuthenticated() === true && $stringId->admin() === $normal && $stringIdCalls === 1
);

// --- repository returns the wrong object ------------------------------ //
$wrongCalls = 0;
$wrong = new Auth(new ArraySession(['identity' => ['id' => 5]]), loaderFor([5 => new stdClass()], $wrongCalls));
$wrongObjectRejected = false;
try {
    $wrong->admin();
} catch (Throwable $e) {
    $wrongObjectRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin has an invalid type';
}
check(
    'wrong repository object fails at the authentication boundary',
    $wrongObjectRejected && $wrongCalls === 1
);

// --- custom identity key ---------------------------------------------- //
$c = new Auth(new ArraySession(['Zend_Auth' => ['id' => 5]]), loaderFor([5 => $normal], $calls), 'Zend_Auth');
check('custom identity key honoured', $c->isAuthenticated() && $c->admin() === $normal);

// --- establish() / clear() (login / logout) --------------------------- //
$sess = new ArraySession([]);
$e = new Auth($sess, loaderFor([5 => $normal], $calls));
check('anonymous before establish', $e->isAuthenticated() === false && $e->admin() === null);
$e->establish($normal);
check('establish writes the identity', $sess->get('identity') === ['username' => 'u5@x', 'user' => $normal, 'id' => 5]);
check('establish -> authenticated', $e->isAuthenticated() === true);
check('establish -> admin() resolves', $e->admin() === $normal);
$e->clear();
check('clear removes the identity', $sess->get('identity') === null);
check('clear -> anonymous', $e->isAuthenticated() === false && $e->admin() === null);

$inactiveEstablishSession = new ArraySession([]);
$inactiveEstablish = new Auth($inactiveEstablishSession, loaderFor([], $calls));
$inactiveEstablishRejected = false;
try {
    $inactiveEstablish->establish($inactive);
} catch (Throwable $e) {
    $inactiveEstablishRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin must be active';
}
check(
    'inactive admin cannot establish a new identity',
    $inactiveEstablishRejected && $inactiveEstablishSession->has('identity') === false
);

$invalidSession = new ArraySession([]);
$invalidEstablish = new Auth($invalidSession, loaderFor([], $calls));
$invalidEstablishRejected = false;
try {
    $invalidEstablish->establish(new stdClass());
} catch (Throwable $e) {
    $invalidEstablishRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin has an invalid type';
}
check(
    'wrong login object fails before writing identity',
    $invalidEstablishRejected && $invalidSession->get('identity') === null
);

$nullUsernameSession = new ArraySession([]);
$nullUsernameEstablish = new Auth($nullUsernameSession, loaderFor([], $calls));
$nullUsernameRejected = false;
try {
    $nullUsernameEstablish->establish(new NullUsernameAdminFake());
} catch (Throwable $e) {
    $nullUsernameRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin username is required';
}
check(
    'null admin username fails before writing identity',
    $nullUsernameRejected && $nullUsernameSession->get('identity') === null
);

$emptyUsernameSession = new ArraySession([]);
$emptyUsernameEstablish = new Auth($emptyUsernameSession, loaderFor([], $calls));
$emptyUsernameRejected = false;
try {
    $emptyUsernameEstablish->establish(new EmptyUsernameAdminFake());
} catch (Throwable $e) {
    $emptyUsernameRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin username is required';
}
check(
    'empty admin username fails before writing identity',
    $emptyUsernameRejected && $emptyUsernameSession->get('identity') === null
);

echo "\n";
if ($failures === 0) {
    echo "OK: all Auth assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
