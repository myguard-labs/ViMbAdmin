<?php

declare(strict_types=1);

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 for the isolated test database.\n");
    exit(2);
}

define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';

use Doctrine\ORM\EntityManager;

final class MaintenanceOrphanBackend extends \ViMbAdmin_Doveadm
{
    /** @var array<string,list<string>> */
    public array $directories = [];
    /** @var list<string> */
    public array $calls = [];
    public ?Throwable $listFailure = null;
    public bool $hasMail = false;
    public ?Throwable $backupFailure = null;
    public ?Closure $operationHook = null;

    public function __construct()
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'fixture-key', 1);
    }

    /** @return array{0:int,1:string} */
    #[\Override]
    protected function _post($payload)
    {
        if (!is_string($payload)) {
            throw new RuntimeException('orphan fixture request must be JSON');
        }
        $request = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $envelope = is_array($request) && count($request) === 1 ? ($request[0] ?? null) : null;
        if (!is_array($envelope)) {
            throw new RuntimeException('unexpected orphan fixture request');
        }
        $command = $envelope[0] ?? null;
        $parameters = $envelope[1] ?? null;
        $tag = $envelope[2] ?? null;
        $path = is_array($parameters) ? ($parameters['path'] ?? null) : null;
        if ($command !== 'fsIterDirs' || !is_string($path) || !is_string($tag)) {
            throw new RuntimeException('unexpected orphan fixture request');
        }
        if ($this->listFailure !== null) {
            throw $this->listFailure;
        }
        $path = rtrim($path, '/');
        $this->calls[] = 'list:' . $path;
        $rows = array_map(static fn (string $name): array => ['path' => $name], $this->directories[$path] ?? []);
        return [200, json_encode([['doveadmResponse', $rows, $tag]], JSON_THROW_ON_ERROR)];
    }

    #[\Override]
    public function authCacheFlush(array $users = [])
    {
        $this->calls[] = 'auth-flush';
        return [];
    }

    #[\Override]
    public function forceResync($user, $mbox = '*')
    {
        $this->calls[] = 'resync:' . $user;
        if ($this->operationHook !== null) {
            ($this->operationHook)();
            $this->operationHook = null;
        }
        return [];
    }

    #[\Override]
    public function index($user, $mbox = '*')
    {
        $this->calls[] = 'index:' . $user;
        return [];
    }

    #[\Override]
    public function purge($user)
    {
        $this->calls[] = 'purge:' . $user;
        return [];
    }

    #[\Override]
    public function maildirHasMail($maildir, $filter = 'posix')
    {
        $this->calls[] = 'has-mail:' . $maildir;
        return $this->hasMail;
    }

    #[\Override]
    public function backup($user, $dest)
    {
        $this->calls[] = 'backup:' . $user . ':' . $dest;
        if ($this->backupFailure !== null) {
            throw $this->backupFailure;
        }
        return [];
    }

    #[\Override]
    public function quotaRecalc($user)
    {
        $this->calls[] = 'quota:' . $user;
        return [];
    }

    #[\Override]
    public function mailboxDelete($user)
    {
        $this->calls[] = 'mailbox-delete:' . $user;
        $this->hasMail = false;
    }

    #[\Override]
    public function fsDelete($path, $filter = 'posix')
    {
        $this->calls[] = 'delete:' . $path;
        unset($this->directories[rtrim((string) $path, '/')]);
        return [];
    }
}

final class MaintenanceOrphanRunner extends \ViMbAdmin_Service_QueueRunner
{
    public MaintenanceOrphanBackend $backend;

    #[\Override]
    protected function newDoveadm($progress)
    {
        return $this->backend;
    }
}

/** @param list<string> $names */
function maintenanceOrphanResponse(array $names): string
{
    return json_encode([['doveadmResponse', array_map(
        static fn (string $name): array => ['path' => $name],
        $names
    ), 'fixture']], JSON_THROW_ON_ERROR);
}

