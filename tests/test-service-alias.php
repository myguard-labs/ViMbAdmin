<?php

/**
 * Unit test: ViMbAdmin_Service_Alias (docs/ZF1-REMOVAL.md, Phase 4). Pure
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

require __DIR__ . '/../library/ViMbAdmin/Service/Alias.php';

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

    public function countPersisted(string $class): int
    {
        return count(array_filter($this->persisted, static fn ($o) => $o instanceof $class));
    }
}

final class RecordingAlias extends \Entities\Alias
{
    public mixed $lastActiveArgument = null;

    public function setActive($active)
    {
        $this->lastActiveArgument = $active;
        return parent::setActive($active);
    }
}

final class InvalidAliasHookState
{
    private static bool $ran = false;

    public static function reset(): void
    {
        self::$ran = false;
    }
    public static function markRan(): void
    {
        self::$ran = true;
    }
    public static function ran(): bool
    {
        return self::$ran;
    }
}

final class TestServiceAliasHarnessState
{
    public static int $count = 0;
}

$failures = & TestServiceAliasHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestServiceAliasHarnessState::$count++;
    }
}

function aliasOperationThrows(string $message, \Closure $operation): bool
{
    try {
        $operation();
    } catch (\LogicException $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
}

echo "== ViMbAdmin_Service_Alias ==\n";

$actor = new \Entities\Admin();
$actor->setUsername('admin@example.com');

$mkAlias = static function (bool $active): \Entities\Alias {
    $al = new \Entities\Alias();
    $al->setAddress("alias@example.com");
    $al->setGoto("target@example.com");
    $al->setActive($active);
    return $al;
};

// --- happy path: activate, hooks fire in order, one flush -------------- //
$em  = new FakeObjectManager();
$al  = $mkAlias(false);
$svc = new ViMbAdmin_Service_Alias($em);

$order = [];
$result = $svc->toggleActive(
    $al,
    $actor,
    function () use (&$order, $al): bool {
        $order[] = 'preToggle:' . (int) (bool) $al->getActive();
        return true;
    },
    function () use (&$order, $al): void {
        $order[] = 'preFlush:' . (int) (bool) $al->getActive();
    },
    function () use (&$order, $al): void {
        $order[] = 'postFlush:' . (int) (bool) $al->getActive();
    },
);

check('returns the new active state (true)', $result === true);
check('alias is now active', (bool) $al->getActive() === true);
check('exactly one flush', $em->flushes === 1);
check('a Log row was persisted', $em->lastLog() instanceof \Entities\Log);
check('log action is ACTIVATE', $em->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ACTIVATE);
check('preToggle saw pre-toggle state (0)', implode('|', $order) === 'preToggle:0|preFlush:1|postFlush:1');
check('preFlush saw post-toggle state (1)', implode('|', $order) === 'preToggle:0|preFlush:1|postFlush:1');
check('postFlush saw post-toggle state (1)', implode('|', $order) === 'preToggle:0|preFlush:1|postFlush:1');
check('hook order preToggle<preFlush<postFlush', $order === ['preToggle:0', 'preFlush:1', 'postFlush:1']);

// --- deactivate path -------------------------------------------------- //
$em2 = new FakeObjectManager();
$al2 = $mkAlias(true);
$r2  = (new ViMbAdmin_Service_Alias($em2))->toggleActive($al2, $actor);
check('toggle without hooks works', $r2 === false && (bool) $al2->getActive() === false);
check('log action is DEACTIVATE', $em2->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DEACTIVATE);
check('still one flush', $em2->flushes === 1);

// --- preToggle veto --------------------------------------------------- //
$em3 = new FakeObjectManager();
$al3 = $mkAlias(true);
$vetoed = (new ViMbAdmin_Service_Alias($em3))->toggleActive(
    $al3,
    $actor,
    static fn (): bool => false, // a plugin vetoes
    static function (): void {
        throw new \RuntimeException('preFlush must not run on veto');
    },
);
check('veto returns null', $vetoed === null);
check('veto leaves alias unchanged', (bool) $al3->getActive() === true);
check('veto does NOT flush', $em3->flushes === 0);
check('veto writes no log', $em3->lastLog() === null);

// --- required identity fails before hooks or mutations --------------- //
$emInvalidToggle = new FakeObjectManager();
$invalidToggle = new \Entities\Alias();
$invalidToggle->setGoto('target@example.com');
$invalidToggle->setActive(false);
InvalidAliasHookState::reset();
check(
    'toggle rejects a null alias address',
    aliasOperationThrows(
        'Alias address cannot be null.',
        static fn (): mixed => (new ViMbAdmin_Service_Alias($emInvalidToggle))->toggleActive(
            $invalidToggle,
            $actor,
            static function (): bool {
                InvalidAliasHookState::markRan();
                return true;
            },
        ),
    ),
);
check(
    'toggle identity failure has no side effects',
    $invalidToggle->getActive() === false
        && $invalidToggle->getModified() === null
        && !InvalidAliasHookState::ran()
        && $emInvalidToggle->persisted === []
        && $emInvalidToggle->flushes === 0,
);

$mkDomain = static function (int $count): \Entities\Domain {
    $d = new \Entities\Domain();
    $d->setDomain('example.com');
    $d->setAliasCount($count);
    return $d;
};

$emInvalidCreate = new FakeObjectManager();
$invalidCreateDomain = $mkDomain(6);
$invalidCreate = (new \Entities\Alias())->setAddress('invalid@example.com');
check(
    'create rejects a null alias goto',
    aliasOperationThrows(
        'Alias goto cannot be null.',
        static fn (): mixed => (new ViMbAdmin_Service_Alias($emInvalidCreate))->create(
            $invalidCreate,
            $invalidCreateDomain,
            $actor,
        ),
    ),
);
check(
    'create identity failure has no side effects',
    $emInvalidCreate->persisted === []
        && $emInvalidCreate->flushes === 0
        && $invalidCreateDomain->getAliasCount() === 6
        && $invalidCreate->getDomain() === null,
);

// --- create: forwarding alias (address != goto) bumps the count ------- //
$emC = new FakeObjectManager();
$domC = $mkDomain(4);
$alC  = new RecordingAlias();
$alC->setAddress('info@example.com');
$alC->setGoto('boss@example.com');
$orderC = [];
$created = (new ViMbAdmin_Service_Alias($emC))->create(
    $alC,
    $domC,
    $actor,
    function () use (&$orderC, $emC): void {
        $orderC[] = 'preFlush:' . $emC->flushes;
    },
    function () use (&$orderC, $emC): void {
        $orderC[] = 'postFlush:' . $emC->flushes;
    },
);
check('create returns the alias', $created === $alC);
check('create set the domain', $alC->getDomain() === $domC);
check('create passes a boolean active value', $alC->lastActiveArgument === true);
check('create set active', $alC->getActive() === true);
check('create stamped created', $alC->getCreated() instanceof \DateTime);
check('create persisted the alias', in_array($alC, $emC->persisted, true));
check('create bumped aliasCount (addr!=goto)', (int) $domC->getAliasCount() === 5);
check('create logged ACTION_ALIAS_ADD', $emC->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ADD);
check('create flushed once', $emC->flushes === 1);
check('create hook order around flush', $orderC === ['preFlush:0', 'postFlush:1']);

// A quoted local part can legally contain markup-looking bytes. Persistence
// must retain that valid address verbatim; HTML safety belongs to the list view.
$storedDestination = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
$emStored = new FakeObjectManager();
$domStored = $mkDomain(0);
$aliasStored = (new \Entities\Alias())
    ->setAddress('stored-xss-regression@example.com')
    ->setGoto($storedDestination);
$createdStored = (new ViMbAdmin_Service_Alias($emStored))->create(
    $aliasStored,
    $domStored,
    $actor,
);
check('quoted markup-like destination is a valid RFC email', filter_var($storedDestination, FILTER_VALIDATE_EMAIL) === $storedDestination);
check(
    'create persists the exact markup-like destination',
    $createdStored === $aliasStored
        && $aliasStored->getGoto() === $storedDestination
        && in_array($aliasStored, $emStored->persisted, true)
        && $emStored->flushes === 1,
);

// --- create: preFlush failure stops flush and postFlush -------------- //
$emE = new FakeObjectManager();
$domE = $mkDomain(2);
$alE = new \Entities\Alias();
$alE->setAddress('error@example.com');
$alE->setGoto('target@example.com');
$postFlushRan = false;
try {
    (new ViMbAdmin_Service_Alias($emE))->create(
        $alE,
        $domE,
        $actor,
        static function (): void {
            throw new \RuntimeException('preFlush failure');
        },
        static function () use (&$postFlushRan): void {
            $postFlushRan = true;
        },
    );
    check('create propagates preFlush failure', false);
} catch (\RuntimeException $e) {
    check('create propagates preFlush failure', $e->getMessage() === 'preFlush failure');
}
check('create preFlush failure prevents flush', $emE->flushes === 0);
check('create preFlush failure skips postFlush', $postFlushRan === false);
check('create accounts allowance before preFlush', (int) $domE->getAliasCount() === 3);

// --- create: self-alias (address == goto) does NOT bump the count ----- //
$emS = new FakeObjectManager();
$domS = $mkDomain(7);
$alS  = new \Entities\Alias();
$alS->setAddress('box@example.com');
$alS->setGoto('box@example.com');
(new ViMbAdmin_Service_Alias($emS))->create($alS, $domS, $actor);
check('self-alias does NOT bump count', (int) $domS->getAliasCount() === 7);
check('self-alias still persisted + logged', in_array($alS, $emS->persisted, true) && $emS->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_ADD);

// --- update (edit): stamps modified, logs EDIT, one flush, no count ---- //
$emU = new FakeObjectManager();
$domU = $mkDomain(9);
$alU  = new \Entities\Alias();
$alU->setAddress('info@example.com');
$alU->setGoto('new@example.com');
$alU->setDomain($domU);
$orderU = [];
$updated = (new ViMbAdmin_Service_Alias($emU))->update(
    $alU,
    $actor,
    function () use (&$orderU, $emU): void {
        $orderU[] = 'preFlush:' . $emU->flushes;
    },
    function () use (&$orderU, $emU): void {
        $orderU[] = 'postFlush:' . $emU->flushes;
    },
);
check('update returns the alias', $updated === $alU);
check('update stamped modified', $alU->getModified() instanceof \DateTime);
check('update logged ACTION_ALIAS_EDIT', $emU->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_EDIT);
check('update flushed once', $emU->flushes === 1);
check('update did NOT touch aliasCount', (int) $domU->getAliasCount() === 9);
check('update did NOT persist (edit only)', $emU->countPersisted(\Entities\Alias::class) === 0);
check('update hook order around flush', $orderU === ['preFlush:0', 'postFlush:1']);

$emInvalidUpdate = new FakeObjectManager();
$invalidUpdate = (new \Entities\Alias())->setGoto('target@example.com');
check(
    'update rejects a null alias address',
    aliasOperationThrows(
        'Alias address cannot be null.',
        static fn (): mixed => (new ViMbAdmin_Service_Alias($emInvalidUpdate))->update(
            $invalidUpdate,
            $actor,
        ),
    ),
);
check(
    'update identity failure has no side effects',
    $invalidUpdate->getModified() === null
        && $emInvalidUpdate->persisted === []
        && $emInvalidUpdate->flushes === 0,
);

// --- delete: forwarding alias, hooks fire, count decrements ----------- //
$emD = new FakeObjectManager();
$domD = $mkDomain(5);
$alD  = new \Entities\Alias();
$alD->setAddress('info@example.com');
$alD->setGoto('boss@example.com');
$alD->setDomain($domD);
$orderD = [];
$rd = (new ViMbAdmin_Service_Alias($emD))->delete(
    $alD,
    $actor,
    function () use (&$orderD): bool {
        $orderD[] = 'preRemove';
        return true;
    },
    function () use (&$orderD): void {
        $orderD[] = 'preFlush';
    },
    function () use (&$orderD): void {
        $orderD[] = 'postFlush';
    },
);
check('delete returns true', $rd === true);
check('delete removed the alias', in_array($alD, $emD->removed, true));
check('delete decremented aliasCount', (int) $domD->getAliasCount() === 4);
check('delete logged ACTION_ALIAS_DELETE', $emD->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DELETE);
check('delete flushed once', $emD->flushes === 1);
check('delete hook order', $orderD === ['preRemove', 'preFlush', 'postFlush']);

$emInvalidDelete = new FakeObjectManager();
$invalidDeleteDomain = $mkDomain(8);
$invalidDelete = (new \Entities\Alias())
    ->setAddress('invalid@example.com')
    ->setDomain($invalidDeleteDomain);
$invalidDelete->addPreference(new \Entities\AliasPreference());
check(
    'delete rejects a null alias goto',
    aliasOperationThrows(
        'Alias goto cannot be null.',
        static fn (): mixed => (new ViMbAdmin_Service_Alias($emInvalidDelete))->delete(
            $invalidDelete,
            $actor,
        ),
    ),
);
check(
    'delete identity failure has no side effects',
    $emInvalidDelete->removed === []
        && $emInvalidDelete->persisted === []
        && $emInvalidDelete->flushes === 0
        && $invalidDeleteDomain->getAliasCount() === 8,
);

$emOrphanDelete = new FakeObjectManager();
$orphanDelete = (new \Entities\Alias())
    ->setAddress('orphan@example.com')
    ->setGoto('target@example.net');
$orphanDelete->addPreference(new \Entities\AliasPreference());
check(
    'delete rejects a null alias domain before preference removal',
    aliasOperationThrows(
        'Alias domain cannot be null.',
        static fn (): mixed => (new ViMbAdmin_Service_Alias($emOrphanDelete))->delete($orphanDelete, $actor),
    ),
);
check(
    'delete relation failure has no side effects',
    $emOrphanDelete->removed === []
        && $emOrphanDelete->persisted === []
        && $emOrphanDelete->flushes === 0,
);

// --- delete: self-alias does NOT touch the count ---------------------- //
$emDS = new FakeObjectManager();
$domDS = $mkDomain(3);
$alDS  = new \Entities\Alias();
$alDS->setAddress('box@example.com');
$alDS->setGoto('box@example.com');
$alDS->setDomain($domDS);
(new ViMbAdmin_Service_Alias($emDS))->delete($alDS, $actor);
check('delete self-alias keeps count', (int) $domDS->getAliasCount() === 3);
check('delete self-alias removed + logged', in_array($alDS, $emDS->removed, true) && $emDS->lastLog()?->getAction() === \Entities\Log::ACTION_ALIAS_DELETE);

// --- delete veto: nothing removed, no flush, no log ------------------- //
$emV = new FakeObjectManager();
$domV = $mkDomain(5);
$alV  = new \Entities\Alias();
$alV->setAddress('info@example.com');
$alV->setGoto('boss@example.com');
$alV->setDomain($domV);
$rv = (new ViMbAdmin_Service_Alias($emV))->delete(
    $alV,
    $actor,
    static fn (): bool => false,
    static function (): void {
        throw new \RuntimeException('preFlush must not run on veto');
    },
);
check('delete veto returns false', $rv === false);
check('delete veto did NOT remove the alias', !in_array($alV, $emV->removed, true));
check('delete veto did NOT flush', $emV->flushes === 0);
check('delete veto wrote no log', $emV->lastLog() === null);
check('delete veto left aliasCount', (int) $domV->getAliasCount() === 5);

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
$metadata = new \Doctrine\ORM\Mapping\ClassMetadata(\Entities\Alias::class);
$aliasRepository = new \Repositories\Alias($entityManager, $metadata);

$invokeAliasQuery = static function (
    ReflectionMethod $method,
    \Repositories\Alias $repository,
    mixed ...$arguments,
): \Doctrine\ORM\QueryBuilder {
    $query = $method->invoke($repository, ...$arguments);
    if (!$query instanceof \Doctrine\ORM\QueryBuilder) {
        throw new RuntimeException('repository query factory did not return a QueryBuilder');
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
$repositoryDomain->setDomain('repository.example');
$listMethod = new ReflectionMethod($aliasRepository, 'aliasListQuery');
$listQuery = $invokeAliasQuery($listMethod, $aliasRepository, $scopedAdmin, $repositoryDomain, false);
$listDql = $listQuery->getDQL();
$listParameters = $queryParameters($listQuery);
check('alias list keeps the selected hydration columns', str_contains($listDql, 'a.id as id') && str_contains($listDql, 'd.domain as domain'));
check('alias list scopes a non-super admin', str_contains($listDql, 'JOIN d.Admins d2a') && str_contains($listDql, 'd2a = ?1'));
check('alias list retains domain and non-mailbox filters', str_contains($listDql, 'a.Domain = ?2') && str_contains($listDql, 'a.address != a.goto'));
check('alias list preserves positional query parameters', ($listParameters[1] ?? null) === $scopedAdmin && ($listParameters[2] ?? null) === $repositoryDomain);

$superAdmin = new \Entities\Admin();
$superAdmin->setSuper(true);
$unscopedQuery = $invokeAliasQuery($listMethod, $aliasRepository, $superAdmin, null, true);
check('super-admin include-mailbox boundary adds no filters', !str_contains($unscopedQuery->getDQL(), 'WHERE') && count($unscopedQuery->getParameters()) === 0);

$filterMethod = new ReflectionMethod($aliasRepository, 'filteredAliasListQuery');
$filterQuery = $invokeAliasQuery($filterMethod, $aliasRepository, '*sales_%', $scopedAdmin, 17, false);
$filterDql = $filterQuery->getDQL();
$filterParameters = $queryParameters($filterQuery);
check('filtered list retains search when admin scoping is appended', str_contains($filterDql, 'a.goto LIKE :s') && str_contains($filterDql, 'AND d2a = :admin'));
check('filtered list escapes wildcard data in contains mode', ($filterParameters['s'] ?? null) === '%sales\_\%%');
check('filtered list accepts the legacy numeric domain id', ($filterParameters['domain'] ?? null) === 17);
check('filtered list preserves mailbox-alias exclusion', str_contains($filterDql, 'a.address != a.goto'));

$quotedFilterQuery = $invokeAliasQuery($filterMethod, $aliasRepository, "o'hare", $superAdmin, null, true);
$quotedParameters = $queryParameters($quotedFilterQuery);
check('filtered list strips quotes and uses prefix mode', ($quotedParameters['s'] ?? null) === 'ohare%');
check('super-admin filtered boundary omits ownership and mailbox filters', !str_contains($quotedFilterQuery->getDQL(), 'd2a') && !str_contains($quotedFilterQuery->getDQL(), 'a.address != a.goto'));

$zeroFilterQuery = $invokeAliasQuery($filterMethod, $aliasRepository, '0', $superAdmin, null, true);
$emptyFilterQuery = $invokeAliasQuery($filterMethod, $aliasRepository, '', $superAdmin, null, true);
check('filtered list preserves the string zero', ($queryParameters($zeroFilterQuery)['s'] ?? null) === '0%');
check('filtered list preserves the empty-string legacy wildcard', ($queryParameters($emptyFilterQuery)['s'] ?? null) === '%');

foreach ([null, false, 1, 1.5, [], new \stdClass()] as $invalidFilter) {
    $message = null;
    try {
        $invokeAliasQuery($filterMethod, $aliasRepository, $invalidFilter, $superAdmin, null, true);
    } catch (\Throwable $exception) {
        $message = $exception->getMessage();
    }
    check(
        'filtered list rejects non-string input: ' . get_debug_type($invalidFilter),
        $message === 'Alias filter must be a string.'
    );
}

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Alias assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
