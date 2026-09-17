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
 * on the Log), removeDomain (domain bound), purge (detaches domains, removes
 * the admin, logs ADMIN_PURGE with no domain), and password-backed create/change
 * paths including legacy auth options and failure ordering.
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
// Password operations hash through OSS_Auth_Password; load the crypt helpers
// plus bcrypt so the service's configured and default-cost paths run host-side.
require __DIR__ . '/../library/OSS/Exception.php';
require __DIR__ . '/../library/OSS/Crypt/Exception.php';
require __DIR__ . '/../library/OSS/String.php';
require __DIR__ . '/../library/OSS/Crypt/Bcrypt.php';
require __DIR__ . '/../library/OSS/Auth/Password.php';

use Doctrine\Common\Collections\ArrayCollection;

/**
 * In-memory ObjectManager double recording persist()/remove()/flush().
 */
final class FakeAdminObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */ public array $persisted = [];
    /** @var object[] */ public array $removed = [];
    public int $flushes = 0;

    public function persist(object $object): void
    {
        $this->persisted[] = $object;
    }
    public function remove(object $object): void
    {
        $this->removed[] = $object;
    }
    public function flush(): void
    {
        $this->flushes++;
    }

    public function find(string $className, mixed $id): ?object
    {
        return null;
    }
    public function clear(): void
    {
    }
    public function detach(object $object): void
    {
    }
    public function refresh(object $object): void
    {
    }
    public function getRepository(string $className): \Doctrine\Persistence\ObjectRepository
    {
        throw new \RuntimeException('not used');
    }
    public function getClassMetadata(string $className): \Doctrine\Persistence\Mapping\ClassMetadata
    {
        throw new \RuntimeException('not used');
    }
    public function getMetadataFactory(): \Doctrine\Persistence\Mapping\ClassMetadataFactory
    {
        throw new \RuntimeException('not used');
    }
    public function initializeObject(object $obj): void
    {
    }
    public function isUninitializedObject(mixed $value): bool
    {
        return false;
    }
    public function contains(object $object): bool
    {
        return false;
    }

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

final class TestServiceAdminHarnessState
{
    public static int $count = 0;
}

$failures = & TestServiceAdminHarnessState::$count;
function check(string $label, bool $ok): void
{

    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestServiceAdminHarnessState::$count++;
    }
}

