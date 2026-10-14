<?php

declare(strict_types=1);

define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';

const MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS = 30_000_000_000;

/** @param resource $stream */
function maintenanceResourceReadLine($stream, string $label, int $timeoutNanoseconds = MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS): string
{
    stream_set_blocking($stream, false);
    $deadline = hrtime(true) + $timeoutNanoseconds;
    do {
        $line = fgets($stream);
        if ($line !== false) {
            return trim($line);
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException($label . ' timed out');
}

function maintenanceResourceWaitForFile(string $path, string $label, int $timeoutNanoseconds = MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS): void
{
    $deadline = hrtime(true) + $timeoutNanoseconds;
    while (!file_exists($path)) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException($label . ' timed out');
        }
        usleep(10000);
    }
}

/** @phpstan-impure */
function maintenanceResourceProcessRunning(int $pid): bool
{
    if ($pid < 1 || !posix_kill($pid, 0)) {
        return false;
    }
    $stat = @file_get_contents('/proc/'.$pid.'/stat');
    if (is_string($stat) && preg_match('/^\d+ \(.*\) ([A-Z]) /s', $stat, $match) === 1 && $match[1] === 'Z') {
        return false;
    }
    return true;
}

/** @param array<int,resource> $pipes */
function maintenanceResourceStopChild(mixed $process, array &$pipes, string $label): void
{
    $running = false;
    if (is_resource($process)) {
        $status = proc_get_status($process);
        $running = $status['running'];
        if ($running) {
            proc_terminate($process, 9);
        }
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $pipes = [];
    if (is_resource($process)) {
        $deadline = hrtime(true) + MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS;
        while ($running && hrtime(true) < $deadline) {
            $running = proc_get_status($process)['running'];
            if ($running) {
                usleep(10000);
            }
        }
        if ($running) {
            throw new RuntimeException($label . ' could not be reaped');
        }
        proc_close($process);
    }
}

function maintenanceResourceStopBackend(int $pid, string $label): void
{
    if (!maintenanceResourceProcessRunning($pid)) {
        return;
    }
    posix_kill($pid, 15);
    $deadline = hrtime(true) + MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS;
    while (maintenanceResourceProcessRunning($pid) && hrtime(true) < $deadline) {
        usleep(10000);
    }
    if (maintenanceResourceProcessRunning($pid)) {
        posix_kill($pid, 9);
        $deadline = hrtime(true) + MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS;
        while (maintenanceResourceProcessRunning($pid) && hrtime(true) < $deadline) {
            usleep(10000);
        }
    }
    if (maintenanceResourceProcessRunning($pid)) {
        throw new RuntimeException($label . ' could not be stopped');
    }
}

/** @param array<int,resource> $pipes */
function maintenanceResourceReconcileCrashFixture(
    mixed $child,
    array &$pipes,
    string $directory,
    \Doctrine\DBAL\Connection $observer,
    string $key,
    ?int $backendPid,
    string $barrierLabel,
    int $timeoutNanoseconds = MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS
): void {
    maintenanceResourceStopChild($child, $pipes, $barrierLabel . ' owner');
    touch($directory . '/complete');
    if ($backendPid === null && is_file($directory . '/backend-pid')) {
        $recordedPid = trim((string) file_get_contents($directory . '/backend-pid'));
        if (ctype_digit($recordedPid)) {
            $backendPid = (int) $recordedPid;
        }
    }
    $claim = $observer->fetchAssociative('SELECT owner_token,phase FROM resource_claim WHERE resource_key=?', [$key]);
    $remote = is_array($claim) && ($claim['phase'] ?? null) === 'REMOTE';
    $backendStarted = $remote || is_file($directory . '/backend-spawning') || is_file($directory . '/started') || $backendPid !== null;
    $backendStopped = !$backendStarted;
    $timeoutError = null;
    if ($backendStarted && is_file($directory . '/stopped')) {
        $backendStopped = true;
    } elseif ($backendStarted && $backendPid === null && is_file($directory . '/backend-spawning')) {
        try {
            maintenanceResourceWaitForFile($directory . '/backend-pid', $barrierLabel . ' PID publication', $timeoutNanoseconds);
            $recordedPid = trim((string) file_get_contents($directory . '/backend-pid'));
            $backendPid = ctype_digit($recordedPid) ? (int) $recordedPid : null;
        } catch (RuntimeException $error) {
            throw new RuntimeException($barrierLabel . ' left backend PID uncertain; retained REMOTE ownership and fixture directory', 0, $error);
        }
        if ($backendPid === null) {
            throw new RuntimeException($barrierLabel . ' published an invalid backend PID; retained REMOTE ownership and fixture directory');
        }
        try {
            maintenanceResourceWaitForFile($directory . '/stopped', $barrierLabel, $timeoutNanoseconds);
            $backendStopped = true;
        } catch (RuntimeException $error) {
            $timeoutError = $error;
            maintenanceResourceStopBackend($backendPid, $barrierLabel . ' backend');
            $backendStopped = !maintenanceResourceProcessRunning($backendPid);
        }
    } elseif ($backendStarted && $backendPid === null) {
        // REMOTE was durable, but the reaped owner never began spawning its
        // callback process, so no independent backend can remain.
        $backendStopped = true;
    } elseif ($backendPid !== null) {
        try {
            maintenanceResourceWaitForFile($directory . '/stopped', $barrierLabel, $timeoutNanoseconds);
            $backendStopped = true;
        } catch (RuntimeException $error) {
            $timeoutError = $error;
            maintenanceResourceStopBackend($backendPid, $barrierLabel . ' backend');
            $backendStopped = !maintenanceResourceProcessRunning($backendPid);
        }
    }
    if (!$backendStopped) {
        throw new RuntimeException($barrierLabel . ' left backend state uncertain; retained REMOTE ownership and fixture directory');
    }
    if (is_array($claim)) {
        $phase = $claim['phase'] ?? null;
        $token = $claim['owner_token'] ?? null;
        if (is_string($phase) && is_string($token) && ($phase === 'READY' || $phase === 'REMOTE')) {
            $observer->executeStatement('DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase=?', [$key,$token,$phase]);
        }
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
    if ($timeoutError !== null) {
        throw $timeoutError;
    }
}

function maintenanceResourceReconcilePriorBackend(
    string $directory,
    \Doctrine\DBAL\Connection $observer,
    string $key,
    string $token,
    int $timeoutNanoseconds = MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS
): void {
    $pidRecord = is_file($directory . '/prior-pid')
        ? trim((string) file_get_contents($directory . '/prior-pid')) : '';
    $pid = ctype_digit($pidRecord) ? (int) $pidRecord : null;
    touch($directory . '/resume-prior');
    $timeoutError = null;
    try {
        maintenanceResourceWaitForFile($directory . '/prior-complete', 'FOUND_ROWS prior backend completion', $timeoutNanoseconds);
    } catch (RuntimeException $error) {
        $timeoutError = $error;
        if ($pid === null) {
            throw new RuntimeException('FOUND_ROWS prior backend PID is uncertain; retained REMOTE ownership and fixture markers', 0, $error);
        }
        maintenanceResourceStopBackend($pid, 'FOUND_ROWS prior backend');
    }
    if (!is_file($directory . '/prior-complete') && ($pid === null || maintenanceResourceProcessRunning($pid))) {
        throw new RuntimeException('FOUND_ROWS prior backend stop is uncertain; retained REMOTE ownership and fixture markers');
    }
    if ($observer->executeStatement(
        "DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='REMOTE'",
        [$key,$token]
    ) !== 1) {
        throw new RuntimeException('FOUND_ROWS cleanup requires its exact retained REMOTE token.');
    }
    foreach (['pause-prior-command','prior-paused','resume-prior','prior-complete','prior-pid','prior-stall'] as $marker) {
        if (file_exists($directory.'/'.$marker)) {
            unlink($directory.'/'.$marker);
        }
    }
    if ($timeoutError !== null) {
        throw $timeoutError;
    }
}


if (PHP_SAPI === 'cli-server' && getenv('MAINTENANCE_BACKEND_RECEIPT')) {
    $request = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($request) || !is_array($request[0] ?? null)) {
        http_response_code(400);
        exit;
    }
    [$command,$parameters,$tag] = $request[0];
    $directory = (string) getenv('MAINTENANCE_BACKEND_RECEIPT');
    $observer = maintenanceTaskManager()->getConnection();
    $claims = $observer->fetchAllAssociative('SELECT username,phase FROM resource_claim');
    file_put_contents($directory.'/requests', json_encode(['command' => $command,'claims' => $claims])."\n", FILE_APPEND);
    if ($command === 'mailboxDelete' || $command === 'mailbox delete') {
        touch($directory.'/emptied');
    }
    if (file_exists($directory.'/pause')) {
        touch($directory.'/paused');
        while (!file_exists($directory.'/resume')) {
            usleep(10000);
        }
    }
    if (file_exists($directory.'/ambiguous') || ($command === 'fsDelete' && file_exists($directory.'/ambiguous-fs-delete'))) {
        echo 'unparseable completed fixture response';
        exit;
    }
    if (($command === 'fsDelete' && file_exists($directory.'/fail-fs-delete'))
        || ($command === 'sync' && file_exists($directory.'/fail-restore-sync'))) {
        header('Content-Type: application/json');
        echo json_encode([['error',['type' => 'injected command failure','exitCode' => 75],$tag]]);
        exit;
    }
    if ($command === 'quota recalc' && file_exists($directory.'/pause-prior-command')) {
        $backend = proc_open(
            [PHP_BINARY, '-r', 'touch($argv[1]); while (!file_exists($argv[2])) { usleep(10000); } while (file_exists($argv[4])) { usleep(10000); } touch($argv[3]);',
                $directory.'/prior-paused', $directory.'/resume-prior', $directory.'/prior-complete', $directory.'/prior-stall'],
            [0 => ['file','/dev/null','r'],1 => ['file','/dev/null','w'],2 => ['file','/dev/null','w']],
            $pipes
        );
        if (!is_resource($backend)) {
            http_response_code(500);
            exit;
        }
        $status = proc_get_status($backend);
        file_put_contents($directory.'/prior-pid', (string) $status['pid']);
        $deadline = hrtime(true) + 5_000_000_000;
        while (!file_exists($directory.'/prior-paused') && hrtime(true) < $deadline) {
            usleep(10000);
        }
        echo 'lost response while prior backend actor remains paused';
        exit;
    }
    $content = $command === 'fsIter' && !file_exists($directory.'/emptied') ? ['message.fixture'] : [];
    if ($command === 'mailbox list') {
        $content = ['INBOX'];
    }
    header('Content-Type: application/json');
    echo json_encode([['doveadmResponse',$content,$tag]]);
    exit;
}

if (in_array($argv[1] ?? '', ['claim-crash-child','claim-wait-timeout-child'], true)) {
    $timeoutChild = $argv[1] === 'claim-wait-timeout-child';
    $username = $timeoutChild ? 'claim-wait-timeout@example.test' : 'claim-crash@example.test';
    $claim = \ViMbAdmin_ResourceClaim::acquire(maintenanceTaskManager()->getConnection(), $username);
    $claim->remote(static function () use ($argv, $timeoutChild): void {
        $script = $timeoutChild
            ? 'file_put_contents($argv[4],(string)getmypid()); touch($argv[1]); usleep(5000000); touch($argv[3]);'
            : 'file_put_contents($argv[4],(string)getmypid()); touch($argv[1]); while (!file_exists($argv[2])) { usleep(10000); } touch($argv[3]);';
        touch($argv[2] . '/backend-spawning');
        $backend = proc_open(
            [PHP_BINARY, '-r', $script,
            $argv[2] . '/started', $argv[2] . '/complete', $argv[2] . '/stopped', $argv[2] . '/backend-pid'],
            [0 => ['file','/dev/null','r'],1 => ['file','/dev/null','w'],2 => ['file','/dev/null','w']],
            $pipes
        );
        if (!is_resource($backend)) {
            throw new RuntimeException('Backend process fixture could not start');
        }
        $status = proc_get_status($backend);
        file_put_contents($argv[2] . '/backend-pid', (string) $status['pid']);
        maintenanceResourceWaitForFile($argv[2] . '/started', 'Child backend start barrier');
        fwrite(STDOUT, 'REMOTE ' . $status['pid'] . "\n");
        fflush(STDOUT);
        fgets(STDIN); // Parent kills this owner while the backend is paused.
    });
    exit(1);
}

if (in_array($argv[1] ?? '', ['domain-purge-child','domain-create-child'], true)) {
    $em = maintenanceTaskManager();
    $domain = $em->find(\Entities\Domain::class, (int) $argv[2]);
    if (!$domain instanceof \Entities\Domain) {
        throw new RuntimeException('domain child fixture missing');
    }
    if (getenv('MAINTENANCE_DOMAIN_CHILD_SILENT') === '1') {
        sleep(60);
    }
    fwrite(STDOUT, 'CONNECTION ' . maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT CONNECTION_ID()')) . "\n");
    fflush(STDOUT);
    if ($argv[1] === 'domain-purge-child') {
        (new \ViMbAdmin_Service_Domain($em))->purge($domain);
        if (getenv('MAINTENANCE_DOMAIN_CHILD_BAD_VERDICT_LIVE') === '1') {
            fwrite(STDOUT, "BAD VERDICT\n");
            fflush(STDOUT);
            sleep(60);
            exit(0);
        }
        fwrite(STDOUT, "PURGED\n");
    } else {
        $actor = $em->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
        if (!$actor instanceof \Entities\Admin) {
            throw new RuntimeException('domain creator actor missing');
        }
        $mailbox = (new \Entities\Mailbox())->setUsername('new@bulk-claim.test')->setLocalPart('new')->setQuota(0)->setPassword('fixture-password');
        \ViMbAdmin_Service_Mailbox::applyCreationPolicy($mailbox, $domain);
        try {
            (new \ViMbAdmin_Service_Mailbox($em))->create(
                $mailbox,
                $domain,
                $actor,
                ['defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']], 'mailboxAliases' => true]
            );
            throw new RuntimeException('concurrent creation unexpectedly escaped the domain fence');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            fwrite(STDOUT, "CREATION REJECTED\n");
        }
    }
    exit(0);
}

// Keep the actor's managed snapshot while an independent ORM owner replaces
// the persisted row. The old ID must reject before any backend effect.
if (($argv[1] ?? '') === 'archive-row-replacement') {
    $snapshotOwner = maintenanceTaskManager();
    (new \ViMbAdmin_Schema($snapshotOwner))->apply((new \ViMbAdmin_Schema($snapshotOwner))->pendingSql());
    $username = 'snapshot-row-replacement@example.test';
    $snapshot = (new \Entities\Archive())->setUsername($username)->setStatus(\Entities\Archive::STATUS_ARCHIVED)
        ->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{"owner":"old"}');
    $snapshotOwner->persist($snapshot);
    $snapshotOwner->flush();
    $snapshotId = $snapshot->getId();
    $replacementOwner = maintenanceTaskManager();
    $persisted = $replacementOwner->find(\Entities\Archive::class, $snapshotId);
    if (!$persisted instanceof \Entities\Archive) {
        throw new RuntimeException('persisted archive snapshot fixture missing');
    }
    $replacementOwner->remove($persisted);
    $replacementOwner->flush();
    $backendEffects = 0;
    foreach ([false, true] as $replace) {
        if ($replace) {
            $replacement = (new \Entities\Archive())->setUsername($username)->setStatus(\Entities\Archive::STATUS_ARCHIVED)
                ->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{"owner":"replacement"}');
            $replacementOwner->persist($replacement);
            $replacementOwner->flush();
            maintenanceTaskCheck('independent archive replacement has a different persisted ID', $replacement->getId() !== $snapshotId);
        }
        $before = $replacementOwner->getConnection()->fetchAllAssociative('SELECT * FROM archive WHERE username=?', [$username]);
        $rejected = false;
        try {
            \ViMbAdmin_ResourceClaim::withMailbox($snapshotOwner, $username, static function (\ViMbAdmin_ResourceClaim $claim) use ($snapshot, &$backendEffects): void {
                $claim->guardArchive($snapshot);
                $claim->remote(static function () use (&$backendEffects): void {
                    ++$backendEffects;
                });
            });
        } catch (\ViMbAdmin_Exception $error) {
            $rejected = $error->getMessage() === 'Archive changed before this operation; reload the archive list and retry.';
        }
        maintenanceTaskCheck(
            'missing persisted direct archive snapshot rejects before backend effects and preserves replacement data',
            $rejected && $backendEffects === 0
            && $before === $replacementOwner->getConnection()->fetchAllAssociative('SELECT * FROM archive WHERE username=?', [$username])
        );
        maintenanceTaskCheck(
            'rejected missing direct snapshot releases its confirmed no-start claim',
            maintenanceTaskInteger($replacementOwner->getConnection()->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [\ViMbAdmin_ResourceClaim::key($username)])) === 0
        );
    }
    if (isset($replacement)) {
        $replacementOwner->remove($replacement);
        $replacementOwner->flush();
    }
    echo "DIRECT MISSING-ROW AND DIFFERENT-ID REPLACEMENT PASSED\n";
    exit(0);
}

