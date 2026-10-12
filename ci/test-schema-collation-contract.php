<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/ViMbAdmin/Schema.php';

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;

final class SchemaCollationAssertions
{
    public static int $failures = 0;
}

function schemaCollationCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        SchemaCollationAssertions::$failures++;
    }
}

final class SchemaCollationConnection extends Connection
{
    /** @var array<string,mixed> */
    private array $columns = [];

    /** @param array<string,mixed> $columns */
    public function configure(array $columns): void
    {
        $this->columns = $columns;
    }

    #[\Override]
    public function getDatabase(): string
    {
        return 'vimbadmin';
    }

    /** @param list<mixed> $params @param array<int|string,mixed> $types */
    #[\Override]
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if ($types !== []) {
            throw new \RuntimeException('Unexpected schema query parameter types');
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return 1;
        }
        if (str_contains($query, 'information_schema.TABLE_CONSTRAINTS')) {
            return 1;
        }
        if (str_contains($query, 'information_schema.COLUMNS')) {
            $table = $params[1] ?? null;
            $field = str_contains($query, 'CHARACTER_SET_NAME') ? 'charset' : 'collation';
            return is_string($table) ? ($this->columns[$table . '.' . $field] ?? null) : null;
        }
        throw new \RuntimeException('Unexpected schema query: ' . $query);
    }
}

final class SchemaCollationEntityManager extends EntityManagerDecorator
{
    public function __construct(private Connection $connection)
    {
        /** @var EntityManager $wrapped */
        $wrapped = (new \ReflectionClass(EntityManager::class))->newInstanceWithoutConstructor();
        parent::__construct($wrapped);
    }

    #[\Override]
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}

/**
 * @param array<string,mixed> $columns
 * @return array<string>
 */
function schemaCollationSql(array $columns): array
{
    /** @var SchemaCollationConnection $connection */
    $connection = (new \ReflectionClass(
        SchemaCollationConnection::class
    ))->newInstanceWithoutConstructor();
    $connection->configure($columns);

    return (new ViMbAdmin_Schema(new SchemaCollationEntityManager(
        $connection
    )))->extraSql();
}

echo "== Native schema collation contract ==\n";

$drifted = schemaCollationSql([
    'dovecot_quota.charset' => 'utf8mb4',
    'dovecot_quota.collation' => 'utf8mb4_unicode_ci',
    'mailbox.charset' => 'utf8mb3',
    'mailbox.collation' => 'utf8mb3_uca1400_ai_ci',
]);
schemaCollationCheck(
    'positive: drift is aligned to the live mailbox charset and collation',
    $drifted === [
        'ALTER TABLE `dovecot_quota` MODIFY `username` VARCHAR(255) '
            . 'CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci NOT NULL',
    ]
);

$aligned = schemaCollationSql([
    'dovecot_quota.charset' => 'utf8mb4',
    'dovecot_quota.collation' => 'utf8mb4_uca1400_ai_ci',
    'mailbox.charset' => 'utf8mb4',
    'mailbox.collation' => 'utf8mb4_uca1400_ai_ci',
]);
schemaCollationCheck('boundary: an already-aligned schema emits no ALTER', $aligned === []);

$missing = schemaCollationSql([
    'dovecot_quota.charset' => 'utf8mb4',
    'dovecot_quota.collation' => 'utf8mb4_unicode_ci',
    'mailbox.charset' => null,
    'mailbox.collation' => null,
]);
schemaCollationCheck('error: missing mailbox metadata emits no unsafe ALTER', $missing === []);

$malformed = schemaCollationSql([
    'dovecot_quota.charset' => 'utf8mb4',
    'dovecot_quota.collation' => 'utf8mb4_unicode_ci',
    'mailbox.charset' => 'utf8mb3` INJECT',
    'mailbox.collation' => 'utf8mb3_unicode_ci',
]);
schemaCollationCheck('malformed: unsafe identifier metadata is rejected', $malformed === []);

$failures = SchemaCollationAssertions::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
