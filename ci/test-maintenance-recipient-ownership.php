<?php

declare(strict_types=1);

define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';

const MAINTENANCE_RECIPIENT_LOCAL_TIMEOUT_NS = 30_000_000_000;

/** @param resource $stream */
function maintenanceRecipientReadLine($stream, string $label, int $timeoutNanoseconds = MAINTENANCE_RECIPIENT_LOCAL_TIMEOUT_NS): string
{
    stream_set_blocking($stream, false);
    $deadline = hrtime(true) + $timeoutNanoseconds;
    do {
        $line = fgets($stream);
        if ($line !== false) {
            return trim($line);
        }
        if (feof($stream)) {
            throw new RuntimeException($label . ' closed before publishing its barrier');
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException($label . ' timed out');
}

/** @param array<int,resource> $pipes */
function maintenanceRecipientStopChild(mixed $child, array &$pipes, string $label): void
{
    $running = false;
    if (is_resource($child)) {
        $running = proc_get_status($child)['running'];
        if ($running) {
            proc_terminate($child, 9);
        }
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $pipes = [];
    if (!is_resource($child)) {
        return;
    }
    $deadline = hrtime(true) + MAINTENANCE_RECIPIENT_LOCAL_TIMEOUT_NS;
    while ($running && hrtime(true) < $deadline) {
        $running = proc_get_status($child)['running'];
        if ($running) {
            usleep(10000);
        }
    }
    if ($running) {
        throw new RuntimeException($label . ' could not be reaped');
    }
    proc_close($child);
}

if (($argv[1] ?? '') === 'recipient-silent-child') {
    sleep(60);
    exit(0);
}

if (($argv[1] ?? '') === 'recipient-child') {
    $em = maintenanceTaskManager();
    $connection = $em->getConnection();
    $mailbox = $em->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => $argv[2]]);
    if (!$mailbox instanceof \Entities\Mailbox) {
        throw new RuntimeException('child mailbox missing');
    }
    fwrite(STDOUT, 'CONNECTION ' . maintenanceTaskInteger($connection->fetchOne('SELECT CONNECTION_ID()')) . "\n");
    fflush(STDOUT);
    \ViMbAdmin_Recipients::atomic($em, static function () use ($em, $mailbox): void {
        $repository = $em->getRepository(\Entities\Mailbox::class);
        if (!$repository instanceof \Repositories\Mailbox) {
            throw new RuntimeException('Mailbox repository mismatch');
        }
        $repository->purgeMailbox($mailbox, null, true);
        fwrite(STDOUT, "STAGED\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            throw new RuntimeException('child commit barrier rejected');
        }
        $em->flush();
    });
    fwrite(STDOUT, "COMMITTED\n");
    exit(0);
}

$readTimeoutChild = proc_open(
    [PHP_BINARY,__FILE__,'recipient-silent-child'],
    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
    $readTimeoutPipes
);
if (!is_resource($readTimeoutChild)) {
    throw new RuntimeException('Cannot start recipient read-timeout child');
}
$readTimeoutStatus = proc_get_status($readTimeoutChild);
$readTimeoutPid = $readTimeoutStatus['pid'];
$readTimeoutError = null;
try {
    maintenanceRecipientReadLine($readTimeoutPipes[1], 'Recipient read barrier', 1_000_000);
} catch (RuntimeException $error) {
    $readTimeoutError = $error;
} finally {
    maintenanceRecipientStopChild($readTimeoutChild, $readTimeoutPipes, 'Recipient read-timeout child');
}
maintenanceTaskCheck(
    'recipient read timeout is bounded, diagnostic, and reaps its owned child',
    $readTimeoutError instanceof RuntimeException && str_contains($readTimeoutError->getMessage(), 'Recipient read barrier timed out')
    && is_int($readTimeoutPid) && !posix_kill($readTimeoutPid, 0)
);

// Exercise the cleanup policy with both supported SQLite native contracts.
// These real connections prove that an intentional clear never closes healthy
// caller work, and that PDO can confirm an already-aborted non-MariaDB server.
foreach (['pdo_sqlite', 'sqlite3'] as $driver) {
    $configuration = maintenanceTaskManager()->getConfiguration();
    $sqlite = \Doctrine\DBAL\DriverManager::getConnection(['driver' => $driver, 'memory' => true], $configuration);
    $sqliteManager = new \Doctrine\ORM\EntityManager($sqlite, $configuration);
    $sqlite->executeStatement('CREATE TABLE recipient_outer_receipt (id INTEGER NOT NULL)');
    $native = $sqlite->getNativeConnection();
    maintenanceTaskCheck(
        'real SQLite fixture uses the expected native driver: ' . $driver,
        $driver === 'pdo_sqlite' ? $native instanceof PDO : $native instanceof SQLite3
    );
    $sqlite->beginTransaction();
    $sqlite->executeStatement('INSERT INTO recipient_outer_receipt VALUES (1)');
    \ViMbAdmin_Recipients::atomic($sqliteManager, static function () use ($sqlite, $sqliteManager, $native, $driver): void {
        $sqliteManager->clear();
        maintenanceTaskCheck(
            'non-MariaDB intentional clear preserves healthy outer connection: ' . $driver,
            $sqlite->getNativeConnection() === $native && $sqlite->getTransactionNestingLevel() === 2
            && maintenanceTaskInteger($sqlite->fetchOne('SELECT COUNT(*) FROM recipient_outer_receipt')) === 1
        );
        $sqlite->executeStatement('INSERT INTO recipient_outer_receipt VALUES (2)');
    });
    maintenanceTaskCheck(
        'non-MariaDB wrapper removes its listener and preserves outer work: ' . $driver,
        $sqlite->getTransactionNestingLevel() === 1 && count($sqliteManager->getEventManager()->getListeners('onClear')) === 0
        && maintenanceTaskInteger($sqlite->fetchOne('SELECT COUNT(*) FROM recipient_outer_receipt')) === 2
    );
    $sqlite->rollBack();
    maintenanceTaskCheck(
        'non-MariaDB outer rollback removes both mutations: ' . $driver,
        maintenanceTaskInteger($sqlite->fetchOne('SELECT COUNT(*) FROM recipient_outer_receipt')) === 0
    );
    if ($native instanceof PDO) {
        $original = new RuntimeException('original SQLite already-aborted operation');
        try {
            \ViMbAdmin_Recipients::atomic($sqliteManager, static function () use ($sqliteManager, $native, $original): void {
                $native->exec('ROLLBACK');
                $sqliteManager->clear();
                throw $original;
            });
        } catch (RuntimeException $error) {
            maintenanceTaskCheck(
                'confirmed non-MariaDB PDO abort preserves exact original error and clears transaction state',
                $error === $original && !$sqlite->isTransactionActive()
                && count($sqliteManager->getEventManager()->getListeners('onClear')) === 0
            );
        }
    }
    $sqlite->close();
}

$unsupported = new class (maintenanceTaskManager()) extends \Doctrine\Persistence\ObjectManagerDecorator {
    public function __construct(\Doctrine\Persistence\ObjectManager $em)
    {
        $this->wrapped = $em;
    }
};
$called = false;
try {
    \ViMbAdmin_Recipients::atomic($unsupported, static function () use (&$called): void {
        $called = true;
    });
    throw new RuntimeException('Unsupported recipient manager unexpectedly executed');
} catch (LogicException $expected) {
    maintenanceTaskCheck('unsupported recipient manager rejects before callback mutation', !$called && str_contains($expected->getMessage(), 'EntityManager'));
}
try {
    \ViMbAdmin_Recipients::guardDelete($unsupported, new \Entities\Alias());
    throw new RuntimeException('Unsupported delete manager unexpectedly accepted');
} catch (LogicException $expected) {
    maintenanceTaskCheck('unsupported delete manager rejects before entity mutation', str_contains($expected->getMessage(), 'EntityManager'));
}


try {
    (new \ViMbAdmin_Service_Mailbox($unsupported))->purge((new \Entities\Mailbox())->setUsername('unsupported@example.test'), new \Entities\Admin(), true, static function () use (&$called): bool {
        $called = true;
        return true;
    });
    throw new RuntimeException('unsupported mailbox purge unexpectedly executed');
} catch (LogicException $expected) {
    maintenanceTaskCheck('unsupported mailbox purge rejects before plugin/backend mutation', !$called && str_contains($expected->getMessage(), 'EntityManager'));
}

$emA = maintenanceTaskManager();
$emB = maintenanceTaskManager();
$conn = $emA->getConnection();
$schema = new \ViMbAdmin_Schema($emA);
$schema->apply($schema->pendingSql());
$emptyManager = maintenanceTaskManager();
$emptyBefore = $conn->fetchAllAssociative('SELECT id,goto,alias_version FROM alias ORDER BY id');
\ViMbAdmin_Recipients::atomic($emptyManager, static function () use ($emptyManager): void {
    $query = $emptyManager->createQuery('SELECT a FROM \\Entities\\Alias a WHERE a.address = :address')
        ->setParameter('address', 'absent-empty-aggregate@example.invalid')->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
        ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);
    maintenanceTaskCheck(
        'empty current alias identity map and locked query return an empty aggregate',
        \ViMbAdmin_Recipients::currentAliases($emptyManager, $query) === []
    );
});
maintenanceTaskCheck(
    'empty alias aggregate preserves every persisted recipient and ends its transaction',
    $conn->fetchAllAssociative('SELECT id,goto,alias_version FROM alias ORDER BY id') === $emptyBefore
    && !$emptyManager->getConnection()->isTransactionActive()
);
if (!$emA->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test'])) {
    $fixtureActor = (new \Entities\Admin())->setUsername('resource-actor@example.test')->setPassword('!')->setSuper(true)->setActive(true)->setCreated(new DateTime());
    $emA->persist($fixtureActor);
    $emA->flush();
}
$conn->executeStatement("DELETE FROM alias WHERE address='shared@alias-owner.test'");
$conn->executeStatement("DELETE FROM mailbox WHERE username IN ('one@owner-a.test','two@owner-b.test')");
$conn->executeStatement("DELETE FROM domain WHERE domain IN ('owner-a.test','owner-b.test','alias-owner.test')");
$conn->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('owner-a.test',NOW(),0,1),('owner-b.test',NOW(),0,1),('alias-owner.test',NOW(),1,0)");
$domainA = maintenanceTaskInteger($conn->fetchOne("SELECT id FROM domain WHERE domain='owner-a.test'"));
$domainB = maintenanceTaskInteger($conn->fetchOne("SELECT id FROM domain WHERE domain='owner-b.test'"));
$aliasDomain = maintenanceTaskInteger($conn->fetchOne("SELECT id FROM domain WHERE domain='alias-owner.test'"));
$conn->executeStatement(
    'INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?),(?,?,?,1,NOW(),?)',
    ['one@owner-a.test','!','one',$domainA,'two@owner-b.test','!','two',$domainB]
);
$conn->executeStatement(
    'INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?)',
    ['shared@alias-owner.test','one@owner-a.test,two@owner-b.test,kept@example.test',$aliasDomain]
);
$aliasId = maintenanceTaskInteger($conn->lastInsertId());
$mailboxA = $emA->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => 'one@owner-a.test']);
$mailboxB = $emB->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => 'two@owner-b.test']);
if (!$mailboxA instanceof \Entities\Mailbox || !$mailboxB instanceof \Entities\Mailbox) {
    throw new RuntimeException('recipient fixture mailbox missing');
}
// Public repository read mode retains its default non-locking behavior.
$readAliases = $emA->getRepository(\Entities\Alias::class);
if (!$readAliases instanceof \Repositories\Alias) {
    throw new RuntimeException('recipient read repository mismatch');
}
$conn->executeStatement('INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?)', ['single-read@alias-owner.test','one@owner-a.test',$aliasDomain]);
$singleReadId = maintenanceTaskInteger($conn->lastInsertId());
$readBefore = $conn->fetchAllAssociative('SELECT id,goto,alias_version FROM alias WHERE id IN (?,?) ORDER BY id', [$aliasId,$singleReadId]);
$directRead = $readAliases->loadForMailbox($mailboxA, null);
$membershipRead = $readAliases->loadWithMailbox($mailboxA, null, false);
maintenanceTaskCheck('default lock=false alias lookup returns the exact single recipient aggregate', count($directRead) === 1 && $directRead[0]->getId() === $singleReadId);
maintenanceTaskCheck('explicit lock=false membership lookup returns only the multi-recipient aggregate', count($membershipRead) === 1 && $membershipRead[0]->getId() === $aliasId);
maintenanceTaskCheck('ordinary repository reads create no transaction or recipient mutation', !$conn->isTransactionActive() && $conn->fetchAllAssociative('SELECT id,goto,alias_version FROM alias WHERE id IN (?,?) ORDER BY id', [$aliasId,$singleReadId]) === $readBefore);
$conn->executeStatement('DELETE FROM alias WHERE id=?', [$singleReadId]);
$emA->detach($directRead[0]);