final class MaintenanceArchiveBackend extends \ViMbAdmin_Doveadm
{
    public Closure $pausedBackup;
    public int $deleteCalls = 0;
    public function __construct()
    {
    }
    public function backup($user, $dest)
    {
        ($this->pausedBackup)();
        return [];
    }
    public function mailboxDelete($user)
    {
    }
    public function quotaRecalc($user)
    {
        return [];
    }
    #[\Override]
    public function fsDelete($path, $filter = 'posix')
    {
        ++$this->deleteCalls;
        return [];
    }
    #[\Override]
    public function fsDirSize($path, $filter = 'posix'): int
    {
        return 123;
    }

}
final class MaintenanceArchiveRunner extends \ViMbAdmin_Service_QueueRunner
{
    public MaintenanceArchiveBackend $backend;
    protected function newDoveadm($progress)
    {
        return $this->backend;
    }
}

final class MaintenanceHttpProgressRunner extends \ViMbAdmin_Service_QueueRunner
{
    /** @var array<string,mixed> */
    public array $backendOptions = [];
    public Closure $whileWaiting;
    protected function newDoveadm($progress)
    {
        $waiting = $this->whileWaiting;
        return \ViMbAdmin_Doveadm::fromOptions($this->backendOptions, static function () use ($progress, $waiting): void {
            $waiting();
            $progress();
        });
    }
}

$queued = maintenanceTaskManager();
$direct = maintenanceTaskManager();
$schema = new \ViMbAdmin_Schema($queued);
$schema->apply($schema->pendingSql());
$readTimeoutProcess = proc_open(
    [PHP_BINARY, '-r', 'usleep(5000000);'],
    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
    $readTimeoutPipes
);
if (!is_resource($readTimeoutProcess)) {
    throw new RuntimeException('Cannot start read-timeout negative-control child');
}
$readTimeoutStatus = proc_get_status($readTimeoutProcess);
$readTimeoutError = null;
$readTimeoutStarted = hrtime(true);
try {
    maintenanceResourceReadLine($readTimeoutPipes[1], 'Forced read-line barrier', 20_000_000);
} catch (RuntimeException $error) {
    $readTimeoutError = $error;
} finally {
    maintenanceResourceStopChild($readTimeoutProcess, $readTimeoutPipes, 'Read-timeout negative-control child');
}
$readTimeoutSafe = $readTimeoutError?->getMessage() === 'Forced read-line barrier timed out'
    && hrtime(true) - $readTimeoutStarted < 2_000_000_000
    && !maintenanceResourceProcessRunning($readTimeoutStatus['pid']);
$snapshotProcess = proc_open([PHP_BINARY, __FILE__, 'archive-row-replacement'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $snapshotPipes);
if (!is_resource($snapshotProcess)) {
    throw new RuntimeException('direct archive replacement actor could not start');
}
maintenanceTaskCheck('independent direct missing-row and replacement actor completes', proc_close($snapshotProcess) === 0);
maintenanceTaskCheck('read-line timeout is bounded, diagnostic, and reaps its owned child', $readTimeoutSafe);
[$task] = maintenanceTaskFixture($queued, 'PENDING');
$queued->getConnection()->executeStatement('DELETE FROM queue_runner');
$queued->getConnection()->executeStatement("DELETE FROM archive WHERE username='maintenance@example.test'");
$actor = $queued->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test'])
    ?? (new \Entities\Admin())->setUsername('resource-actor@example.test')->setPassword('!')->setSuper(true)->setActive(true)->setCreated(new DateTime());
$queued->persist($actor);
$archive = (new \Entities\Archive())->setUsername('maintenance@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)
    ->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{}');
$queued->persist($archive);
$task->setType(\Entities\MailboxTask::TYPE_ARCHIVE);
$queued->flush();
$directActor = $direct->find(\Entities\Admin::class, $actor->getId());
if (!$directActor instanceof \Entities\Admin) {
    throw new RuntimeException('direct archive actor fixture missing');
}
$session = (object)['identity' => ['id' => $directActor->getId()],'csrfToken' => 'fixture-token'];
$storage = new \ViMbAdmin\Kernel\Session\MagicPropertyStorage($session);
$auth = new \ViMbAdmin\Kernel\Security\Auth($storage, static fn (int $id): \Entities\Admin => $directActor);
$bootstrap = new class ($direct, $session) {
    /** @var array<string,mixed> */
    public array $options = [];
    public function __construct(private \Doctrine\ORM\EntityManager $em, private object $session)
    {
    }
    public function getResource(string $name): object
    {
        return match ($name) {
            'doctrine2' => $this->em,'namespace' => $this->session,default => throw new RuntimeException('unexpected resource')
        };
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
};
$controller = new \ViMbAdmin\Kernel\Controller\ArchiveController(
    new \ViMbAdmin\Kernel\Container($bootstrap, $auth),
    new \ViMbAdmin\Kernel\RouteMatch('archive', 'delete', 'ArchiveController', 'deleteAction', [])
);
$backend = new MaintenanceArchiveBackend();
$rejected = false;
$backend->pausedBackup = static function () use ($controller, $archive, $storage, &$rejected): void {
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $archive->getId(),'csrf' => 'fixture-token'];
        $response = $controller->deleteAction();
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
    }
    $messages = (new \ViMbAdmin\Kernel\Flash\FlashMessages($storage))->peek();
    $rejected = $response->status === 302 && count($messages) === 1 && $messages[0]->level === 'error';
    maintenanceTaskCheck('direct archive deletion is safely rejected while queued archive work owns the same mailbox', $rejected);
};
$runner = new MaintenanceArchiveRunner($queued, ['queue' => ['runner' => ['max_concurrent' => 1]],'doveadm' => ['backup_root' => '/backup']]);
$runner->backend = $backend;
$runner->runOne($task, static function (?Throwable $error) use ($task): void {
    if ($error !== null) {
        throw $error;
    }
    $task->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
});
maintenanceTaskCheck('direct archive deletion is safely rejected while queued archive work owns the same mailbox', $rejected);
// The direct actor retains its pre-backup snapshot after the queued owner exits.
$flash = new \ViMbAdmin\Kernel\Flash\FlashMessages($storage);
$flash->drain();
$queueConnection = $queued->getConnection();
$domainId = $queueConnection->fetchOne("SELECT id FROM domain WHERE domain='example.test'");
if ($domainId === false) {
    $queueConnection->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('example.test',NOW(),0,0)");
    $domainId = $queueConnection->lastInsertId();
}
if (!$queueConnection->fetchOne("SELECT id FROM mailbox WHERE username='maintenance@example.test'")) {
    $queueConnection->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', ['maintenance@example.test','!','maintenance',$domainId]);
}
foreach (['deleteAction','restoreAction','toggleAutopruneAction'] as $action) {
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $archive->getId(),'csrf' => 'fixture-token'];
        $response = $controller->$action();
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
    }
    $messages = $flash->drain();
    maintenanceTaskCheck('stale direct operation preserves the replacement archive', maintenanceTaskInteger($queueConnection->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$archive->getId()])) === 1);
    maintenanceTaskCheck('stale direct archive snapshot safely rejects ' . $action, $response->status === 302 && count($messages) === 1 && $messages[0]->level === 'error' && str_contains($messages[0]->text, 'Archive changed'));
}

foreach (['deleteAction','toggleAutopruneAction'] as $action) {
    $queueConnection->executeStatement("CREATE TRIGGER maintenance_direct_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected exact-token cleanup failure'; END IF; END");
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $archive->getId(),'csrf' => 'fixture-token'];
        $response = $controller->$action();
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
        $queueConnection->executeStatement('DROP TRIGGER maintenance_direct_cleanup_fault');
    }
    $messages = $flash->drain();
    $resourceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
    $resourceToken = $queueConnection->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$resourceKey]);
    maintenanceTaskCheck('stale direct '.$action.' preserves original flash and redirect when cleanup fails', $response->status === 302 && count($messages) === 1 && $messages[0]->level === 'error' && str_contains($messages[0]->text, 'Archive changed'));
    maintenanceTaskCheck('stale direct '.$action.' retains its exact token after cleanup failure', is_string($resourceToken) && maintenanceTaskInteger($queueConnection->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$resourceKey,$resourceToken])) === 1);
    $queueConnection->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$resourceKey,$resourceToken]);
}

require_once __DIR__.'/../application/controllers/McpController.php';
$bootstrap->options = ['doveadm' => ['http' => ['url' => 'http://127.0.0.1:1','api_key' => 'fixture-key']]];
$mcpController = new \McpController(
    new \ViMbAdmin\Kernel\Container($bootstrap, $auth),
    new \ViMbAdmin\Kernel\RouteMatch('mcp', 'index', 'McpController', 'indexAction', [])
);
$queueConnection->executeStatement("CREATE TRIGGER maintenance_mcp_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected MCP exact-token cleanup failure'; END IF; END");
$mcpError = null;
try {
    (new ReflectionMethod($mcpController, '_archiveState'))->invoke($mcpController, ['username' => 'maintenance@example.test'], 'delete');
} catch (\Throwable $error) {
    $mcpError = $error;
} finally {
    $queueConnection->executeStatement('DROP TRIGGER maintenance_mcp_cleanup_fault');
}
$mcpKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
$mcpToken = $queueConnection->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$mcpKey]);
maintenanceTaskCheck('stale MCP archive guard preserves its original domain error when exact-token cleanup fails', $mcpError instanceof \ViMbAdmin_Exception && $mcpError->getMessage() === 'Archive changed before this operation; reload the archive list and retry.');
maintenanceTaskCheck('stale MCP archive guard retains its exact token after cleanup failure', is_string($mcpToken) && maintenanceTaskInteger($queueConnection->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$mcpKey,$mcpToken])) === 1);
$queueConnection->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$mcpKey,$mcpToken]);
$cleanupManager = maintenanceTaskManager();
$cleanupActor = $cleanupManager->find(\Entities\Admin::class, $directActor->getId());
$currentArchive = $cleanupManager->getRepository(\Entities\Archive::class)->findOneBy(['username' => 'maintenance@example.test']);
if (!$cleanupActor instanceof \Entities\Admin || !$currentArchive instanceof \Entities\Archive) {
    throw new RuntimeException('Current MCP cleanup-only archive fixture missing');
}
$currentArchive->setMaildirFile('');
$cleanupManager->flush();
$cleanupMcpController = new \McpController(
    maintenanceResourceContainer($cleanupManager, $session, $bootstrap->options, $cleanupActor),
    new \ViMbAdmin\Kernel\RouteMatch('mcp', 'index', 'McpController', 'indexAction', [])
);
$queueConnection->executeStatement("CREATE TRIGGER maintenance_mcp_success_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected MCP successful-operation cleanup failure'; END IF; END");
$mcpCleanupError = null;
try {
    (new ReflectionMethod($cleanupMcpController, '_archiveState'))->invoke($cleanupMcpController, ['username' => 'maintenance@example.test'], 'delete');
} catch (\Throwable $error) {
    $mcpCleanupError = $error;
} finally {
    $queueConnection->executeStatement('DROP TRIGGER maintenance_mcp_success_cleanup_fault');
}
$mcpSuccessToken = $queueConnection->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$mcpKey]);
maintenanceTaskCheck('successful MCP archive operation still reports its cleanup-only failure', $mcpCleanupError instanceof \Doctrine\DBAL\Exception && str_contains($mcpCleanupError->getMessage(), 'injected MCP successful-operation cleanup failure'));
maintenanceTaskCheck('MCP cleanup-only failure retains its exact token after publishing the operation', is_string($mcpSuccessToken) && maintenanceTaskInteger($queueConnection->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$mcpKey,$mcpSuccessToken])) === 1 && maintenanceTaskInteger($queueConnection->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$currentArchive->getId()])) === 0);
$queueConnection->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$mcpKey,$mcpSuccessToken]);

$pruneManager = maintenanceTaskManager();
$stalePrune = (new \Entities\Archive())->setUsername('stale-cleanup@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setAutoprune(true)->setArchivedAt(new DateTime('2000-01-01'))->setStatusChangedAt(new DateTime('2000-01-01'))->setData('{}');
$healthyPrune = (new \Entities\Archive())->setUsername('healthy-cleanup@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setAutoprune(true)->setArchivedAt(new DateTime('2000-01-01'))->setStatusChangedAt(new DateTime('2000-01-01'))->setData('{}');
$pruneManager->persist($stalePrune);
$pruneManager->persist($healthyPrune);
$pruneManager->flush();
$pruneObserver = maintenanceTaskManager()->getConnection();
$pruneObserver->executeStatement('UPDATE archive SET archive_version=archive_version+1 WHERE id=?', [$stalePrune->getId()]);
$pruneObserver->executeStatement("CREATE TRIGGER maintenance_prune_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='stale-cleanup@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected per-item cleanup failure'; END IF; END");
$pruneActor = $pruneManager->find(\Entities\Admin::class, $directActor->getId());
if (!$pruneActor instanceof \Entities\Admin) {
    throw new RuntimeException('prune cleanup actor missing');
}
$pruneController = new \ViMbAdmin\Kernel\Controller\MaintenanceController(
    maintenanceResourceContainer($pruneManager, $session, ['doveadm' => ['http' => ['url' => 'http://127.0.0.1:1','api_key' => 'fixture-key']]], $pruneActor),
    new \ViMbAdmin\Kernel\RouteMatch('maintenance', 'prune', 'MaintenanceController', 'pruneAction', [])
);
try {
    $pruneCount = (new ReflectionMethod($pruneController, 'prune'))->invoke($pruneController, [$stalePrune,$healthyPrune]);
} finally {
    $pruneObserver->executeStatement('DROP TRIGGER maintenance_prune_cleanup_fault');
}
$stalePruneKey = \ViMbAdmin_ResourceClaim::key('stale-cleanup@example.test');
$stalePruneToken = $pruneObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$stalePruneKey]);
maintenanceTaskCheck('per-item stale cleanup fault continues to healthy unrelated prune', $pruneCount === 1 && maintenanceTaskInteger($pruneObserver->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$stalePrune->getId()])) === 1 && maintenanceTaskInteger($pruneObserver->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$healthyPrune->getId()])) === 0);
maintenanceTaskCheck('per-item cleanup fault retains only the stale exact token', is_string($stalePruneToken) && maintenanceTaskInteger($pruneObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$stalePruneKey,$stalePruneToken])) === 1);
$pruneObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$stalePruneKey,$stalePruneToken]);


/** @param array<string,mixed> $options */
function maintenanceResourceContainer(\Doctrine\ORM\EntityManager $manager, object $session, array $options, \Entities\Admin $actor): \ViMbAdmin\Kernel\Container
{
    $bootstrap = new class ($manager, $session, $options) {
        /** @param array<string,mixed> $options */
        public function __construct(private \Doctrine\ORM\EntityManager $manager, private object $session, private array $options)
        {
        }
        public function getResource(string $name): object
        {
            return match ($name) {
                'doctrine2' => $this->manager, 'namespace' => $this->session, default => throw new RuntimeException('unexpected resource')
            };
        }
        /** @return array<string,mixed> */
        public function getOptions(): array
        {
            return $this->options;
        }
    };
    return new \ViMbAdmin\Kernel\Container($bootstrap, new \ViMbAdmin\Kernel\Security\Auth(new \ViMbAdmin\Kernel\Session\MagicPropertyStorage($session), static fn (int $id): \Entities\Admin => $actor));
}

