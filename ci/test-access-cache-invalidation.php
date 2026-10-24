<?php

declare(strict_types=1);

/**
 * Prove authorization-filtered preference results change cache identity with
 * every committed administrator scope change while unrelated entries remain.
 */
if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 for the isolated test database.\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Admin.php';
require __DIR__ . '/../library/ViMbAdmin/Service/Domain.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => __DIR__ . '/../application',
    'repositories_path' => __DIR__ . '/../application',
]]]);

function accessCacheManager(ArrayAdapter $cache, ?EventManager $events = null): EntityManager
{
    $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../application/Entities'], true);
    if (PHP_VERSION_ID >= 80400) {
        $config->enableNativeLazyObjects(true);
    }
    $config->setResultCache($cache);

    return new EntityManager(DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ], $config), $config, $events);
}

/**
 * @return list<string>
 * @phpstan-impure
 */
function accessCacheValues(EntityManager $em, \Entities\Admin $admin): array
{
    $repository = $em->getRepository(\Entities\MailboxPreference::class);
    if (!$repository instanceof \Repositories\MailboxPreference) {
        throw new RuntimeException('MailboxPreference repository fixture missing');
    }
    $values = $repository->loadPrefrenceValuesByAttribute('xpiInfo.department', $admin);
    sort($values, SORT_STRING);
    return $values;
}

function accessCacheAdmin(EntityManager $em, int $id): \Entities\Admin
{
    $admin = $em->find(\Entities\Admin::class, $id);
    if (!$admin instanceof \Entities\Admin) {
        throw new RuntimeException('admin fixture missing');
    }
    return $admin;
}

function accessCacheDomain(EntityManager $em, int $id): \Entities\Domain
{
    $domain = $em->find(\Entities\Domain::class, $id);
    if (!$domain instanceof \Entities\Domain) {
        throw new RuntimeException('domain fixture missing');
    }
    return $domain;
}

/** @param list<int> $domainIds */
function accessCacheResetScope(Connection $db, int $adminId, array $domainIds, bool $super): void
{
    $db->executeStatement('DELETE FROM domain_admins WHERE Admin_id = ?', [$adminId]);
    foreach ($domainIds as $domainId) {
        $db->insert('domain_admins', ['Admin_id' => $adminId, 'Domain_id' => $domainId]);
    }
    $db->update('admin', ['super' => $super ? 1 : 0], ['id' => $adminId]);
}

/**
 * @param list<int> $initialDomains
 * @param list<string> $warmValues
 * @param list<string> $expectedValues
 * @param callable(EntityManager,\Entities\Admin,\Entities\Admin,array<int,\Entities\Domain>):void $mutation
 */
function accessCacheMutationCase(
    string $label,
    ArrayAdapter $cache,
    Connection $db,
    int $targetId,
    int $actorId,
    array $domainIds,
    array $initialDomains,
    array $warmValues,
    array $expectedValues,
    callable $mutation,
): int {
    accessCacheResetScope($db, $targetId, $initialDomains, false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();

    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    $actor = accessCacheAdmin($em, $actorId);
    $domains = [];
    foreach ($domainIds as $domainId) {
        $domains[$domainId] = accessCacheDomain($em, $domainId);
    }

    if (accessCacheValues($em, $target) !== $warmValues) {
        throw new RuntimeException('FAIL: ' . $label . ' warm values');
    }
    $mutation($em, $target, $actor, $domains);

    $checks = 0;
    if (accessCacheValues($em, $target) !== $expectedValues) {
        throw new RuntimeException('FAIL: ' . $label . ' same-request cache invalidation');
    }
    echo 'ok: ' . $label . " same-request cache invalidation\n";
    $checks++;

    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== $expectedValues) {
        throw new RuntimeException('FAIL: ' . $label . ' cross-request cache invalidation');
    }
    echo 'ok: ' . $label . " cross-request cache invalidation\n";
    $checks++;

    return $checks;
}

$cache = new ArrayAdapter(3600, false);
$setup = accessCacheManager($cache);
$db = $setup->getConnection();
$prefix = 'access-cache-' . bin2hex(random_bytes(6));
$ids = ['admins' => [], 'domains' => [], 'mailboxes' => [], 'preferences' => []];
$checks = 0;
$poisonFailures = [];