// Independent ORM actor B holds its aggregate snapshot before actor A commits.
$emB->find(\Entities\Alias::class, $aliasId);
$repoA = $emA->getRepository(\Entities\Mailbox::class);
$repoB = $emB->getRepository(\Entities\Mailbox::class);
if (!$repoA instanceof \Repositories\Mailbox || !$repoB instanceof \Repositories\Mailbox) {
    throw new RuntimeException('recipient fixture repository mismatch');
}
\ViMbAdmin_Recipients::atomic($emA, static function () use ($repoA, $mailboxA, $emA): void {
    $repoA->purgeMailbox($mailboxA, null, true);
    $emA->flush();
});
\ViMbAdmin_Recipients::atomic($emB, static function () use ($repoB, $mailboxB, $emB): void {
    $repoB->purgeMailbox($mailboxB, null, true);
    $emB->flush();
});
$current = $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]);
maintenanceTaskCheck('independent mailbox purges cannot restore an already removed recipient', $current === 'kept@example.test');
// Whole-list replacement writers carry the mapping's optimistic version.
$editA = maintenanceTaskManager();
$editB = maintenanceTaskManager();
$aliasA = $editA->find(\Entities\Alias::class, $aliasId);
$aliasB = $editB->find(\Entities\Alias::class, $aliasId);
$actorA = $editA->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
$actorB = $editB->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
if (!$aliasA instanceof \Entities\Alias || !$aliasB instanceof \Entities\Alias || !$actorA instanceof \Entities\Admin || !$actorB instanceof \Entities\Admin) {
    throw new RuntimeException('replacement fixture missing');
}
$aliasA->setGoto('current@example.test,kept@example.test');
(new \ViMbAdmin_Service_Alias($editA))->update($aliasA, $actorA);
$auditCount = maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM log'));
$aliasB->setGoto('one@owner-a.test,two@owner-b.test,kept@example.test');
try {
    (new \ViMbAdmin_Service_Alias($editB))->update($aliasB, $actorB);
    throw new RuntimeException('stale replacement unexpectedly succeeded');
} catch (\Doctrine\ORM\OptimisticLockException $expected) {
    maintenanceTaskCheck('stale whole-list replacement cannot restore removed recipients', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]) === 'current@example.test,kept@example.test');
    maintenanceTaskCheck('stale replacement rolls back its audit write', maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM log')) === $auditCount);
}
$deleteManager = maintenanceTaskManager();
$deleteAlias = $deleteManager->find(\Entities\Alias::class, $aliasId);
$deleteActor = $deleteManager->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
if (!$deleteAlias instanceof \Entities\Alias || !$deleteActor instanceof \Entities\Admin) {
    throw new RuntimeException('delete fixture missing');
}
$aliasA->setGoto('newer@example.test,kept@example.test');
$editA->flush();
try {
    (new \ViMbAdmin_Service_Alias($deleteManager))->delete($deleteAlias, $deleteActor);
    throw new RuntimeException('stale aggregate delete unexpectedly succeeded');
} catch (\Doctrine\ORM\OptimisticLockException $expected) {
    maintenanceTaskCheck('explicit delete fence preserves newer aggregate', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]) === 'newer@example.test,kept@example.test');
}
$deleteManager->flush();
maintenanceTaskCheck('later flush after stale delete cannot remove the aggregate', maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM alias WHERE id=?', [$aliasId])) === 1);

// A real independent contender waits inside the production locking query.
$conn->executeStatement('UPDATE alias SET goto=?,alias_version=alias_version+1 WHERE id=?', ['one@owner-a.test,two@owner-b.test,kept@example.test',$aliasId]);
$conn->executeStatement('UPDATE domain SET mailbox_count=1 WHERE id IN (?,?)', [$domainA,$domainB]);
$conn->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?),(?,?,?,1,NOW(),?)', ['one@owner-a.test','!','one',$domainA,'two@owner-b.test','!','two',$domainB]);
$children = [];
$pipesByActor = [];
try {
    foreach (['one@owner-a.test','two@owner-b.test'] as $index => $username) {
        $child = proc_open([PHP_BINARY,__FILE__,'recipient-child',$username], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $childPipes);
        if (!is_resource($child)) {
            throw new RuntimeException('recipient child did not start');
        }
        $children[$index] = $child;
        $pipesByActor[$index] = $childPipes;
        $header = maintenanceRecipientReadLine($childPipes[1], 'Recipient child connection barrier');
        if (!preg_match('/^CONNECTION ([0-9]+)$/', $header, $match)) {
            throw new RuntimeException('recipient child connection barrier failed');
        }
        if ($index === 0) {
            maintenanceTaskCheck('first independent purge stages under its row locks', maintenanceRecipientReadLine($childPipes[1], 'First recipient staging barrier') === 'STAGED');
        } else {
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $process = $conn->fetchAssociative('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?', [(int) $match[1]]);
                $waiting = is_array($process) && is_string($process['INFO']) && str_contains($process['INFO'], 'FOR UPDATE') && str_contains($process['INFO'], 'alias');
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && hrtime(true) < $deadline);
            maintenanceTaskCheck('second independent purge is observed waiting in the current aggregate query', $waiting);
        }
    }
    fwrite($pipesByActor[0][0], "COMMIT\n");
    fflush($pipesByActor[0][0]);
    maintenanceTaskCheck('first concurrent purge commits', maintenanceRecipientReadLine($pipesByActor[0][1], 'First recipient commit barrier') === 'COMMITTED');
    maintenanceTaskCheck('second concurrent purge reacquires the current aggregate', maintenanceRecipientReadLine($pipesByActor[1][1], 'Second recipient staging barrier') === 'STAGED');
    fwrite($pipesByActor[1][0], "COMMIT\n");
    fflush($pipesByActor[1][0]);
    maintenanceTaskCheck('second concurrent purge commits', maintenanceRecipientReadLine($pipesByActor[1][1], 'Second recipient commit barrier') === 'COMMITTED');
    maintenanceTaskCheck('both concurrent removals survive without losing the unrelated recipient', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]) === 'kept@example.test');
} finally {
    foreach ($children as $index => $child) {
        $childPipes = $pipesByActor[$index] ?? [];
        maintenanceRecipientStopChild($child, $childPipes, 'Recipient concurrency child '.$index);
    }
}