function maintenanceOrphanTask(EntityManager $em, string $type, string $username): \Entities\MailboxTask
{
    $task = (new \Entities\MailboxTask())
        ->setType($type)->setUsername($username)->setStatus(\Entities\MailboxTask::STATUS_PENDING)
        ->setCreatedAt(new DateTime())->setLog('');
    $em->persist($task);
    $em->flush();
    return $task;
}

/** @return array{int,?Throwable,bool} */
function maintenanceOrphanRun(MaintenanceOrphanRunner $runner, \Entities\MailboxTask $task): array
{
    $error = null;
    $completed = false;
    $result = $runner->runOne($task, static function (?Throwable $received) use ($task, &$error, &$completed): void {
        $completed = true;
        $error = $received;
        $task->setStatus($received === null ? \Entities\MailboxTask::STATUS_DONE : \Entities\MailboxTask::STATUS_FAILED)
            ->setRunner(null)->setFinishedAt(new DateTime());
    });
    return [$result, $error, $completed];
}

function maintenanceOrphanReset(EntityManager $em): void
{
    $em->clear();
    $connection = $em->getConnection();
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
    foreach (['resource_claim','mailbox_task','queue_runner','archive','log','mailbox','domain'] as $table) {
        $connection->executeStatement('DELETE FROM ' . $table);
    }
    $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
}

function maintenanceOrphanKnownMailbox(EntityManager $em, string $username): \Entities\Mailbox
{
    $domainName = substr(strrchr($username, '@') ?: '', 1);
    $localPart = strstr($username, '@', true) ?: $username;
    $domain = (new \Entities\Domain())->setDomain($domainName)->setDescription('fixture')
        ->setQuota(0)->setTransport('virtual')->setBackupmx(false)->setActive(true)->setCreated(new DateTime())
        ->setMaxQuota(0)->setMaxAliases(0)->setMaxMailboxes(0)->setAliasCount(0)->setMailboxCount(1);
    $mailbox = (new \Entities\Mailbox())->setUsername($username)->setLocalPart($localPart)->setName('Known')
        ->setPassword('!')->setQuota(0)->setActive(true)->setDomain($domain)->setCreated(new DateTime());
    $em->persist($domain);
    $em->persist($mailbox);
    $em->flush();
    return $mailbox;
}

