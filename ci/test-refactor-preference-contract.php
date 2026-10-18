<?php

declare(strict_types=1);

/**
 * VC-E01 decision: a method-only trait may reuse the ten scalar getters/setters
 * for attribute, ix, op, value and expire, preserving their existing signatures,
 * nullable backing fields and fluent identity. Explicit methods remain valid but
 * duplicate that surface four times. Keep mapped properties, generated IDs,
 * concrete owner associations and Admin's compatibility methods in each entity.
 * Neither an entity base class nor mapped trait properties are needed: both
 * enlarge Doctrine's mapping contract without reducing scalar-method duplication
 * further. VC-E02 owns extraction; this test seals the pre-extraction behavior.
 *
 * Real ORM/DBAL persistence, clear/reload, metadata and schema checks below are
 * the oracle. Fresh PHP nulls are permitted; the five SQL columns are NOT NULL.
 * Values remain raw storage bytes; escaping belongs to the rendering boundary.
 * Run only against the disposable MariaDB service, never an application database.
 */
if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    throw new RuntimeException('Disposable MariaDB fixture requires VIMBADMIN_DESTRUCTIVE_DB_TESTS=1');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType;

EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => dirname(__DIR__) . '/application',
    'repositories_path' => dirname(__DIR__) . '/application',
]]]);
if (!Type::hasType(LegacyObjectType::NAME)) {
    Type::addType(LegacyObjectType::NAME, LegacyObjectType::class);
}

final class PreferenceReuseContractState
{
    public static int $checks = 0;
}

/** @phpstan-assert true $passed */
function preferenceReuseCheck(string $label, bool $passed): void
{
    ++PreferenceReuseContractState::$checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo '  ok   ' . $label . "\n";
}

/** @phpstan-impure */
function preferenceReuseInvoke(object $subject, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($subject, $method))->invoke($subject, ...$arguments);
}

$throws = static function (callable $operation, string $class): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw $exception;
        }
        return true;
    }
    return false;
};
$configuration = ORMSetup::createAttributeMetadataConfiguration(
    [dirname(__DIR__) . '/application/Entities'],
    true,
    cache: new ArrayAdapter(),
);
if (PHP_VERSION_ID >= 80400) {
    $configuration->enableNativeLazyObjects(true);
} else {
    $configuration->setProxyDir(sys_get_temp_dir());
    $configuration->setProxyNamespace('PreferenceContractProxies');
}
$parameters = ['driver' => 'pdo_mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306), 'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '', 'charset' => 'utf8mb4'];
$server = DriverManager::getConnection($parameters);
$database = 'vimbadmin_preference_contract_' . bin2hex(random_bytes(8));
$server->createSchemaManager()->createDatabase($database);
$parameters['dbname'] = $database;
$manager = static fn (): EntityManager => new EntityManager(
    DriverManager::getConnection($parameters, $configuration),
    $configuration,
);
$em = $manager();