$rollbackManager = maintenanceTaskManager();
$rollbackAlias = $rollbackManager->find(\Entities\Alias::class, $aliasId);
if (!$rollbackAlias instanceof \Entities\Alias) {
    throw new RuntimeException('rollback fixture missing');
}
try {
    \ViMbAdmin_Recipients::atomic($rollbackManager, static function () use ($rollbackManager, $rollbackAlias): void {
        $rollbackAlias->setGoto('uncommitted@example.test');
        $rollbackManager->flush();
        throw new RuntimeException('injected recipient persistence boundary failure');
    });
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('recipient rollback reports the original error', $expected->getMessage() === 'injected recipient persistence boundary failure');
}
$rollbackManager->flush();
maintenanceTaskCheck('recipient rollback and later flush preserve current persistent state', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]) === 'kept@example.test');
$conn->executeStatement('UPDATE alias SET goto=?,alias_version=alias_version+1 WHERE id=?', ['one@owner-a.test,kept@example.test',$aliasId]);
$conn->executeStatement('UPDATE domain SET mailbox_count=1 WHERE id=?', [$domainA]);
$conn->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', ['one@owner-a.test','!','one',$domainA]);
$hookManager = maintenanceTaskManager();
$hookMailbox = $hookManager->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => 'one@owner-a.test']);
$hookAlias = $hookManager->find(\Entities\Alias::class, $aliasId);
$hookActor = $hookManager->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
if (!$hookMailbox instanceof \Entities\Mailbox || !$hookAlias instanceof \Entities\Alias || !$hookActor instanceof \Entities\Admin) {
    throw new RuntimeException('hook fixture missing');
}
(new \ViMbAdmin_Service_Mailbox($hookManager))->purge(
    $hookMailbox,
    $hookActor,
    false,
    static function () use ($hookAlias): bool {
        $hookAlias->setGoto('one@owner-a.test,kept@example.test,plugin@example.test');
        return true;
    }
);
maintenanceTaskCheck('current preRemove aggregate changes survive locked refresh and removal', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$aliasId]) === 'kept@example.test,plugin@example.test');

