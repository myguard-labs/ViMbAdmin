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

// OSS_Auth_Password (used by create() to hash the plaintext password). Pull in
// only its dependencies and exercise it with pwhash=crypt:sha512 — bcrypt fatals
// on a host CLI without the OSS_Crypt_Bcrypt salt generator, but crypt:sha512
// needs only PHP's crypt(), so the hash-dependent logic is testable framework-free.
require __DIR__ . '/../library/OSS/Exception.php';
require __DIR__ . '/../library/OSS/String.php';
require __DIR__ . '/../library/OSS/Auth/Password.php';

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

/**
 * Stands in for the Alias repository the auto mailbox-alias check queries: a
 * configurable findOneBy() result lets the create() test exercise both the
 * "no clashing alias -> create one" and "alias already exists -> skip" branches.
 */
final class FakeAliasRepo
{
    public ?object $existing = null;

    public function findOneBy(array $criteria)
    {
        return $this->existing;
    }
}

final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */ public array $persisted = [];
    /** @var object[] */ public array $removed = [];
    public int $flushes = 0;
    public ?FakeMailboxRepo $mailboxRepo = null;
    public ?FakeAliasRepo $aliasRepo = null;

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
    public function find(string $className, $id) { return null; }
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function getRepository(string $className) {
        if ($this->aliasRepo !== null && str_contains($className, 'Alias')) {
            return $this->aliasRepo;
        }
        if ($this->mailboxRepo !== null && str_contains($className, 'Mailbox')) {
            return $this->mailboxRepo;
        }
        throw new \RuntimeException('not used');
    }

    public function lastAlias(): ?\Entities\Alias
    {
        for ($i = count($this->persisted) - 1; $i >= 0; $i--) {
            if ($this->persisted[$i] instanceof \Entities\Alias) {
                return $this->persisted[$i];
            }
        }
        return null;
    }

    public function countPersisted(string $class): int
    {
        return count(array_filter($this->persisted, static fn($o) => $o instanceof $class));
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

// --- create: full add path, auto-alias on, hooks fire in order -------- //
$mkDomain = static function (int $count): \Entities\Domain {
    $d = new \Entities\Domain();
    $d->setDomain('example.com');
    $d->setMailboxCount($count);
    return $d;
};

$createOptions = [
    'mailboxAliases' => 1,
    'defaults' => ['mailbox' => [
        'homedir'         => '/var/vmail/%d/',
        'maildir'         => '/var/vmail/%d/%u/',
        'uid'             => 5000,
        'gid'             => 5000,
        'password_scheme' => 'crypt:sha512',
    ]],
];

$emC = new FakeObjectManager();
$emC->aliasRepo = new FakeAliasRepo();        // findOneBy -> null (no clash)
$domC = $mkDomain(7);
$mbC  = new \Entities\Mailbox();
$mbC->setUsername('new@example.com');
$mbC->setLocalPart('new');
$mbC->setName('New User');
$mbC->setQuota(0);
$mbC->setPassword('s3cr3t-plaintext');

$orderC = [];
$created = (new ViMbAdmin_Service_Mailbox($emC))->create(
    $mbC, $domC, $actor, $createOptions,
    function () use (&$orderC, $emC): void { $orderC[] = 'preFlush:' . $emC->flushes; },
    function () use (&$orderC, $emC): void { $orderC[] = 'postFlush:' . $emC->flushes; },
);

check('create returns the mailbox',           $created === $mbC);
check('create set the domain',                $mbC->getDomain() === $domC);
check('create set active',                    (bool) $mbC->getActive() === true);
check('create cleared delete-pending',        (bool) $mbC->getDeletePending() === false);
check('create set uid/gid from options',      (int) $mbC->getUid() === 5000 && (int) $mbC->getGid() === 5000);
check('create formatted homedir (%d)',        $mbC->getHomedir() === '/var/vmail/example.com/');
check('create formatted maildir (%d/%u)',     $mbC->getMaildir() === '/var/vmail/example.com/new/');
check('create hashed the password',           $mbC->getPassword() !== 's3cr3t-plaintext');
check('create password verifies',             OSS_Auth_Password::verify('s3cr3t-plaintext', $mbC->getPassword(), ['pwhash' => 'crypt:sha512']) === true);
check('create persisted the mailbox',         in_array($mbC, $emC->persisted, true));
check('create bumped domain mailboxCount',    (int) $domC->getMailboxCount() === 8);
check('create logged ACTION_MAILBOX_ADD',     $emC->lastLog()?->getAction() === \Entities\Log::ACTION_MAILBOX_ADD);
check('create flushed once',                  $emC->flushes === 1);
check('create hook order around flush',       $orderC === ['preFlush:0', 'postFlush:1']);
// auto mailbox-alias
check('create made the auto-alias',           $emC->countPersisted(\Entities\Alias::class) === 1);
check('auto-alias address == username',       $emC->lastAlias()?->getAddress() === 'new@example.com');
check('auto-alias goto == username',          $emC->lastAlias()?->getGoto() === 'new@example.com');

// --- create: mailboxAliases off -> no auto-alias ---------------------- //
$emN = new FakeObjectManager();
$emN->aliasRepo = new FakeAliasRepo();
$optsN = $createOptions; $optsN['mailboxAliases'] = 0;
$mbN = new \Entities\Mailbox();
$mbN->setUsername('noalias@example.com');
$mbN->setPassword('s3cr3t-plaintext');
(new ViMbAdmin_Service_Mailbox($emN))->create($mbN, $mkDomain(0), $actor, $optsN);
check('aliases off: no auto-alias',           $emN->countPersisted(\Entities\Alias::class) === 0);
check('aliases off: still creates mailbox',   $emN->countPersisted(\Entities\Mailbox::class) === 1 && $emN->flushes === 1);

// --- create: a clashing alias already exists -> skip the auto-alias --- //
$emE = new FakeObjectManager();
$emE->aliasRepo = new FakeAliasRepo();
$emE->aliasRepo->existing = new \Entities\Alias();   // findOneBy returns one
$mbE = new \Entities\Mailbox();
$mbE->setUsername('clash@example.com');
$mbE->setPassword('s3cr3t-plaintext');
(new ViMbAdmin_Service_Mailbox($emE))->create($mbE, $mkDomain(0), $actor, $createOptions);
check('existing alias: no new auto-alias',    $emE->countPersisted(\Entities\Alias::class) === 0);
check('existing alias: mailbox still created', $emE->countPersisted(\Entities\Mailbox::class) === 1);

// --- update: stamp modified, log EDIT, single flush, hooks around flush -- //
$emU = new FakeObjectManager();
$mbU = new \Entities\Mailbox();
$mbU->setUsername('edit@example.com');
$orderU = [];
$updated = (new ViMbAdmin_Service_Mailbox($emU))->update(
    $mbU, $actor,
    function () use (&$orderU, $emU): void { $orderU[] = 'preFlush:' . $emU->flushes; },
    function () use (&$orderU, $emU): void { $orderU[] = 'postFlush:' . $emU->flushes; },
);
check('update returns the mailbox',           $updated === $mbU);
check('update stamped modified',              $mbU->getModified() instanceof \DateTime);
check('update logged ACTION_MAILBOX_EDIT',    $emU->lastLog()?->getAction() === \Entities\Log::ACTION_MAILBOX_EDIT);
check('update did NOT create an alias',       $emU->countPersisted(\Entities\Alias::class) === 0);
check('update flushed once',                  $emU->flushes === 1);
check('update hook order around flush',       $orderU === ['preFlush:0', 'postFlush:1']);

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Mailbox assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
