<?php
/**
 * Unit test: ViMbAdmin\Kernel\Session\MagicPropertyStorage (Phase 5,
 * docs/ZF1-REMOVAL.md). Proves the adapter round-trips through any object with
 * magic property access — the shape of the ZF1 session namespace it bridges in
 * the app — and that the Csrf service works on top of it. No framework, no DB.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Security/Csrf.php';

use ViMbAdmin\Kernel\Session\MagicPropertyStorage;
use ViMbAdmin\Kernel\Security\Csrf;

/** Stand-in for Zend_Session_Namespace: data via magic property access. */
final class MagicNamespaceFake
{
    /** @var array<string,mixed> */
    private array $d = [];
    public function __get(string $k): mixed { return $this->d[$k] ?? null; }
    public function __set(string $k, mixed $v): void { $this->d[$k] = $v; }
    public function __isset(string $k): bool { return isset($this->d[$k]); }
    public function __unset(string $k): void { unset($this->d[$k]); }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin\\Kernel\\Session\\MagicPropertyStorage ==\n";

$ns = new MagicNamespaceFake();
$s  = new MagicPropertyStorage($ns);

check('has() false on empty',       $s->has('x') === false);
check('get() null on empty',        $s->get('x') === null);
$s->set('x', 'hi');
check('set then has',               $s->has('x') === true);
check('set then get',               $s->get('x') === 'hi');
check('writes through to object',    $ns->x === 'hi');
$ns->y = 'direct';
check('reads object writes',         $s->get('y') === 'direct' && $s->has('y'));
$s->remove('x');
check('remove() clears',            $s->has('x') === false && $s->get('x') === null && !isset($ns->x));

echo "== Csrf over the namespace adapter ==\n";
$ns2  = new MagicNamespaceFake();
$csrf = new Csrf(new MagicPropertyStorage($ns2));
$t = $csrf->token();
check('token minted + stored on ns',  is_string($t) && strlen($t) === 64 && $ns2->csrfToken === $t);
check('isValid(token) true',          $csrf->isValid($t) === true);
check('isValid(bad) false',           $csrf->isValid('nope') === false);

// Back-compat: a pre-existing (old-format) token on the namespace is honoured,
// not regenerated — so tokens minted before the upgrade keep validating.
$ns3 = new MagicNamespaceFake();
$ns3->csrfToken = 'legacy-40-char-token-from-OSS_String-random';
$csrf3 = new Csrf(new MagicPropertyStorage($ns3));
check('pre-existing token reused',    $csrf3->token() === 'legacy-40-char-token-from-OSS_String-random');
check('pre-existing token validates', $csrf3->isValid('legacy-40-char-token-from-OSS_String-random') === true);

echo "\n";
if ($failures === 0) {
    echo "OK: all MagicPropertyStorage assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
