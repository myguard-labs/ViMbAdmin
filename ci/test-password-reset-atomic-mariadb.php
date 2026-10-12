<?php

declare(strict_types=1);

/**
 * MariaDB integration regression for atomic one-use password-reset proofs.
 */

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 for the isolated test database.\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

$applicationDir = __DIR__ . '/../application';
EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => $applicationDir,
    'repositories_path' => $applicationDir,
]]]);

function passwordResetEntityManager(): EntityManager
{
    $entityDir = realpath(__DIR__ . '/../application/Entities');
    if ($entityDir === false) {
        throw new RuntimeException('application/Entities directory not found');
    }
    $config = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
    $config->enableNativeLazyObjects(true);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ], $config);
    return new EntityManager($connection, $config);
}

/** @return array{0:EntityManager,1:\Entities\Admin,2:\Repositories\Admin} */
function passwordResetSession(int $adminId): array
{
    $em = passwordResetEntityManager();
    $admin = $em->find(\Entities\Admin::class, $adminId);
    $repository = $em->getRepository(\Entities\Admin::class);
    if (!$admin instanceof \Entities\Admin || !$repository instanceof \Repositories\Admin) {
        throw new RuntimeException('password-reset fixture is unavailable');
    }
    \OSS_Runtime::configure([], '', $em);
    $admin->getIndexedPreference('tokens.password_reset');
    return [$em, $admin, $repository];
}

function passwordResetWaitForPath(string $path, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('timed out waiting for synchronization path: ' . basename($path));
        }
        usleep(10_000);
    }
}

function passwordResetRunWorker(string $role, string $syncDir, int $adminId, string $token): int
{
    try {
        [$em, $admin, $repository] = passwordResetSession($adminId);
        if (!touch($syncDir . '/' . strtolower($role) . '-ready')) {
            throw new RuntimeException('failed to publish worker readiness');
        }
        passwordResetWaitForPath($syncDir . '/' . strtolower($role) . '-go', 10.0);
        $published = $repository->resetPasswordWithToken(
            $admin,
            $token,
            'password-' . strtolower($role),
        );
        $admin->setModified(new DateTime('2026-01-0' . ($role === 'A' ? '2' : '3') . ' 00:00:00'));
        $em->flush();
        echo $role . ':' . ($published ? 'published' : 'rejected') . "\n";
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, $role . ': ' . $e->getMessage() . "\n");
        return 1;
    }
}

/** @return array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} */
function passwordResetStartWorker(string $role, string $syncDir, int $adminId, string $token): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker', $role, $syncDir, (string) $adminId, $token],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__ . '/..',
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start password-reset worker ' . $role);
    }
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'last_status' => proc_get_status($process)];
}

/** @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker */
function passwordResetWorkerRemainsBlocked(array &$worker, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    do {
        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            return false;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    return true;
}

/**
 * @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker
 * @return array{exit:int,stdout:string,stderr:string}
 */
function passwordResetWaitWorker(array &$worker, float $timeoutSeconds): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    if ($worker['last_status']['running']) {
        proc_terminate($worker['process']);
        throw new RuntimeException('password-reset worker timed out');
    }
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);
    return [
        'exit' => (int) $worker['last_status']['exitcode'],
        'stdout' => trim($stdout === false ? '' : $stdout),
        'stderr' => trim($stderr === false ? '' : $stderr),
    ];
}