// Every primitive observer uses an independent SQL connection/ORM actor.
$primary = maintenanceTaskManager();
$observer = maintenanceTaskManager()->getConnection();
$waitTimeoutDirectory = sys_get_temp_dir() . '/claim-wait-timeout-' . bin2hex(random_bytes(8));
if (!mkdir($waitTimeoutDirectory, 0700)) {
    throw new RuntimeException('Cannot create wait-timeout negative-control directory');
}
$waitTimeoutChild = proc_open(
    [PHP_BINARY,__FILE__,'claim-wait-timeout-child',$waitTimeoutDirectory],
    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
    $waitTimeoutPipes
);
if (!is_resource($waitTimeoutChild)) {
    throw new RuntimeException('Cannot start wait-timeout negative-control child');
}
$waitTimeoutKey = \ViMbAdmin_ResourceClaim::key('claim-wait-timeout@example.test');
$waitTimeoutBackendPid = null;
$waitTimeoutError = null;
$waitTimeoutStarted = hrtime(true);
try {
    $waitTimeoutBarrier = maintenanceResourceReadLine($waitTimeoutPipes[1], 'Wait-timeout child start barrier');
    if (!preg_match('/^REMOTE ([0-9]+)$/', $waitTimeoutBarrier, $waitTimeoutMatch)) {
        throw new RuntimeException('Wait-timeout child barrier failed: ' . stream_get_contents($waitTimeoutPipes[2]));
    }
    $waitTimeoutBackendPid = (int) $waitTimeoutMatch[1];
    maintenanceResourceStopChild($waitTimeoutChild, $waitTimeoutPipes, 'Wait-timeout claim owner');
    maintenanceTaskCheck(
        'wait-timeout negative control starts with a live backend and durable REMOTE claim',
        maintenanceResourceProcessRunning($waitTimeoutBackendPid)
        && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$waitTimeoutKey]) === 'REMOTE'
    );
    try {
        maintenanceResourceReconcileCrashFixture(
            $waitTimeoutChild,
            $waitTimeoutPipes,
            $waitTimeoutDirectory,
            $observer,
            $waitTimeoutKey,
            $waitTimeoutBackendPid,
            'Forced backend completion barrier',
            20_000_000
        );
    } catch (RuntimeException $error) {
        $waitTimeoutError = $error;
    }
} finally {
    if (is_dir($waitTimeoutDirectory)) {
        maintenanceResourceReconcileCrashFixture(
            $waitTimeoutChild,
            $waitTimeoutPipes,
            $waitTimeoutDirectory,
            $observer,
            $waitTimeoutKey,
            $waitTimeoutBackendPid,
            'Wait-timeout fallback cleanup'
        );
    }
}
maintenanceTaskCheck(
    'wait-for-file timeout is bounded and diagnostic after safe backend, claim, and directory cleanup',
    $waitTimeoutError?->getMessage() === 'Forced backend completion barrier timed out'
    && hrtime(true) - $waitTimeoutStarted < 2_000_000_000
    && !maintenanceResourceProcessRunning($waitTimeoutBackendPid)
    && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$waitTimeoutKey])) === 0
    && !is_dir($waitTimeoutDirectory)
);
$identity = 'claim-primitive@example.test';
$key = \ViMbAdmin_ResourceClaim::key($identity);
maintenanceTaskCheck('canonical physical identity ignores caller case and domain id', $key === \ViMbAdmin_ResourceClaim::key(' CLAIM-PRIMITIVE@EXAMPLE.TEST '));
$claim = \ViMbAdmin_ResourceClaim::acquire($primary->getConnection(), $identity);
try {
    try {
        \ViMbAdmin_ResourceClaim::acquire($observer, strtoupper($identity));
        throw new RuntimeException('conflicting claim unexpectedly acquired');
    } catch (\ViMbAdmin_Exception $expected) {
        maintenanceTaskCheck('same canonical resource rejects an independent owner', str_contains($expected->getMessage(), 'already claimed'));
    }
    $unrelated = \ViMbAdmin_ResourceClaim::acquire($observer, 'unrelated-claim@example.test');
    $unrelated->finish();
    maintenanceTaskCheck('unrelated resource progresses under an active claim', $observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE username=?', ['unrelated-claim@example.test']) === 0);
    $primary->getConnection()->close();
    $primary->getConnection()->fetchOne('SELECT 1');
    maintenanceTaskCheck('primary reconnect does not transfer the independent durable token', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === 1);
    $wrong = clone $claim;
    (new ReflectionProperty(\ViMbAdmin_ResourceClaim::class, 'token'))->setValue($wrong, str_repeat('0', 64));
    try {
        $wrong->release();
        throw new RuntimeException('wrong token unexpectedly released');
    } catch (RuntimeException $expected) {
        maintenanceTaskCheck('wrong token cannot release current ownership', str_contains($expected->getMessage(), 'current ready token'));
    }
} finally {
    $claim->finish();
}
maintenanceTaskCheck('confirmed completion releases its exact claim', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === 0);
try {
    \ViMbAdmin_ResourceClaim::withMailbox($primary, $identity, static function (): void {
        throw new LogicException('confirmed no-start fixture failure');
    });
} catch (LogicException $expected) {
    maintenanceTaskCheck('known no-start failure preserves original exception', $expected->getMessage() === 'confirmed no-start fixture failure');
}
maintenanceTaskCheck('known no-start failure releases safely', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === 0);

$nativeAutocommitManager = maintenanceTaskManager();
$nativeAutocommitParams = $nativeAutocommitManager->getConnection()->getParams();
$nativeAutocommitParams['driverOptions'][PDO::ATTR_AUTOCOMMIT] = false;
$nativeAutocommitManager = new \Doctrine\ORM\EntityManager(
    \Doctrine\DBAL\DriverManager::getConnection($nativeAutocommitParams, $nativeAutocommitManager->getConfiguration()),
    $nativeAutocommitManager->getConfiguration()
);
$nativeAutocommitUser = 'native-autocommit-disabled@example.test';
$nativeAutocommitKey = \ViMbAdmin_ResourceClaim::key($nativeAutocommitUser);
$nativeAutocommitClaim = \ViMbAdmin_ResourceClaim::acquire($nativeAutocommitManager->getConnection(), $nativeAutocommitUser);
try {
    maintenanceTaskCheck('primary native PDO autocommit=false configuration remains unchanged', ($nativeAutocommitManager->getConnection()->getParams()['driverOptions'][PDO::ATTR_AUTOCOMMIT] ?? null) === false);
    maintenanceTaskCheck('independent claim is durable despite primary native PDO autocommit=false', $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$nativeAutocommitKey]) === 'READY');
    $nativeAutocommitClaim->remote(static function () use ($observer, $nativeAutocommitKey): void {
        maintenanceTaskCheck('independent REMOTE phase is durable despite primary native PDO autocommit=false', $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$nativeAutocommitKey]) === 'REMOTE');
    });
    try {
        \ViMbAdmin_ResourceClaim::acquire($observer, $nativeAutocommitUser);
        throw new RuntimeException('native-autocommit contender unexpectedly acquired');
    } catch (\ViMbAdmin_Exception $expected) {
        maintenanceTaskCheck('durable native-autocommit claim rejects an independent contender', str_contains($expected->getMessage(), 'already claimed'));
    }
} finally {
    $nativeAutocommitClaim->finish();
}
maintenanceTaskCheck('native-autocommit claim releases after confirmed completion', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$nativeAutocommitKey])) === 0);


// Remote preflight must preserve a caller-owned healthy transaction untouched.
$primary->getConnection()->beginTransaction();
$primary->getConnection()->fetchOne('SELECT COUNT(*) FROM archive');
$outerLevel = $primary->getConnection()->getTransactionNestingLevel();
$effects = 0;
try {
    \ViMbAdmin_ResourceClaim::withMailbox($primary, $identity, static function ($owner) use (&$effects): void {
        $owner->remote(static function () use (&$effects): void {
            ++$effects;
        });
    });
    throw new RuntimeException('active-transaction remote mutation was accepted');
} catch (LogicException $expected) {
    maintenanceTaskCheck('remote preflight rejects before backend effects and preserves original error', $effects === 0 && str_contains($expected->getMessage(), 'before starting'));
}
maintenanceTaskCheck('remote preflight preserves the healthy outer transaction', $primary->getConnection()->getTransactionNestingLevel() === $outerLevel && $primary->getConnection()->fetchOne('SELECT @@in_transaction') === 1);
maintenanceTaskCheck('no-start outer conflict conservatively retains READY token', $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$key]) === 'READY');
$primary->getConnection()->rollBack();
$token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$key]);
// Fixture-only reconciliation after the callback was proven never invoked.
$observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$key,$token]);
foreach (['', str_repeat('a', 256)] as $invalidIdentity) {
    try {
        \ViMbAdmin_ResourceClaim::key($invalidIdentity);
        throw new RuntimeException('malformed identity accepted');
    } catch (InvalidArgumentException) {
        maintenanceTaskCheck('malformed physical identity rejects before acquisition', true);
    }
}


// A preexisting claim still rejects remote work if the caller later opens a
// primary read view: its independent phase update cannot be finalized there.
$earlyClaim = \ViMbAdmin_ResourceClaim::acquire($primary->getConnection(), $identity);
$primary->getConnection()->beginTransaction();
$primary->getConnection()->fetchOne('SELECT COUNT(*) FROM resource_claim');
$effects = 0;
try {
    $earlyClaim->remote(static function () use (&$effects): void {
        ++$effects;
    });
    throw new RuntimeException('early-claim outer remote mutation was accepted');
} catch (LogicException $expected) {
    maintenanceTaskCheck('early claim rejects old-view remote work before effects', $effects === 0 && str_contains($expected->getMessage(), 'before starting'));
} finally {
    $earlyClaim->finish();
    $primary->getConnection()->rollBack();
}
$token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$key]);
$observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$key,$token]);

foreach ([true, false] as $commit) {
    $primary->getConnection()->beginTransaction();
    \ViMbAdmin_ResourceClaim::withMailbox($primary, $identity, static function (): void {
    });
    maintenanceTaskCheck('outer transaction keeps release invisible to observer', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === 1);
    if ($commit) {
        $primary->getConnection()->commit();
    } else {
        $primary->getConnection()->rollBack();
    }
    maintenanceTaskCheck('outer ' . ($commit ? 'commit releases' : 'rollback retains') . ' ownership', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === ($commit ? 0 : 1));
    if (!$commit) {
        // Disposable-fixture operator reconciliation: no remote command started.
        $token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$key]);
        $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$key,$token]);
    }
}
$observer->executeStatement("CREATE TRIGGER maintenance_claim_release_failure BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username = 'claim-primitive@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected release storage failure'; END IF; END");
$claim = \ViMbAdmin_ResourceClaim::acquire($primary->getConnection(), $identity);
try {
    try {
        $claim->finish();
        throw new RuntimeException('injected release unexpectedly succeeded');
    } catch (\Doctrine\DBAL\Exception $expected) {
        maintenanceTaskCheck('release failure retains durable claim', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$key])) === 1);
    }
} finally {
    $observer->executeStatement('DROP TRIGGER maintenance_claim_release_failure');
    $claim->release(); // Explicit exact-owner no-start reconciliation, not finally retry.
    $claim->close();
}

// Failure after exact-token DELETE but before B1's task flush rolls both back.
[$failedTask] = maintenanceTaskFixture($queued, 'PENDING');
$queued->getConnection()->executeStatement('DELETE FROM queue_runner');
$failedTask->setType(\Entities\MailboxTask::TYPE_ARCHIVE);
$queued->flush();
$backend->pausedBackup = static function (): void {
};
try {
    $runner->runOne($failedTask, static function (?Throwable $error) use ($failedTask): void {
        if ($error !== null) {
            throw $error;
        }
        $failedTask->setStatus('DONE')->appendLog('uncommitted callback dirt');
        throw new RuntimeException('injected terminal flush boundary failure');
    });
    throw new RuntimeException('terminal fixture failure was not reached');
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('terminal rollback reports the injected boundary failure', $expected->getMessage() === 'injected terminal flush boundary failure');
}
$failedKey = \ViMbAdmin_ResourceClaim::key($failedTask->requiredUsername());
maintenanceTaskCheck('terminal rollback restores the resource token', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$failedKey])) === 1);
$queued->flush();
maintenanceTaskCheck('later flush cannot publish terminal callback dirt', $observer->fetchOne('SELECT status FROM mailbox_task WHERE id=?', [$failedTask->getId()]) === 'RUNNING');
$failedToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$failedKey]);
$observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$failedKey,$failedToken]);
$crashDirectory = sys_get_temp_dir() . '/claim-crash-' . bin2hex(random_bytes(8));
if (!mkdir($crashDirectory, 0700)) {
    throw new RuntimeException('Cannot create crash barrier fixture');
}
$child = proc_open(
    [PHP_BINARY,__FILE__,'claim-crash-child',$crashDirectory],
    [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],
    $pipes
);
if (!is_resource($child)) {
    throw new RuntimeException('Cannot start independent claim owner');
}
$backendPid = null;
$crashKey = \ViMbAdmin_ResourceClaim::key('claim-crash@example.test');
try {
    $barrier = maintenanceResourceReadLine($pipes[1], 'Claim crash barrier');
    if (!preg_match('/^REMOTE ([0-9]+)$/', $barrier, $match)) {
        throw new RuntimeException('Claim crash barrier failed: ' . stream_get_contents($pipes[2]));
    }
    $backendPid = (int) $match[1];
    maintenanceResourceStopChild($child, $pipes, 'Claim crash owner');
    maintenanceTaskCheck('owner death does not stop the independent paused backend', posix_kill($backendPid, 0) && !file_exists($crashDirectory . '/stopped'));
    try {
        \ViMbAdmin_ResourceClaim::acquire($observer, 'claim-crash@example.test');
        throw new RuntimeException('crashed owner was stolen while remote work remained active');
    } catch (\ViMbAdmin_Exception $expected) {
        maintenanceTaskCheck('crash retains ownership while remote work remains in flight', str_contains($expected->getMessage(), 'already claimed'));
    }
    maintenanceTaskCheck('crash preserves durable REMOTE phase', $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$crashKey]) === 'REMOTE');
} finally {
    maintenanceResourceReconcileCrashFixture($child, $pipes, $crashDirectory, $observer, $crashKey, $backendPid, 'Claim crash backend completion barrier');
}

