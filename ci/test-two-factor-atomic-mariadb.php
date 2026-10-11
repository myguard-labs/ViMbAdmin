<?php

declare(strict_types=1);

/**
 * MariaDB integration regression for atomic one-time second factors.
 *
 * Two independent entity managers load the same credential before either
 * consumes it. Exactly one stale snapshot may complete the database-backed
 * compare-and-swap.
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

function secondFactorEntityManager(): EntityManager
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

/** @return array{0:EntityManager,1:\Entities\Admin} */
function secondFactorSession(int $adminId): array
{
    $em = secondFactorEntityManager();
    $admin = $em->find(\Entities\Admin::class, $adminId);
    if (!$admin instanceof \Entities\Admin) {
        throw new RuntimeException('admin fixture not found');
    }
    return [$em, $admin];
}

function configureSecondFactorRuntime(EntityManager $em): void
{
    \OSS_Runtime::configure([], '', $em);
}

$setup = secondFactorEntityManager();
$db = $setup->getConnection();
$existingId = $db->fetchOne('SELECT id FROM admin WHERE username = ?', ['ci027@example.test']);
if ($existingId !== false) {
    $db->delete('admin_pref', ['Admin_id' => $existingId]);
    $db->delete('admin', ['id' => $existingId]);
}
$db->insert('admin', [
    'username' => 'ci027@example.test',
    'password' => 'unused',
    'super' => 1,
    'active' => 1,
    'created' => '2026-01-01 00:00:00',
]);
$adminId = (int) $db->lastInsertId();

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
};

$twoFactor = new \ViMbAdmin_TwoFactor('CI-027', 'test-security-salt');
$secret = $twoFactor->createSecret();
$encrypt = new ReflectionMethod($twoFactor, '_encrypt');
$ciphertext = $encrypt->invoke($twoFactor, $secret);
$engineProperty = new ReflectionProperty($twoFactor, '_tfa');
$engine = $engineProperty->getValue($twoFactor);
if (!$engine instanceof \RobThree\Auth\TwoFactorAuth) {
    throw new RuntimeException('two-factor engine fixture is unavailable');
}
$code = $engine->getCode($secret);
$backupCode = '23456789AB';
$backupState = json_encode([password_hash($backupCode, PASSWORD_BCRYPT)], JSON_THROW_ON_ERROR);
$db->insert('admin_pref', [
    'Admin_id' => $adminId,
    'attribute' => \ViMbAdmin_TwoFactor::PREF_SECRET,
    'ix' => 0,
    'op' => '=',
    'value' => $ciphertext,
    'expire' => 0,
]);
$db->insert('admin_pref', [
    'Admin_id' => $adminId,
    'attribute' => \ViMbAdmin_TwoFactor::PREF_BACKUP,
    'ix' => 0,
    'op' => '=',
    'value' => $backupState,
    'expire' => 0,
]);

[$totpEmA, $totpAdminA] = secondFactorSession($adminId);
[$totpEmB, $totpAdminB] = secondFactorSession($adminId);
configureSecondFactorRuntime($totpEmA);
$totpAdminA->getPreference(\ViMbAdmin_TwoFactor::PREF_LASTTS);
configureSecondFactorRuntime($totpEmB);
$totpAdminB->getPreference(\ViMbAdmin_TwoFactor::PREF_LASTTS);
configureSecondFactorRuntime($totpEmA);
$totpA = $twoFactor->verifyForAdmin($totpAdminA, $code);
configureSecondFactorRuntime($totpEmB);
$totpB = $twoFactor->verifyForAdmin($totpAdminB, $code);
$totpAdminA->setLastLogin(new DateTime());
$totpEmA->flush();
$check('one independent database session consumes a fresh TOTP slice', $totpA);
$check('the second stale database session cannot reuse that TOTP slice', !$totpB);

[$backupEmA, $backupAdminA] = secondFactorSession($adminId);
[$backupEmB, $backupAdminB] = secondFactorSession($adminId);
configureSecondFactorRuntime($backupEmA);
$backupAdminA->getPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP);
configureSecondFactorRuntime($backupEmB);
$backupAdminB->getPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP);
configureSecondFactorRuntime($backupEmA);
$backupA = $twoFactor->consumeBackupCode($backupAdminA, $backupCode);
$backupAdminA->setLastLogin(new DateTime());
$backupEmA->flush();
configureSecondFactorRuntime($backupEmB);
$backupB = $twoFactor->consumeBackupCode($backupAdminB, $backupCode);
$check('one independent database session consumes a valid backup code', $backupA);
$check('the second stale database session cannot reuse that backup code', !$backupB);
$check(
    'the consumed backup state is an empty JSON list',
    $db->fetchOne(
        'SELECT value FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
        [$adminId, \ViMbAdmin_TwoFactor::PREF_BACKUP],
    ) === '[]',
);

$backupCodeA = '3456789ABC';
$backupCodeB = '456789ABCD';
$backupHashB = password_hash($backupCodeB, PASSWORD_BCRYPT);
$twoCodeState = json_encode(
    [password_hash($backupCodeA, PASSWORD_BCRYPT), $backupHashB],
    JSON_THROW_ON_ERROR,
);
$db->executeStatement(
    'UPDATE admin_pref SET value = ? WHERE Admin_id = ? AND attribute = ?',
    [$twoCodeState, $adminId, \ViMbAdmin_TwoFactor::PREF_BACKUP],
);
[$delayedEmA, $delayedAdminA] = secondFactorSession($adminId);
configureSecondFactorRuntime($delayedEmA);
$delayedAdminA->getPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP);
$check(
    'actor A consumes the first backup code through compare-and-swap',
    $twoFactor->consumeBackupCode($delayedAdminA, $backupCodeA),
);
[$delayedEmB, $delayedAdminB] = secondFactorSession($adminId);
configureSecondFactorRuntime($delayedEmB);
$check(
    'actor B consumes the second backup code after actor A compare-and-swap',
    $twoFactor->consumeBackupCode($delayedAdminB, $backupCodeB),
);
$delayedAdminB->setLastLogin(new DateTime());
$delayedEmB->flush();
$delayedAdminA->setLastLogin(new DateTime());
$delayedEmA->flush();
$check(
    'actor A delayed flush does not restore actor B consumed backup-code state',
    $db->fetchOne(
        'SELECT value FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
        [$adminId, \ViMbAdmin_TwoFactor::PREF_BACKUP],
    ) === '[]',
);

$db->executeStatement(
    'UPDATE admin_pref SET value = ? WHERE Admin_id = ? AND attribute = ?',
    ['{"malformed":"state"}', $adminId, \ViMbAdmin_TwoFactor::PREF_BACKUP],
);
[$malformedEm, $malformedAdmin] = secondFactorSession($adminId);
configureSecondFactorRuntime($malformedEm);
$check(
    'malformed backup state fails closed without a database transition',
    !$twoFactor->consumeBackupCode($malformedAdmin, $backupCode),
);
$check(
    'malformed TOTP input fails before changing the replay slice',
    !$twoFactor->verifyForAdmin($malformedAdmin, 'not-a-code'),
);
$check(
    'the accepted TOTP slice remains present after malformed input',
    is_string($db->fetchOne(
        'SELECT value FROM admin_pref WHERE Admin_id = ? AND attribute = ?',
        [$adminId, \ViMbAdmin_TwoFactor::PREF_LASTTS],
    )),
);

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
