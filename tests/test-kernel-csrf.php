<?php
/**
 * Unit test: ViMbAdmin\Kernel\Security\Csrf (Phase 5 foundation, docs/ZF1-REMOVAL.md).
 *
 * Pure logic over the SessionStorage port, so it runs with no framework, no
 * database and no Composer install — it requires the source files directly and
 * uses an in-memory array-backed session.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Security/Csrf.php';

use ViMbAdmin\Kernel\Session\SessionStorage;
use ViMbAdmin\Kernel\Security\Csrf;

/** In-memory SessionStorage for the test. */
final class ArraySession implements SessionStorage
{
    /** @var array<string,mixed> */
    private array $data = [];
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin\\Kernel\\Security\\Csrf ==\n";

$s    = new ArraySession();
$csrf = new Csrf($s);

$t1 = $csrf->token();
check('token() returns non-empty string',     is_string($t1) && $t1 !== '');
check('token is 64 hex chars (32 bytes)',      strlen($t1) === 64 && ctype_xdigit($t1));
check('token persisted to session',            $s->get('csrfToken') === $t1);

$t2 = $csrf->token();
check('token() stable within a session',       $t2 === $t1);

check('isValid(correct) true',                 $csrf->isValid($t1) === true);
check('isValid(wrong) false',                  $csrf->isValid('deadbeef') === false);
check('isValid(empty) false',                  $csrf->isValid('') === false);
check('isValid(null) false',                   $csrf->isValid(null) === false);

// A fresh session has no token yet -> nothing validates until one is minted.
$fresh = new Csrf(new ArraySession());
check('isValid before token() false',          $fresh->isValid('anything') === false);

// Independent sessions mint independent tokens.
$other = new Csrf(new ArraySession());
check('distinct sessions -> distinct tokens',  $other->token() !== $t1);
check('cross-session token rejected',          $csrf->isValid($other->token()) === false);

// rotate() drops the token; the next token() mints a different one.
$csrf->rotate();
check('rotate clears stored token',            $s->get('csrfToken') === null);
$t3 = $csrf->token();
check('token() after rotate is new',           $t3 !== $t1 && strlen($t3) === 64);
check('old token invalid after rotate',        $csrf->isValid($t1) === false);
check('new token valid after rotate',          $csrf->isValid($t3) === true);

// Custom key is honoured.
$s2  = new ArraySession();
$c2  = new Csrf($s2, 'formToken');
$tk  = $c2->token();
check('custom key stored under that key',       $s2->get('formToken') === $tk && $s2->get('csrfToken') === null);

echo "\n";
if ($failures === 0) {
    echo "OK: all Csrf assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
