<?php

/**
 * Unit test: ViMbAdmin_Service_Archive (docs/ZF1-REMOVAL.md, Phase 4). Pure
 * logic over a fake ObjectManager + real entities — no framework, no DB. Proves
 * the autoprune flip in both directions, the timestamp bookkeeping (OFF→ON
 * resets archivedAt + statusChangedAt; ON→OFF stamps only statusChangedAt), the
 * single flush, the Log write, and the returned state.
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

require __DIR__ . '/../library/ViMbAdmin/Service/Archive.php';

final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
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
}

final class TestServiceArchiveHarnessState
{
    public static int $count = 0;
}

$failures = & TestServiceArchiveHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestServiceArchiveHarnessState::$count++;
    }
}

echo "== ViMbAdmin_Service_Archive ==\n";

$actor = new \Entities\Admin();
$actor->setUsername('admin@example.com');

$mkArchive = static function (bool $autoprune): \Entities\Archive {
    $ar = new \Entities\Archive();
    $ar->setUsername('box@example.com');
    $ar->setAutoprune($autoprune);
    return $ar;
};

// --- nullable lifecycle getters + required operational boundaries ----- //
$uninitialized = new \Entities\Archive();
check(
    'pre-hydration archive identities remain nullable',
    $uninitialized->getUsername() === null && $uninitialized->getDomain() === null
);

$usernameError = null;
try {
    $uninitialized->requiredUsername();
} catch (\LogicException $e) {
    $usernameError = $e->getMessage();
}
check(
    'required username rejects an uninitialized archive',
    $usernameError === 'Archive username cannot be null.'
);

$domainError = null;
try {
    $uninitialized->requiredDomain();
} catch (\LogicException $e) {
    $domainError = $e->getMessage();
}
check(
    'required domain rejects an uninitialized archive',
    $domainError === 'Archive domain cannot be null.'
);

$identityDomain = (new \Entities\Domain())->setDomain('example.test');
$initialized = (new \Entities\Archive())
    ->setUsername('box@example.test')
    ->setDomain($identityDomain);
check(
    'required archive identities preserve initialized values',
    $initialized->requiredUsername() === 'box@example.test'
        && $initialized->requiredDomain() === $identityDomain
);

$invalidToggleManager = new FakeObjectManager();
$invalidToggle = new \Entities\Archive();
$invalidToggleError = null;
try {
    (new ViMbAdmin_Service_Archive($invalidToggleManager))->toggleAutoprune($invalidToggle, $actor);
} catch (\LogicException $e) {
    $invalidToggleError = $e->getMessage();
}
check(
    'toggle rejects a missing username before mutation',
    $invalidToggleError === 'Archive username cannot be null.'
        && $invalidToggle->getAutoprune() === false
        && $invalidToggleManager->persisted === []
        && $invalidToggleManager->flushes === 0
);

$invalidDeleteManager = new FakeObjectManager();
$invalidDelete = new \Entities\Archive();
$invalidDeleteError = null;
try {
    (new ViMbAdmin_Service_Archive($invalidDeleteManager))->delete($invalidDelete, $actor);
} catch (\LogicException $e) {
    $invalidDeleteError = $e->getMessage();
}
check(
    'delete rejects a missing username before mutation',
    $invalidDeleteError === 'Archive username cannot be null.'
        && $invalidDeleteManager->removed === []
        && $invalidDeleteManager->persisted === []
        && $invalidDeleteManager->flushes === 0
);

// --- OFF -> ON: sets autoprune, resets archivedAt + statusChangedAt ----- //
$emOn = new FakeObjectManager();
$arOff = $mkArchive(false);
$arOff->setArchivedAt(new \DateTime('2000-01-01'));
$resOn = (new ViMbAdmin_Service_Archive($emOn))->toggleAutoprune($arOff, $actor);
check('OFF->ON returns true', $resOn === true);
check('OFF->ON sets autoprune', (bool) $arOff->getAutoprune() === true);
check('OFF->ON reset archivedAt to now', $arOff->getArchivedAt() instanceof \DateTime && $arOff->getArchivedAt()->getTimestamp() > strtotime('2001-01-01'));
check('OFF->ON stamped statusChangedAt', $arOff->getStatusChangedAt() instanceof \DateTime);
check('OFF->ON one flush', $emOn->flushes === 1);
check('OFF->ON logged ARCHIVE_REQUEST', $emOn->lastLog()?->getAction() === \Entities\Log::ACTION_ARCHIVE_REQUEST);
check('OFF->ON log mentions enabled', str_contains((string) $emOn->lastLog()?->getData(), 'enabled autoprune'));

// --- ON -> OFF: clears autoprune, stamps only statusChangedAt ----------- //
$emOff = new FakeObjectManager();
$arOn  = $mkArchive(true);
$arOn->setArchivedAt(new \DateTime('2000-01-01'));
$resOff = (new ViMbAdmin_Service_Archive($emOff))->toggleAutoprune($arOn, $actor);
check('ON->OFF returns false', $resOff === false);
check('ON->OFF clears autoprune', (bool) $arOn->getAutoprune() === false);
$archivedAt = $arOn->getArchivedAt();
check('ON->OFF did NOT touch archivedAt', $archivedAt instanceof \DateTime && $archivedAt->getTimestamp() === strtotime('2000-01-01'));
check('ON->OFF stamped statusChangedAt', $arOn->getStatusChangedAt() instanceof \DateTime);
check('ON->OFF one flush', $emOff->flushes === 1);
check('ON->OFF logged ARCHIVE_REQUEST', $emOff->lastLog()?->getAction() === \Entities\Log::ACTION_ARCHIVE_REQUEST);
check('ON->OFF log mentions disabled', str_contains((string) $emOff->lastLog()?->getData(), 'disabled autoprune'));

// --- repository list query contracts --------------------------------- //
$configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
    __DIR__ . '/../application/Entities',
]);
$configuration->enableNativeLazyObjects(true);
$connection = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => '127.0.0.1',
    'dbname' => 'unused',
], $configuration);
$entityManager = new \Doctrine\ORM\EntityManager($connection, $configuration);
$metadata = new \Doctrine\ORM\Mapping\ClassMetadata(\Entities\Archive::class);
$archiveRepository = new \Repositories\Archive($entityManager, $metadata);
$queryMethod = new ReflectionMethod($archiveRepository, 'archiveListQuery');
$invokeArchiveQuery = static function (
    ReflectionMethod $method,
    \Repositories\Archive $repository,
    \Entities\Admin $admin,
    ?\Entities\Domain $domain,
): \Doctrine\ORM\QueryBuilder {
    $query = $method->invoke($repository, $admin, $domain);
    if (!$query instanceof \Doctrine\ORM\QueryBuilder) {
        throw new RuntimeException('archiveListQuery did not return a QueryBuilder');
    }
    return $query;
};
$queryParameters = static function (\Doctrine\ORM\QueryBuilder $query): array {
    $parameters = [];
    foreach ($query->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter->getValue();
    }
    return $parameters;
};

$scopedAdmin = new \Entities\Admin();
$scopedAdmin->setSuper(false);
$repositoryDomain = new \Entities\Domain();
$repositoryDomain->setDomain('archive.example');
$scopedQuery = $invokeArchiveQuery($queryMethod, $archiveRepository, $scopedAdmin, $repositoryDomain);
$scopedDql = $scopedQuery->getDQL();
$scopedParameters = $queryParameters($scopedQuery);
check('archive list retains all hydration fields', str_contains($scopedDql, 'a.maildir_size as maildir_size') && str_contains($scopedDql, 'as user_exists'));
check('archive list retains live-mailbox existence join', str_contains($scopedDql, 'LEFT JOIN \\Entities\\Mailbox m WITH m.username = a.username'));
check('archive list scopes a non-super admin', str_contains($scopedDql, 'JOIN d.Admins d2a') && str_contains($scopedDql, 'd2a = :admin'));
check('archive list appends the selected domain filter', str_contains($scopedDql, 'AND a.Domain = ?2'));
check('archive list preserves named and positional parameters', ($scopedParameters['admin'] ?? null) === $scopedAdmin && ($scopedParameters[2] ?? null) === $repositoryDomain);

$superAdmin = new \Entities\Admin();
$superAdmin->setSuper(true);
$unscopedQuery = $invokeArchiveQuery($queryMethod, $archiveRepository, $superAdmin, null);
check('super-admin null-domain boundary adds no filters', !str_contains($unscopedQuery->getDQL(), 'WHERE') && count($unscopedQuery->getParameters()) === 0);

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Archive assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