function passwordResetWaitForAdminLock(EntityManager $probe, int $adminId, float $timeoutSeconds): bool
{
    $connection = $probe->getConnection();
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $connection->beginTransaction();
        try {
            $connection->fetchOne('SELECT id FROM admin WHERE id = ? FOR UPDATE NOWAIT', [$adminId]);
            $connection->rollBack();
        } catch (Throwable $e) {
            $connection->rollBack();
            if (stripos($e->getMessage(), 'lock wait timeout') !== false
                || stripos($e->getMessage(), 'nowait') !== false) {
                return true;
            }
            throw $e;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    return false;
}

if (($argv[1] ?? '') === '--worker') {
    exit(passwordResetRunWorker(
        $argv[2] ?? '',
        $argv[3] ?? '',
        (int) ($argv[4] ?? 0),
        $argv[5] ?? '',
    ));
}

$setup = passwordResetEntityManager();
$db = $setup->getConnection();
$existingId = $db->fetchOne('SELECT id FROM admin WHERE username = ?', ['ci028@example.test']);
if ($existingId !== false) {
    $db->delete('admin_pref', ['Admin_id' => $existingId]);
    $db->delete('admin', ['id' => $existingId]);
}
$db->insert('admin', [
    'username' => 'ci028@example.test',
    'password' => 'old-password',
    'super' => 1,
    'active' => 1,
    'created' => '2026-01-01 00:00:00',
]);
$adminId = (int) $db->lastInsertId();

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n");
    }
};
$addProof = static function (string $value, int $index, int $expire) use ($db, $adminId): void {
    $db->insert('admin_pref', [
        'Admin_id' => $adminId,
        'attribute' => 'tokens.password_reset',
        'ix' => $index,
        'op' => '=',
        'value' => \ViMbAdmin_PasswordResetToken::verifier($value),
        'expire' => $expire,
    ]);
};
$clearProofs = static function () use ($db, $adminId): void {
    $db->delete('admin_pref', ['Admin_id' => $adminId, 'attribute' => 'tokens.password_reset']);
};

