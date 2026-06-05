<?php
/**
 * Unit test: ViMbAdmin_Service_Admin (Phase 1 of docs/ZF1-REMOVAL.md).
 *
 * Proves the business logic extracted out of AdminController behaves correctly
 * without booting ZF1 or a database. The service depends only on
 * Doctrine\Persistence\ObjectManager, so we pass an in-memory fake that records
 * persist()/remove()/flush() and exercise it against plain-PHP \Entities\*.
 *
 * Covers toggleActive / toggleSuper (both directions, correct Log action, no
 * domain bound), assignDomain (happy + duplicate-throws-no-flush, domain bound
 * on the Log), removeDomain (domain bound), and purge (detaches domains, removes
 * the admin, logs ADMIN_PURGE with no domain).
 *
 * Exit 0 = all passed, 1 = a failure, 2 = bootstrap error.
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

require __DIR__ . '/../library/ViMbAdmin/Service/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Admin.php';

use Doctrine\Common\Collections\ArrayCollection;

/**
 * In-memory ObjectManager double recording persist()/remove()/flush().
 */
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

    public function removedContains(object $o): bool
    {
        return in_array($o, $this->removed, true);
    }
}

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

function makeAdmin(string $username, bool $hydrateCollections = false): \Entities\Admin {
    $a = new \Entities\Admin();
    $a->setUsername($username);
    if ($hydrateCollections) {
        // A fresh entity leaves $Preferences / $RememberMes uninitialised; a
        // DB-hydrated admin always has them as collections. Mirror that so
        // purge()'s foreach loops see real (empty) collections.
        foreach (['Preferences', 'RememberMes'] as $prop) {
            $rp = new \ReflectionProperty(\Entities\Admin::class, $prop);
            $rp->setAccessible(true);
            $rp->setValue($a, new ArrayCollection());
        }
    }
    return $a;
}

function makeDomain(string $name): \Entities\Domain {
    $d = new \Entities\Domain();
    $d->setDomain($name);
    return $d;
}

echo "== ViMbAdmin_Service_Admin ==\n";

// ---- toggleActive both directions --------------------------------------- //
$actor = makeAdmin('boss@example.com');

$em = new FakeObjectManager();
$t  = makeAdmin('t@example.com'); $t->setActive(true);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleActive($t, $actor);
check('toggleActive(true) returns false',        $r === false);
check('toggleActive(true) sets inactive',        $t->getActive() === false);
check('toggleActive flushed once',               $em->flushes === 1);
check('toggleActive logged DEACTIVATE',          $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_DEACTIVATE);
check('toggleActive log binds NO domain',        $em->lastLog() && $em->lastLog()->getDomain() === null);
check('toggleActive log binds actor',            $em->lastLog() && $em->lastLog()->getAdmin() === $actor);

$em = new FakeObjectManager();
$t  = makeAdmin('t2@example.com'); $t->setActive(false);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleActive($t, $actor);
check('toggleActive(false) returns true',        $r === true);
check('toggleActive logged ACTIVATE',            $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_ACTIVATE);

// ---- toggleSuper both directions ---------------------------------------- //
$em = new FakeObjectManager();
$t  = makeAdmin('s@example.com'); $t->setSuper(false);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleSuper($t, $actor);
check('toggleSuper(false) returns true',         $r === true);
check('toggleSuper sets super',                  $t->getSuper() === true);
check('toggleSuper logged SUPER',                $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_SUPER);

$em = new FakeObjectManager();
$t  = makeAdmin('s2@example.com'); $t->setSuper(true);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleSuper($t, $actor);
check('toggleSuper(true) returns false',         $r === false);
check('toggleSuper logged NORMAL',               $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_NORMAL);

// ---- assignDomain happy path -------------------------------------------- //
$em  = new FakeObjectManager();
$t   = makeAdmin('a@example.com');
$dom = makeDomain('assign.example');
(new ViMbAdmin_Service_Admin($em))->assignDomain($t, $dom, $actor);
check('assignDomain mutates target->Domains',    $t->getDomains()->contains($dom));
check('assignDomain flushed once',               $em->flushes === 1);
check('assignDomain logged ADD',                 $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD);
check('assignDomain Log binds the domain',       $em->lastLog() && $em->lastLog()->getDomain() === $dom);

// ---- assignDomain duplicate throws -------------------------------------- //
$em  = new FakeObjectManager();
$t   = makeAdmin('d@example.com');
$dom = makeDomain('dup.example');
$t->addDomain($dom);
$threw = false;
try { (new ViMbAdmin_Service_Admin($em))->assignDomain($t, $dom, $actor); }
catch (ViMbAdmin_Service_Exception $e) { $threw = true; }
check('assignDomain duplicate throws',           $threw);
check('assignDomain duplicate did NOT flush',    $em->flushes === 0);
check('assignDomain duplicate wrote no Log',     $em->lastLog() === null);

// ---- removeDomain ------------------------------------------------------- //
$em  = new FakeObjectManager();
$t   = makeAdmin('rm@example.com');
$dom = makeDomain('remove.example');
$t->addDomain($dom);
(new ViMbAdmin_Service_Admin($em))->removeDomain($t, $dom, $actor);
check('removeDomain detaches',                   !$t->getDomains()->contains($dom));
check('removeDomain flushed once',               $em->flushes === 1);
check('removeDomain logged REMOVE',              $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_REMOVE);
check('removeDomain Log binds the domain',       $em->lastLog() && $em->lastLog()->getDomain() === $dom);

// ---- purge -------------------------------------------------------------- //
$em     = new FakeObjectManager();
$victim = makeAdmin('victim@example.com', true);
$dom    = makeDomain('purge.example');
$victim->addDomain($dom);   // owning side
$dom->addAdmin($victim);    // inverse side, so removeAdmin() has something to drop
(new ViMbAdmin_Service_Admin($em))->purge($victim, $actor);
check('purge removed the admin',                 $em->removedContains($victim));
check('purge detached victim from domain',       !$dom->getAdmins()->contains($victim));
check('purge flushed once',                      $em->flushes === 1);
check('purge logged ADMIN_PURGE',                $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_PURGE);
check('purge Log binds NO domain',               $em->lastLog() && $em->lastLog()->getDomain() === null);
check('purge Log binds actor not victim',        $em->lastLog() && $em->lastLog()->getAdmin() === $actor);

echo "\n";
if ($failures === 0) {
    echo "OK: all ViMbAdmin_Service_Admin assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