/** @param resource $stream */
function maintenanceOrphanReadLine($stream, string $label): string
{
    stream_set_blocking($stream, false);
    $deadline = hrtime(true) + 30_000_000_000;
    do {
        $line = fgets($stream);
        if ($line !== false) {
            return trim($line);
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException($label . ' timed out');
}

/** @param array<int,resource> $pipes */
function maintenanceOrphanCloseChild(mixed $process, array &$pipes, string $label): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $pipes = [];
    if (!is_resource($process)) {
        return;
    }
    $deadline = hrtime(true) + 30_000_000_000;
    while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
        usleep(10000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
        throw new RuntimeException($label . ' did not stop within the local deadline');
    }
    proc_close($process);
}

function maintenanceOrphanTransportBound(): void
{
    $directory = sys_get_temp_dir() . '/vimbadmin-orphan-transport-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('could not create orphan transport fixture directory');
    }
    $script = $directory . '/server.php';
    $countFile = $directory . '/bytes';
    file_put_contents(
        $script,
        <<<'PHP'
<?php
declare(strict_types=1);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    fwrite(STDERR, $error . "\n");
    exit(2);
}
echo 'http://' . stream_socket_get_name($server, false) . "/doveadm/v1\n";
flush();
stream_set_timeout($server, 30);
foreach (['before-rejection', 'oversized', 'after-rejection'] as $response) {
    $client = stream_socket_accept($server, 30);
    if (!is_resource($client)) {
        exit(3);
    }
    stream_set_timeout($client, 5);
    $contentLength = 0;
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        if (str_starts_with(strtolower($line), 'content-length:')) {
            $contentLength = (int) trim(substr($line, strlen('content-length:')));
        }
    }
    $requestBody = '';
    while (strlen($requestBody) < $contentLength) {
        $chunk = fread($client, $contentLength - strlen($requestBody));
        if ($chunk === false || $chunk === '') {
            exit(4);
        }
        $requestBody .= $chunk;
    }
    $request = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
    $name = $response === 'oversized' ? str_repeat('a', 2 * 1024 * 1024) : $response;
    $body = json_encode([['doveadmResponse', [['path' => $name]], $request[0][2]]], JSON_THROW_ON_ERROR);
    if ($response === 'after-rejection') {
        $body = str_pad($body, 32768, ' ');
    }
    $header = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n";
    fwrite($client, $header);
    $written = 0;
    for ($offset = 0, $length = strlen($body); $offset < $length; $offset += 8192) {
        $bytes = @fwrite($client, substr($body, $offset, 8192));
        if (!is_int($bytes) || $bytes < 1) {
            break;
        }
        $written += $bytes;
        usleep(1000);
    }
    if ($response === 'oversized') {
        file_put_contents($argv[1], (string) $written);
    }
    fclose($client);
}
fclose($server);
PHP
    );
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $script, $countFile],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start orphan transport fixture');
    }
    try {
        $url = maintenanceOrphanReadLine($pipes[1], 'orphan transport address');
        $client = new \ViMbAdmin_Doveadm($url, 'fixture-key', 5);
        maintenanceTaskCheck(
            'bounded transport returns the exact successful response',
            $client->fsListDirsBounded('/', 500, 255, 32768) === ['before-rejection']
        );
        $error = null;
        try {
            $client->fsListDirsBounded('/', 500, 255, 32768);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        maintenanceTaskCheck(
            'same client accepts an exact-limit response after oversized rejection',
            $client->fsListDirsBounded('/', 500, 255, 32768) === ['after-rejection']
        );
        maintenanceOrphanCloseChild($process, $pipes, 'orphan transport fixture');
        $process = null;
        $sent = is_file($countFile) ? (int) file_get_contents($countFile) : PHP_INT_MAX;
        maintenanceTaskCheck(
            'oversized transport aborts before the complete response is materialized',
            $error instanceof \ViMbAdmin_Exception
            && str_contains($error->getMessage(), 'response exceeds the bounded limit of 32768 bytes')
            && $sent >= 32768 && $sent < 2 * 1024 * 1024
        );
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            maintenanceOrphanCloseChild($process, $pipes, 'orphan transport fixture cleanup');
        }
        foreach ([$countFile, $script] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

$em = maintenanceTaskManager();
$schema = new \ViMbAdmin_Schema($em);
$schema->apply($schema->pendingSql());
$options = [
    'queue' => ['runner' => ['max_concurrent' => 1]],
    'doveadm' => [
        'maildir_root' => '/mail',
        'backup' => ['dest' => 'maildir:/backups/%d/%u'],
        'http' => ['url' => 'http://doveadm.invalid/doveadm/v1', 'api_key' => 'fixture-key'],
    ],
];

maintenanceOrphanTransportBound();

foreach ([0, 499, 500, 501] as $count) {
    maintenanceOrphanReset($em);
    $backend = new MaintenanceOrphanBackend();
    $backend->directories['/mail'] = array_map(
        static fn (int $number): string => sprintf('orphan-%03d@example.test', $number),
        range(1, max(1, $count))
    );
    if ($count === 0) {
        $backend->directories['/mail'] = [];
    }
    foreach ($backend->directories['/mail'] as $name) {
        $backend->directories['/mail/' . $name] = ['cur'];
    }
    $runner = new MaintenanceOrphanRunner($em, $options);
    $runner->backend = $backend;
    $task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_SCAN_ORPHANS, 'scan-' . $count . '@example.test');
    [$result, $error] = maintenanceOrphanRun($runner, $task);
    $data = json_decode($task->getData() ?? '', true);
    $orphans = is_array($data) && isset($data['orphans']) && is_array($data['orphans'])
        ? $data['orphans']
        : null;
    $valid = $count <= 500;
    maintenanceTaskCheck(
        'orphan scan boundary ' . $count . ' rejects only values above 500',
        $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
        && ($valid ? $error === null : $error instanceof \ViMbAdmin_Exception)
        && ($valid || str_contains((string) $error?->getMessage(), 'filesystem response exceeds 500 entries'))
        && ($valid ? is_array($orphans) && count($orphans) === $count : $task->getData() === null)
        && ($valid ? count($backend->calls) === $count + 1 : $backend->calls === ['list:/mail'])
    );
}

foreach ([
    'overlong name' => [str_repeat('a', 256), 'exceeds 255 bytes'],
    'malformed name' => ["bad/name", 'safe path component'],
] as $label => [$name, $message]) {
    maintenanceOrphanReset($em);
    $backend = new MaintenanceOrphanBackend();
    $backend->directories['/mail'] = [$name];
    $runner = new MaintenanceOrphanRunner($em, $options);
    $runner->backend = $backend;
    $task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_SCAN_ORPHANS, str_replace(' ', '-', $label) . '@example.test');
    [, $error] = maintenanceOrphanRun($runner, $task);
    maintenanceTaskCheck(
        $label . ' is rejected before child backend work',
        $error instanceof \ViMbAdmin_Exception && str_contains($error->getMessage(), $message)
        && $backend->calls === ['list:/mail']
    );
}

