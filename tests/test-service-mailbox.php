<?php
/**
 * Unit test: ViMbAdmin_Service_Mailbox (docs/ZF1-REMOVAL.md, Phase 4). Pure
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

require __DIR__ . '/../library/ViMbAdmin/Service/Mailbox.php';

/**
 * Records purgeMailbox() calls so the service's orchestration can be asserted
 * without the real (DB-backed) repository.
 */
final class FakeMailboxRepo
{
    /** @var array<int,array{mailbox:object,admin:?object,removeMailbox:bool}> */
    public array $purges = [];

    public function purgeMailbox($mailbox, $admin, $removeMailbox = true)
    {
        $this->purges[] = ['mailbox' => $mailbox, 'admin' => $admin, 'removeMailbox' => $removeMailbox];
        return true;
    }
}

final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */ public array $persisted = [];
    /** @var object[] */ public array $removed = [];
    public int $flushes = 0;
    public ?FakeMailboxRepo $mailboxRepo = null;

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
    public function find(string $className, $id) { return null; }
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function getRepository(string $className) {
        if ($this->mailboxRepo !== null && str_contains($className, 'Mailbox')) {
            return $this->mailboxRepo;
        }
        throw new \RuntimeException('not used');
    }
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

echo "== ViMbAdmin_Service_Mailbox ==\n";

$actor = new \Entities\Admin();
$actor->setUsername('admin@example.com');

$mkMailbox = static function (bool $active): \Entities\Mailbox {
    $mb = new \Entities\Mailbox();
    $mb->setUsername('user@example.com');
    $mb->setActive($active ? 1 : 0);
    return $mb;
};

// --- happy path: activate, hooks fire in order, one flush -------------- //
$em  = new FakeObjectManager();
$mb  = $mkMailbox(false);
$svc = new ViMbAdmin_Service_Mailbox($em);

$order = [];
$result = $svc->toggleActive(
    $mb,
    $actor,
    function () use (&$order, $mb): bool { $order[] = 'preToggle:' . (int) (bool) $mb->getActive(); return true; },
    function () use (&$order, $mb): void { $order[] = 'preFlush:' . (int) (bool) $mb->getActive(); },
    function () use (&$order, $mb): void { $order[] = 'postFlush:' . (int) (bool) $mb->getActive(); },
);

check('returns the new active state (true)',  $result === true);
check('mailbox is now active',                (bool) $mb->getActive() === true);
check('exactly one flush',                    $em->flushes === 1);
check('a Log row was persisted',              $em->lastLog() instanceof \Entities\Log);
check('log action is ACTIVATE',               $em->lastLog()?->getAction() === \Entities\Log::ACTION_MAILBOX_ACTIVATE);
check('preToggle saw pre-toggle state (0)',   $order[0] === 'preToggle:0');
check('preFlush saw post-toggle state (1)',   $order[1] === 'preFlush:1');
check('postFlush saw post-toggle state (1)',  $order[2] === 'postFlush:1');
check('hook order preToggle<preFlush<postFlush', $order === ['preToggle:0', 'preFlush:1', 'postFlush:1']);

// --- deactivate path -------------------------------------------------- //
$em2 = new FakeObjectManager();
$mb2 = $mkMailbox(true);
$r2  = (new ViMbAdmin_Service_Mailbox($em2))->toggleActive($mb2, $actor);
check('toggle without hooks works',           $r2 === false && (bool) $mb2->getActive() === false);
check('log action is DEACTIVATE',             $em2->lastLog()?->getAction() === \Entities\Log::ACTION_MAILBOX_DEACTIVATE);
check('still one flush',                      $em2->flushes === 1);

// --- preToggle veto --------------------------------------------------- //
$em3 = new FakeObjectManager();
$mb3 = $mkMailbox(true);
$vetoed = (new ViMbAdmin_Service_Mailbox($em3))->toggleActive(
    $mb3,
    $actor,
    static fn(): bool => false, // a plugin vetoes
    static function (): void { throw new \RuntimeException('preFlush must not run on veto'); },
);
check('veto returns null',                    $vetoed === null);
check('veto leaves mailbox unchanged',        (bool) $mb3->getActive() === true);
check('veto does NOT flush',                  $em3->flushes === 0);
check('veto writes no log',                   $em3->lastLog() === null);

// --- purge: no delete_files (remove the mailbox) ---------------------- //
$emP = new FakeObjectManager();
$emP->mailboxRepo = new FakeMailboxRepo();
$mbP = $mkMailbox(true);
$orderP = [];
$rp = (new ViMbAdmin_Service_Mailbox($emP))->purge(
    $mbP, $actor, false,
    function () use (&$orderP): bool { $orderP[] = 'preRemove'; return true; },
    function () use (&$orderP): void { $orderP[] = 'preFlush'; },
    function () use (&$orderP): void { $orderP[] = 'postFlush'; },
);
check('purge returns true',                   $rp === true);
check('purge called purgeMailbox',            count($emP->mailboxRepo->purges) === 1);
check('purge removeMailbox=true (!delete)',   $emP->mailboxRepo->purges[0]['removeMailbox'] === true);
check('purge removed the mailbox entity',     in_array($mbP, $emP->removed, true));
check('purge did NOT mark delete-pending',    (bool) $mbP->getDeletePending() === false);
check('purge logged ACTION_MAILBOX_PURGE',    $emP->lastLog()?->getAction() === \Entities\Log::ACTION_MAILBOX_PURGE);
check('purge flushed once',                   $emP->flushes === 1);
check('purge hook order',                     $orderP === ['preRemove', 'preFlush', 'postFlush']);

// --- purge: delete_files (mark pending, keep the row) ----------------- //
$emD = new FakeObjectManager();
$emD->mailboxRepo = new FakeMailboxRepo();
$mbD = $mkMailbox(true);
(new ViMbAdmin_Service_Mailbox($emD))->purge($mbD, $actor, true);
check('delete_files removeMailbox=false',     $emD->mailboxRepo->purges[0]['removeMailbox'] === false);
check('delete_files marks delete-pending',    (bool) $mbD->getDeletePending() === true);
check('delete_files deactivates',             (bool) $mbD->getActive() === false);
check('delete_files does NOT remove row',     $emD->removed === []);
check('delete_files flushed once',            $emD->flushes === 1);

// --- purge veto ------------------------------------------------------- //
$emV = new FakeObjectManager();
$emV->mailboxRepo = new FakeMailboxRepo();
$mbV = $mkMailbox(true);
$rv = (new ViMbAdmin_Service_Mailbox($emV))->purge(
    $mbV, $actor, false,
    static fn(): bool => false,
    static function (): void { throw new \RuntimeException('preFlush must not run on veto'); },
);
check('purge veto returns false',             $rv === false);
check('purge veto did NOT purgeMailbox',      $emV->mailboxRepo->purges === []);
check('purge veto did NOT flush',             $emV->flushes === 0);
check('purge veto wrote no log',              $emV->lastLog() === null);

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Mailbox assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