// Intentional clear and nested wrappers must keep healthy outer transactions.
$guardManager = maintenanceTaskManager();
$guardConnection = $guardManager->getConnection();
$events = $guardManager->getEventManager();
$listenersBefore = count($events->getListeners('onClear'));
$guardConnection->beginTransaction();
\ViMbAdmin_Recipients::atomic($guardManager, static function () use ($guardManager, $guardConnection, $events, $listenersBefore): void {
    maintenanceTaskCheck('transaction cleanup listener is scoped to its wrapper', count($events->getListeners('onClear')) === $listenersBefore + 1);
    \ViMbAdmin_Recipients::atomic($guardManager, static function () use ($guardManager, $guardConnection, $events, $listenersBefore): void {
        $guardManager->clear();
        maintenanceTaskCheck('intentional clear leaves healthy nested server transaction open', $guardConnection->getTransactionNestingLevel() === 3 && $guardConnection->fetchOne('SELECT @@in_transaction') === 1);
        maintenanceTaskCheck('nested cleanup listeners remain independent', count($events->getListeners('onClear')) === $listenersBefore + 2);
    });
    maintenanceTaskCheck('nested wrapper removes only its own listener', count($events->getListeners('onClear')) === $listenersBefore + 1 && $guardConnection->getTransactionNestingLevel() === 2);
});
maintenanceTaskCheck('successful wrapper preserves caller outer transaction and removes listener', $guardConnection->getTransactionNestingLevel() === 1 && count($events->getListeners('onClear')) === $listenersBefore);
$guardConnection->rollBack();