// Actual HTTP backend calls exercise every normal direct archive caller. The
// independent server observes the durable REMOTE marker during each request.
$socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
if ($socket === false) {
    throw new RuntimeException('HTTP backend socket allocation failed');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
if (!is_string($address)) {
    throw new RuntimeException('HTTP backend address missing');
}
$backendDirectory = sys_get_temp_dir().'/maintenance-backend-'.bin2hex(random_bytes(6));
mkdir($backendDirectory);
$environment = getenv();
$environment['MAINTENANCE_BACKEND_RECEIPT'] = $backendDirectory;
$server = proc_open([PHP_BINARY,'-S',$address,__FILE__], [0 => ['file','/dev/null','r'],1 => ['file',$backendDirectory.'/server.log','a'],2 => ['file',$backendDirectory.'/server.log','a']], $serverPipes, null, $environment);
if (!is_resource($server)) {
    throw new RuntimeException('HTTP backend failed to start');
}
try {
    $ready = false;
    $deadline = hrtime(true) + 5_000_000_000;
    do {
        $probe = @stream_socket_client('tcp://'.$address, $probeCode, $probeMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
        } else {
            usleep(10000);
        }
    } while (!$ready && hrtime(true) < $deadline);
    maintenanceTaskCheck('isolated HTTP backend starts', $ready);
    $bootstrap->options = ['doveadm' => ['http' => ['url' => 'http://'.$address,'api_key' => 'fixture-key','timeout' => 5]],'queue' => ['autoprune' => ['days' => 90]]];
    require_once __DIR__.'/../application/controllers/McpController.php';
    $mcp = new \McpController(new \ViMbAdmin\Kernel\Container($bootstrap, $auth), new \ViMbAdmin\Kernel\RouteMatch('mcp', 'index', 'McpController', 'indexAction', []));
    $maintenance = new \ViMbAdmin\Kernel\Controller\MaintenanceController(new \ViMbAdmin\Kernel\Container($bootstrap, $auth), new \ViMbAdmin\Kernel\RouteMatch('maintenance', 'prune', 'MaintenanceController', 'pruneAction', []));
    foreach (['deleteAction','restoreAction','mcp-delete','mcp-restore','maintenance-prune','native-recreate','mcp-recreate'] as $caller) {
        $direct->clear();
        $currentAdmin = $direct->find(\Entities\Admin::class, $directActor->getId());
        if (!$currentAdmin instanceof \Entities\Admin) {
            throw new RuntimeException('HTTP actor missing');
        }
        $callerAuth = new \ViMbAdmin\Kernel\Security\Auth($storage, static fn (int $id): \Entities\Admin => $currentAdmin);
        $callerContainer = new \ViMbAdmin\Kernel\Container($bootstrap, $callerAuth);
        $controller = new \ViMbAdmin\Kernel\Controller\ArchiveController($callerContainer, new \ViMbAdmin\Kernel\RouteMatch('archive', 'delete', 'ArchiveController', 'deleteAction', []));
        $mcp = new \McpController($callerContainer, new \ViMbAdmin\Kernel\RouteMatch('mcp', 'index', 'McpController', 'indexAction', []));
        $maintenance = new \ViMbAdmin\Kernel\Controller\MaintenanceController($callerContainer, new \ViMbAdmin\Kernel\RouteMatch('maintenance', 'prune', 'MaintenanceController', 'pruneAction', []));
        $fixture = (new \Entities\Archive())->setUsername('maintenance@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{}')->setMaildirFile('/backup/caller-fixture');
        $direct->getConnection()->executeStatement("DELETE FROM archive WHERE username='maintenance@example.test'");
        $direct->persist($fixture);
        $direct->flush();
        if (in_array($caller, ['native-recreate','mcp-recreate'], true)) {
            $archiveDomain = $direct->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
            if (!$archiveDomain instanceof \Entities\Domain) {
                throw new RuntimeException('recreate domain missing');
            }
            $direct->getConnection()->executeStatement("DELETE FROM mailbox WHERE username='maintenance@example.test'");
            $archiveDomain->setMailboxCount(0);
            $fixture->setDomain($archiveDomain)->setData(json_encode(['mailbox' => ['username' => 'maintenance@example.test','local_part' => 'maintenance','name' => null,'password' => '!','quota' => 0,'active' => true]], JSON_THROW_ON_ERROR));
            $direct->flush();
        }
        if ($caller === 'deleteAction') {
            $savedPost = $_POST;
            $savedServer = $_SERVER;
            try {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST = ['arid' => $fixture->getId(),'csrf' => 'fixture-token'];
                foreach ([true,false] as $enabled) {
                    $toggleResponse = $controller->toggleAutopruneAction();
                    maintenanceTaskCheck('current autoprune toggle publishes its expected setting', $toggleResponse->status === 302 && (bool) $observer->fetchOne('SELECT autoprune FROM archive WHERE id=?', [$fixture->getId()]) === $enabled);
                    maintenanceTaskCheck('completed toggle releases its resource and invokes no backend', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='maintenance@example.test'")) === 0);
                }
            } finally {
                $_POST = $savedPost;
                $_SERVER = $savedServer;
            }
        }
        file_put_contents($backendDirectory.'/requests', '');
        if (str_starts_with($caller, 'mcp-')) {
            $result = (new ReflectionMethod($mcp, '_archiveState'))->invoke($mcp, ['username' => 'maintenance@example.test'], $caller === 'mcp-recreate' ? 'restore' : substr($caller, 4));
            maintenanceTaskCheck('normal RPC archive caller completes: '.$caller, is_array($result));
        } elseif ($caller === 'maintenance-prune') {
            $result = (new ReflectionMethod($maintenance, 'prune'))->invoke($maintenance, [$fixture]);
            maintenanceTaskCheck('normal direct maintenance prune completes', $result === 1);
        } else {
            $savedPost = $_POST;
            $savedServer = $_SERVER;
            try {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST = ['arid' => $fixture->getId(),'csrf' => 'fixture-token'];
                $response = match ($caller) {
                    'deleteAction' => $controller->deleteAction(), 'restoreAction', 'native-recreate' => $controller->restoreAction(), default => throw new RuntimeException('unknown native caller')
                };
            } finally {
                $_POST = $savedPost;
                $_SERVER = $savedServer;
            }
            $messages = $flash->drain();
            maintenanceTaskCheck('normal native archive caller completes: '.$caller, $response->status === 302 && count($messages) >= 1 && $messages[0]->level === 'success');
        }
        $requests = array_filter(explode("\n", (string) file_get_contents($backendDirectory.'/requests')));
        maintenanceTaskCheck('normal archive caller reaches remote backend: '.$caller, count($requests) >= 1);
        foreach ($requests as $request) {
            $record = json_decode($request, true);
            if (!is_array($record) || !is_string($record['command'] ?? null) || !is_array($record['claims'] ?? null)) {
                throw new RuntimeException('Invalid backend observation receipt');
            }
            maintenanceTaskCheck('backend observes exact resource held REMOTE: '.$caller, in_array(['username' => 'maintenance@example.test','phase' => 'REMOTE'], $record['claims'], true));
        }
        if (in_array($caller, ['native-recreate','mcp-recreate'], true)) {
            maintenanceTaskCheck('restore recreates the exact archived identity after its generation fence: '.$caller, $observer->fetchOne("SELECT password FROM mailbox WHERE username='maintenance@example.test'") === '!' && maintenanceTaskInteger($observer->fetchOne('SELECT mailbox_count FROM domain WHERE id=?', [$domainId])) === 1);
        }
        maintenanceTaskCheck('normal archive caller deletes its current row and releases ownership: '.$caller, maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM archive WHERE username='maintenance@example.test'")) === 0 && maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='maintenance@example.test'")) === 0);
    }

    // A parsed command failure confirms that remote work stopped and resets
    // the claim to READY. Cleanup failure must not replace the controller's
    // intended command-error flash and redirect.
    $commandFailureOnly = getenv('MAINTENANCE_R3_COMMAND_FAILURE_ONLY');
    foreach ([
        ['deleteAction','fail-fs-delete','fsDelete'],
        ['restoreAction','fail-restore-sync','sync'],
    ] as [$failureAction,$failureMarker,$failureCommand]) {
        if (is_string($commandFailureOnly) && $commandFailureOnly !== '' && $commandFailureOnly !== $failureAction) {
            continue;
        }
        $direct->clear();
        $commandFailureActor = $direct->find(\Entities\Admin::class, $directActor->getId());
        $commandFailureDomain = $direct->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
        if (!$commandFailureActor instanceof \Entities\Admin || !$commandFailureDomain instanceof \Entities\Domain) {
            throw new RuntimeException('command-failure archive fixtures missing');
        }
        $direct->getConnection()->executeStatement("DELETE FROM archive WHERE username='maintenance@example.test'");
        if (!$direct->getConnection()->fetchOne("SELECT id FROM mailbox WHERE username='maintenance@example.test'")) {
            $direct->getConnection()->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', ['maintenance@example.test','!','maintenance',$domainId]);
        }
        $commandFailureArchive = (new \Entities\Archive())->setUsername('maintenance@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{}')->setMaildirFile('/backup/command-failure')->setDomain($commandFailureDomain);
        $direct->persist($commandFailureArchive);
        $direct->flush();
        $commandFailureController = new \ViMbAdmin\Kernel\Controller\ArchiveController(
            maintenanceResourceContainer($direct, $session, $bootstrap->options, $commandFailureActor),
            new \ViMbAdmin\Kernel\RouteMatch('archive', substr($failureAction, 0, -6), 'ArchiveController', $failureAction, [])
        );
        $savedPost = $_POST;
        $savedServer = $_SERVER;
        $flash->drain();
        touch($backendDirectory.'/'.$failureMarker);
        $observer->executeStatement("CREATE TRIGGER maintenance_command_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected command cleanup failure'; END IF; END");
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['arid' => $commandFailureArchive->getId(),'csrf' => 'fixture-token'];
            $commandFailureResponse = $commandFailureController->$failureAction();
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
            $observer->executeStatement('DROP TRIGGER maintenance_command_cleanup_fault');
            unlink($backendDirectory.'/'.$failureMarker);
        }
        $commandFailureMessages = $flash->drain();
        $commandFailureKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
        $commandFailureToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$commandFailureKey]);
        maintenanceTaskCheck(
            $failureAction.' command failure preserves its original flash and redirect when cleanup fails',
            $commandFailureResponse->status === 302 && count($commandFailureMessages) === 1
            && $commandFailureMessages[0]->level === 'error'
            && str_contains($commandFailureMessages[0]->text, $failureCommand)
            && str_contains($commandFailureMessages[0]->text, 'injected command failure')
        );
        maintenanceTaskCheck(
            $failureAction.' command cleanup fault retains its exact token',
            is_string($commandFailureToken)
            && maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$commandFailureKey,$commandFailureToken])) === 1
        );
        if (is_string($commandFailureToken)) {
            $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$commandFailureKey,$commandFailureToken]);
        }
    }

    foreach ([\Entities\MailboxTask::TYPE_PRUNE,\Entities\MailboxTask::TYPE_ARCHIVE,\Entities\MailboxTask::TYPE_DELETE,\Entities\MailboxTask::TYPE_BACKUP_ORPHAN,\Entities\MailboxTask::TYPE_REPAIR,\Entities\MailboxTask::TYPE_OPTIMIZE,\Entities\MailboxTask::TYPE_QUOTA_RECALC] as $type) {
        $taskManager = maintenanceTaskManager();
        [$callerTask] = maintenanceTaskFixture($taskManager, 'PENDING');
        $taskManager->getConnection()->executeStatement('DELETE FROM queue_runner');
        $username = $type === \Entities\MailboxTask::TYPE_BACKUP_ORPHAN ? 'orphan-caller@example.test' : 'maintenance@example.test';
        $callerTask->setUsername($username)->setType($type);
        $callerDomain = $taskManager->getRepository(\Entities\Domain::class)->findOneBy(['domain' => 'example.test']);
        if (!$callerDomain instanceof \Entities\Domain) {
            throw new RuntimeException('queue caller domain missing');
        }
        $callerTask->setDomain($callerDomain);
        if ($type === \Entities\MailboxTask::TYPE_PRUNE) {
            $taskManager->getConnection()->executeStatement("DELETE FROM archive WHERE username='maintenance@example.test'");
            $pruneArchive = (new \Entities\Archive())->setUsername($username)->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setAutoprune(true)->setArchivedAt(new DateTime('2000-01-01'))->setStatusChangedAt(new DateTime('2000-01-01'))->setMaildirFile('/backup/http-prune-generation')->setData('{}');
            $taskManager->persist($pruneArchive);
            $taskManager->flush();
            $callerTask->setData(json_encode(['archive_id' => $pruneArchive->getId(),'archive_generation' => $pruneArchive->getArchiveGeneration(),'archive_version' => $pruneArchive->getArchiveVersion(),'cutoff' => time() - 90 * 86400,'dest' => $pruneArchive->getMaildirFile()], JSON_THROW_ON_ERROR));
        }
        $taskManager->flush();
        file_put_contents($backendDirectory.'/requests', '');
        if (file_exists($backendDirectory.'/emptied')) {
            unlink($backendDirectory.'/emptied');
        }
        $queueRunner = new \ViMbAdmin_Service_QueueRunner($taskManager, $bootstrap->options);
        $queueRunner->runOne($callerTask, static function (?Throwable $error) use ($callerTask): void {
            if ($error !== null) {
                throw $error;
            }
            $callerTask->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
        });
        $commands = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($backendDirectory.'/requests'))) as $request) {
            $record = json_decode($request, true);
            if (!is_array($record) || !is_string($record['command'] ?? null) || !is_array($record['claims'] ?? null)) {
                throw new RuntimeException('Invalid backend observation receipt');
            }
            $commands[] = $record['command'];
            if (in_array($record['command'], ['backup','mailbox delete','fsDelete','force-resync','index','purge','quota recalc','auth cache flush'], true)) {
                maintenanceTaskCheck('queued mutation holds exact REMOTE resource: '.$type.'/'.$record['command'], in_array(['username' => $username,'phase' => 'REMOTE'], $record['claims'], true));
            }
        }
        $expected = match($type) {
            \Entities\MailboxTask::TYPE_REPAIR, \Entities\MailboxTask::TYPE_OPTIMIZE => ['force-resync','index','purge','quota recalc'],
            \Entities\MailboxTask::TYPE_QUOTA_RECALC => ['quota recalc'],
            default => null,
        };
        maintenanceTaskCheck('normal queued caller completes with command order: '.$type, $callerTask->getStatus() === 'DONE' && count($commands) > 0 && ($expected === null || $commands === $expected));
        maintenanceTaskCheck('normal queued caller atomically releases exact resource: '.$type, maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE username=?', [$username])) === 0);
    }

    $reconnectManager = maintenanceTaskManager();
    [$reconnectTask] = maintenanceTaskFixture($reconnectManager, 'PENDING');
    $reconnectManager->getConnection()->executeStatement('DELETE FROM queue_runner');
    $reconnectTask->setUsername('paused-reconnect@example.test')->setType(\Entities\MailboxTask::TYPE_QUOTA_RECALC);
    $reconnectManager->flush();
    $oldConnection = maintenanceTaskInteger($reconnectManager->getConnection()->fetchOne('SELECT CONNECTION_ID()'));
    touch($backendDirectory.'/pause');
    $disconnected = false;
    $httpRunner = new MaintenanceHttpProgressRunner($reconnectManager, $bootstrap->options);
    $httpRunner->backendOptions = $bootstrap->options;
    $httpRunner->whileWaiting = static function () use ($reconnectManager, $observer, $backendDirectory, &$disconnected): void {
        if ($disconnected || !file_exists($backendDirectory.'/paused')) {
            return;
        }
        $reconnectManager->getConnection()->close();
        $disconnected = true;
        maintenanceTaskCheck('paused remote request keeps durable REMOTE claim after primary disconnect', $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [\ViMbAdmin_ResourceClaim::key('paused-reconnect@example.test')]) === 'REMOTE');
        try {
            \ViMbAdmin_ResourceClaim::acquire($observer, 'paused-reconnect@example.test');
            throw new RuntimeException('paused remote owner was stolen after primary disconnect');
        } catch (\ViMbAdmin_Exception $expected) {
            maintenanceTaskCheck('independent contender rejects while primary is disconnected and remote backend paused', str_contains($expected->getMessage(), 'already claimed'));
        }
        touch($backendDirectory.'/resume');
    };
    try {
        $httpRunner->runOne($reconnectTask, static function (?Throwable $error) use ($reconnectTask): void {
            if ($error !== null) {
                throw $error;
            } $reconnectTask->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
        });
        $newConnection = maintenanceTaskInteger($reconnectManager->getConnection()->fetchOne('SELECT CONNECTION_ID()'));
        maintenanceTaskCheck('actual paused HTTP request completes through a new primary connection', $disconnected && $newConnection !== $oldConnection && $reconnectTask->getStatus() === 'DONE');
        maintenanceTaskCheck('reconnected publication releases exact resource only after remote completion', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='paused-reconnect@example.test'")) === 0);
    } finally {
        touch($backendDirectory.'/resume');
        foreach (['pause','paused','resume'] as $marker) {
            if (file_exists($backendDirectory.'/'.$marker)) {
                unlink($backendDirectory.'/'.$marker);
            }
        }
    }

    $foundRowsBase = maintenanceTaskManager();
    $foundRowsParams = $foundRowsBase->getConnection()->getParams();
    $foundRowsParams['driverOptions'][PDO::MYSQL_ATTR_FOUND_ROWS] = true;
    $foundRowsManager = new \Doctrine\ORM\EntityManager(
        \Doctrine\DBAL\DriverManager::getConnection($foundRowsParams, $foundRowsBase->getConfiguration()),
        $foundRowsBase->getConfiguration()
    );
    [$foundRowsTask] = maintenanceTaskFixture($foundRowsManager, 'PENDING');
    $foundRowsManager->getConnection()->executeStatement('DELETE FROM queue_runner');
    $foundRowsTask->setUsername('found-rows-latched@example.test')->setType(\Entities\MailboxTask::TYPE_ARCHIVE);
    $foundRowsTask->setDomain($foundRowsManager->getRepository(\Entities\Domain::class)->findOneBy(['domain' => 'example.test']));
    $foundRowsManager->flush();
    file_put_contents($backendDirectory.'/requests', '');
    touch($backendDirectory.'/pause-prior-command');
    touch($backendDirectory.'/prior-stall');
    $foundRowsError = null;
    $foundRowsTimeoutError = null;
    $foundRowsPriorPid = null;
    $foundRowsKey = \ViMbAdmin_ResourceClaim::key('found-rows-latched@example.test');
    $foundRowsToken = null;
    try {
        (new \ViMbAdmin_Service_QueueRunner($foundRowsManager, $bootstrap->options))->runOne($foundRowsTask, static function (?Throwable $error) use ($foundRowsTask, &$foundRowsError): void {
            $foundRowsError = $error;
            $foundRowsTask->setStatus($error === null ? 'DONE' : 'FAILED')->setRunner(null)->setFinishedAt(new DateTime());
        });
        $foundRowsRequests = array_values(array_filter(explode("\n", trim((string) file_get_contents($backendDirectory.'/requests')))));
        $foundRowsCommands = [];
        foreach ($foundRowsRequests as $request) {
            $record = json_decode($request, true);
            if (!is_array($record) || !is_string($record['command'] ?? null)) {
                throw new RuntimeException('Invalid FOUND_ROWS backend observation receipt');
            }
            $foundRowsCommands[] = $record['command'];
        }
        $foundRowsToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$foundRowsKey]);
        maintenanceTaskCheck('FOUND_ROWS queued caller stops after its paused uncertain command with zero later effects', $foundRowsError instanceof Throwable && $foundRowsTask->getStatus() === 'FAILED' && $foundRowsCommands === ['backup','quota recalc'] && file_exists($backendDirectory.'/prior-paused') && !file_exists($backendDirectory.'/prior-complete'));
        maintenanceTaskCheck('FOUND_ROWS queued caller retains its exact REMOTE claim', is_string($foundRowsToken) && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=? AND owner_token=?', [$foundRowsKey,$foundRowsToken]) === 'REMOTE');
        try {
            \ViMbAdmin_ResourceClaim::acquire($observer, 'found-rows-latched@example.test');
            throw new RuntimeException('FOUND_ROWS contender unexpectedly acquired');
        } catch (\ViMbAdmin_Exception $expected) {
            maintenanceTaskCheck('FOUND_ROWS contender rejects while prior backend actor remains paused', str_contains($expected->getMessage(), 'already claimed'));
        }
    } finally {
        touch($backendDirectory.'/resume-prior');
        $recordedPriorPid = trim((string) file_get_contents($backendDirectory.'/prior-pid'));
        $foundRowsPriorPid = ctype_digit($recordedPriorPid) ? (int) $recordedPriorPid : null;
        if (!is_string($foundRowsToken)) {
            throw new RuntimeException('FOUND_ROWS retained token missing before reconciliation');
        }
        try {
            maintenanceResourceReconcilePriorBackend($backendDirectory, $observer, $foundRowsKey, $foundRowsToken, 1_000_000);
        } catch (RuntimeException $error) {
            $foundRowsTimeoutError = $error;
        }
    }
    maintenanceTaskCheck(
        'FOUND_ROWS timeout stops its prior backend before exact-token and marker cleanup',
        $foundRowsTimeoutError instanceof RuntimeException
        && str_contains($foundRowsTimeoutError->getMessage(), 'timed out')
        && $foundRowsPriorPid !== null && !maintenanceResourceProcessRunning($foundRowsPriorPid)
        && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$foundRowsKey])) === 0
        && !file_exists($backendDirectory.'/prior-pid') && !file_exists($backendDirectory.'/resume-prior')
    );

    $uncertainDirectory = sys_get_temp_dir().'/found-rows-uncertain-'.bin2hex(random_bytes(6));
    if (!mkdir($uncertainDirectory)) {
        throw new RuntimeException('Cannot create FOUND_ROWS uncertain-stop fixture');
    }
    $uncertainUser = 'found-rows-uncertain@example.test';
    $uncertainKey = \ViMbAdmin_ResourceClaim::key($uncertainUser);
    $uncertainToken = bin2hex(random_bytes(16));
    $observer->executeStatement(
        "INSERT INTO resource_claim (resource_key,owner_token,username,phase,created_at) VALUES (?,?,?,'REMOTE',NOW())",
        [$uncertainKey,$uncertainToken,$uncertainUser]
    );
    file_put_contents($uncertainDirectory.'/prior-pid', 'invalid');
    touch($uncertainDirectory.'/prior-stall');
    $uncertainError = null;
    try {
        maintenanceResourceReconcilePriorBackend($uncertainDirectory, $observer, $uncertainKey, $uncertainToken, 1_000_000);
    } catch (RuntimeException $error) {
        $uncertainError = $error;
    }
    maintenanceTaskCheck(
        'FOUND_ROWS uncertain stop retains REMOTE ownership and fixture markers',
        $uncertainError instanceof RuntimeException && str_contains($uncertainError->getMessage(), 'PID is uncertain')
        && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=? AND owner_token=?', [$uncertainKey,$uncertainToken]) === 'REMOTE'
        && is_dir($uncertainDirectory) && file_exists($uncertainDirectory.'/prior-pid')
        && file_exists($uncertainDirectory.'/prior-stall') && file_exists($uncertainDirectory.'/resume-prior')
    );
    $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='REMOTE'", [$uncertainKey,$uncertainToken]);
    foreach (glob($uncertainDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($uncertainDirectory);

    foreach ([\Entities\MailboxTask::TYPE_REPAIR,\Entities\MailboxTask::TYPE_OPTIMIZE,\Entities\MailboxTask::TYPE_QUOTA_RECALC] as $type) {
        foreach ([false,true] as $ambiguous) {
            $taskManager = maintenanceTaskManager();
            [$callerTask] = maintenanceTaskFixture($taskManager, 'PENDING');
            $taskManager->getConnection()->executeStatement('DELETE FROM queue_runner');
            $callerTask->setType($type);
            $taskManager->flush();
            file_put_contents($backendDirectory.'/requests', '');
            $blocking = null;
            if ($ambiguous) {
                touch($backendDirectory.'/ambiguous');
            } else {
                $blocking = \ViMbAdmin_ResourceClaim::acquire($observer, 'maintenance@example.test');
            }
            $received = null;
            try {
                (new \ViMbAdmin_Service_QueueRunner($taskManager, $bootstrap->options))->runOne($callerTask, static function (?Throwable $error) use ($callerTask, &$received): void {
                    $received = $error;
                    $callerTask->setStatus('FAILED')->setRunner(null)->setFinishedAt(new DateTime());
                });
                maintenanceTaskCheck(($ambiguous ? 'ambiguous remote' : 'same identity conflict').' preserves queued error mapping: '.$type, $received instanceof \ViMbAdmin_Exception && $callerTask->getStatus() === 'FAILED');
                maintenanceTaskCheck(($ambiguous ? 'ambiguous remote retains' : 'conflicting owner keeps').' durable resource: '.$type, maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='maintenance@example.test'")) === 1);
                if (!$ambiguous) {
                    maintenanceTaskCheck('conflicting queued caller performs zero backend work: '.$type, file_get_contents($backendDirectory.'/requests') === '');
                }
            } finally {
                if ($blocking !== null) {
                    $blocking->finish();
                }
                if ($ambiguous) {
                    unlink($backendDirectory.'/ambiguous');
                    $resourceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
                    $resourceToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$resourceKey]);
                    // Completed synchronous HTTP fixture returned malformed data;
                    // backend completion is proven before exact-token recovery.
                    $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='REMOTE'", [$resourceKey,$resourceToken]);
                }
            }
        }
    }


    // Actual native and maintenance error mappings after a completed malformed reply.
    foreach (['restoreAction','maintenance-prune'] as $failureCaller) {
        $direct->clear();
        $failureActor = $direct->find(\Entities\Admin::class, $directActor->getId());
        if (!$failureActor instanceof \Entities\Admin) {
            throw new RuntimeException('failure actor missing');
        }
        $failureContainer = maintenanceResourceContainer($direct, $session, $bootstrap->options, $failureActor);
        $failureController = new \ViMbAdmin\Kernel\Controller\ArchiveController($failureContainer, new \ViMbAdmin\Kernel\RouteMatch('archive', 'restore', 'ArchiveController', 'restoreAction', []));
        $failureMaintenance = new \ViMbAdmin\Kernel\Controller\MaintenanceController($failureContainer, new \ViMbAdmin\Kernel\RouteMatch('maintenance', 'prune', 'MaintenanceController', 'pruneAction', []));
        $failureArchive = (new \Entities\Archive())->setUsername('maintenance@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{}')->setMaildirFile('/backup/failure-fixture');
        $direct->getConnection()->executeStatement("DELETE FROM archive WHERE username='maintenance@example.test'");
        $failureDomain = $direct->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
        if (!$failureDomain instanceof \Entities\Domain) {
            throw new RuntimeException('failure archive domain missing');
        }
        $failureArchive->setDomain($failureDomain)->setData(json_encode(['mailbox' => ['username' => 'maintenance@example.test','local_part' => 'maintenance','name' => null,'password' => '!','quota' => 0,'active' => true]], JSON_THROW_ON_ERROR));
        $direct->persist($failureArchive);
        $direct->flush();
        $flash->drain();
        file_put_contents($backendDirectory.'/requests', '');
        touch($backendDirectory.'/ambiguous-fs-delete');
        try {
            if ($failureCaller === 'restoreAction') {
                $savedPost = $_POST;
                $savedServer = $_SERVER;
                try {
                    $_SERVER['REQUEST_METHOD'] = 'POST';
                    $_POST = ['arid' => $failureArchive->getId(),'csrf' => 'fixture-token'];
                    $failureResponse = $failureController->restoreAction();
                } finally {
                    $_POST = $savedPost;
                    $_SERVER = $savedServer;
                }
                $failureMessages = $flash->drain();
                maintenanceTaskCheck('native restore uncertainty returns an error without misleading success', $failureResponse->status === 302 && count($failureMessages) === 1 && $failureMessages[0]->level === 'error' && str_contains($failureMessages[0]->text, 'completion is uncertain'));
            } else {
                $failureCount = (new ReflectionMethod($failureMaintenance, 'prune'))->invoke($failureMaintenance, [$failureArchive]);
                maintenanceTaskCheck('maintenance uncertainty does not count an unfinished prune', $failureCount === 0);
            }
            $resourceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
            maintenanceTaskCheck('actual failed caller preserves archive and retained REMOTE ownership: '.$failureCaller, maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$failureArchive->getId()])) === 1 && $observer->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$resourceKey]) === 'REMOTE');
            $completedRequests = explode("\n", trim((string)file_get_contents($backendDirectory.'/requests')));
            maintenanceTaskCheck('failed caller reached its actual synchronous HTTP fsDelete', str_contains(end($completedRequests), 'fsDelete'));
        } finally {
            unlink($backendDirectory.'/ambiguous-fs-delete');
            // The synchronous local HTTP response and its caller have finished.
            $resourceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
            $resourceToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$resourceKey]);
            $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='REMOTE'", [$resourceKey,$resourceToken]);
        }
    }
    // A held owner rejects direct restore before any backend request.
    $heldOwner = \ViMbAdmin_ResourceClaim::acquire($direct->getConnection(), 'maintenance@example.test');
    $flash->drain();
    file_put_contents($backendDirectory.'/requests', '');
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $failureArchive->getId(),'csrf' => 'fixture-token'];
        $heldResponse = $failureController->restoreAction();
        $heldMessages = $flash->drain();
        maintenanceTaskCheck('native restore conflict maps its original error before backend effects', $heldResponse->status === 302 && count($heldMessages) === 1 && $heldMessages[0]->level === 'error' && str_contains($heldMessages[0]->text, 'already claimed') && file_get_contents($backendDirectory.'/requests') === '');
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
        $heldOwner->finish();
    }
    // Actual RPC alias deletion executes the same current-row generation fence.
    $direct->clear();
    $rpcDomain = $direct->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
    if (!$rpcDomain instanceof \Entities\Domain) {
        throw new RuntimeException('RPC alias domain missing');
    }
    $rpcAlias = (new \Entities\Alias())->setAddress('rpc-coverage@example.test')->setGoto('kept@example.test')->setDomain($rpcDomain)->setActive(true)->setCreated(new DateTime());
    $rpcDomain->increaseAliasCount();
    $direct->persist($rpcAlias);
    $direct->flush();
    $rpcActor = $direct->find(\Entities\Admin::class, $directActor->getId());
    if (!$rpcActor instanceof \Entities\Admin) {
        throw new RuntimeException('RPC actor missing');
    }
    $rpcController = new \McpController(maintenanceResourceContainer($direct, $session, $bootstrap->options, $rpcActor), new \ViMbAdmin\Kernel\RouteMatch('mcp', 'index', 'McpController', 'indexAction', []));
    $rpcResult = (new ReflectionMethod($rpcController, '_aliasDelete'))->invoke($rpcController, ['address' => 'rpc-coverage@example.test']);
    maintenanceTaskCheck('actual RPC alias deletion publishes only the fenced current row', $rpcResult === ['deleted' => true,'address' => 'rpc-coverage@example.test'] && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM alias WHERE id=?', [$rpcAlias->getId()])) === 0);

    // A legitimate read-only queued metadata writer may advance Archive version
    // after the first fence; the second restore fence must reject before create.
    $direct->getConnection()->executeStatement("DELETE FROM mailbox WHERE username='maintenance@example.test'");
    $direct->getConnection()->executeStatement('UPDATE domain SET mailbox_count=0 WHERE id=?', [$domainId]);
    $raceArchiveId = maintenanceTaskInteger($failureArchive->getId());
    $raceLog = new class ($raceArchiveId) extends \Psr\Log\AbstractLogger {
        public bool $armed = true;
        public function __construct(private int $archiveId)
        {
        }
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $sql = $context['sql'] ?? null;
            if (!$this->armed || !is_string($sql) || !str_contains($sql, 'FROM mailbox ') || !str_contains($sql, 'username')) {
                return;
            }
            $this->armed = false;
            $writer = maintenanceTaskManager();
            $task = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_MEASURE_SIZE)->setUsername('maintenance@example.test')->setStatus('PENDING')->setCreatedAt(new DateTime())->setData('{}')->setLog('metadata race');
            $writer->persist($task);
            $writer->flush();
            $runner = new MaintenanceArchiveRunner($writer, ['queue' => ['runner' => ['max_concurrent' => 1]]]);
            $runner->backend = new MaintenanceArchiveBackend();
            $result = $runner->runOne($task, static function (?Throwable $error) use ($task): void {
                if ($error !== null) {
                    throw $error;
                }$task->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
            });
            maintenanceTaskCheck('independent actual read-only task updates archive metadata during the restore fence', $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && maintenanceTaskInteger($writer->getConnection()->fetchOne('SELECT maildir_size FROM archive WHERE id=?', [$this->archiveId])) === 123);
        }
    };
    $raceConfiguration = clone $direct->getConfiguration();
    $raceConfiguration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($raceLog)]);
    $raceManager = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection($direct->getConnection()->getParams(), $raceConfiguration), $raceConfiguration);
    $raceActor = $raceManager->find(\Entities\Admin::class, $directActor->getId());
    if (!$raceActor instanceof \Entities\Admin) {
        throw new RuntimeException('race actor missing');
    }
    $raceController = new \ViMbAdmin\Kernel\Controller\ArchiveController(maintenanceResourceContainer($raceManager, $session, $bootstrap->options, $raceActor), new \ViMbAdmin\Kernel\RouteMatch('archive', 'restore', 'ArchiveController', 'restoreAction', []));
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    $flash->drain();
    file_put_contents($backendDirectory.'/requests', '');
    $observer->executeStatement("CREATE TRIGGER maintenance_restore_nested_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected nested restore cleanup failure'; END IF; END");
    try {
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['arid' => $raceArchiveId,'csrf' => 'fixture-token'];
            $raceResponse = $raceController->restoreAction();
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
        $raceMessages = $flash->drain();
        $raceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
        $raceToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$raceKey]);
        maintenanceTaskCheck('nested restore guard preserves its original redirect and flash when cleanup fails', !$raceLog->armed && $raceResponse->status === 302 && count($raceMessages) === 1 && $raceMessages[0]->level === 'error' && str_contains($raceMessages[0]->text, 'Archive changed'));
        maintenanceTaskCheck('nested restore guard cleanup fault retains its exact token', is_string($raceToken) && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$raceKey,$raceToken])) === 1);
        maintenanceTaskCheck('nested restore fence preserves missing mailbox and current archive before remote work', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM mailbox WHERE username='maintenance@example.test'")) === 0 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$raceArchiveId])) === 1 && file_get_contents($backendDirectory.'/requests') === '');
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
        $observer->executeStatement('DROP TRIGGER maintenance_restore_nested_cleanup_fault');
        $raceKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
        $raceToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$raceKey]);
        if (is_string($raceToken)) {
            $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$raceKey,$raceToken]);
        }
    }

    // With an existing mailbox, the later guard is the first restore fence.
    // Advance the loaded Archive only after the mailbox lookup and inject a
    // cleanup fault to prove that the original rejection response survives.
    $secondGuardSetup = maintenanceTaskManager();
    $secondGuardDomain = $secondGuardSetup->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
    if (!$secondGuardDomain instanceof \Entities\Domain) {
        throw new RuntimeException('second restore guard domain missing');
    }
    $secondGuardMailbox = (new \Entities\Mailbox())->setUsername('maintenance@example.test')->setLocalPart('maintenance')->setQuota(0)->setPassword('!')->setActive(true)->setDomain($secondGuardDomain)->setCreated(new DateTime());
    $secondGuardSetup->persist($secondGuardMailbox);
    $secondGuardSetup->flush();
    $secondGuardLog = new class ($raceArchiveId) extends \Psr\Log\AbstractLogger {
        public bool $armed = true;
        public function __construct(private int $archiveId)
        {
        }
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $sql = $context['sql'] ?? null;
            if (!$this->armed || !is_string($sql) || !str_contains($sql, 'FROM mailbox ') || !str_contains($sql, 'username')) {
                return;
            }
            $this->armed = false;
            maintenanceTaskManager()->getConnection()->executeStatement('UPDATE archive SET archive_version=archive_version+1 WHERE id=?', [$this->archiveId]);
        }
    };
    $secondGuardConfiguration = clone $direct->getConfiguration();
    $secondGuardConfiguration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($secondGuardLog)]);
    $secondGuardManager = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection($direct->getConnection()->getParams(), $secondGuardConfiguration), $secondGuardConfiguration);
    $secondGuardActor = $secondGuardManager->find(\Entities\Admin::class, $directActor->getId());
    if (!$secondGuardActor instanceof \Entities\Admin) {
        throw new RuntimeException('second restore guard actor missing');
    }
    $secondGuardController = new \ViMbAdmin\Kernel\Controller\ArchiveController(maintenanceResourceContainer($secondGuardManager, $session, $bootstrap->options, $secondGuardActor), new \ViMbAdmin\Kernel\RouteMatch('archive', 'restore', 'ArchiveController', 'restoreAction', []));
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    $flash->drain();
    $observer->executeStatement("CREATE TRIGGER maintenance_restore_later_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected later restore cleanup failure'; END IF; END");
    try {
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['arid' => $raceArchiveId,'csrf' => 'fixture-token'];
            $secondGuardResponse = $secondGuardController->restoreAction();
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
        $secondGuardMessages = $flash->drain();
        $secondGuardKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
        $secondGuardToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$secondGuardKey]);
        maintenanceTaskCheck('later restore guard preserves its original redirect and flash when cleanup fails', !$secondGuardLog->armed && $secondGuardResponse->status === 302 && count($secondGuardMessages) === 1 && $secondGuardMessages[0]->level === 'error' && str_contains($secondGuardMessages[0]->text, 'Archive changed'));
        maintenanceTaskCheck('later restore guard cleanup fault retains its exact token', is_string($secondGuardToken) && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=? AND owner_token=?', [$secondGuardKey,$secondGuardToken])) === 1);
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
        $observer->executeStatement('DROP TRIGGER maintenance_restore_later_cleanup_fault');
        $secondGuardKey = \ViMbAdmin_ResourceClaim::key('maintenance@example.test');
        $secondGuardToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$secondGuardKey]);
        if (is_string($secondGuardToken)) {
            $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$secondGuardKey,$secondGuardToken]);
        }
    }

    // A REPAIR worker may claim the newly published task immediately. It must
    // observe the restore claim already released, never fail on self-conflict.
    $publicationSetup = maintenanceTaskManager();
    $publicationDomain = $publicationSetup->find(\Entities\Domain::class, maintenanceTaskInteger($domainId));
    $publicationActor = $publicationSetup->find(\Entities\Admin::class, $directActor->getId());
    if (!$publicationDomain instanceof \Entities\Domain || !$publicationActor instanceof \Entities\Admin) {
        throw new RuntimeException('restore publication fixtures missing');
    }
    $publicationUser = 'restore-publication@example.test';
    $publicationMailbox = (new \Entities\Mailbox())->setUsername($publicationUser)->setLocalPart('restore-publication')->setQuota(0)->setPassword('!')->setActive(true)->setDomain($publicationDomain)->setCreated(new DateTime());
    $publicationArchive = (new \Entities\Archive())->setUsername($publicationUser)->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setArchivedAt(new DateTime())->setStatusChangedAt(new DateTime())->setData('{}')->setMaildirFile('/backup/restore-publication')->setDomain($publicationDomain);
    $publicationSetup->persist($publicationMailbox);
    $publicationSetup->persist($publicationArchive);
    $publicationSetup->flush();
    $publicationArchiveId = maintenanceTaskInteger($publicationArchive->getId());
    $publicationLog = new class ($publicationUser, $bootstrap->options) extends \Psr\Log\AbstractLogger {
        public bool $armed = true;
        public ?string $taskStatus = null;
        public ?int $runResult = null;
        /** @param array<string,mixed> $options */
        public function __construct(private string $username, private array $options)
        {
        }
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $sql = $context['sql'] ?? null;
            if (!$this->armed || !is_string($sql) || !str_contains($sql, 'FROM mailbox_task') || !str_contains($sql, 'id')) {
                return;
            }
            $this->armed = false;
            $worker = maintenanceTaskManager();
            $task = $worker->getRepository(\Entities\MailboxTask::class)->findOneBy(['username' => $this->username,'type' => \Entities\MailboxTask::TYPE_REPAIR,'status' => \Entities\MailboxTask::STATUS_PENDING]);
            if (!$task instanceof \Entities\MailboxTask) {
                throw new RuntimeException('published restore REPAIR task missing');
            }
            $this->runResult = (new \ViMbAdmin_Service_QueueRunner($worker, $this->options))->runOne($task, static function (?Throwable $error) use ($task): void {
                $task->setStatus($error === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)->setRunner(null)->setFinishedAt(new DateTime());
            });
            $this->taskStatus = $task->getStatus();
        }
    };
    $publicationConfiguration = clone $direct->getConfiguration();
    $publicationConfiguration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($publicationLog)]);
    $publicationManager = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection($direct->getConnection()->getParams(), $publicationConfiguration), $publicationConfiguration);
    $publicationControllerActor = $publicationManager->find(\Entities\Admin::class, $publicationActor->getId());
    if (!$publicationControllerActor instanceof \Entities\Admin) {
        throw new RuntimeException('restore publication controller actor missing');
    }
    $publicationController = new \ViMbAdmin\Kernel\Controller\ArchiveController(maintenanceResourceContainer($publicationManager, $session, $bootstrap->options, $publicationControllerActor), new \ViMbAdmin\Kernel\RouteMatch('archive', 'restore', 'ArchiveController', 'restoreAction', []));
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    $flash->drain();
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $publicationArchiveId,'csrf' => 'fixture-token'];
        $publicationResponse = $publicationController->restoreAction();
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
    }
    $publicationMessages = $flash->drain();
    maintenanceTaskCheck('restore releases its exact claim before publishing REPAIR', !$publicationLog->armed && $publicationLog->runResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $publicationLog->taskStatus === \Entities\MailboxTask::STATUS_DONE && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [\ViMbAdmin_ResourceClaim::key($publicationUser)])) === 0);
    maintenanceTaskCheck('concurrent published REPAIR does not terminally fail on the restore claim', $publicationResponse->status === 302 && count($publicationMessages) === 1 && $publicationMessages[0]->level !== 'error' && maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM mailbox_task WHERE username=? AND type=? AND status='FAILED'", [$publicationUser,\Entities\MailboxTask::TYPE_REPAIR])) === 0);

    // A database flush failure in an otherwise current toggle preserves the row.
    $toggleManager = maintenanceTaskManager();
    $toggleActor = $toggleManager->find(\Entities\Admin::class, $directActor->getId());
    if (!$toggleActor instanceof \Entities\Admin) {
        throw new RuntimeException('toggle actor missing');
    }
    $toggleArchive = $toggleManager->getRepository(\Entities\Archive::class)->findOneBy(['username' => 'maintenance@example.test']);
    if (!$toggleArchive instanceof \Entities\Archive) {
        throw new RuntimeException('toggle archive missing');
    }
    $toggleBefore = $observer->fetchAssociative('SELECT autoprune,archive_version FROM archive WHERE id=?', [$toggleArchive->getId()]);
    $toggleController = new \ViMbAdmin\Kernel\Controller\ArchiveController(maintenanceResourceContainer($toggleManager, $session, $bootstrap->options, $toggleActor), new \ViMbAdmin\Kernel\RouteMatch('archive', 'toggle-autoprune', 'ArchiveController', 'toggleAutopruneAction', []));
    $observer->executeStatement("CREATE TRIGGER maintenance_toggle_flush_fault BEFORE UPDATE ON archive FOR EACH ROW BEGIN IF OLD.username='maintenance@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected toggle persistence failure'; END IF; END");
    $savedPost = $_POST;
    $savedServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['arid' => $toggleArchive->getId(),'csrf' => 'fixture-token'];
        try {
            $toggleController->toggleAutopruneAction();
            throw new RuntimeException('toggle persistence fault unexpectedly accepted');
        } catch (\Doctrine\DBAL\Exception $expected) {
            maintenanceTaskCheck('native toggle preserves its actual flush failure', str_contains($expected->getMessage(), 'injected toggle persistence failure'));
        }
        maintenanceTaskCheck('failed current toggle rolls back metadata and safely releases no-start ownership', $observer->fetchAssociative('SELECT autoprune,archive_version FROM archive WHERE id=?', [$toggleArchive->getId()]) === $toggleBefore && maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='maintenance@example.test'")) === 0);
    } finally {
        $_POST = $savedPost;
        $_SERVER = $savedServer;
        $observer->executeStatement('DROP TRIGGER maintenance_toggle_flush_fault');
    }

} finally {
    proc_terminate($server);
    proc_close($server);
    foreach (glob($backendDirectory.'/*') ?: [] as $file) {
        unlink($file);
    } rmdir($backendDirectory);
}


