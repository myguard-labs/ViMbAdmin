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

    public function countPersisted(string $class): int
    {
        return count(array_filter($this->persisted, static fn($o) => $o instanceof $class));
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

$mkDomain = static function (int $count): \Entities\Domain {
    $d = new \Entities\Domain();
    $d->setDomain('example.com');
    $d->setAliasCount($count);
    return $d;
};

// --- create: forwarding alias (address != goto) bumps the count ------- //
$emC = new FakeObjectManager();
$domC = $mkDomain(4);
$alC  = new \Entities\Alias();
$alC->setAddress('info@example.com');
$alC->setGoto('boss@example.com');
$orderC = [];
$created = (new ViMbAdmin_Service_Alias($emC))->create(
    $alC, $domC, $actor,
    function () use (&$orderC, $emC): void { $orderC[] = 'preFlush:' . $emC->flushes; },
    function () use (&$orderC, $emC): void { $orderC[] = 'postFlush:' . $emC->flushes; },
);
check('create returns the alias',             $created === $alC);
check('create set the domain',                $alC->getDomain() === $domC);
check('create set active',                    (bool) $alC->getActive() === true);
check('create stamped created',               $alC->getCreated() instanceof \DateTime);
check('create persisted the alias',           in_array($alC, $emC->persisted, true));
check('create bumped aliasCount (addr!=goto)', (int) $domC->getAliasCount() === 5);
check('create logged ACTION_ALIAS_ADD',       $emC->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ADD);
check('create flushed once',                  $emC->flushes === 1);
check('create hook order around flush',       $orderC === ['preFlush:0', 'postFlush:1']);

// --- create: self-alias (address == goto) does NOT bump the count ----- //
$emS = new FakeObjectManager();
$domS = $mkDomain(7);
$alS  = new \Entities\Alias();
$alS->setAddress('box@example.com');
$alS->setGoto('box@example.com');
(new ViMbAdmin_Service_Alias($emS))->create($alS, $domS, $actor);
check('self-alias does NOT bump count',       (int) $domS->getAliasCount() === 7);
check('self-alias still persisted + logged',  in_array($alS, $emS->persisted, true) && $emS->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ADD);

// --- delete: forwarding alias, hooks fire, count decrements ----------- //
$emD = new FakeObjectManager();
$domD = $mkDomain(5);
$alD  = new \Entities\Alias();
$alD->setAddress('info@example.com');
$alD->setGoto('boss@example.com');
$alD->setDomain($domD);
$orderD = [];
$rd = (new ViMbAdmin_Service_Alias($emD))->delete(
    $alD, $actor,
    function () use (&$orderD): bool { $orderD[] = 'preRemove'; return true; },
    function () use (&$orderD): void { $orderD[] = 'preFlush'; },
    function () use (&$orderD): void { $orderD[] = 'postFlush'; },
);
check('delete returns true',                  $rd === true);
check('delete removed the alias',             in_array($alD, $emD->removed, true));
check('delete decremented aliasCount',        (int) $domD->getAliasCount() === 4);
check('delete logged ACTION_ALIAS_DELETE',    $emD->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DELETE);
check('delete flushed once',                  $emD->flushes === 1);
check('delete hook order',                    $orderD === ['preRemove', 'preFlush', 'postFlush']);

// --- delete: self-alias does NOT touch the count ---------------------- //
$emDS = new FakeObjectManager();
$domDS = $mkDomain(3);
$alDS  = new \Entities\Alias();
$alDS->setAddress('box@example.com');
$alDS->setGoto('box@example.com');
$alDS->setDomain($domDS);
(new ViMbAdmin_Service_Alias($emDS))->delete($alDS, $actor);
check('delete self-alias keeps count',        (int) $domDS->getAliasCount() === 3);
check('delete self-alias removed + logged',   in_array($alDS, $emDS->removed, true) && $emDS->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DELETE);

// --- delete veto: nothing removed, no flush, no log ------------------- //
$emV = new FakeObjectManager();
$domV = $mkDomain(5);
$alV  = new \Entities\Alias();
$alV->setAddress('info@example.com');
$alV->setGoto('boss@example.com');
$alV->setDomain($domV);
$rv = (new ViMbAdmin_Service_Alias($emV))->delete(
    $alV, $actor,
    static fn(): bool => false,
    static function (): void { throw new \RuntimeException('preFlush must not run on veto'); },
);
check('delete veto returns false',            $rv === false);
check('delete veto did NOT remove the alias', !in_array($alV, $emV->removed, true));
check('delete veto did NOT flush',            $emV->flushes === 0);
check('delete veto wrote no log',             $emV->lastLog() === null);
check('delete veto left aliasCount',          (int) $domV->getAliasCount() === 5);

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Alias assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