// Real connection loss makes the MariaDB probe unknown. Preserve the operation
// exception even when the required best-effort rollback also loses transport.
$failedManager = maintenanceTaskManager();
$failedConnection = $failedManager->getConnection();
$failedId = maintenanceTaskInteger($failedConnection->fetchOne('SELECT CONNECTION_ID()'));
try {
    \ViMbAdmin_Recipients::atomic($failedManager, static function () use ($failedManager, $conn, $failedId): void {
        $conn->executeStatement('KILL CONNECTION ' . $failedId);
        $failedManager->clear(); // Actual PDO probe fails; it cannot prove abort.
        throw new RuntimeException('original connection-loss operation failure');
    });
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('failed server-state probe and rollback preserve original operation error', $expected->getMessage() === 'original connection-loss operation failure');
}
maintenanceTaskCheck('failed wrapper removes its listener and resets dead connection', count($failedManager->getEventManager()->getListeners('onClear')) === 0 && !$failedConnection->isTransactionActive());


// Staged same-version hook dirt survives; staged stale dirt must reject instead.
$conn->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', ['staged-target@owner-a.test','!','staged-target',$domainA]);
$conn->executeStatement('INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?)', ['staged-list@owner-a.test','staged-target@owner-a.test,kept@example.test',$domainA]);
$stagedId = maintenanceTaskInteger($conn->lastInsertId());
$staleManager = maintenanceTaskManager();
$currentManager = maintenanceTaskManager();
$staleAlias = $staleManager->find(\Entities\Alias::class, $stagedId);
$currentAlias = $currentManager->find(\Entities\Alias::class, $stagedId);
$stagedMailbox = $staleManager->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => 'staged-target@owner-a.test']);
if (!$staleAlias instanceof \Entities\Alias || !$currentAlias instanceof \Entities\Alias || !$stagedMailbox instanceof \Entities\Mailbox) {
    throw new RuntimeException('stale staged fixture missing');
}
$staleAlias->setGoto('staged-target@owner-a.test,stale-hook@example.test');
$currentAlias->setGoto('staged-target@owner-a.test,current-kept@example.test');
$currentManager->flush();
try {
    \ViMbAdmin_Recipients::atomic($staleManager, static function () use ($staleManager, $stagedMailbox): void {
        $repository = $staleManager->getRepository(\Entities\Mailbox::class);
        if (!$repository instanceof \Repositories\Mailbox) {
            throw new RuntimeException('recipient repository missing');
        }
        $repository->purgeMailbox($stagedMailbox, null, true);
        $staleManager->flush();
    });
    throw new RuntimeException('stale staged recipient dirt unexpectedly accepted');
} catch (\Doctrine\ORM\OptimisticLockException $expected) {
    maintenanceTaskCheck('current aggregate rejects stale staged hook dirt under the row fence', !$staleManager->contains($staleAlias));
}
$staleManager->flush();
maintenanceTaskCheck('stale hook rejection and later flush preserve current recipients and mailbox', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$stagedId]) === 'staged-target@owner-a.test,current-kept@example.test' && maintenanceTaskInteger($conn->fetchOne("SELECT COUNT(*) FROM mailbox WHERE username='staged-target@owner-a.test'")) === 1);
try {
    $repository = $staleManager->getRepository(\Entities\Mailbox::class);
    if (!$repository instanceof \Repositories\Mailbox) {
        throw new RuntimeException('recipient repository missing');
    }
    $reference = $staleManager->getReference(\Entities\Mailbox::class, $stagedMailbox->getId());
    if (!$reference instanceof \Entities\Mailbox) {
        throw new RuntimeException('unfenced purge mailbox missing');
    }
    $repository->purgeMailbox($reference, null, true);
    throw new RuntimeException('unfenced public purge unexpectedly accepted');
} catch (LogicException $expected) {
    maintenanceTaskCheck('public mailbox purge rejects missing caller transaction before mutation', str_contains($expected->getMessage(), 'caller-owned recipient transaction') && maintenanceTaskInteger($conn->fetchOne("SELECT COUNT(*) FROM mailbox WHERE username='staged-target@owner-a.test'")) === 1);
}
foreach ([false, true] as $stringify) {
    $abortedManager = maintenanceTaskManager();
    $abortedConnection = $abortedManager->getConnection();
    $nativeAbort = $abortedConnection->getNativeConnection();
    if (!$nativeAbort instanceof PDO) {
        throw new RuntimeException('MariaDB abort fixture requires its real PDO driver');
    }
    $nativeAbort->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify);
    $abortProbe = $nativeAbort->query('SELECT @@in_transaction');
    if (!$abortProbe instanceof PDOStatement) {
        throw new RuntimeException('MariaDB diagnostic fixture did not return its real PDO statement');
    }
    maintenanceTaskCheck(
        'MariaDB diagnostic fixture uses the requested integer/string representation',
        $abortProbe->fetchColumn() === ($stringify ? '0' : 0)
    );
    try {
        \ViMbAdmin_Recipients::atomic($abortedManager, static function () use ($abortedManager, $abortedConnection): void {
            $abortedConnection->executeStatement('ROLLBACK'); // Real server abort; DBAL still has nesting state.
            $abortedManager->clear();
            throw new RuntimeException('original already-aborted operation');
        });
    } catch (RuntimeException $expected) {
        maintenanceTaskCheck('confirmed MariaDB abort resets stale client transaction without masking error', $expected->getMessage() === 'original already-aborted operation' && !$abortedConnection->isTransactionActive() && count($abortedManager->getEventManager()->getListeners('onClear')) === 0);
    }
}


