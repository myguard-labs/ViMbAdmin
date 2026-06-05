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
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

/** Stand-in for \Entities\Admin (only getSuper()/getId() are used). */
final class AdminFake
{
    public function __construct(private int $id, private bool $super) {}
    public function getId(): int { return $this->id; }
    public function getSuper(): bool { return $this->super; }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

/** Loader over a small id->admin table; records how many times it ran. */
function loaderFor(array $table, int &$calls): callable {
    return function (int $id) use ($table, &$calls): ?object { $calls++; return $table[$id] ?? null; };
}

echo "== ViMbAdmin\\Kernel\\Security\\Auth ==\n";

$normal = new AdminFake(5, false);
$super  = new AdminFake(9, true);

// --- authenticated normal admin --------------------------------------- //
$calls = 0;
$a = new Auth(new ArraySession(['identity' => ['id' => 5, 'username' => 'u@x']]), loaderFor([5 => $normal], $calls));
check('identity returns the array',     $a->identity() === ['id' => 5, 'username' => 'u@x']);
check('isAuthenticated true',           $a->isAuthenticated() === true);
check('admin() loads the entity',       $a->admin() === $normal);
check('admin() caches (1 load)',        ($a->admin() === $normal && $calls === 1));
check('isSuper false for normal',       $a->isSuper() === false);
check('isAuthorised() true',            $a->isAuthorised() === true);
check('isAuthorised(super) false',      $a->isAuthorised(true) === false);

// --- authenticated super admin ---------------------------------------- //
$calls = 0;
$s = new Auth(new ArraySession(['identity' => ['id' => 9]]), loaderFor([9 => $super], $calls));
check('super isSuper true',             $s->isSuper() === true);
check('super isAuthorised(super) true', $s->isAuthorised(true) === true);

// --- not authenticated (no identity) ---------------------------------- //
$calls = 0;
$g = new Auth(new ArraySession([]), loaderFor([5 => $normal], $calls));
check('no identity -> null',            $g->identity() === null);
check('not authenticated',              $g->isAuthenticated() === false);
check('admin() null, loader not called',$g->admin() === null && $calls === 0);
check('isSuper false',                  $g->isSuper() === false);
check('isAuthorised false',             $g->isAuthorised() === false);
check('isAuthorised(super) false',      $g->isAuthorised(true) === false);

// --- identity present but admin gone (stale session) ------------------ //
$calls = 0;
$x = new Auth(new ArraySession(['identity' => ['id' => 77]]), loaderFor([], $calls));
check('stale: authenticated by session',$x->isAuthenticated() === true);
check('stale: admin() null',            $x->admin() === null);
check('stale: isAuthorised false',      $x->isAuthorised() === false);

// --- malformed identity (no id) --------------------------------------- //
$m = new Auth(new ArraySession(['identity' => ['username' => 'no-id']]), loaderFor([5 => $normal], $calls));
check('no id -> not authenticated',     $m->isAuthenticated() === false);

// --- custom identity key ---------------------------------------------- //
$c = new Auth(new ArraySession(['Zend_Auth' => ['id' => 5]]), loaderFor([5 => $normal], $calls), 'Zend_Auth');
check('custom identity key honoured',   $c->isAuthenticated() && $c->admin() === $normal);

echo "\n";
if ($failures === 0) {
    echo "OK: all Auth assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
