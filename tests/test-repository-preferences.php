<?php

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        require __DIR__ . '/../application/' . $directory . '/' . $relative . '.php';
    }
});

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @param array{adminId:int,super:bool,domainIds:list<int>} $scope */
function invokePreferenceQuery(
    ReflectionMethod $method,
    \Repositories\MailboxPreference $repository,
    string $attribute,
    array $scope,
): \Doctrine\ORM\QueryBuilder {
    $query = $method->invoke($repository, $attribute, $scope);
    if (!$query instanceof \Doctrine\ORM\QueryBuilder) {
        throw new RuntimeException('preferenceValueQuery did not return a QueryBuilder');
    }
    return $query;
}

/** @return array<int|string,mixed> */
function preferenceQueryParameters(\Doctrine\ORM\QueryBuilder $query): array
{
    $parameters = [];
    foreach ($query->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter->getValue();
    }
    return $parameters;
}

$configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
    __DIR__ . '/../application/Entities',
]);
if (PHP_VERSION_ID >= 80400) {
    $configuration->enableNativeLazyObjects(true);
} else {
    $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
    $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
}
$connection = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => '127.0.0.1',
    'dbname' => 'unused',
], $configuration);
$entityManager = new \Doctrine\ORM\EntityManager($connection, $configuration);
$metadata = new \Doctrine\ORM\Mapping\ClassMetadata(\Entities\MailboxPreference::class);
$repository = new \Repositories\MailboxPreference($entityManager, $metadata);
$queryMethod = new ReflectionMethod($repository, 'preferenceValueQuery');
$query = invokePreferenceQuery($queryMethod, $repository, 'xpiInfo.department', [
    'adminId' => 42,
    'super' => false,
    'domainIds' => [11, 12],
]);
$parameters = preferenceQueryParameters($query);
$uniqueMethod = new ReflectionMethod($repository, 'uniquePreferenceValues');
$requiredRowsMethod = new ReflectionMethod($repository, 'requiredPreferenceValueRows');
$authorizationRowMethod = new ReflectionMethod($repository, 'requiredAuthorizationRow');
$values = $uniqueMethod->invoke(null, [
    ['value' => 'alpha'],
    ['value' => 'beta'],
    ['value' => 'alpha'],
]);

echo "== preference repositories ==\n";
$check('scalar preference values retain order and remove duplicates', $values === ['alpha', 'beta']);
$preferenceRows = [3 => ['value' => 'preserved']];
$check(
    'preference row preserves integer key and string value',
    $requiredRowsMethod->invoke(null, $preferenceRows) === $preferenceRows
);
$invalidPreferenceRows = static function (mixed $rows) use ($requiredRowsMethod): ?string {
    try {
        $requiredRowsMethod->invoke(null, $rows);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};
$check(
    'preference rows reject a scalar result',
    $invalidPreferenceRows('invalid') === 'Preference query result must be an array.'
);
$check(
    'preference rows reject a scalar row',
    $invalidPreferenceRows(['invalid']) === 'Preference query row has an invalid shape.'
);
$check(
    'preference rows reject a string outer key',
    $invalidPreferenceRows(['row' => ['value' => 'x']]) === 'Preference query row has an invalid shape.'
);
$check(
    'preference rows reject a missing value key',
    $invalidPreferenceRows([['other' => 'x']]) === 'Preference query row has an invalid shape.'
);
$check(
    'preference rows reject an extra field',
    $invalidPreferenceRows([['value' => 'x', 'other' => 'y']]) === 'Preference query row has an invalid shape.'
);
$check(
    'preference rows reject a non-string value',
    $invalidPreferenceRows([['value' => null]]) === 'Preference query row has an invalid shape.'
);
$check(
    'authorization rows preserve an empty scoped assignment',
    $authorizationRowMethod->invoke(null, ['super' => 0, 'domain_id' => null])
        === ['super' => false, 'domainId' => null]
);
$check(
    'authorization rows normalize a persisted positive domain ID',
    $authorizationRowMethod->invoke(null, ['super' => '1', 'domain_id' => '12'])
        === ['super' => true, 'domainId' => 12]
);
$invalidAuthorizationRow = static function (mixed $row) use ($authorizationRowMethod): ?string {
    try {
        $authorizationRowMethod->invoke(null, $row);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};
$check(
    'authorization rows reject a malformed shape',
    $invalidAuthorizationRow(['super' => 0]) === 'Preference authorization query returned an invalid shape.'
);
$check(
    'authorization rows reject a malformed super flag',
    $invalidAuthorizationRow(['super' => 2, 'domain_id' => null])
        === 'Preference authorization query returned an invalid super flag.'
);
$check(
    'authorization rows reject a malformed domain ID',
    $invalidAuthorizationRow(['super' => 0, 'domain_id' => '01'])
        === 'Preference authorization query returned an invalid domain identifier.'
);
$numericValues = $uniqueMethod->invoke(null, [['value' => '0'], ['value' => '00']]);
$check('legacy loose duplicate comparison is preserved', $numericValues === ['0']);
$check('attribute predicate is retained for a scoped admin', str_contains($query->getDQL(), 'mp.attribute = :attr'));
$check('non-super admin joins mailbox and the captured domains', str_contains($query->getDQL(), 'JOIN mp.Mailbox m') && str_contains($query->getDQL(), 'JOIN m.Domain d'));
$check('captured scope is appended instead of replacing the attribute predicate', str_contains($query->getDQL(), 'AND d.id IN (:authorizedDomainIds)'));
$check('named parameters preserve both query inputs', ($parameters['attr'] ?? null) === 'xpiInfo.department' && ($parameters['authorizedDomainIds'] ?? null) === [11, 12]);

$emptyScopeQuery = invokePreferenceQuery($queryMethod, $repository, 'quota', [
    'adminId' => 42,
    'super' => false,
    'domainIds' => [],
]);
$check('empty captured scope is denied without an invalid array parameter', str_contains($emptyScopeQuery->getDQL(), 'AND 1 = 0'));

$superQuery = invokePreferenceQuery($queryMethod, $repository, 'quota', [
    'adminId' => 7,
    'super' => true,
    'domainIds' => [],
]);
$superParameters = preferenceQueryParameters($superQuery);
$superValues = $uniqueMethod->invoke(null, [['value' => 'global']]);
$check('super admin retains unscoped result values', $superValues === ['global']);
$check('super admin query omits ownership joins', !str_contains($superQuery->getDQL(), 'JOIN'));
$check('super admin query still filters the requested attribute', str_contains($superQuery->getDQL(), 'mp.attribute = :attr') && ($superParameters['attr'] ?? null) === 'quota');

echo $failures === 0
    ? "OK: all preference repository assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