try {
    preferenceReuseCheck('ORM 3 / DBAL 4 locked runtime', str_starts_with(Composer\InstalledVersions::getVersion('doctrine/orm') ?? '', '3.')
        && str_starts_with(Composer\InstalledVersions::getVersion('doctrine/dbal') ?? '', '4.'));
    $scalarMethods = ['getAttribute', 'getExpire', 'getIx', 'getOp', 'getValue',
        'setAttribute', 'setExpire', 'setIx', 'setOp', 'setValue'];
    $scalarTrait = new ReflectionClass(Entities\PreferenceScalarAccessors::class);
    $actualScalarMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $scalarTrait->getMethods(),
    );
    sort($actualScalarMethods);
    preferenceReuseCheck(
        'shared scalar accessor trait is property-free and limited to the approved ten methods',
        $scalarTrait->isTrait() && $scalarTrait->getProperties() === [] && $scalarTrait->getAttributes() === []
        && $actualScalarMethods === $scalarMethods
    );
    $scalarTraitFile = realpath((string) $scalarTrait->getFileName());
    preferenceReuseCheck(
        'shared scalar accessor trait resolves from the concrete Entities autoload path',
        $scalarTraitFile === realpath(dirname(__DIR__) . '/application/Entities/PreferenceScalarAccessors.php')
    );
    $adminCas = new ReflectionMethod(Entities\Admin::class, 'compareAndSwapPreference');
    $adminCasParameters = $adminCas->getParameters();
    $expectedCasTypes = ['string', 'string|false', 'string'];
    $actualCasTypes = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $adminCasParameters,
    );
    preferenceReuseCheck(
        'Admin compare-and-swap remains concrete with its exact public signature',
        $adminCas->isPublic() && !$adminCas->isStatic() && (string) $adminCas->getReturnType() === 'bool'
        && $actualCasTypes === $expectedCasTypes
        && realpath((string) $adminCas->getFileName()) === realpath(dirname(__DIR__) . '/application/Entities/Admin.php')
    );
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    preferenceReuseCheck('all concrete Doctrine mappings validate', (new SchemaValidator($em))->validateMapping() === []);
    $schema = new SchemaTool($em);
    $schema->createSchema($metadata);
    // Use the application's migration predicate, including its Dovecot-owned
    // timestamp normalization and non-ORM foreign keys, rather than copy it.
    $nativeSchema = new ViMbAdmin_Schema($em);
    $nativeSchema->apply($nativeSchema->pendingSql());
    preferenceReuseCheck('real MariaDB schema has no pending mapping changes', $nativeSchema->pendingSql() === []);
    OSS_Runtime::configure([], '', $em);

    $owners = [];
    for ($index = 0; $index < 2; ++$index) {
        $domain = (new Entities\Domain())->setDomain('preference-' . $index . '.example.test')->setQuota(256)
            ->setTransport('virtual')->setBackupmx(false)->setMaxQuota(1024)->setMaxMailboxes(10)->setMaxAliases(10)
            ->setMailboxCount(0)->setAliasCount(0)->setActive(true)->setCreated(new DateTime('2026-01-01'));
        $owners['Domain'][] = $domain;
        $owners['Admin'][] = (new Entities\Admin())->setUsername('admin-' . $index . '@example.test')
            ->setPassword('!')->setSuper(false)->setActive(true)->setCreated(new DateTime('2026-01-01'));
        $owners['Alias'][] = (new Entities\Alias())->setAddress('alias@' . $domain->getDomain())
            ->setGoto('destination@example.test')->setActive(true)->setCreated(new DateTime('2026-01-01'))->setDomain($domain);
        $owners['Mailbox'][] = (new Entities\Mailbox())->setUsername('mailbox@' . $domain->getDomain())
            ->setLocalPart('mailbox')->setName('Preference fixture')->setPassword('!')->setQuota(128)
            ->setDeletePending(false)->setActive(true)->setCreated(new DateTime('2026-01-01'))->setDomain($domain);
    }
    foreach ($owners as $familyOwners) {
        foreach ($familyOwners as $owner) {
            $em->persist($owner);
        }
    }
    $em->flush();
    $ids = [];
    $now = time();
    $raw = '<script>"&\' preference %_\\ λ';
    $fields = ['Attribute' => 'contract', 'Ix' => 0, 'Op' => ':=', 'Value' => $raw, 'Expire' => 0];
    foreach ($owners as $family => [$owner, $other]) {
        $class = 'Entities\\' . $family . 'Preference';
        $ownerClass = 'Entities\\' . $family;
        $reflection = new ReflectionClass($class);
        $sharedSignatures = true;
        foreach ($scalarMethods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $isSetter = str_starts_with($methodName, 'set');
            $parameters = $method->getParameters();
            $sharedSignatures = $sharedSignatures
                && $method->isPublic() && !$method->isStatic() && !$method->hasReturnType()
                && realpath((string) $method->getFileName()) === $scalarTraitFile
                && count($parameters) === ($isSetter ? 1 : 0)
                && (!$isSetter || ($parameters[0]->getType() === null && !$parameters[0]->isOptional()));
        }
        preferenceReuseCheck(
            "$family imports only the approved method-only preference trait with unchanged signatures",
            $reflection->getTraitNames() === [Entities\PreferenceScalarAccessors::class] && $sharedSignatures
        );
        $mapping = $em->getClassMetadata($class);
        $association = $mapping->getAssociationMapping($family);
        preferenceReuseCheck("$family owner mapping remains many-to-one", $association instanceof ManyToOneAssociationMapping);
        preferenceReuseCheck("$family keeps concrete mapping without inheritance", $mapping->inheritanceType === ClassMetadata::INHERITANCE_TYPE_NONE
            && $mapping->getTableName() === strtolower($family) . '_pref'
            && $mapping->getAssociationTargetClass($family) === $ownerClass
            && $association->inversedBy === 'Preferences');
        $expectedTypes = ['attribute' => 'string', 'expire' => 'bigint', 'id' => 'bigint',
            'ix' => 'integer', 'op' => 'string', 'value' => 'text'];
        $actualTypes = [];
        foreach ($mapping->fieldMappings as $name => $fieldMapping) {
            $actualTypes[$name] = $fieldMapping->type;
            preferenceReuseCheck("$family $name retains SQL NOT NULL", !$fieldMapping->nullable);
        }
        ksort($actualTypes);
        preferenceReuseCheck("$family scalar columns retain their exact types, lengths and defaults", $actualTypes === $expectedTypes
            && $mapping->getFieldMapping('attribute')->length === 255 && $mapping->getFieldMapping('op')->length === 2
            && ($mapping->getFieldMapping('ix')->options['default'] ?? null) === 0
            && ($mapping->getFieldMapping('op')->options['default'] ?? null) === ':='
            && ($mapping->getFieldMapping('expire')->options['default'] ?? null) === 0);
        $ownerParameter = (new ReflectionMethod($class, 'set' . $family))->getParameters()[0]->getType();
        preferenceReuseCheck("$family owner association remains concretely typed and mapped", $mapping->parentClasses === []
            && $reflection->getParentClass() === false
            && $ownerParameter instanceof ReflectionNamedType && $ownerParameter->getName() === $ownerClass && $ownerParameter->allowsNull()
            && $association->joinColumns[0]->name === $family . '_id' && $association->joinColumns[0]->referencedColumnName === 'id');
        $concreteProperties = true;
        foreach (['attribute', 'ix', 'op', 'value', 'expire', 'id', $family] as $propertyName) {
            $concreteProperties = $concreteProperties
                && $reflection->getProperty($propertyName)->getDeclaringClass()->getName() === $class;
        }
        preferenceReuseCheck("$family retains every mapped scalar, generated ID and owner property concretely", $concreteProperties);
        if ($family === 'Admin') {
            preferenceReuseCheck(
                'Admin compatibility preference methods remain explicit',
                realpath((string) $reflection->getMethod('setPreferences')->getFileName())
                    === realpath(dirname(__DIR__) . '/application/Entities/AdminPreference.php')
                && realpath((string) $reflection->getMethod('getPreferences')->getFileName())
                    === realpath(dirname(__DIR__) . '/application/Entities/AdminPreference.php')
            );
        }
        $indexes = $em->getConnection()->createSchemaManager()->listTableIndexes($mapping->getTableName());
        $indexName = strtolower('IX_' . $family . 'Preference_1');
        preferenceReuseCheck("$family concrete SQL index retains owner/attribute/ix ordering and uniqueness", isset($indexes[$indexName])
            && $indexes[$indexName]->getColumns() === [$family . '_id', 'attribute', 'ix']
            && $indexes[$indexName]->isUnique() === ($family === 'Admin'));
        $fresh = new $class();
        $setOwner = 'set' . $family;
        $getOwner = 'get' . $family;
        preferenceReuseCheck("$family fresh generated ID and concrete owner are null", $fresh->getId() === null && preferenceReuseInvoke($fresh, $getOwner) === null);
        foreach ($fields as $field => $value) {
            $get = 'get' . $field;
            // Reflection deliberately crosses each setter's documented input
            // contract to test malformed containers and nullable backing state.
            $set = 'set' . $field;
            preferenceReuseCheck("$family $field starts null and fluent setter preserves identity", preferenceReuseInvoke($fresh, $get) === null
                && preferenceReuseInvoke($fresh, $set, $value) === $fresh && preferenceReuseInvoke($fresh, $get) === $value);
            preferenceReuseCheck("$family $field rejects malformed container without conversion", $throws(static fn () => preferenceReuseInvoke($fresh, $set, []), TypeError::class)
                && preferenceReuseInvoke($fresh, $get) === $value);
            preferenceReuseCheck("$family $field retains nullable PHP state", preferenceReuseInvoke($fresh, $set, null) === $fresh
                && preferenceReuseInvoke($fresh, $get) === null);
            preferenceReuseInvoke($fresh, $set, $value);
        }
        $wrongOwner = $family === 'Domain' ? $owners['Admin'][0] : $owners['Domain'][0];
        preferenceReuseCheck("$family rejects wrong owner type", $throws(static fn () => preferenceReuseInvoke($fresh, $setOwner, $wrongOwner), TypeError::class));
        $wrongPreference = $family === 'Admin' ? new Entities\DomainPreference() : new Entities\AdminPreference();
        preferenceReuseCheck("$family rejects wrong preference family", $throws(static fn () => preferenceReuseInvoke($owner, 'addPreference', $wrongPreference), TypeError::class));
        preferenceReuseCheck("$family concrete association is fluent and detachable", preferenceReuseInvoke($fresh, $setOwner, $owner) === $fresh
            && preferenceReuseInvoke($fresh, $getOwner) === $owner && preferenceReuseInvoke($fresh, $setOwner, null) === $fresh
            && preferenceReuseInvoke($fresh, $getOwner) === null);
        foreach ([[$owner, 'owner'], [$other, 'foreign']] as [$target, $marker]) {
            $target->setPreference('contract', $marker . ':' . $raw, ':=', 0, 0);
            $target->setPreference('index', '', '=', 0, 0);
            $target->setPreference('index', '0', '=', $now + 3600, 7);
            $target->setPreference('expired', $marker . ':expired', '=', $now - 3600);
            $target->setPreference(str_repeat('a', 255), $marker . ':boundary', ':=', 0, -1);
            $target->setPreference('tree.leaf', $marker . ':nested', '=', 0, 2);
            $target->setPreference('integer-boundary', $marker . ':large', '=', 4102444800, 2147483647);
            $target->setPreference('integer-boundary', $marker . ':small', '=', 0, -2147483648);
        }
        $ids[$family] = [$owner->getId(), $other->getId()];
    }
    $em->flush();
    $em->clear();
    foreach ($ids as $family => [$id, $otherId]) {
        $ownerClass = 'Entities\\' . $family;
        $class = $ownerClass . 'Preference';
        $owner = $em->find($ownerClass, $id);
        $other = $em->find($ownerClass, $otherId);
        preferenceReuseCheck("$family real clear/reload finds both owners", $owner instanceof $ownerClass && $other instanceof $ownerClass);
        $preference = $owner->loadPreference('contract');
        preferenceReuseCheck("$family real clear/reload preserves scalar fields and concrete association", $preference instanceof $class
            && is_int($preference->getId()) && $preference->getId() > 0 && $preference->getAttribute() === 'contract'
            && $preference->getIx() === 0 && $preference->getOp() === ':=' && $preference->getExpire() === 0
            && $preference->getValue() === 'owner:' . $raw && (new ReflectionMethod($class, 'get' . $family))->invoke($preference) === $owner);
        preferenceReuseCheck("$family source-row control: raw value and cross-owner isolation", $owner->getPreference('contract') === 'owner:' . $raw
            && $other->getPreference('contract') === 'foreign:' . $raw);
        preferenceReuseCheck("$family missing preference is false", $owner->loadPreference('absent') === false && $owner->getPreference('absent') === false);
        preferenceReuseCheck("$family expiry guard control: expired hidden unless requested", $owner->getPreference('expired') === false
            && $owner->loadPreference('expired') === false && $owner->getPreference('expired', 0, true) === 'owner:expired');
        preferenceReuseCheck("$family indexed and associative expiry switches preserve their existing meaning", $owner->getIndexedPreference('expired', true, false) === false
            && $owner->getIndexedPreference('expired', true, true) === [0 => ['p_index' => 0, 'p_value' => 'owner:expired']]
            && $owner->getAssocPreference('expired', null, false) === false
            && $owner->getAssocPreference('expired', null, true) === [0 => 'owner:expired']);
        preferenceReuseCheck("$family index zero, empty value and string zero survive reload", $owner->getIndexedPreference('index', true, false)
            === [0 => ['p_index' => 0, 'p_value' => ''], 7 => ['p_index' => 7, 'p_value' => '0']]);
        preferenceReuseCheck("$family maximum attribute and negative integer index survive reload", $owner->getPreference(str_repeat('a', 255), -1) === 'owner:boundary');
        $largeIndex = $owner->loadPreference('integer-boundary', 2147483647);
        preferenceReuseCheck("$family signed SQL index boundaries and bigint expiry survive reload", $owner->getPreference('integer-boundary', -2147483648) === 'owner:small'
            && $owner->getPreference('integer-boundary', 2147483647) === 'owner:large'
            && $largeIndex !== false && $largeIndex->getExpire() === 4102444800);
        preferenceReuseCheck("$family associative value survives reload", $owner->getAssocPreference('tree') === [2 => ['leaf' => 'owner:nested']]);
        $owner->setPreference('contract', 'updated:' . $raw, '=', $now + 7200);
        $updated = $owner->loadPreference('contract');
        preferenceReuseCheck("$family update schedules existing row", $updated !== false && $updated->getId() === $preference->getId());
        preferenceReuseCheck("$family expiry cutoff is strict and owner-scoped", $owner->cleanExpiredPreferences($now - 3600, 'expired') === 0
            && $owner->cleanExpiredPreferences($now - 3599, 'expired') === 1);
        preferenceReuseCheck("$family index deletion stays scoped", $owner->deletePreference('index', 7) === 1);
        $em->flush();
        $em->clear();
        $owner = $em->find($ownerClass, $id);
        $other = $em->find($ownerClass, $otherId);
        preferenceReuseCheck("$family second clear/reload finds both owners", $owner instanceof $ownerClass && $other instanceof $ownerClass);
        $reloaded = $owner->loadPreference('contract');
        preferenceReuseCheck("$family persisted update preserves ID, operator, expiry and raw bytes", $reloaded !== false && $reloaded->getId() === $preference->getId()
            && $reloaded->getOp() === '=' && $reloaded->getExpire() === $now + 7200
            && $owner->getPreference('contract') === 'updated:' . $raw);
        preferenceReuseCheck("$family persisted cleanup and deletion cannot affect another owner", $owner->getPreference('expired', 0, true) === false
            && $owner->getPreference('index', 7) === false && $owner->getPreference('index', 0) === ''
            && $other->getPreference('expired', 0, true) === 'foreign:expired' && $other->getPreference('index', 7) === '0'
            && $other->getPreference('contract') === 'foreign:' . $raw);
        foreach ($fields as $nullField => $unused) {
            $invalidManager = $manager();
            try {
                $invalid = new $class();
                foreach ($fields as $field => $value) {
                    preferenceReuseInvoke($invalid, 'set' . $field, $field === $nullField ? null : $value);
                }
                preferenceReuseInvoke($invalid, 'set' . $family, $invalidManager->getReference($ownerClass, $id));
                $invalidManager->persist($invalid);
                preferenceReuseCheck("$family SQL rejects null $nullField on real flush", $throws(static fn () => $invalidManager->flush(), NotNullConstraintViolationException::class));
            } finally {
                $invalidManager->close();
                $invalidManager->getConnection()->close();
            }
        }
    }
    preferenceReuseCheck('contract leaves schema unchanged after real writes', preferenceReuseInvoke($nativeSchema, 'pendingSql') === []);
    echo 'ALL PASSED (' . PreferenceReuseContractState::$checks . " checks)\n";
} finally {
    $em->close();
    $em->getConnection()->close();
    $server->createSchemaManager()->dropDatabase($database);
    $server->close();
}