// The actual maintenance raw writer advances the same optimistic generation.
$direct->clear();
$direct->getConnection()->executeStatement("INSERT INTO domain (domain,created,active,alias_count,mailbox_count) VALUES ('disabled-generation.test',NOW(),0,1,0)");
$disabledDomainId = maintenanceTaskInteger($direct->getConnection()->lastInsertId());
$direct->getConnection()->executeStatement('INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?)', ['list@disabled-generation.test','kept@example.test',$disabledDomainId]);
$disabledAliasId = maintenanceTaskInteger($direct->getConnection()->lastInsertId());
$disabledSnapshotManager = maintenanceTaskManager();
$disabledSnapshot = $disabledSnapshotManager->find(\Entities\Alias::class, $disabledAliasId);
if (!$disabledSnapshot instanceof \Entities\Alias) {
    throw new RuntimeException('disabled alias fixture missing');
}
$disabledVersion = $disabledSnapshot->getAliasVersion();
$disabledActor = $direct->find(\Entities\Admin::class, $directActor->getId());
if (!$disabledActor instanceof \Entities\Admin) {
    throw new RuntimeException('maintenance actor missing');
}
$disableAuth = new \ViMbAdmin\Kernel\Security\Auth($storage, static fn (int $id): \Entities\Admin => $disabledActor);
$disableController = new \ViMbAdmin\Kernel\Controller\MaintenanceController(new \ViMbAdmin\Kernel\Container($bootstrap, $disableAuth), new \ViMbAdmin\Kernel\RouteMatch('maintenance', 'disable-inactive', 'MaintenanceController', 'disableInactiveAction', []));
$savedPost = $_POST;
$savedServer = $_SERVER;
try {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'fixture-token'];
    $disableResponse = $disableController->disableInactiveAction();
} finally {
    $_POST = $savedPost;
    $_SERVER = $savedServer;
}
maintenanceTaskCheck('actual maintenance writer disables the alias and advances its optimistic generation', $disableResponse->status === 302 && maintenanceTaskInteger($observer->fetchOne('SELECT active FROM alias WHERE id=?', [$disabledAliasId])) === 0 && maintenanceTaskInteger($observer->fetchOne('SELECT alias_version FROM alias WHERE id=?', [$disabledAliasId])) === $disabledVersion + 1);
$disabledSnapshot->setGoto('stale-after-disable@example.test');
try {
    $disabledSnapshotManager->flush();
    throw new RuntimeException('stale raw-writer snapshot unexpectedly published');
} catch (\Doctrine\ORM\OptimisticLockException $expected) {
    maintenanceTaskCheck('raw writer generation prevents a stale recipient overwrite', $observer->fetchOne('SELECT goto FROM alias WHERE id=?', [$disabledAliasId]) === 'kept@example.test');
}