try {
    foreach ([
        'actor' => ['super' => 1],
        'target' => ['super' => 0],
        'control' => ['super' => 0],
    ] as $name => $values) {
        $db->insert('admin', [
            'username' => $prefix . '-' . $name . '@example.test',
            'password' => 'test',
            'super' => $values['super'],
            'active' => 1,
            'created' => '2026-01-01 00:00:00',
        ]);
        $ids['admins'][$name] = (int) $db->lastInsertId();
    }

    foreach (['alpha', 'beta'] as $name) {
        $db->insert('domain', [
            'domain' => $prefix . '-' . $name . '.test',
            'quota' => 0,
            'transport' => 'virtual',
            'backupmx' => 0,
            'active' => 1,
            'created' => '2026-01-01 00:00:00',
        ]);
        $domainId = (int) $db->lastInsertId();
        $ids['domains'][$name] = $domainId;
        $db->insert('mailbox', [
            'username' => $name . '@' . $prefix . '-' . $name . '.test',
            'password' => 'test',
            'quota' => 0,
            'local_part' => $name,
            'active' => 1,
            'created' => '2026-01-01 00:00:00',
            'Domain_id' => $domainId,
        ]);
        $mailboxId = (int) $db->lastInsertId();
        $ids['mailboxes'][] = $mailboxId;
        $db->insert('mailbox_pref', [
            'attribute' => 'xpiInfo.department',
            'ix' => 0,
            'op' => ':=',
            'value' => $name,
            'expire' => 0,
            'Mailbox_id' => $mailboxId,
        ]);
        $ids['preferences'][$name] = (int) $db->lastInsertId();
    }

    $targetId = $ids['admins']['target'];
    $actorId = $ids['admins']['actor'];
    $alphaId = $ids['domains']['alpha'];
    $betaId = $ids['domains']['beta'];
    $domainIds = [$alphaId, $betaId];

    $checks += accessCacheMutationCase(
        'admin revoke',
        $cache,
        $db,
        $targetId,
        $actorId,
        $domainIds,
        [$alphaId],
        ['alpha'],
        [],
        static function (EntityManager $em, \Entities\Admin $target, \Entities\Admin $actor, array $domains) use ($alphaId): void {
            (new \ViMbAdmin_Service_Admin($em))->removeDomain($target, $domains[$alphaId], $actor);
        },
    );
    $checks += accessCacheMutationCase(
        'domain grant',
        $cache,
        $db,
        $targetId,
        $actorId,
        $domainIds,
        [],
        [],
        ['beta'],
        static function (EntityManager $em, \Entities\Admin $target, \Entities\Admin $actor, array $domains) use ($betaId): void {
            (new \ViMbAdmin_Service_Domain($em))->assignAdmin($domains[$betaId], $target, $actor);
        },
    );
    $checks += accessCacheMutationCase(
        'admin grant',
        $cache,
        $db,
        $targetId,
        $actorId,
        $domainIds,
        [],
        [],
        ['alpha'],
        static function (EntityManager $em, \Entities\Admin $target, \Entities\Admin $actor, array $domains) use ($alphaId): void {
            (new \ViMbAdmin_Service_Admin($em))->assignDomain($target, $domains[$alphaId], $actor);
        },
    );
    $checks += accessCacheMutationCase(
        'domain revoke',
        $cache,
        $db,
        $targetId,
        $actorId,
        $domainIds,
        [$betaId],
        ['beta'],
        [],
        static function (EntityManager $em, \Entities\Admin $target, \Entities\Admin $actor, array $domains) use ($betaId): void {
            (new \ViMbAdmin_Service_Domain($em))->removeAdmin($domains[$betaId], $target, $actor);
        },
    );

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: super promotion warm values');
    }
    (new \ViMbAdmin_Service_Admin($em))->toggleSuper($target, accessCacheAdmin($em, $actorId));
    if (accessCacheValues($em, $target) !== ['alpha', 'beta']) {
        throw new RuntimeException('FAIL: super promotion same-request cache invalidation');
    }
    echo "ok: super promotion same-request cache invalidation\n";
    $checks++;
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== ['alpha', 'beta']) {
        throw new RuntimeException('FAIL: super promotion cross-request cache invalidation');
    }
    echo "ok: super promotion cross-request cache invalidation\n";
    $checks++;

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    $actor = accessCacheAdmin($em, $actorId);
    $alpha = accessCacheDomain($em, $alphaId);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: revoke-then-regrant warm values');
    }
    $initialScopeKeys = array_keys($cache->getValues());
    sort($initialScopeKeys, SORT_STRING);
    (new \ViMbAdmin_Service_Admin($em))->removeDomain($target, $alpha, $actor);
    if (accessCacheValues($em, $target) !== []) {
        throw new RuntimeException('FAIL: revoke-then-regrant revoked values');
    }
    $revokedScopeKeys = array_keys($cache->getValues());
    sort($revokedScopeKeys, SORT_STRING);
    if (count($initialScopeKeys) !== 1 || count($revokedScopeKeys) !== 2
        || array_diff($initialScopeKeys, $revokedScopeKeys) !== []) {
        throw new RuntimeException('FAIL: revoke-then-regrant generated distinct scoped cache identities');
    }
    echo "ok: revoke-then-regrant generates a distinct revoked cache identity\n";
    $checks++;
    (new \ViMbAdmin_Service_Admin($em))->assignDomain($target, $alpha, $actor);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: revoke-then-regrant restored values');
    }
    $regrantedScopeKeys = array_keys($cache->getValues());
    sort($regrantedScopeKeys, SORT_STRING);
    if ($regrantedScopeKeys !== $revokedScopeKeys) {
        throw new RuntimeException('FAIL: revoke-then-regrant reuses prior cache identity');
    }
    echo "ok: revoke-then-regrant reuses prior cache identity for unchanged preference data\n";
    $checks++;
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== ['alpha']) {
        throw new RuntimeException('FAIL: revoke-then-regrant cross-request cache identity');
    }
    echo "ok: revoke-then-regrant cross-request cache identity\n";
    $checks++;
    $duplicateRegrant = null;
    try {
        (new \ViMbAdmin_Service_Admin($em))->assignDomain($target, $alpha, $actor);
    } catch (\ViMbAdmin_Service_Exception $exception) {
        $duplicateRegrant = $exception->getMessage();
    }
    if ($duplicateRegrant !== 'This domain is already assigned to the admin.') {
        throw new RuntimeException('FAIL: duplicate regrant was not rejected');
    }
    $duplicateScopeKeys = array_keys($cache->getValues());
    sort($duplicateScopeKeys, SORT_STRING);
    if (accessCacheValues($em, $target) !== ['alpha']
        || $duplicateScopeKeys !== $regrantedScopeKeys) {
        throw new RuntimeException('FAIL: rejected duplicate regrant changed cache state');
    }
    echo "ok: rejected duplicate regrant preserves values and cache identity\n";
    $checks++;

    accessCacheResetScope($db, $targetId, [$alphaId], true);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    if (accessCacheValues($em, $target) !== ['alpha', 'beta']) {
        throw new RuntimeException('FAIL: super scope warm values');
    }
    (new \ViMbAdmin_Service_Admin($em))->toggleSuper($target, accessCacheAdmin($em, $actorId));
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: super revoke same-request cache invalidation');
    }
    echo "ok: super revoke same-request cache invalidation\n";
    $checks++;
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== ['alpha']) {
        throw new RuntimeException('FAIL: super revoke cross-request cache invalidation');
    }
    echo "ok: super revoke cross-request cache invalidation\n";
    $checks++;

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    accessCacheResetScope($db, $ids['admins']['control'], [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $control = accessCacheAdmin($em, $ids['admins']['control']);
    if (accessCacheValues($em, $control) !== ['alpha']) {
        throw new RuntimeException('FAIL: unaffected authorization warm values');
    }
    $db->update('mailbox_pref', ['value' => 'database-changed'], ['id' => $ids['preferences']['alpha']]);
    $target = accessCacheAdmin($em, $targetId);
    (new \ViMbAdmin_Service_Admin($em))->removeDomain($target, accessCacheDomain($em, $alphaId), accessCacheAdmin($em, $actorId));
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $ids['admins']['control'])) !== ['alpha']) {
        throw new RuntimeException('FAIL: unrelated authorized cache entry was broadened or cleared');
    }
    echo "ok: unrelated authorized cache entry remains scoped and cached\n";
    $checks++;
    $db->update('mailbox_pref', ['value' => 'alpha'], ['id' => $ids['preferences']['alpha']]);

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: rollback boundary warm values');
    }
    $cachedKeys = array_keys($cache->getValues());
    sort($cachedKeys, SORT_STRING);
    $connection = $em->getConnection();
    $connection->beginTransaction();
    try {
        (new \ViMbAdmin_Service_Admin($em))->removeDomain(
            $target,
            accessCacheDomain($em, $alphaId),
            accessCacheAdmin($em, $actorId),
        );
        if (accessCacheValues($em, $target) !== []) {
            throw new RuntimeException('FAIL: active transaction uses the uncommitted authorization snapshot');
        }
        $activeTransactionKeys = array_keys($cache->getValues());
        sort($activeTransactionKeys, SORT_STRING);
        if ($activeTransactionKeys !== $cachedKeys) {
            throw new RuntimeException('FAIL: active transaction published a shared result-cache entry');
        }
        echo "ok: active transaction does not publish shared authorization results\n";
        $checks++;
    } finally {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
    $db->update('mailbox_pref', ['value' => 'database-changed'], ['id' => $ids['preferences']['alpha']]);
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== ['alpha']) {
        throw new RuntimeException('FAIL: rolled-back access change invalidated committed authorization cache');
    }
    echo "ok: rolled-back access change preserves committed authorization generation\n";
    $checks++;
    $db->update('mailbox_pref', ['value' => 'alpha'], ['id' => $ids['preferences']['alpha']]);

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $em = accessCacheManager($cache);
    $target = accessCacheAdmin($em, $targetId);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: same-EM rollback poison warm values');
    }
    $em->getConnection()->beginTransaction();
    (new \ViMbAdmin_Service_Admin($em))->removeDomain(
        $target,
        accessCacheDomain($em, $alphaId),
        accessCacheAdmin($em, $actorId),
    );
    $em->getConnection()->rollBack();
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: same-EM rollback restored authorization read');
    }
    $commit = accessCacheManager($cache);
    (new \ViMbAdmin_Service_Admin($commit))->removeDomain(
        accessCacheAdmin($commit, $targetId),
        accessCacheDomain($commit, $alphaId),
        accessCacheAdmin($commit, $actorId),
    );
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== []) {
        $poisonFailures[] = 'same-EM rollback cache poison survived committed revoke';
    } else {
        echo "ok: same-EM rollback cannot poison a later revoked scope\n";
        $checks++;
    }

    accessCacheResetScope($db, $targetId, [$alphaId], false);
    $db->executeStatement('DELETE FROM log');
    $cache->clear();
    $events = new EventManager();
    $events->addEventListener([Events::onFlush], new class () {
        private bool $pending = true;

        public function onFlush(): void
        {
            if ($this->pending) {
                $this->pending = false;
                throw new RuntimeException('injected access mutation flush failure');
            }
        }
    });
    $em = accessCacheManager($cache, $events);
    $target = accessCacheAdmin($em, $targetId);
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: flush-failure poison warm values');
    }
    $flushFailure = null;
    try {
        (new \ViMbAdmin_Service_Admin($em))->removeDomain(
            $target,
            accessCacheDomain($em, $alphaId),
            accessCacheAdmin($em, $actorId),
        );
    } catch (RuntimeException $exception) {
        $flushFailure = $exception->getMessage();
    }
    if ($flushFailure !== 'injected access mutation flush failure') {
        throw new RuntimeException('FAIL: access mutation flush failure was not observed');
    }
    if (accessCacheValues($em, $target) !== ['alpha']) {
        throw new RuntimeException('FAIL: failed-flush restored authorization read');
    }
    $commit = accessCacheManager($cache);
    (new \ViMbAdmin_Service_Admin($commit))->removeDomain(
        accessCacheAdmin($commit, $targetId),
        accessCacheDomain($commit, $alphaId),
        accessCacheAdmin($commit, $actorId),
    );
    $other = accessCacheManager($cache);
    if (accessCacheValues($other, accessCacheAdmin($other, $targetId)) !== []) {
        $poisonFailures[] = 'failed-flush cache poison survived committed revoke';
    } else {
        echo "ok: failed flush cannot poison a later revoked scope\n";
        $checks++;
    }

    if ($poisonFailures !== []) {
        throw new RuntimeException('FAIL: ' . implode('; ', $poisonFailures));
    }

    echo 'ALL PASSED (' . $checks . " checks)\n";
} finally {
    $db->executeStatement('DELETE FROM log WHERE Admin_id IN (?)', [$ids['admins'] ?: [0]], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    if ($ids['admins'] !== []) {
        $db->executeStatement('DELETE FROM domain_admins WHERE Admin_id IN (?)', [$ids['admins']], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }
    if ($ids['preferences'] !== []) {
        $db->executeStatement('DELETE FROM mailbox_pref WHERE id IN (?)', [array_values($ids['preferences'])], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }
    if ($ids['mailboxes'] !== []) {
        $db->executeStatement('DELETE FROM mailbox WHERE id IN (?)', [$ids['mailboxes']], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }
    if ($ids['domains'] !== []) {
        $db->executeStatement('DELETE FROM domain WHERE id IN (?)', [array_values($ids['domains'])], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }
    if ($ids['admins'] !== []) {
        $db->executeStatement('DELETE FROM admin WHERE id IN (?)', [array_values($ids['admins'])], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
    }
}