$token = 'valid-reset-proof';
$addProof($token, 0, time() + 3600);
$addProof('second-live-proof', 1, time() + 3600);
$storedProof = $db->fetchOne(
    'SELECT value FROM admin_pref WHERE Admin_id = ? AND attribute = ? AND ix = 0',
    [$adminId, 'tokens.password_reset'],
);
$check(
    'a database read cannot recover the live reset bearer',
    is_string($storedProof)
        && $storedProof !== $token
        && \ViMbAdmin_PasswordResetToken::matches($storedProof, $token),
);
$syncDir = sys_get_temp_dir() . '/vimbadmin-password-reset-atomic-' . bin2hex(random_bytes(8));
if (!mkdir($syncDir, 0700)) {
    throw new RuntimeException('failed to create synchronization directory');
}
$workerA = null;
$workerB = null;
$db->beginTransaction();
try {
    $lockedProof = $db->fetchOne(
        'SELECT id FROM admin_pref WHERE Admin_id = ? AND attribute = ? AND BINARY value = BINARY ? FOR UPDATE',
        [$adminId, 'tokens.password_reset', \ViMbAdmin_PasswordResetToken::verifier($token)],
    );
    if ($lockedProof === false) {
        throw new RuntimeException('failed to lock the reset-proof fixture');
    }

    $workerA = passwordResetStartWorker('A', $syncDir, $adminId, $token);
    passwordResetWaitForPath($syncDir . '/a-ready', 10.0);
    touch($syncDir . '/a-go');
    $check(
        'the first reset owns the admin-row lock before the second starts',
        passwordResetWaitForAdminLock(passwordResetEntityManager(), $adminId, 2.0),
    );

    $workerB = passwordResetStartWorker('B', $syncDir, $adminId, $token);
    passwordResetWaitForPath($syncDir . '/b-ready', 10.0);
    touch($syncDir . '/b-go');
    $check(
        'the second reset waits while the first reset owns the admin-row lock',
        passwordResetWorkerRemainsBlocked($workerB, 0.25),
    );

    $db->commit();
    $resultA = passwordResetWaitWorker($workerA, 10.0);
    $resultB = passwordResetWaitWorker($workerB, 10.0);
} catch (Throwable $e) {
    if ($db->isTransactionActive()) {
        $db->rollBack();
    }
    foreach ([$workerA, $workerB] as $worker) {
        if (is_array($worker) && $worker['last_status']['running']) {
            proc_terminate($worker['process']);
        }
    }
    throw $e;
} finally {
    foreach (glob($syncDir . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($syncDir);
}

$check('worker A exits cleanly', $resultA['exit'] === 0, $resultA['stderr']);
$check('worker B exits cleanly', $resultB['exit'] === 0, $resultB['stderr']);
$published = array_values(array_filter(
    [$resultA['stdout'], $resultB['stdout']],
    static fn (string $result): bool => str_ends_with($result, ':published'),
));
$rejected = array_values(array_filter(
    [$resultA['stdout'], $resultB['stdout']],
    static fn (string $result): bool => str_ends_with($result, ':rejected'),
));
$check('exactly one overlapping reset consumes the live proof', count($published) === 1);
$check('the losing overlapping reset cannot publish', count($rejected) === 1);
$winningPassword = ($published[0] ?? '') === 'A:published' ? 'password-a' : 'password-b';
$check(
    'the winning reset publishes only its password',
    count($published) === 1
        && $db->fetchOne('SELECT password FROM admin WHERE id = ?', [$adminId]) === $winningPassword,
);
$remainingProofs = $db->fetchOne(
    'SELECT COUNT(*) FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
    [$adminId, 'tokens.password_reset'],
);
$check('one successful reset invalidates every reset proof', $remainingProofs === 0 || $remainingProofs === '0');

$addProof('expired-proof', 0, time() - 1);
[, $expiredAdmin, $expiredRepository] = passwordResetSession($adminId);
$check(
    'an expired boundary proof cannot publish a password',
    !$expiredRepository->resetPasswordWithToken($expiredAdmin, 'expired-proof', 'expired-password'),
);
$check('the expired proof failure preserves the winning password', $db->fetchOne('SELECT password FROM admin WHERE id = ?', [$adminId]) === $winningPassword);

$clearProofs();
$addProof('live-proof', 0, time() + 3600);
[, $malformedAdmin, $malformedRepository] = passwordResetSession($adminId);
$check(
    'an empty malformed proof fails closed',
    !$malformedRepository->resetPasswordWithToken($malformedAdmin, '', 'malformed-password'),
);
$remainingProofs = $db->fetchOne(
    'SELECT COUNT(*) FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
    [$adminId, 'tokens.password_reset'],
);
$check('a malformed proof does not consume the live proof', $remainingProofs === 1 || $remainingProofs === '1');

$transient = new \Entities\Admin();
$transientRepository = $setup->getRepository(\Entities\Admin::class);
$check(
    'an unpersisted admin cannot consume a proof',
    $transientRepository instanceof \Repositories\Admin
        && !$transientRepository->resetPasswordWithToken($transient, 'live-proof', 'transient-password'),
);

$clearProofs();
for ($index = 0; $index < 4; $index++) {
    $addProof('retained-proof-' . $index, $index, time() + 3600);
}
$db->insert('admin_pref', [
    'Admin_id' => $adminId,
    'attribute' => 'tokens.password_reset',
    'ix' => 4,
    'op' => '=',
    'value' => 'legacy-raw-proof',
    'expire' => time() + 3600,
]);
[$migrationManager, $migrationAdmin] = passwordResetSession($adminId);
$storedTokens = $migrationAdmin->getIndexedPreference('tokens.password_reset', true);
foreach (\ViMbAdmin_PasswordResetToken::legacyIndexes($storedTokens) as $legacyIndex) {
    $migrationAdmin->deletePreference('tokens.password_reset', $legacyIndex);
}
$migrationManager->flush();
$migrationAdmin->addIndexedPreference(
    'tokens.password_reset',
    \ViMbAdmin_PasswordResetToken::verifier('new-proof-after-migration'),
    '=',
    time() + 3600,
    5,
);
$migrationManager->flush();
$migratedCount = $db->fetchOne(
    'SELECT COUNT(*) FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
    [$adminId, 'tokens.password_reset'],
);
$check(
    'mixed migration preserves four verifiers and adds one request at the limit',
    $migratedCount === 5 || $migratedCount === '5',
);
$legacyCount = $db->fetchOne(
    'SELECT COUNT(*) FROM admin_pref WHERE Admin_id = ? AND attribute = ? AND value = ?',
    [$adminId, 'tokens.password_reset', 'legacy-raw-proof'],
);
$check(
    'mixed migration removes the legacy live bearer from the database',
    $legacyCount === 0 || $legacyCount === '0',
);

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