// Real Domain service entry point: the bulk DQL writers share the same policy.
$bulkManager = maintenanceTaskManager();
$bulkConnection = $bulkManager->getConnection();
$bulkConnection->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('empty-resource-domain.test',NOW(),0,0)");
$emptyDomainId = maintenanceTaskInteger($bulkConnection->lastInsertId());
$emptyDomain = $bulkManager->find(\Entities\Domain::class, $emptyDomainId);
if (!$emptyDomain instanceof \Entities\Domain) {
    throw new RuntimeException('empty resource domain fixture missing');
}
$emptyClaimsBefore = $bulkConnection->fetchAllAssociative('SELECT * FROM resource_claim ORDER BY resource_key');
(new \ViMbAdmin_Service_Domain($bulkManager))->purge($emptyDomain);
maintenanceTaskCheck(
    'empty Domain resource aggregate purges through the production entry point without acquiring claims',
    maintenanceTaskInteger($bulkConnection->fetchOne('SELECT COUNT(*) FROM domain WHERE id=?', [$emptyDomainId])) === 0
    && maintenanceTaskInteger($bulkConnection->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$emptyDomainId])) === 0
    && maintenanceTaskInteger($bulkConnection->fetchOne('SELECT COUNT(*) FROM alias WHERE Domain_id=?', [$emptyDomainId])) === 0
    && $bulkConnection->fetchAllAssociative('SELECT * FROM resource_claim ORDER BY resource_key') === $emptyClaimsBefore
    && !$bulkConnection->isTransactionActive()
);
$bulkConnection->executeStatement("DELETE FROM alias WHERE address IN ('team@bulk-claim.test','new@bulk-claim.test','other@bulk-unrelated.test')");
$bulkConnection->executeStatement("DELETE FROM mailbox WHERE username IN ('a@bulk-claim.test','b@bulk-claim.test','new@bulk-claim.test')");
$bulkConnection->executeStatement("DELETE FROM domain WHERE domain IN ('bulk-claim.test','bulk-unrelated.test')");
$bulkConnection->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('bulk-claim.test',NOW(),1,2),('bulk-unrelated.test',NOW(),1,0)");
$bulkId = maintenanceTaskInteger($bulkConnection->fetchOne("SELECT id FROM domain WHERE domain='bulk-claim.test'"));
$unrelatedId = maintenanceTaskInteger($bulkConnection->fetchOne("SELECT id FROM domain WHERE domain='bulk-unrelated.test'"));
$bulkConnection->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?),(?,?,?,1,NOW(),?)', ['a@bulk-claim.test','!','a',$bulkId,'b@bulk-claim.test','!','b',$bulkId]);
$bulkConnection->executeStatement('INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?),(?,?,1,NOW(),?)', ['team@bulk-claim.test','a@bulk-claim.test,b@bulk-claim.test,kept@example.test',$bulkId,'other@bulk-unrelated.test','kept@example.test',$unrelatedId]);
$bulkDomain = $bulkManager->find(\Entities\Domain::class, $bulkId);
$otherDomain = $bulkManager->find(\Entities\Domain::class, $unrelatedId);
if (!$bulkDomain instanceof \Entities\Domain || !$otherDomain instanceof \Entities\Domain) {
    throw new RuntimeException('bulk domain fixture missing');
}
$bulkRepository = $bulkManager->getRepository(\Entities\Domain::class);
if (!$bulkRepository instanceof \Repositories\Domain) {
    throw new RuntimeException('Domain repository mismatch');
}
foreach (['purgeMailboxes','purgeAliases'] as $method) {
    try {
        $bulkRepository->$method($bulkDomain);
        throw new RuntimeException('unfenced bulk deletion unexpectedly accepted');
    } catch (LogicException $expected) {
        maintenanceTaskCheck('standalone bulk helper rejects before deletion: ' . $method, str_contains($expected->getMessage(), 'fenced'));
    }
}

