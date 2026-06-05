<?php
/**
 * Unit test: ViMbAdmin_Service_Alias (docs/ZF1-REMOVAL.md, Phase 4). Pure
 * logic over a fake ObjectManager + real entities — no framework, no DB. Proves
 * the toggleActive entity change, the single flush, the log write, the exact
 * preToggle/preFlush/postFlush hook ordering, and the preToggle veto.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/../application/' . $dir . '/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/../library/ViMbAdmin/Service/Alias.php';

final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */ public array $persisted = [];
    /** @var object[] */ public array $removed = [];
    public int $flushes = 0;

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
    public function find(string $className, $id) { return null; }
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function getRepository(string $className) { throw new \RuntimeException('not used'); }
    public function getClassMetadata(string $className) { throw new \RuntimeException('not used'); }
    public function getMetadataFactory() { throw new \RuntimeException('not used'); }
    public function initializeObject(object $obj): void {}
    public function contains(object $object): bool { return false; }

    public function lastLog(): ?\Entities\Log
    {
        for ($i = count($this->persisted) - 1; $i >= 0; $i--) {
            if ($this->persisted[$i] instanceof \Entities\Log) {
                return $this->persisted[$i];
            }
        }
        return null;
    }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin_Service_Alias ==\n";

$actor = new \Entities\Admin();
$actor->setUsername('admin@example.com');

$mkAlias = static function (bool $active): \Entities\Alias {
    $al = new \Entities\Alias();
    $al->setAddress("alias@example.com");
    $al->setActive($active ? 1 : 0);
    return $al;
};

// --- happy path: activate, hooks fire in order, one flush -------------- //
$em  = new FakeObjectManager();
$al  = $mkAlias(false);
$svc = new ViMbAdmin_Service_Alias($em);

$order = [];
$result = $svc->toggleActive(
    $al,
    $actor,
    function () use (&$order, $al): bool { $order[] = 'preToggle:' . (int) (bool) $al->getActive(); return true; },
    function () use (&$order, $al): void { $order[] = 'preFlush:' . (int) (bool) $al->getActive(); },
    function () use (&$order, $al): void { $order[] = 'postFlush:' . (int) (bool) $al->getActive(); },
);

check('returns the new active state (true)',  $result === true);
check('alias is now active',                (bool) $al->getActive() === true);
check('exactly one flush',                    $em->flushes === 1);
check('a Log row was persisted',              $em->lastLog() instanceof \Entities\Log);
check('log action is ACTIVATE',               $em->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ACTIVATE);
check('preToggle saw pre-toggle state (0)',   $order[0] === 'preToggle:0');
check('preFlush saw post-toggle state (1)',   $order[1] === 'preFlush:1');
check('postFlush saw post-toggle state (1)',  $order[2] === 'postFlush:1');
check('hook order preToggle<preFlush<postFlush', $order === ['preToggle:0', 'preFlush:1', 'postFlush:1']);

// --- deactivate path -------------------------------------------------- //
$em2 = new FakeObjectManager();
$al2 = $mkAlias(true);
$r2  = (new ViMbAdmin_Service_Alias($em2))->toggleActive($al2, $actor);
check('toggle without hooks works',           $r2 === false && (bool) $al2->getActive() === false);
check('log action is DEACTIVATE',             $em2->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DEACTIVATE);
check('still one flush',                      $em2->flushes === 1);

// --- preToggle veto --------------------------------------------------- //
$em3 = new FakeObjectManager();
$al3 = $mkAlias(true);
$vetoed = (new ViMbAdmin_Service_Alias($em3))->toggleActive(
    $al3,
    $actor,
    static fn(): bool => false, // a plugin vetoes
    static function (): void { throw new \RuntimeException('preFlush must not run on veto'); },
);
check('veto returns null',                    $vetoed === null);
check('veto leaves alias unchanged',        (bool) $al3->getActive() === true);
check('veto does NOT flush',                  $em3->flushes === 0);
check('veto writes no log',                   $em3->lastLog() === null);

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Alias assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
