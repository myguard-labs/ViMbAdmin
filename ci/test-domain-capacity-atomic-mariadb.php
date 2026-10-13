<?php

declare(strict_types=1);

/** Real ORM sessions: a stale domain admission cannot publish any part of its flush. */
if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 for the isolated test database.\n");
    exit(2);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/plugins/MailboxAutomaticAliases.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => __DIR__ . '/../application',
    'repositories_path' => __DIR__ . '/../application',
]]]);

final class CapacitySqlLogger extends \Psr\Log\AbstractLogger
{
    public function __construct(private string $path)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        // SQL templates only: never log connection or parameter values.
        if (isset($context['sql']) && is_string($context['sql'])) {
            file_put_contents($this->path, $context['sql'] . "\n", FILE_APPEND);
        }
    }
}

function capacityManager(?string $sqlTrace = null): EntityManager
{
    $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../application/Entities'], true);
    $config->enableNativeLazyObjects(true);
    if ($sqlTrace !== null) {
        $logger = new CapacitySqlLogger($sqlTrace);
        $config->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
    }
    return new EntityManager(DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ], $config), $config);
}

function capacityWait(callable $predicate): void
{
    $deadline = microtime(true) + 15;
    do {
        clearstatcache();
        if ($predicate()) {
            return;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('capacity synchronization timed out');
}

function capacityInteger(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }
    throw new RuntimeException('invalid database integer');
}

function capacityDomain(EntityManager $em, int $id): \Entities\Domain
{
    $domain = $em->find(\Entities\Domain::class, $id);
    if (!$domain instanceof \Entities\Domain) {
        throw new RuntimeException('capacity domain fixture missing');
    }
    return $domain;
}

function capacityActor(EntityManager $em, int $id): \Entities\Admin
{
    $actor = $em->find(\Entities\Admin::class, $id);
    if (!$actor instanceof \Entities\Admin) {
        throw new RuntimeException('capacity actor fixture missing');
    }
    return $actor;
}

function capacityCreateAlias(EntityManager $em, \Entities\Domain $domain, \Entities\Admin $actor, string $local, ?callable $pre = null, ?callable $post = null, bool $self = false): void
{
    $address = $local . '@' . $domain->requiredDomainName();
    $alias = (new \Entities\Alias())->setAddress($address)->setGoto($self ? $address : 'destination@example.test');
    (new ViMbAdmin_Service_Alias($em))->create($alias, $domain, $actor, $pre, $post);
}

/** @return list<list<array<string,mixed>>> */
function capacitySnapshot(EntityManager $em): array
{
    $db = $em->getConnection();
    return [
        $db->fetchAllAssociative('SELECT id, alias_count, mailbox_count, max_aliases, max_mailboxes, capacity_version FROM domain ORDER BY id'),
        $db->fetchAllAssociative('SELECT id, address, goto FROM alias ORDER BY id'),
        $db->fetchAllAssociative('SELECT id, username FROM mailbox ORDER BY id'),
        $db->fetchAllAssociative('SELECT id, action, data FROM log ORDER BY id'),
    ];
}

if (($argv[1] ?? '') === '--worker') {
    $case = $argv[2];
    $sync = $argv[3];
    $em = capacityManager($sync . '/sql');
    $domain = capacityDomain($em, (int) $argv[4]);
    $other = capacityDomain($em, (int) $argv[5]);
    $actor = capacityActor($em, (int) $argv[6]);
    $hooks = [];
    $pre = static function () use (&$hooks, $sync, $em): void {
        $hooks[] = 'pre';
        file_put_contents($sync . '/pre', (string) capacityInteger($em->getConnection()->fetchOne('SELECT CONNECTION_ID()')));
    };
    $post = static function () use (&$hooks): void {
        $hooks[] = 'post';
    };
    file_put_contents($sync . '/ready', (string) capacityInteger($em->getConnection()->fetchOne('SELECT CONNECTION_ID()')));
    capacityWait(static fn (): bool => is_file($sync . '/go'));
    try {
        if ($case === 'mailbox') {
            $mailbox = (new \Entities\Mailbox())->setUsername('loser@capacity-primary.test')->setLocalPart('loser')->setName('Loser')->setQuota(0)->setPassword('test-password');
            ViMbAdmin_Service_Mailbox::applyCreationPolicy($mailbox, $domain);
            (new ViMbAdmin_Service_Mailbox($em))->create($mailbox, $domain, $actor, ['defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']], 'mailboxAliases' => true], $pre, $post);
        } elseif ($case === 'delete') {
            $alias = $em->getRepository(\Entities\Alias::class)->findOneBy(['address' => 'old@capacity-primary.test']);
            if (!$alias instanceof \Entities\Alias) {
                throw new RuntimeException('delete alias fixture missing');
            }
            (new ViMbAdmin_Service_Alias($em))->delete($alias, $actor, null, $pre, $post);
        } else {
            if ($domain->getMaxAliases() !== 0 && $domain->getAliasCount() >= $domain->getMaxAliases()) {
                throw new RuntimeException('fixture did not pass stale alias admission');
            }
            if ($case === 'net-zero' || $case === 'multi-domain') {
                $removalDomain = $case === 'multi-domain' ? $other : $domain;
                $old = $em->getRepository(\Entities\Alias::class)->findOneBy(['address' => 'old@' . $removalDomain->requiredDomainName()]);
                if (!$old instanceof \Entities\Alias) {
                    throw new RuntimeException('replacement alias fixture missing');
                }
                $em->remove($old);
                $removalDomain->decreaseAliasCount();
            }
            capacityCreateAlias($em, $domain, $actor, 'loser', $pre, $post);
        }
        echo 'PUBLISHED ' . json_encode($hooks) . "\n";
    } catch (OptimisticLockException) {
        echo 'CONFLICT ' . json_encode($hooks) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

$setup = capacityManager();
$db = $setup->getConnection();
$db->insert('admin', ['username' => 'capacity-actor@example.test', 'password' => 'test', 'super' => 1, 'active' => 1, 'created' => '2026-01-01 00:00:00']);
$actorId = (int) $db->lastInsertId();
$ids = [];
foreach (['primary', 'secondary'] as $name) {
    $db->insert('domain', ['domain' => 'capacity-' . $name . '.test', 'quota' => 0, 'transport' => 'virtual', 'backupmx' => 0, 'active' => 1, 'created' => '2026-01-01 00:00:00']);
    $ids[] = (int) $db->lastInsertId();
}
$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks++;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'ok: ' . $label . "\n";
};
$sync = sys_get_temp_dir() . '/vimbadmin-capacity-' . bin2hex(random_bytes(8));
mkdir($sync, 0700);
$process = null;
$winner = null;
$pipes = [];
try {
    foreach (['alias', 'mailbox', 'delete', 'net-zero', 'multi-domain', 'policy'] as $case) {
        $db->executeStatement('DELETE FROM log');
        $db->executeStatement('DELETE FROM alias');
        $db->executeStatement('DELETE FROM mailbox');
        $db->executeStatement('UPDATE domain SET alias_count = 0, mailbox_count = 0, max_aliases = 1, max_mailboxes = 1, capacity_version = capacity_version + 1');
        foreach (glob($sync . '/*') ?: [] as $path) {
            unlink($path);
        }
        $winner = capacityManager();
        $domain = capacityDomain($winner, $ids[0]);
        $other = capacityDomain($winner, $ids[1]);
        $actor = capacityActor($winner, $actorId);
        if (in_array($case, ['delete', 'net-zero', 'policy'], true)) {
            $domain->setMaxAliases(2);
            capacityCreateAlias($winner, $domain, $actor, 'old');
        } elseif ($case === 'multi-domain') {
            $other->setMaxAliases(2);
            capacityCreateAlias($winner, $other, $actor, 'old');
        }
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $case, $sync, (string) $ids[0], (string) $ids[1], (string) $actorId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('failed to start capacity worker');
        }
        fclose($pipes[0]);
        capacityWait(static fn (): bool => is_file($sync . '/ready'));
        $winner->getConnection()->beginTransaction();
        if ($case === 'mailbox') {
            $mailbox = (new \Entities\Mailbox())->setUsername('winner@capacity-primary.test')->setLocalPart('winner')->setName('Winner')->setQuota(0)->setPassword('test-password');
            ViMbAdmin_Service_Mailbox::applyCreationPolicy($mailbox, $domain);
            (new ViMbAdmin_Service_Mailbox($winner))->create($mailbox, $domain, $actor, ['defaults' => ['mailbox' => ['password_scheme' => 'crypt:sha512']], 'mailboxAliases' => true]);
        } elseif ($case === 'policy') {
            $domain->setMaxAliases(1);
            $winner->flush();
        } else {
            if ($case === 'net-zero') {
                $old = $winner->getRepository(\Entities\Alias::class)->findOneBy(['address' => 'old@capacity-primary.test']);
                if (!$old instanceof \Entities\Alias) {
                    throw new RuntimeException('winner replacement alias fixture missing');
                }
                $winner->remove($old);
                $domain->decreaseAliasCount();
            }
            capacityCreateAlias($winner, $case === 'multi-domain' ? $other : $domain, $actor, 'winner');
        }
        // A ready marker proves the child loaded its domain in an independent
        // session before the winning transition. No transaction-table snapshot
        // is used: MariaDB can retain an early INNODB_TRX view during polling.
        $readyId = (int) file_get_contents($sync . '/ready');
        $probe = capacityManager()->getConnection();
        $probe->beginTransaction();
        $locked = false;
        try {
            $probe->fetchOne('SELECT id FROM domain WHERE id = ? FOR UPDATE NOWAIT', [$case === 'multi-domain' ? $ids[1] : $ids[0]]);
        } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            $locked = true;
        } finally {
            $probe->rollBack();
            $probe->close();
        }
        $check($case . ': winner really holds the domain row lock', $locked);
        touch($sync . '/go');
        capacityWait(static fn (): bool => is_file($sync . '/pre'));
        $connectionId = (int) file_get_contents($sync . '/pre');
        $check($case . ': preFlush uses the independently loaded session', $connectionId === $readyId);
        // Observe the live server thread executing the child flush mutation
        // while the winner still holds that lock; do not infer overlap from a
        // sleep or from a SQL log written before the driver executes it.
        try {
            capacityWait(static function () use ($connectionId): bool {
                $monitor = capacityManager()->getConnection();
                try {
                    $threads = $monitor->fetchAllAssociative('SHOW FULL PROCESSLIST');
                } finally {
                    $monitor->close();
                }
                foreach ($threads as $thread) {
                    if (capacityInteger($thread['Id']) === $connectionId && $thread['Command'] === 'Query'
                        && is_string($thread['Info']) && preg_match('/^(INSERT INTO (alias|mailbox|log)|UPDATE domain SET|DELETE FROM alias)/', $thread['Info']) === 1) {
                        return true;
                    }
                }
                return false;
            });
        } catch (RuntimeException $error) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            fwrite(STDERR, 'worker diagnostic: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            throw new RuntimeException('worker connection ' . $connectionId . ' did not execute a concurrent flush mutation', 0, $error);
        }
        $check($case . ': independent session executes its flush while the winner holds the lock', true);
        $winner->getConnection()->commit();
        $snapshot = capacitySnapshot($setup);
        capacityWait(static fn (): bool => !proc_get_status($process)['running']);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $process = null;
        $check($case . ': stale transition rejects before postFlush (' . trim($output) . ' ' . trim($error) . ')', trim($output) === 'CONFLICT ["pre"]' && $error === '');
        $check($case . ': all loser children, logs and domain updates roll back', capacitySnapshot($setup) === $snapshot);
        $sql = file_get_contents($sync . '/sql');
        $check($case . ': actual domain UPDATE compares the loaded version', is_string($sql) && preg_match('/UPDATE domain SET .* WHERE id = \? AND capacity_version = \?/', $sql) === 1);
        if ($case !== 'delete') {
            $check($case . ': child INSERT executes before the rejected domain CAS', is_string($sql) && preg_match('/INSERT INTO (alias|mailbox).*\n(?:.*\n)*UPDATE domain SET /', $sql) === 1);
        }
        $winner->getConnection()->close();
    }

    // A successful postFlush creates a separate alias transition with the
    // refreshed domain version, as the automatic-alias plugin does.
    $em = capacityManager();
    $domain = capacityDomain($em, $ids[0]);
    $actor = capacityActor($em, $actorId);
    $version = capacityInteger($db->fetchOne('SELECT capacity_version FROM domain WHERE id = ?', [$ids[0]]));
    $before = $domain->getAliasCount();
    capacityCreateAlias($em, $domain, $actor, 'postflush-parent', null, static function () use ($em, $domain, $actor): void {
        $alias = $em->getRepository(\Entities\Alias::class)->findOneBy(['address' => 'postflush-parent@capacity-primary.test']);
        if (!$alias instanceof \Entities\Alias) {
            throw new RuntimeException('postflush parent alias missing');
        }
        $options = ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => ['defaultAliases' => ['postflush-automatic']]]];
        $context = new \ViMbAdmin\Kernel\Plugin\AliasContext($em, $actor, $domain, $alias, $options, new \ViMbAdmin\Kernel\Flash\FlashMessages(new \ViMbAdmin\Kernel\Session\MagicPropertyStorage(new stdClass())));
        (new ViMbAdminPlugin_MailboxAutomaticAliases($context))->alias_add_addPostflush($context, $options);
    });
    $check('postFlush automatic aliases advance the refreshed version in two commits', $domain->getCapacityVersion() === $version + 2 && capacityInteger($db->fetchOne('SELECT capacity_version FROM domain WHERE id = ?', [$ids[0]])) === $version + 2 && $domain->getAliasCount() === $before + 2);
    $version += 2;
    capacityCreateAlias($em, $domain, $actor, 'self', null, null, true);
    $check('boundary: exact self-alias is counter and version exempt', capacityInteger($db->fetchOne('SELECT capacity_version FROM domain WHERE id = ?', [$ids[0]])) === $version && $domain->getAliasCount() === $before + 2);
    $em->getConnection()->close();
} finally {
    if ($winner instanceof EntityManager) {
        $winner->getConnection()->close();
    }
    if (is_resource($process)) {
        touch($sync . '/go');
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($sync . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($sync);
    $db->executeStatement('DELETE FROM log');
    $db->executeStatement('DELETE FROM alias');
    $db->executeStatement('DELETE FROM mailbox');
    foreach ($ids as $id) {
        $db->delete('domain', ['id' => $id]);
    }
    $db->delete('admin', ['id' => $actorId]);
}
echo 'ALL PASSED (' . $checks . " checks)\n";