$outerManager = maintenanceTaskManager();
$outerDomain = $outerManager->find(\Entities\Domain::class, $bulkId);
if (!$outerDomain instanceof \Entities\Domain) {
    throw new RuntimeException('outer Domain fixture missing');
}
$outerConnection = $outerManager->getConnection();
$outerConnection->beginTransaction();
$outerConnection->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId]);
try {
    (new \ViMbAdmin_Service_Domain($outerManager))->purge($outerDomain);
    throw new RuntimeException('unsupported outer domain purge was accepted');
} catch (LogicException $expected) {
    maintenanceTaskCheck('Domain outer preflight preserves original supported-order rejection', str_contains($expected->getMessage(), 'before the caller transaction'));
}
maintenanceTaskCheck('Domain outer preflight preserves healthy caller state and zero mutations', $outerConnection->getTransactionNestingLevel() === 1 && $outerConnection->fetchOne('SELECT @@in_transaction') === 1 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 2);
maintenanceTaskCheck('Domain outer preflight acquires zero tokens', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username IN ('a@bulk-claim.test','b@bulk-claim.test')")) === 0);
$outerConnection->rollBack();

$blockingClaim = \ViMbAdmin_ResourceClaim::acquire($observer, 'b@bulk-claim.test');
try {
    try {
        (new \ViMbAdmin_Service_Domain($bulkManager))->purge($bulkDomain);
        throw new RuntimeException('domain purge unexpectedly ignored its claimed mailbox');
    } catch (\ViMbAdmin_Exception $expected) {
        maintenanceTaskCheck('bulk domain purge rejects a currently claimed physical mailbox', str_contains($expected->getMessage(), 'already claimed'));
    }
    maintenanceTaskCheck('partial bulk claim acquisition leaves every child unchanged', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 2 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM alias WHERE Domain_id=?', [$bulkId])) === 1);
    maintenanceTaskCheck('partial known no-start acquisition releases earlier canonical claims', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE username=?', ['a@bulk-claim.test'])) === 0);
    $unrelatedManager = maintenanceTaskManager();
    $unrelatedDomain = $unrelatedManager->find(\Entities\Domain::class, $unrelatedId);
    if (!$unrelatedDomain instanceof \Entities\Domain) {
        throw new RuntimeException('unrelated domain missing');
    }
    (new \ViMbAdmin_Service_Domain($unrelatedManager))->purge($unrelatedDomain);
    maintenanceTaskCheck('unrelated domain progresses while the other mail store is claimed', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM domain WHERE id=?', [$unrelatedId])) === 0);
} finally {
    $blockingClaim->finish();
}
$staleManager = maintenanceTaskManager();
$staleDomain = $staleManager->find(\Entities\Domain::class, $bulkId);
if (!$staleDomain instanceof \Entities\Domain) {
    throw new RuntimeException('stale domain fixture missing');
}
$observer->executeStatement('UPDATE domain SET capacity_version=capacity_version+1 WHERE id=?', [$bulkId]);
try {
    (new \ViMbAdmin_Service_Domain($staleManager))->purge($staleDomain);
    throw new RuntimeException('stale domain unexpectedly deleted current children');
} catch (\Doctrine\ORM\OptimisticLockException $expected) {
    maintenanceTaskCheck('bulk deletion requires the loaded current domain generation', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 2);
}
$observer->executeStatement("CREATE TRIGGER maintenance_domain_purge_failure BEFORE DELETE ON alias FOR EACH ROW BEGIN IF OLD.address = 'team@bulk-claim.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected bulk recipient deletion failure'; END IF; END");
try {
    $failureManager = maintenanceTaskManager();
    $failureDomain = $failureManager->find(\Entities\Domain::class, $bulkId);
    if (!$failureDomain instanceof \Entities\Domain) {
        throw new RuntimeException('failure domain fixture missing');
    }
    try {
        (new \ViMbAdmin_Service_Domain($failureManager))->purge($failureDomain);
        throw new RuntimeException('bulk failure was not reached');
    } catch (\Doctrine\DBAL\Exception $expected) {
        maintenanceTaskCheck('bulk storage failure preserves its original exception', str_contains($expected->getMessage(), 'injected bulk recipient deletion failure'));
    }
    $failureManager->flush();
    maintenanceTaskCheck('bulk failure rolls back earlier mailbox deletion and later flush', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 2 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM alias WHERE Domain_id=?', [$bulkId])) === 1);
    maintenanceTaskCheck('confirmed rollback without remote work releases bulk claims', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username IN ('a@bulk-claim.test','b@bulk-claim.test')")) === 0);
} finally {
    $observer->executeStatement('DROP TRIGGER maintenance_domain_purge_failure');
}


// The first cleanup failure must not skip a later independently owned token.
$observer->executeStatement("CREATE TRIGGER maintenance_domain_operation_failure BEFORE DELETE ON alias FOR EACH ROW BEGIN IF OLD.address='team@bulk-claim.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='original bulk operation failure'; END IF; END");
$observer->executeStatement("CREATE TRIGGER maintenance_domain_cleanup_failure BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='a@bulk-claim.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='first bulk cleanup failure'; END IF; END");
try {
    $failureManager = maintenanceTaskManager();
    $failureDomain = $failureManager->find(\Entities\Domain::class, $bulkId);
    if (!$failureDomain instanceof \Entities\Domain) {
        throw new RuntimeException('cleanup domain missing');
    }
    try {
        (new \ViMbAdmin_Service_Domain($failureManager))->purge($failureDomain);
        throw new RuntimeException('cleanup schedule did not fail');
    } catch (\Doctrine\DBAL\Exception $expected) {
        maintenanceTaskCheck('multiple-claim cleanup preserves original operation failure', str_contains($expected->getMessage(), 'original bulk operation failure'));
    }
    maintenanceTaskCheck('first cleanup failure retains only uncertain token and later claims finish', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='a@bulk-claim.test'")) === 1 && maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username='b@bulk-claim.test'")) === 0);
    maintenanceTaskCheck('multi-claim cleanup failure rolls back all child deletion', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 2);
} finally {
    $observer->executeStatement('DROP TRIGGER maintenance_domain_operation_failure');
    $observer->executeStatement('DROP TRIGGER maintenance_domain_cleanup_failure');
    $cleanupKey = \ViMbAdmin_ResourceClaim::key('a@bulk-claim.test');
    $cleanupToken = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$cleanupKey]);
    // DB-only callback rolled back; no remote command exists in this schedule.
    $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$cleanupKey,$cleanupToken]);
}

// Observe the real acquisition connections on a multi-resource production purge.
$observer->executeStatement("DELETE FROM mailbox WHERE username LIKE '%@bulk-sessions.test'");
$observer->executeStatement("DELETE FROM domain WHERE domain='bulk-sessions.test'");
$observer->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('bulk-sessions.test',NOW(),0,24)");
$sessionDomainId = maintenanceTaskInteger($observer->fetchOne("SELECT id FROM domain WHERE domain='bulk-sessions.test'"));
for ($i = 0; $i < 24; ++$i) {
    $observer->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', [sprintf('user%02d@bulk-sessions.test', $i),'!','user'.$i,$sessionDomainId]);
}
$sessionLogger = new class ($observer) extends \Psr\Log\AbstractLogger {
    public int $peak = 0;
    public int $acquisitions = 0;
    public ?Closure $onAcquisition = null;
    public function __construct(private \Doctrine\DBAL\Connection $observer)
    {
    }
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql']) && is_string($context['sql']) && str_starts_with($context['sql'], 'INSERT INTO resource_claim')) {
            ++$this->acquisitions;
            if ($this->onAcquisition !== null) {
                $callback = $this->onAcquisition;
                $this->onAcquisition = null;
                $callback();
            }
            $this->peak = max($this->peak, maintenanceTaskInteger($this->observer->fetchOne('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE()')));
        }
    }
};
$sourceManager = maintenanceTaskManager();
$config = clone $sourceManager->getConfiguration();
$config->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($sessionLogger)]);
$sessionManager = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection($sourceManager->getConnection()->getParams(), $config), $config);
$sessionDomain = $sessionManager->find(\Entities\Domain::class, $sessionDomainId);
if (!$sessionDomain instanceof \Entities\Domain) {
    throw new RuntimeException('session domain missing');
}
$baselineSessions = maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE()'));
(new \ViMbAdmin_Service_Domain($sessionManager))->purge($sessionDomain);
maintenanceTaskCheck('24-resource purge keeps peak sessions constant', $sessionLogger->acquisitions === 24 && $sessionLogger->peak <= $baselineSessions + 1);
maintenanceTaskCheck('24-resource purge releases every durable token', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username LIKE '%@bulk-sessions.test'")) === 0);
echo 'resource-session-receipt baseline='.$baselineSessions.' peak='.$sessionLogger->peak.' acquisitions='.$sessionLogger->acquisitions."\n";


// A real creator commits after enumeration but before the Domain fence. It
// remains independent of the purger's ObjectManager and acquisition connection.
$creator = maintenanceTaskManager();
$creatorDomain = $creator->find(\Entities\Domain::class, $bulkId);
$creatorActor = $creator->getRepository(\Entities\Admin::class)->findOneBy(['username' => 'resource-actor@example.test']);
if (!$creatorDomain instanceof \Entities\Domain || !$creatorActor instanceof \Entities\Admin) {
    throw new RuntimeException('pre-fence creator fixture missing');
}
$sessionLogger->onAcquisition = static function () use ($creator, $creatorDomain, $creatorActor): void {
    $created = (new \Entities\Mailbox())->setUsername('new@bulk-claim.test')->setLocalPart('new')->setQuota(0)->setPassword('fixture-password');
    \ViMbAdmin_Service_Mailbox::applyCreationPolicy($created, $creatorDomain);
    (new \ViMbAdmin_Service_Mailbox($creator))->create($created, $creatorDomain, $creatorActor, ['defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']],'mailboxAliases' => true]);
};
$changedManager = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection($sourceManager->getConnection()->getParams(), $config), $config);
$changedDomain = $changedManager->find(\Entities\Domain::class, $bulkId);
if (!$changedDomain instanceof \Entities\Domain) {
    throw new RuntimeException('changed-set domain missing');
}
try {
    (new \ViMbAdmin_Service_Domain($changedManager))->purge($changedDomain);
    throw new RuntimeException('changed resource set was deleted');
} catch (\Doctrine\ORM\OptimisticLockException) {
    maintenanceTaskCheck('creation between enumeration and Domain fence rejects before any deletion', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 3 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM alias WHERE Domain_id=?', [$bulkId])) === 2);
}
maintenanceTaskCheck('changed-set rejection releases known no-start claims', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username IN ('a@bulk-claim.test','b@bulk-claim.test')")) === 0);
$observer->executeStatement("DELETE FROM alias WHERE address='new@bulk-claim.test'");
$observer->executeStatement("DELETE FROM mailbox WHERE username='new@bulk-claim.test'");

// Pause the real DELETE after the domain fence, then observe a real creator's
// FK INSERT waiting. The creator cannot appear after resource enumeration.
$observer->fetchOne("SELECT GET_LOCK('maintenance_domain_purge_barrier', 0)");
$observer->executeStatement("CREATE TRIGGER maintenance_domain_purge_pause BEFORE DELETE ON mailbox FOR EACH ROW BEGIN IF OLD.Domain_id = " . $bulkId . " THEN SET @maintenance_barrier = GET_LOCK('maintenance_domain_purge_barrier', 60); DO RELEASE_LOCK('maintenance_domain_purge_barrier'); END IF; END");
$domainChildren = [];
$domainPipes = [];
$domainChildPids = [];
$domainBarrierError = null;
try {
    foreach (['domain-purge-child','domain-create-child'] as $index => $mode) {
        $child = proc_open([PHP_BINARY,__FILE__,$mode,(string) $bulkId], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $childPipes);
        if (!is_resource($child)) {
            throw new RuntimeException('Domain race actor failed to start');
        }
        $domainChildren[$index] = $child;
        $domainPipes[$index] = $childPipes;
        $domainChildPids[$index] = proc_get_status($child)['pid'];
        $header = maintenanceResourceReadLine($childPipes[1], 'Domain race connection barrier');
        if (!preg_match('/^CONNECTION ([0-9]+)$/', $header, $match)) {
            throw new RuntimeException('Domain race connection barrier failed');
        }
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $process = $observer->fetchAssociative('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?', [(int) $match[1]]);
            $waiting = is_array($process) && is_string($process['INFO']) && ($index === 0
                ? (str_starts_with($process['INFO'], 'DELETE FROM mailbox') || ($process['STATE'] === 'User lock' && str_contains($process['INFO'], 'maintenance_domain_purge_barrier')))
                : str_starts_with($process['INFO'], 'INSERT INTO mailbox'));
            if (!$waiting) {
                usleep(10000);
            }
        } while (!$waiting && hrtime(true) < $deadline);
        maintenanceTaskCheck($index === 0 ? 'bulk deletion is paused under the domain and resource fences' : 'concurrent production creation waits after current resource enumeration', $waiting);
    }
    $observer->fetchOne("SELECT RELEASE_LOCK('maintenance_domain_purge_barrier')");
    $purgeVerdict = maintenanceResourceReadLine($domainPipes[0][1], 'Domain purge verdict barrier');
    if ($purgeVerdict !== 'PURGED') {
        stream_set_blocking($domainPipes[0][2], false);
        $diagnostic = trim((string) stream_get_contents($domainPipes[0][2]));
        throw new RuntimeException('Domain purge child failure: unexpected verdict '.$purgeVerdict.($diagnostic === '' ? '' : '; '.$diagnostic));
    }
    maintenanceTaskCheck('fenced bulk domain purge completes', true);
    maintenanceTaskCheck('concurrent creator safely rejects the deleted domain', maintenanceResourceReadLine($domainPipes[1][1], 'Domain creator verdict barrier') === 'CREATION REJECTED');
    maintenanceTaskCheck('bulk completion deletes only current scoped children', maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM domain WHERE id=?', [$bulkId])) === 0 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox WHERE Domain_id=?', [$bulkId])) === 0 && maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM alias WHERE Domain_id=?', [$bulkId])) === 0);
    maintenanceTaskCheck('bulk normal completion releases every exact claim', maintenanceTaskInteger($observer->fetchOne("SELECT COUNT(*) FROM resource_claim WHERE username IN ('a@bulk-claim.test','b@bulk-claim.test')")) === 0);
} catch (RuntimeException $error) {
    if (getenv('MAINTENANCE_DOMAIN_CHILD_SILENT') !== '1' && getenv('MAINTENANCE_DOMAIN_CHILD_BAD_VERDICT_LIVE') !== '1') {
        throw $error;
    }
    $domainBarrierError = $error;
} finally {
    $observer->fetchOne("SELECT RELEASE_LOCK('maintenance_domain_purge_barrier')");
    foreach ($domainChildren as $index => $child) {
        $childPipes = $domainPipes[$index] ?? [];
        maintenanceResourceStopChild($child, $childPipes, 'Domain race actor '.$index);
    }
    $observer->executeStatement('DROP TRIGGER maintenance_domain_purge_pause');
    foreach (['a@bulk-claim.test','b@bulk-claim.test'] as $username) {
        $resourceKey = \ViMbAdmin_ResourceClaim::key($username);
        $token = $observer->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$resourceKey]);
        if ($token !== false) {
            $observer->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$resourceKey,$token]);
        }
    }
}
if (getenv('MAINTENANCE_DOMAIN_CHILD_SILENT') === '1') {
    maintenanceTaskCheck(
        'domain race connection timeout is bounded, diagnostic, and reaps every owned child',
        $domainBarrierError instanceof RuntimeException
        && str_contains($domainBarrierError->getMessage(), 'Domain race connection barrier timed out')
        && count($domainChildPids) === 1
        && !maintenanceResourceProcessRunning($domainChildPids[0])
    );
    echo "DOMAIN RACE TIMEOUT CONTROL PASSED\n";
    exit(0);
}
if (getenv('MAINTENANCE_DOMAIN_CHILD_BAD_VERDICT_LIVE') === '1') {
    maintenanceTaskCheck(
        'domain race bad verdict is diagnostic and reaps every owned child before returning',
        $domainBarrierError instanceof RuntimeException
        && str_contains($domainBarrierError->getMessage(), 'unexpected verdict BAD VERDICT')
        && count($domainChildPids) === 2
        && !maintenanceResourceProcessRunning($domainChildPids[0])
        && !maintenanceResourceProcessRunning($domainChildPids[1])
    );
    echo "DOMAIN RACE BAD VERDICT CONTROL PASSED\n";
    exit(0);
}

