<?php

/** Focused constructor contract tests for the legacy schema helper. */

namespace ViMbAdmin\Tests;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/ViMbAdmin/Schema.php';

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use TypeError;
use ViMbAdmin_Schema;

final class SchemaConstructorAssertions
{
    public static int $failures = 0;
}

function schemaConstructorCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        SchemaConstructorAssertions::$failures++;
    }
}

function schemaStoredEntityManager(ViMbAdmin_Schema $schema): EntityManagerInterface
{
    $property = new ReflectionProperty($schema, '_em');
    $value = $property->getValue($schema);
    if (!$value instanceof EntityManagerInterface) {
        throw new RuntimeException('schema did not retain an entity manager');
    }

    return $value;
}

function schemaConstructorRejects(mixed $value): bool
{
    try {
        (new ReflectionClass(ViMbAdmin_Schema::class))->newInstance($value);
    } catch (TypeError) {
        return true;
    }

    return false;
}

echo "== ViMbAdmin_Schema constructor ==\n";

$parameter = (new ReflectionMethod(ViMbAdmin_Schema::class, '__construct'))->getParameters()[0];
$type = $parameter->getType();
schemaConstructorCheck(
    'declares the Doctrine entity-manager interface contract',
    $type instanceof ReflectionNamedType
        && $type->getName() === EntityManagerInterface::class
        && !$type->allowsNull(),
);

/** @var EntityManager $entityManager */
$entityManager = (new ReflectionClass(EntityManager::class))->newInstanceWithoutConstructor();
$schema = new ViMbAdmin_Schema($entityManager);
schemaConstructorCheck(
    'accepts and retains the legacy concrete Doctrine entity manager',
    schemaStoredEntityManager($schema) === $entityManager,
);

$decorator = new class ($entityManager) extends EntityManagerDecorator {};
$decoratedSchema = new ViMbAdmin_Schema($decorator);
schemaConstructorCheck(
    'accepts an entity-manager decorator at the interface boundary',
    schemaStoredEntityManager($decoratedSchema) === $decorator,
);

schemaConstructorCheck('rejects an unrelated object', schemaConstructorRejects(new stdClass()));
schemaConstructorCheck('rejects null', schemaConstructorRejects(null));

$failures = SchemaConstructorAssertions::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