maintenanceOrphanReset($em);
maintenanceOrphanKnownMailbox($em, 'known@example.test');
$backend = new MaintenanceOrphanBackend();
$backend->directories = [
    '/mail' => ['orphan@example.test', 'known@example.test', 'empty@example.test'],
    '/mail/orphan@example.test' => ['cur'],
    '/mail/empty@example.test' => [],
];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_SCAN_ORPHANS, 'scan-parity@example.test');
[, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'scan preserves known, empty and orphan classification and ordering',
    $error === null
    && json_decode($task->getData() ?? '', true, 512, JSON_THROW_ON_ERROR) === ['orphans' => ['orphan@example.test']]
    && $backend->calls === ['list:/mail', 'list:/mail/empty@example.test', 'list:/mail/orphan@example.test']
);

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$backend->listFailure = new \ViMbAdmin_Exception('injected storage decoder failure');
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_SCAN_ORPHANS, 'scan-failure@example.test');
[, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'scan storage failure keeps its original task error and publishes no cache',
    $error instanceof \ViMbAdmin_Exception && $error->getMessage() === 'injected storage decoder failure'
    && $task->getData() === null
);

maintenanceOrphanReset($em);
maintenanceOrphanKnownMailbox($em, 'known@example.test');
$backend = new MaintenanceOrphanBackend();
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'known@example.test');
[$result, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'backup-orphan skips a mailbox that became real before execution',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $error === null && $backend->calls === []
    && str_contains($task->getLog() ?? '', 'a real mailbox now exists')
);

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'missing-domain');
[$result, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'backup-orphan rejects a missing domain before backend or temp storage effects',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $error === null && $backend->calls === []
    && str_contains($task->getLog() ?? '', 'has no domain part')
    && maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
);

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$backend->directories['/mail/empty-orphan@example.test'] = ['cur'];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'empty-orphan@example.test');
[$result, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'empty orphan preserves backend ordering and removes transient storage',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $error === null
    && $backend->calls === [
        'auth-flush', 'resync:empty-orphan@example.test', 'index:empty-orphan@example.test',
        'purge:empty-orphan@example.test', 'has-mail:/mail/empty-orphan@example.test',
        'list:/mail/empty-orphan@example.test', 'has-mail:/mail/empty-orphan@example.test',
        'delete:/mail/empty-orphan@example.test', 'auth-flush',
    ]
    && maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM mailbox')) === 0
    && maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM domain')) === 0
    && str_contains($task->getLog() ?? '', 'transient domain row removed')
);

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$backend->hasMail = true;
$backend->directories['/mail/full-orphan@example.test'] = ['cur'];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'full-orphan@example.test');
[$result, $error] = maintenanceOrphanRun($runner, $task);
maintenanceTaskCheck(
    'mail-bearing orphan backs up before mailbox and home cleanup and records archive',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $error === null
    && array_slice($backend->calls, 0, 8) === [
        'auth-flush', 'resync:full-orphan@example.test', 'index:full-orphan@example.test',
        'purge:full-orphan@example.test', 'has-mail:/mail/full-orphan@example.test',
        'backup:full-orphan@example.test:maildir:/backups/example.test/full-orphan@example.test',
        'quota:full-orphan@example.test', 'mailbox-delete:full-orphan@example.test',
    ]
    && maintenanceTaskInteger($em->getConnection()->fetchOne("SELECT COUNT(*) FROM archive WHERE username='full-orphan@example.test'")) === 1
    && maintenanceTaskInteger($em->getConnection()->fetchOne("SELECT COUNT(*) FROM mailbox WHERE username='full-orphan@example.test'")) === 0
    && maintenanceTaskInteger($em->getConnection()->fetchOne("SELECT COUNT(*) FROM domain WHERE domain='example.test' AND active=0")) === 1
);

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$backend->hasMail = true;
$backend->backupFailure = new RuntimeException('injected backup storage failure');
$backend->directories['/mail/failed-orphan@example.test'] = ['cur'];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'failed-orphan@example.test');
[$result, $error] = maintenanceOrphanRun($runner, $task);
$failureMailboxCount = maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM mailbox'));
$failureDomainCount = maintenanceTaskInteger($em->getConnection()->fetchOne('SELECT COUNT(*) FROM domain'));
maintenanceTaskCheck(
    'backup storage failure keeps original error and still cleans exact temp state',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED
    && $error instanceof RuntimeException && $error->getMessage() === 'injected backup storage failure'
    && $failureMailboxCount === 0 && $failureDomainCount === 0
    && end($backend->calls) === 'backup:failed-orphan@example.test:maildir:/backups/example.test/failed-orphan@example.test'
);