// Real persistence/error boundaries uncovered by the aggregate measurement.
$boundaryManager = maintenanceTaskManager();
$boundaryConnection = $boundaryManager->getConnection();
$boundaryObserver = maintenanceTaskManager()->getConnection();
$boundaryUser = 'coverage-boundary@example.test';
$boundaryKey = \ViMbAdmin_ResourceClaim::key($boundaryUser);
$createdAt = new DateTime('2030-01-01 12:00:00');
$entityToken = str_repeat('b', 64);
$claimEntity = new \Entities\ResourceClaim($boundaryKey, $entityToken, $boundaryUser, 'READY', $createdAt);
$boundaryManager->persist($claimEntity);
$boundaryManager->flush();
$boundaryManager->clear();
$storedClaim = $boundaryManager->find(\Entities\ResourceClaim::class, $boundaryKey);
maintenanceTaskCheck('resource mapping round-trips its exact immutable ownership receipt', $storedClaim instanceof \Entities\ResourceClaim && $storedClaim->getResourceKey() === $boundaryKey && $storedClaim->getOwnerToken() === $entityToken && $storedClaim->getUsername() === $boundaryUser && $storedClaim->getPhase() === 'READY' && $storedClaim->getCreatedAt()->format('Y-m-d H:i:s') === $createdAt->format('Y-m-d H:i:s'));
$boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$entityToken]);
$boundaryManager->clear();
\ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, $boundaryUser, static function ($outer) use ($boundaryManager, $boundaryObserver, $boundaryUser): void {
    \ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, strtoupper($boundaryUser), static function ($inner) use ($outer, $boundaryObserver): void {
        maintenanceTaskCheck('nested same-resource callers reuse the exact owner without competing claims', $inner === $outer && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE username=?', ['coverage-boundary@example.test'])) === 1);
    });
});
maintenanceTaskCheck('nested completion releases exactly one claim', maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 0);
$boundaryClaim = \ViMbAdmin_ResourceClaim::acquire($boundaryConnection, $boundaryUser);
try {
    try {
        $boundaryClaim->releaseInTransaction($boundaryConnection);
        throw new RuntimeException('nontransactional publication unexpectedly accepted');
    } catch (LogicException $expected) {
        maintenanceTaskCheck('publication release rejects an absent transaction without deleting ownership', str_contains($expected->getMessage(), 'active publication transaction') && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 1);
    }
    try {
        $boundaryClaim->guardArchive((new \Entities\Archive())->setUsername('different-coverage@example.test'));
        throw new RuntimeException('foreign archive unexpectedly accepted');
    } catch (LogicException $expected) {
        maintenanceTaskCheck('archive fence rejects another physical identity before any lookup or backend effect', str_contains($expected->getMessage(), 'claimed mailbox resource'));
    }
} finally {
    $boundaryClaim->finish();
}
$boundaryObserver->executeStatement("CREATE TRIGGER maintenance_claim_insert_fault BEFORE INSERT ON resource_claim FOR EACH ROW BEGIN IF NEW.username='coverage-boundary@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected claim acquisition failure'; END IF; END");
try {
    try {
        \ViMbAdmin_ResourceClaim::acquire($boundaryConnection, $boundaryUser);
        throw new RuntimeException('claim acquisition fault unexpectedly accepted');
    } catch (\Doctrine\DBAL\Exception $expected) {
        maintenanceTaskCheck('claim acquisition storage failure preserves its actual database error', str_contains($expected->getMessage(), 'injected claim acquisition failure'));
    }
    maintenanceTaskCheck('failed acquisition creates no durable owner', maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 0);
} finally {
    $boundaryObserver->executeStatement('DROP TRIGGER maintenance_claim_insert_fault');
}
$replacementToken = str_repeat('c', 64);
$remoteEffects = 0;
try {
    \ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, $boundaryUser, static function ($owner) use ($boundaryObserver, $boundaryKey, $replacementToken, &$remoteEffects): void {
        $oldToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
        $boundaryObserver->executeStatement('UPDATE resource_claim SET owner_token=? WHERE resource_key=? AND owner_token=?', [$replacementToken,$boundaryKey,$oldToken]);
        $owner->remote(static function () use (&$remoteEffects): void {
            ++$remoteEffects;
        });
    });
    throw new RuntimeException('lost resource owner unexpectedly started backend work');
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('resource token replacement rejects before backend mutation', $expected->getMessage() === 'Mailbox resource ownership was lost.' && $remoteEffects === 0 && $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === $replacementToken);
}
// This replacement token belongs only to the finished no-start fixture above.
$boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$replacementToken]);
$boundaryClaim = \ViMbAdmin_ResourceClaim::acquire($boundaryConnection, $boundaryUser);
$exactToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
$boundaryObserver->executeStatement('UPDATE resource_claim SET owner_token=? WHERE resource_key=? AND owner_token=?', [$replacementToken,$boundaryKey,$exactToken]);
$boundaryConnection->beginTransaction();
try {
    try {
        $boundaryClaim->releaseInTransaction($boundaryConnection);
        throw new RuntimeException('stale transactional release unexpectedly accepted');
    } catch (RuntimeException $expected) {
        maintenanceTaskCheck('transactional release requires the exact current ready token', str_contains($expected->getMessage(), 'current ready token'));
    }
} finally {
    $boundaryConnection->rollBack();
    $boundaryClaim->finish();
}
maintenanceTaskCheck('rejected transactional release and cleanup preserve the replacement token', $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === $replacementToken);
$boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$replacementToken]);
$completedOwner = null;
try {
    \ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, $boundaryUser, static function ($owner) use (&$completedOwner): void {
        $completedOwner = $owner;
        $owner->remote(static fn (): string => 'confirmed synchronous fixture completion');
        throw new RuntimeException('failure after synchronous backend completion');
    });
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('post-backend failure preserves original error and durable ownership', $expected->getMessage() === 'failure after synchronous backend completion' && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 1);
}
if (!$completedOwner instanceof \ViMbAdmin_ResourceClaim) {
    throw new RuntimeException('completed fixture owner missing');
}
try {
    $completedOwner->assertReady();
    throw new RuntimeException('retained completion unexpectedly published success');
} catch (RuntimeException $expected) {
    maintenanceTaskCheck('retained completion cannot publish misleading success', str_contains($expected->getMessage(), 'backend completion is uncertain'));
}
// The only backend callback was synchronous and has returned before recovery.
$exactToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
$boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$exactToken]);
$boundaryObserver->executeStatement("CREATE TRIGGER maintenance_claim_finish_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='coverage-boundary@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected claim completion failure'; END IF; END");
try {
    try {
        \ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, $boundaryUser, static fn (): string => 'successful no-backend operation');
        throw new RuntimeException('completion failure unexpectedly hidden');
    } catch (\Doctrine\DBAL\Exception $expected) {
        maintenanceTaskCheck('normal callback completion reports a release failure rather than success', str_contains($expected->getMessage(), 'injected claim completion failure'));
    }
    maintenanceTaskCheck('completion release failure retains its exact durable owner', maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 1);
} finally {
    $boundaryObserver->executeStatement('DROP TRIGGER maintenance_claim_finish_fault');
    $exactToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
    $boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$exactToken]);
}



// Inject an inconsistent affected-row receipt after actual MariaDB DML. The
// safety boundary must roll back the physical DELETE, not accept that receipt.
$faultConfiguration = new \Doctrine\DBAL\Configuration();
$faultConfiguration->setMiddlewares([new class () implements \Doctrine\DBAL\Driver\Middleware {
    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new class ($driver) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            /** @inheritDoc */
            public function connect(array $params): \Doctrine\DBAL\Driver\Connection
            {
                return new class (parent::connect($params)) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
                    public function prepare(string $sql): \Doctrine\DBAL\Driver\Statement
                    {
                        $statement = parent::prepare($sql);
                        if (!str_starts_with($sql, 'DELETE FROM resource_claim ')) {
                            return $statement;
                        }
                        return new class ($statement) extends \Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware {
                            public function execute(): \Doctrine\DBAL\Driver\Result
                            {
                                return new class (parent::execute()) extends \Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware {
                                    public function rowCount(): int
                                    {
                                        return 0;
                                    }
                                };
                            }
                        };
                    }
                };
            }
        };
    }
}]);
$faultConnection = \Doctrine\DBAL\DriverManager::getConnection($boundaryConnection->getParams(), $faultConfiguration);
$faultClaim = \ViMbAdmin_ResourceClaim::acquire($faultConnection, $boundaryUser);
$faultToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
$faultConnection->beginTransaction();
try {
    try {
        $faultClaim->releaseInTransaction($faultConnection);
        throw new RuntimeException('ambiguous affected-row result unexpectedly accepted');
    } catch (RuntimeException $expected) {
        maintenanceTaskCheck('zero-row receipt rejects the actual transactional release', str_contains($expected->getMessage(), 'current ready token') && maintenanceTaskInteger($faultConnection->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 0);
        maintenanceTaskCheck('independent observer still sees the uncommitted exact owner', $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === $faultToken);
    }
} finally {
    $faultConnection->rollBack();
    $faultClaim->finish();
    $faultConnection->close();
}
maintenanceTaskCheck('ambiguous SQL result rollback and cleanup restore the exact durable claim', $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === $faultToken);
// No backend was started; the exact fixture token is reconciled after rollback.
$boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$faultToken]);

// Read-only tasks progress under an existing physical-store owner.
$readOnlyManager = maintenanceTaskManager();
$readOnlyUser = 'readonly-boundary@example.test';
$readOnlyTask = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_MEASURE_SIZE)->setUsername($readOnlyUser)->setStatus('PENDING')->setCreatedAt(new DateTime())->setData('{}')->setLog('');
$readOnlyManager->persist($readOnlyTask);
$readOnlyManager->flush();
$readOnlyOwner = \ViMbAdmin_ResourceClaim::acquire($readOnlyManager->getConnection(), $readOnlyUser);
$readOnlyRunner = new MaintenanceArchiveRunner($readOnlyManager, ['queue' => ['runner' => ['max_concurrent' => 1]]]);
$readOnlyRunner->backend = new MaintenanceArchiveBackend();
try {
    $readOnlyResult = $readOnlyRunner->runOne($readOnlyTask, static function (?Throwable $error) use ($readOnlyTask): void {
        if ($error !== null) {
            throw $error;
        }$readOnlyTask->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
    });
    maintenanceTaskCheck('actual read-only queue task completes without competing for store mutation ownership', $readOnlyResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $readOnlyTask->getStatus() === 'DONE' && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE username=?', [$readOnlyUser])) === 1);
} finally {
    $readOnlyOwner->finish();
}
$unguardedEffects = 0;
try {
    (new ReflectionMethod(\ViMbAdmin_Service_QueueRunner::class, 'remoteMutation'))->invoke($readOnlyRunner, static function () use (&$unguardedEffects): void {
        ++$unguardedEffects;
    });
    throw new RuntimeException('unguarded backend callback unexpectedly executed');
} catch (LogicException $expected) {
    maintenanceTaskCheck('backend mutation boundary rejects missing owner before effects', str_contains($expected->getMessage(), 'requires its resource claim') && $unguardedEffects === 0);
}

// A known no-start failure plus a failed release preserves the original error.
$noStartOriginal = new RuntimeException('original no-start callback failure');
$noStartToken = null;
$observedNoStart = null;
$boundaryObserver->executeStatement("CREATE TRIGGER maintenance_no_start_cleanup_fault BEFORE DELETE ON resource_claim FOR EACH ROW BEGIN IF OLD.username='coverage-boundary@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected no-start cleanup failure'; END IF; END");
try {
    try {
        \ViMbAdmin_ResourceClaim::withMailbox($boundaryManager, $boundaryUser, static function () use ($boundaryObserver, $boundaryKey, $noStartOriginal, &$noStartToken): void {
            $noStartToken = $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]);
            throw $noStartOriginal;
        });
    } catch (RuntimeException $expected) {
        $observedNoStart = $expected;
        maintenanceTaskCheck('withMailbox cleanup failure preserves exact original no-start error and token', $expected === $noStartOriginal && $boundaryObserver->fetchOne('SELECT phase FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === 'READY' && is_string($noStartToken) && $boundaryObserver->fetchOne('SELECT owner_token FROM resource_claim WHERE resource_key=?', [$boundaryKey]) === $noStartToken);
    }
} finally {
    $boundaryObserver->executeStatement('DROP TRIGGER maintenance_no_start_cleanup_fault');
    // The callback owner has returned, no remote() was called, and the only
    // fixture-trigger fault is removed: reconcile this exact READY token only.
    if (is_string($noStartToken)) {
        $boundaryObserver->executeStatement("DELETE FROM resource_claim WHERE resource_key=? AND owner_token=? AND phase='READY'", [$boundaryKey,$noStartToken]);
    }
}
maintenanceTaskCheck('finished no-start cleanup control releases only its reconciled fixture token', $observedNoStart === $noStartOriginal && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM resource_claim WHERE resource_key=?', [$boundaryKey])) === 0);

// Exercise the malformed persisted prune payload through the actual runner,
// independently of the enqueue-sweep instrumentation workload.
$cutoffManager = maintenanceTaskManager();
$cutoffArchive = (new \Entities\Archive())->setUsername('cutoff-coverage@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)->setAutoprune(true)->setArchivedAt(new DateTime('2000-01-01'))->setStatusChangedAt(new DateTime('2000-01-01'))->setMaildirFile('/backup/cutoff-coverage')->setData('{}');
$cutoffManager->persist($cutoffArchive);
$cutoffManager->flush();
$cutoffTask = (new \Entities\MailboxTask())->setType(\Entities\MailboxTask::TYPE_PRUNE)->setUsername('cutoff-coverage@example.test')->setStatus('PENDING')->setCreatedAt(new DateTime())->setLog('')->setData(json_encode(['archive_id' => $cutoffArchive->getId(),'archive_generation' => $cutoffArchive->getArchiveGeneration(),'archive_version' => $cutoffArchive->getArchiveVersion(),'cutoff' => 'invalid cutoff'], JSON_THROW_ON_ERROR));
$cutoffManager->persist($cutoffTask);
$cutoffManager->flush();
$cutoffRunner = new MaintenanceArchiveRunner($cutoffManager, ['queue' => ['runner' => ['max_concurrent' => 1],'autoprune' => ['days' => 90]]]);
$cutoffBackend = new MaintenanceArchiveBackend();
$cutoffRunner->backend = $cutoffBackend;
$cutoffResult = $cutoffRunner->runOne($cutoffTask, static function (?Throwable $error) use ($cutoffTask): void {
    if ($error !== null) {
        throw $error;
    }
    $cutoffTask->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
});
maintenanceTaskCheck('actual prune rejects noninteger cutoff before backend deletion and preserves current archive', $cutoffResult === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $cutoffBackend->deleteCalls === 0 && maintenanceTaskInteger($boundaryObserver->fetchOne('SELECT COUNT(*) FROM archive WHERE id=?', [$cutoffArchive->getId()])) === 1);

echo "ALL PASSED\n";
