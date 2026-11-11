<?php

/**
 * Schema command result contract. Run as an isolated PHP process.
 *
 * Only ViMbAdmin_Schema is replaced: the command, Container, auth/session
 * interfaces and Doctrine EntityManager interface are the maintained classes.
 * No session is opened, schema SQL applied or database connection constructed.
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use ViMbAdmin\Kernel\Cli\Command\SchemaUpdateCommand;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Kernel/Session/SessionStorage.php';
require_once dirname(__DIR__) . '/src/Kernel/Security/Auth.php';
require_once dirname(__DIR__) . '/src/Kernel/Container.php';
require_once dirname(__DIR__) . '/src/Kernel/Cli/CliCommand.php';

final class CliSchemaContractState
{
    /** @var array{applied:int,version:int|null,statements:list<string>} */
    public static array $result = ['applied' => 0, 'version' => null, 'statements' => []];
    public static ?Throwable $error = null;
    public static int $constructed = 0;
    public static int $migrations = 0;
    public static ?EntityManagerInterface $received = null;
    public static int $checks = 0;

    /** @return array{constructed:int,migrations:int,received:EntityManagerInterface|null} */
    public static function observation(): array
    {
        return [
            'constructed' => self::$constructed,
            'migrations' => self::$migrations,
            'received' => self::$received,
        ];
    }
}

/** Match the real Schema constructor and migrate() result contract. */
final class ViSchemaResultBoundaryDouble
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        CliSchemaContractState::$constructed++;
        CliSchemaContractState::$received = $entityManager;
    }

    /** @return array{applied:int,version:int|null,statements:list<string>} */
    public function migrate(): array
    {
        CliSchemaContractState::$migrations++;
        if (CliSchemaContractState::$error !== null) {
            throw CliSchemaContractState::$error;
        }

        return CliSchemaContractState::$result;
    }
}

// Keep the test declaration distinct from the production class in static graphs.
// Only this isolated process aliases the typed boundary for the real command.
if (class_exists('ViMbAdmin_Schema', false)) {
    throw new LogicException('Schema contract requires an unloaded production schema class.');
}
if (!class_alias(ViSchemaResultBoundaryDouble::class, 'ViMbAdmin_Schema', false)) {
    throw new LogicException('Could not install the isolated schema boundary.');
}

/** Use Doctrine's supported decorator extension point without constructing DBAL. */
final class CliSchemaEntityManager extends EntityManagerDecorator
{
    public function __construct()
    {
    }

    #[\Override]
    public function getConnection(): Connection
    {
        throw new LogicException('Schema wrapper must not access a database connection.');
    }
}

/** The command never needs auth/session access; reject any accidental use. */
final class CliSchemaUnusedSession implements SessionStorage
{
    #[\Override]
    public function has(string $key): bool
    {
        throw new LogicException('Schema wrapper must not read a session.');
    }

    #[\Override]
    public function get(string $key): mixed
    {
        throw new LogicException('Schema wrapper must not read a session.');
    }

    #[\Override]
    public function set(string $key, mixed $value): void
    {
        throw new LogicException('Schema wrapper must not write a session.');
    }

    #[\Override]
    public function remove(string $key): void
    {
        throw new LogicException('Schema wrapper must not remove a session.');
    }
}

/** The actual Container must request exactly its Doctrine resource. */
final class CliSchemaResources
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getResource(string $name): EntityManagerInterface
    {
        $this->requests[] = $name;
        if ($name !== 'doctrine2') {
            throw new LogicException('Unexpected schema command resource.');
        }

        return $this->entityManager;
    }
}

require_once dirname(__DIR__) . '/src/Kernel/Cli/Command/SchemaUpdateCommand.php';

function cliSchemaContractCheck(string $name, bool $condition): void
{
    CliSchemaContractState::$checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
}

/**
 * Observe only the actual command wrapper's status, stdout and boundary calls.
 *
 * @param array{applied:int,version:int|null,statements:list<string>} $result
 * @param array<string,mixed> $arguments
 */
function cliSchemaContractCase(
    string $name,
    array $result,
    array $arguments,
    ?Throwable $error,
    int $expectedStatus,
    string $expectedOutput,
): void {
    CliSchemaContractState::$result = $result;
    CliSchemaContractState::$error = $error;
    CliSchemaContractState::$constructed = 0;
    CliSchemaContractState::$migrations = 0;
    CliSchemaContractState::$received = null;
    $entityManager = new CliSchemaEntityManager();
    $resources = new CliSchemaResources($entityManager);
    $container = new Container(
        $resources,
        new Auth(new CliSchemaUnusedSession(), static fn (int $id): ?object => null),
    );
    ob_start();
    try {
        $status = (new SchemaUpdateCommand())->run($container, $arguments);
        $output = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $observation = CliSchemaContractState::observation();
    cliSchemaContractCheck($name . ': exact exit status', $status === $expectedStatus);
    cliSchemaContractCheck($name . ': exact stdout', $output === $expectedOutput);
    cliSchemaContractCheck($name . ': actual Container requests Doctrine once', $resources->requests === ['doctrine2']);
    cliSchemaContractCheck($name . ': Schema constructed once', $observation['constructed'] === 1);
    cliSchemaContractCheck($name . ': migrate attempted exactly once', $observation['migrations'] === 1);
    cliSchemaContractCheck($name . ': same interface-compatible EntityManager passed', $observation['received'] === $entityManager);
}

cliSchemaContractCheck('registered command name', (new SchemaUpdateCommand())->name() === 'maintenance.cli-schema-update');
$applied = ['applied' => 2, 'version' => 7, 'statements' => ['ALTER TABLE fixture ADD id INT', 'CREATE INDEX fixture_id ON fixture (id)']];
$noOp = ['applied' => 0, 'version' => 7, 'statements' => []];
$nullableVersion = ['applied' => 0, 'version' => null, 'statements' => []];
$appliedOutput = "Applied 2 schema statement(s):\n  ALTER TABLE fixture ADD id INT\n  CREATE INDEX fixture_id ON fixture (id)\nDB version: 7\n";

cliSchemaContractCase('applied/default silent', $applied, [], null, 0, '');
cliSchemaContractCase('no-op/default silent', $noOp, [], null, 0, '');
// getopt() represents a present, valueless flag as false. Presence enables output.
cliSchemaContractCase('applied/short verbose flag', $applied, ['v' => false], null, 0, $appliedOutput);
cliSchemaContractCase('applied/long verbose flag', $applied, ['verbose' => false], null, 0, $appliedOutput);
cliSchemaContractCase('no-op/verbose', $noOp, ['verbose' => true], null, 0, "Schema already up to date.\nDB version: 7\n");
cliSchemaContractCase('nullable version/verbose', $nullableVersion, ['v' => true], null, 0, "Schema already up to date.\nDB version: ?\n");
cliSchemaContractCase('migration exception/default', $applied, [], new RuntimeException('controlled migration failure'), 1, "ERROR: schema update failed: controlled migration failure\n");
cliSchemaContractCase('migration error/verbose', $applied, ['v' => false], new Error('controlled migration error'), 1, "ERROR: schema update failed: controlled migration error\n");

echo 'ALL PASSED (' . CliSchemaContractState::$checks . " checks)\n";