$wrongQueryManager = maintenanceTaskManager();
$wrongQueryAlias = $wrongQueryManager->find(\Entities\Alias::class, $stagedId);
if (!$wrongQueryAlias instanceof \Entities\Alias) {
    throw new RuntimeException('wrong-query alias missing');
}
$wrongQueryBefore = $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$stagedId]);
$wrongQueryAlias->setGoto('uncommitted-invalid-query@example.test');
try {
    \ViMbAdmin_Recipients::atomic($wrongQueryManager, static function () use ($wrongQueryManager): void {
        \ViMbAdmin_Recipients::currentAliases($wrongQueryManager, $wrongQueryManager->createQuery('SELECT m FROM \\Entities\\Mailbox m'));
    });
    throw new RuntimeException('misrouted mapped aggregate unexpectedly accepted');
} catch (UnexpectedValueException $expected) {
    maintenanceTaskCheck('real ORM aggregate rejects a query returning another mapped entity', str_contains($expected->getMessage(), 'Locked alias aggregate query returned an invalid entity.') && $wrongQueryManager->getUnitOfWork()->getIdentityMap() === []);
}

$wrongQueryManager->flush();
maintenanceTaskCheck('invalid mapped query rollback and later flush preserve persisted recipients', $conn->fetchOne('SELECT goto FROM alias WHERE id=?', [$stagedId]) === $wrongQueryBefore);
echo "ALL PASSED\n";