maintenanceOrphanReset($em);
$observer = maintenanceTaskManager()->getConnection();
$backend = new MaintenanceOrphanBackend();
$backend->directories['/mail/lease-loss@example.test'] = ['cur'];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'lease-loss@example.test');
$backend->operationHook = static function () use ($observer, $task): void {
    $observer->executeStatement('UPDATE mailbox_task SET QueueRunner_id=NULL WHERE id=?', [$task->getId()]);
};
[$result, $error, $completed] = maintenanceOrphanRun($runner, $task);
$leaseMailboxCount = maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM mailbox'));
$leaseClaimCount = maintenanceTaskInteger($observer->fetchOne('SELECT COUNT(*) FROM resource_claim'));
maintenanceTaskCheck(
    'lease loss rejects terminal publication after action cleanup and retains uncertain ownership',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_NOT_CLAIMED && $error === null && !$completed
    && $leaseMailboxCount === 0 && $leaseClaimCount === 1
);
maintenanceTaskReconcileFakeBackend($observer, 'lease-loss@example.test');

maintenanceOrphanReset($em);
$backend = new MaintenanceOrphanBackend();
$backend->directories['/mail/cleanup-fault@example.test'] = ['cur'];
$runner = new MaintenanceOrphanRunner($em, $options);
$runner->backend = $backend;
$task = maintenanceOrphanTask($em, \Entities\MailboxTask::TYPE_BACKUP_ORPHAN, 'cleanup-fault@example.test');
$em->getConnection()->executeStatement("CREATE TRIGGER orphan_temp_cleanup_fault BEFORE DELETE ON mailbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected orphan cleanup fault'");
try {
    [$result, $error] = maintenanceOrphanRun($runner, $task);
} finally {
    $em->getConnection()->executeStatement('DROP TRIGGER orphan_temp_cleanup_fault');
}
maintenanceTaskCheck(
    'temp cleanup failure remains a warning and retains the exact sentinel',
    $result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED && $error === null
    && str_contains($task->getLog() ?? '', 'temp user row cleanup failed:')
    && maintenanceTaskInteger($em->getConnection()->fetchOne("SELECT COUNT(*) FROM mailbox WHERE username='cleanup-fault@example.test' AND active=0 AND password LIKE '{PLAIN}!vimbadmin-orphan-backup-temp-v1:%!'")) === 1
);

echo "ALL PASSED\n";
