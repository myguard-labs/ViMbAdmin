<?php
/**
 * Unit test: ViMbAdmin_Service_Domain (Phase 1 of docs/ZF1-REMOVAL.md).
 *
 * Proves the business logic extracted out of DomainController behaves correctly
 * WITHOUT booting ZF1 or touching a database — the whole point of Phase 1. The
 * service depends only on Doctrine\Persistence\ObjectManager, so we pass a tiny
 * in-memory fake that records persist()/flush() calls, and exercise the methods
 * against real (plain-PHP) \Entities\* objects.
 *
 * Covers:
 *   - toggleActive(): flips state, writes the correct ACTIVATE/DEACTIVATE Log
 *     row, flushes, returns the new state (both directions).
 *   - assignAdmin(): happy path mutates the owning side + logs + flushes;
 *     duplicate assignment throws ViMbAdmin_Service_Exception and does NOT flush.
 *   - removeAdmin(): detaches + logs + flushes.
 *
 * Exit 0 = all assertions passed, 1 = a failure, 2 = bootstrap error.
 */

require __DIR__ . '/../vendor/autoload.php';

// Entities\ live under application/ and are not in Composer's PSR map (the app
// registers them via the Doctrine/ZF class loader at runtime). Minimal loader,
// same approach as tests/test-schema-no-pending.php.
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

// The service + its exception are PSR-0 underscore classes under library/.
require __DIR__ . '/../library/ViMbAdmin/Service/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Domain.php';

/**
 * In-memory ObjectManager test double. Records every persist()ed object and
 * counts flush() calls; everything else is an inert stub. Only persist / flush /
 * getRepository are exercised by the service.
 */
final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */
    public array $persisted = [];
    public int $flushes = 0;

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function flush(): void { $this->flushes++; }

    public function find(string $className, $id) { return null; }
    public function remove(object $object): void {}
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function getRepository(string $className) { throw new \RuntimeException('not used'); }
    public function getClassMetadata(string $className) { throw new \RuntimeException('not used'); }
    public function getMetadataFactory() { throw new \RuntimeException('not used'); }
    public function initializeObject(object $obj): void {}
    public function contains(object $object): bool { return false; }

    /** Most recently persisted \Entities\Log, or null. */
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
    global $failures;
    if ($ok) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

function makeAdmin(string $username): \Entities\Admin {
    $a = new \Entities\Admin();
    $a->setUsername($username);
    return $a;
}

function makeDomain(string $name, bool $active): \Entities\Domain {
    $d = new \Entities\Domain();
    $d->setDomain($name);
    $d->setActive($active);
    return $d;
}

echo "== ViMbAdmin_Service_Domain ==\n";

// ---- toggleActive: active -> inactive ----------------------------------- //
$em      = new FakeObjectManager();
$svc     = new ViMbAdmin_Service_Domain($em);
$actor   = makeAdmin('boss@example.com');
$domain  = makeDomain('example.com', true);

$result = $svc->toggleActive($domain, $actor);
check('toggleActive(true) returns false',            $result === false);
check('toggleActive(true) sets domain inactive',     $domain->getActive() === false);
check('toggleActive stamps modified',                $domain->getModified() instanceof \DateTime);
check('toggleActive flushed once',                   $em->flushes === 1);
$log = $em->lastLog();
check('toggleActive wrote a Log row',                $log instanceof \Entities\Log);
check('toggleActive logged DEACTIVATE',              $log !== null && $log->getAction() === \Entities\Log::ACTION_DOMAIN_DEACTIVATE);
check('toggleActive log bound to actor',             $log !== null && $log->getAdmin() === $actor);
check('toggleActive log bound to domain',            $log !== null && $log->getDomain() === $domain);

// ---- toggleActive: inactive -> active ----------------------------------- //
$em2     = new FakeObjectManager();
$svc2    = new ViMbAdmin_Service_Domain($em2);
$domain2 = makeDomain('example.net', false);
$res2    = $svc2->toggleActive($domain2, $actor);
check('toggleActive(false) returns true',            $res2 === true);
check('toggleActive(false) sets domain active',      $domain2->getActive() === true);
check('toggleActive logged ACTIVATE',                $em2->lastLog() !== null && $em2->lastLog()->getAction() === \Entities\Log::ACTION_DOMAIN_ACTIVATE);

// ---- assignAdmin: happy path -------------------------------------------- //
$em3    = new FakeObjectManager();
$svc3   = new ViMbAdmin_Service_Domain($em3);
$dom3   = makeDomain('assign.example', true);
$target = makeAdmin('newadmin@example.com');
$svc3->assignAdmin($dom3, $target, $actor);
check('assignAdmin mutates owning side (target->Domains)', $target->getDomains()->contains($dom3));
check('assignAdmin flushed once',                          $em3->flushes === 1);
check('assignAdmin logged ADMIN_TO_DOMAIN_ADD',            $em3->lastLog() !== null && $em3->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD);

// ---- assignAdmin: duplicate throws, no flush ---------------------------- //
$em4   = new FakeObjectManager();
$svc4  = new ViMbAdmin_Service_Domain($em4);
$dom4  = makeDomain('dup.example', true);
$dupe  = makeAdmin('dupe@example.com');
$dom4->addAdmin($dupe); // pre-seed the inverse side the guard checks
$threw = false;
try {
    $svc4->assignAdmin($dom4, $dupe, $actor);
} catch (ViMbAdmin_Service_Exception $e) {
    $threw = true;
}
check('assignAdmin duplicate throws ViMbAdmin_Service_Exception', $threw);
check('assignAdmin duplicate did NOT flush',                      $em4->flushes === 0);
check('assignAdmin duplicate wrote no Log',                       $em4->lastLog() === null);

// ---- removeAdmin -------------------------------------------------------- //
$em5    = new FakeObjectManager();
$svc5   = new ViMbAdmin_Service_Domain($em5);
$dom5   = makeDomain('remove.example', true);
$victim = makeAdmin('victim@example.com');
$victim->addDomain($dom5); // it is currently assigned
$svc5->removeAdmin($dom5, $victim, $actor);
check('removeAdmin detaches owning side',          !$victim->getDomains()->contains($dom5));
check('removeAdmin flushed once',                  $em5->flushes === 1);
check('removeAdmin logged ADMIN_TO_DOMAIN_REMOVE', $em5->lastLog() !== null && $em5->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_REMOVE);

echo "\n";
if ($failures === 0) {
    echo "OK: all ViMbAdmin_Service_Domain assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