function makeAdminForService(string $username, bool $hydrateCollections = false): \Entities\Admin
{
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

function makeDomainForService(string $name): \Entities\Domain
{
    $d = new \Entities\Domain();
    $d->setDomain($name);
    return $d;
}

echo "== ViMbAdmin_Service_Admin ==\n";

// ---- toggleActive both directions --------------------------------------- //
$actor = makeAdminForService('boss@example.com');

$em = new FakeAdminObjectManager();
$t  = makeAdminForService('t@example.com');
$t->setActive(true);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleActive($t, $actor);
check('toggleActive(true) returns false', $r === false);
check('toggleActive(true) sets inactive', $t->getActive() === false);
check('toggleActive flushed once', $em->flushes === 1);
check('toggleActive logged DEACTIVATE', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_DEACTIVATE);
check('toggleActive log binds NO domain', $em->lastLog() && $em->lastLog()->getDomain() === null);
check('toggleActive log binds actor', $em->lastLog() && $em->lastLog()->getAdmin() === $actor);

$em = new FakeAdminObjectManager();
$t  = makeAdminForService('t2@example.com');
$t->setActive(false);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleActive($t, $actor);
check('toggleActive(false) returns true', $r === true);
check('toggleActive logged ACTIVATE', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_ACTIVATE);

// ---- toggleSuper both directions ---------------------------------------- //
$em = new FakeAdminObjectManager();
$t  = makeAdminForService('s@example.com');
$t->setSuper(false);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleSuper($t, $actor);
check('toggleSuper(false) returns true', $r === true);
check('toggleSuper sets super', $t->getSuper() === true);
check('toggleSuper logged SUPER', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_SUPER);

$em = new FakeAdminObjectManager();
$t  = makeAdminForService('s2@example.com');
$t->setSuper(true);
$r  = (new ViMbAdmin_Service_Admin($em))->toggleSuper($t, $actor);
check('toggleSuper(true) returns false', $r === false);
check('toggleSuper logged NORMAL', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_NORMAL);

$em = new FakeAdminObjectManager();
$t  = makeAdminForService('unset-super@example.com');
$unsetSuperError = null;
try {
    (new ViMbAdmin_Service_Admin($em))->toggleSuper($t, $actor);
} catch (\LogicException $exception) {
    $unsetSuperError = $exception->getMessage();
}
check(
    'toggleSuper rejects an uninitialized privilege flag',
    $unsetSuperError === 'Admin super flag cannot be null.'
);
check(
    'toggleSuper null failure leaves state and persistence untouched',
    $t->getSuper() === null && $t->getModified() === null && $em->flushes === 0 && $em->persisted === []
);

// ---- assignDomain happy path -------------------------------------------- //
$em  = new FakeAdminObjectManager();
$t   = makeAdminForService('a@example.com');
$dom = makeDomainForService('assign.example');
(new ViMbAdmin_Service_Admin($em))->assignDomain($t, $dom, $actor);
check('assignDomain mutates target->Domains', $t->getDomains()->contains($dom));
check('assignDomain flushed once', $em->flushes === 1);
check('assignDomain logged ADD', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_ADD);
check('assignDomain Log binds the domain', $em->lastLog() && $em->lastLog()->getDomain() === $dom);

$em = new FakeAdminObjectManager();
$t = makeAdminForService('malformed-domain@example.com');
$malformedDomain = new \Entities\Domain();
$malformedError = null;
try {
    (new ViMbAdmin_Service_Admin($em))->assignDomain($t, $malformedDomain, $actor);
} catch (\LogicException $e) {
    $malformedError = $e->getMessage();
}
check('assignDomain rejects a null domain name', $malformedError === 'Domain name cannot be null.');
check(
    'assignDomain name failure precedes mutation',
    !$t->getDomains()->contains($malformedDomain)
        && $em->persisted === []
        && $em->flushes === 0
);

// ---- assignDomain duplicate throws -------------------------------------- //
$em  = new FakeAdminObjectManager();
$t   = makeAdminForService('d@example.com');
$dom = makeDomainForService('dup.example');
$t->addDomain($dom);
$threw = false;
try {
    (new ViMbAdmin_Service_Admin($em))->assignDomain($t, $dom, $actor);
} catch (ViMbAdmin_Service_Exception $e) {
    $threw = true;
}
check('assignDomain duplicate throws', $threw);
check('assignDomain duplicate did NOT flush', $em->flushes === 0);
check('assignDomain duplicate wrote no Log', $em->lastLog() === null);

// ---- removeDomain ------------------------------------------------------- //
$em  = new FakeAdminObjectManager();
$t   = makeAdminForService('rm@example.com');
$dom = makeDomainForService('remove.example');
$t->addDomain($dom);
(new ViMbAdmin_Service_Admin($em))->removeDomain($t, $dom, $actor);
check('removeDomain detaches', !$t->getDomains()->contains($dom));
check('removeDomain flushed once', $em->flushes === 1);
check('removeDomain logged REMOVE', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_TO_DOMAIN_REMOVE);
check('removeDomain Log binds the domain', $em->lastLog() && $em->lastLog()->getDomain() === $dom);

// ---- purge -------------------------------------------------------------- //
$em     = new FakeAdminObjectManager();
$victim = makeAdminForService('victim@example.com', true);
$dom    = makeDomainForService('purge.example');
$victim->addDomain($dom);   // owning side
$dom->addAdmin($victim);    // inverse side, so removeAdmin() has something to drop
(new ViMbAdmin_Service_Admin($em))->purge($victim, $actor);
check('purge removed the admin', $em->removedContains($victim));
check('purge detached victim from domain', !$dom->getAdmins()->contains($victim));
check('purge flushed once', $em->flushes === 1);
check('purge logged ADMIN_PURGE', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_PURGE);
check('purge Log binds NO domain', $em->lastLog() && $em->lastLog()->getDomain() === null);
check('purge Log binds actor not victim', $em->lastLog() && $em->lastLog()->getAdmin() === $actor);

// ---- create: state, hashing and persistence ordering ------------------- //
$em = new FakeAdminObjectManager();
$created = (new ViMbAdmin_Service_Admin($em))->create(
    'created@example.com',
    'CreatePass123',
    true,
    $actor,
    $authOpts = ['pwhash' => 'crypt:sha512'],
);
check(
    'create returns and persists the new admin first',
    $em->persisted[0] === $created && $created->getUsername() === 'created@example.com'
);
check(
    'create passes native boolean admin states',
    $created->getSuper() === true && $created->getActive() === true
);
check(
    'create hashes and verifies the plaintext password',
    $created->getPassword() !== 'CreatePass123'
        && OSS_Auth_Password::verify('CreatePass123', $created->requiredPassword(), $authOpts)
);
check(
    'create persists the log after the admin and flushes once',
    count($em->persisted) === 2
        && $em->persisted[1] instanceof \Entities\Log
        && $em->lastLog()?->getAction() === \Entities\Log::ACTION_ADMIN_ADD
        && $em->flushes === 1
);

// The auth helper historically accepts a numeric-string bcrypt cost from INI.
$em = new FakeAdminObjectManager();
$legacyAuthOpts = ['pwhash' => 'bcrypt', 'hash_cost' => '04'];
$legacy = (new ViMbAdmin_Service_Admin($em))->create(
    'legacy@example.com',
    'LegacyPass123',
    false,
    $actor,
    $legacyAuthOpts,
);
check(
    'create retains legacy numeric-string bcrypt cost',
    str_starts_with($legacy->requiredPassword(), '$2a$04$')
        && $legacy->getSuper() === false
        && $legacy->getActive() === true
);

$em = new FakeAdminObjectManager();
$defaultBcrypt = (new ViMbAdmin_Service_Admin($em))->create(
    'default@example.com',
    'DefaultPass123',
    false,
    $actor,
    ['pwhash' => 'bcrypt'],
);
check('create retains bcrypt default cost 12', str_starts_with($defaultBcrypt->requiredPassword(), '$2a$12$'));

$em = new FakeAdminObjectManager();
$createRejected = false;
try {
    (new ViMbAdmin_Service_Admin($em))->create(
        'invalid@example.com',
        'InvalidPass123',
        false,
        $actor,
        [],
    );
} catch (OSS_Exception $e) {
    $createRejected = $e->getMessage() === 'Cannot hash password without a hash method';
}
check(
    'create hash errors precede persistence, logging and flush',
    $createRejected && $em->persisted === [] && $em->flushes === 0
);

// ---- changePassword: self (no log) -------------------------------------- //
$em  = new FakeAdminObjectManager();
$me  = makeAdminForService('me@example.com');
$me->setPassword(OSS_Auth_Password::hash('OldPass123', $authOpts));
$old = $me->getPassword();
(new ViMbAdmin_Service_Admin($em))->changePassword($me, 'NewPass456', $me, true, $authOpts);
check('changePassword(self) flushed once', $em->flushes === 1);
check('changePassword(self) wrote NO log', $em->lastLog() === null);
check('changePassword(self) changed the hash', $me->getPassword() !== $old);
check('changePassword(self) new password verifies', OSS_Auth_Password::verify('NewPass456', $me->requiredPassword(), $authOpts));

// ---- changePassword: super for another (logs PW_CHANGE) ----------------- //
$em  = new FakeAdminObjectManager();
$tgt = makeAdminForService('target@example.com');
$tgt->setPassword(OSS_Auth_Password::hash('Whatever11', $authOpts));
(new ViMbAdmin_Service_Admin($em))->changePassword($tgt, 'ForcedPass9', $actor, false, $authOpts);
check('changePassword(other) flushed once', $em->flushes === 1);
check('changePassword(other) logged PW_CHANGE', $em->lastLog() && $em->lastLog()->getAction() === \Entities\Log::ACTION_ADMIN_PW_CHANGE);
check('changePassword(other) log binds actor', $em->lastLog() && $em->lastLog()->getAdmin() === $actor);
check('changePassword(other) log binds NO domain', $em->lastLog() && $em->lastLog()->getDomain() === null);
check('changePassword(other) new password verifies', OSS_Auth_Password::verify('ForcedPass9', $tgt->requiredPassword(), $authOpts));

$em = new FakeAdminObjectManager();
$unchanged = makeAdminForService('unchanged@example.com');
$unchanged->setPassword('existing-hash');
$changeRejected = false;
try {
    (new ViMbAdmin_Service_Admin($em))->changePassword($unchanged, 'RejectedPass9', $actor, false, []);
} catch (OSS_Exception $e) {
    $changeRejected = $e->getMessage() === 'Cannot hash password without a hash method';
}
check(
    'changePassword hash errors preserve password and precede log and flush',
    $changeRejected
        && $unchanged->getPassword() === 'existing-hash'
        && $em->persisted === []
        && $em->flushes === 0
);

// ---- getNotAssignedForDomain: query pushes exclusion into DQL ---------- //
// VIM-D10: super admins and admins already assigned to the domain are now
// excluded by the query itself (a NOT IN subquery over the domain's admins),
// not by hydrating every admin and unsetting matches in PHP. The row mapping
// is a pure static helper (mapNotAssignedRows) so its output contract can be
// pinned without standing up a Doctrine query.
//
// The exclusion contract itself -- that super admins and already-assigned
// admins really are filtered out -- is pinned in
// tests/test-repository-admin-not-assigned.php, which compiles the real DQL
// through Doctrine and asserts on the generated SQL. Source-text assertions
// were tried here first and are not adequate: the substrings survive a
// mutation that neuters the clause containing them, so both "a super admin
// leaks into the dropdown" and "an assigned admin is offered twice" shipped
// green. What remains below is the row-mapping contract, which is pure and
// belongs with the service tests.

$mapRows = new ReflectionMethod(\Repositories\Admin::class, 'mapNotAssignedRows');
$mapFailure = static function (mixed $rows) use ($mapRows): ?string {
    try {
        $mapRows->invoke(null, $rows);
    } catch (\UnexpectedValueException $exception) {
        return $exception->getMessage();
    }
    return null;
};

check('no admins produces an empty map', $mapRows->invoke(null, []) === []);
check('an active admin keeps its plain username', $mapRows->invoke(null, [
    ['id' => 101, 'username' => 'available@example.com', 'active' => true],
]) === [101 => 'available@example.com']);
check('an inactive admin is labelled " (inactive)"', $mapRows->invoke(null, [
    ['id' => 102, 'username' => 'inactive@example.com', 'active' => false],
]) === [102 => 'inactive@example.com (inactive)']);
check('mixed active/inactive rows preserve the id-to-username map shape', $mapRows->invoke(null, [
    ['id' => 101, 'username' => 'available@example.com', 'active' => true],
    ['id' => 102, 'username' => 'inactive@example.com', 'active' => false],
]) === [101 => 'available@example.com', 102 => 'inactive@example.com (inactive)']);
// A super admin or an already-assigned admin never reaches this helper at
// all -- the query excludes both -- so the "excluded" case here is simply
// that the row is absent from the input, and the map has no trace of it.
$exclusionMap = $mapRows->invoke(null, [
    ['id' => 101, 'username' => 'available@example.com', 'active' => true],
]);
check(
    'an admin absent from the query result is absent from the map (super/assigned exclusion)',
    is_array($exclusionMap) && !array_key_exists(103, $exclusionMap)
);

check('a scalar query result is rejected', $mapFailure('invalid') === 'Admin not-assigned query result must be an array.');
foreach ([
    'scalar row' => ['invalid'],
    'missing id' => [['username' => 'x@example.test', 'active' => true]],
    'missing username' => [['id' => 1, 'active' => true]],
    'missing active' => [['id' => 1, 'username' => 'x@example.test']],
    'null username' => [['id' => 1, 'username' => null, 'active' => true]],
    'non-scalar id' => [['id' => [1], 'username' => 'x@example.test', 'active' => true]],
] as $label => $rows) {
    check($label . ' is rejected', $mapFailure($rows) === 'Admin not-assigned query row has an invalid shape.');
}

$countMethodDoc = (new ReflectionMethod(\Repositories\Admin::class, 'getCount'))->getDocComment();
check(
    'count contract preserves Doctrine scalar return types',
    is_string($countMethodDoc) && str_contains($countMethodDoc, '@return bool|float|int|string|null')
);

echo "\n";
if ($failures === 0) {
    echo "OK: all ViMbAdmin_Service_Admin assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
