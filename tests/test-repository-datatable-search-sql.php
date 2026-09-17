<?php

declare(strict_types=1);

/**
 * Unit test: the anchored/contains search toggle across the five DataTables
 * list repositories, asserted on the SQL Doctrine actually compiles and the
 * value bound to the LIKE parameter.
 *
 * The leading-wildcard `LIKE '%term%'` on every draw defeats any index on
 * username/name/domain (etc.), forcing a full scan on every keystroke. The
 * fix anchors the pattern by default (`'term%'`, index-friendly) and only
 * falls back to `'%term%'` when the caller opts in with a leading `*`
 * (DataTableQuery::fromArray's $contains flag). Source-text assertions on the
 * repository PHP cannot catch a regression here -- a mutation that always
 * binds the old `'%term%'` pattern still "looks like" the anchored feature in
 * the diff. So this test runs the real repository method end to end and reads
 * the exact SQL and the exact value bound to the search parameter, the moment
 * the driver would otherwise send them to a real server.
 *
 * No database is required and there is no ext-pdo_sqlite in this environment:
 * a fake DBAL Driver/Connection/Statement stands in for the network driver.
 * It never subclasses a Doctrine class the framework marks unsubclassable
 * (`\Doctrine\ORM\Query` and `\Doctrine\ORM\EntityManager` carry an advisory
 * `@final` PHPStan enforces); every class here implements a plain DBAL
 * interface instead, so the ORM layer above (QueryBuilder, Query, DQL
 * parsing, SQL generation) is exercised completely unmodified -- only the
 * bytes that would go out over the wire are intercepted.
 *
 * The unfiltered COUNT query (queried first by every pagedFor*List method) is
 * given a stub scalar row so it succeeds normally; only the FIRST statement
 * that actually receives a bound parameter -- the filtered COUNT or the paged
 * SELECT, both gated on `$search !== ''` -- is captured, since that is the one
 * whose LIKE pattern is under test.
 *
 * Exit 0 = all passed, 1 = a failure, 2 = bootstrap error.
 */

require __DIR__ . '/../vendor/autoload.php';
// The paged-list DQL joins Mailbox/Alias/Archive/Log to Domain, and Doctrine's
// metadata factory validates every association reachable from the classes
// actually used in a query -- so every entity Domain (and its own
// associations) points to must be loaded, not just the ones this test queries
// directly. Fixed, literal filenames only (no dynamic include path).
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/AdminPreference.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/DatabaseVersion.php';
require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/DomainPreference.php';
require_once __DIR__ . '/../application/Entities/LastLogin.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../application/Entities/McpToken.php';
require_once __DIR__ . '/../application/Entities/QueueRunner.php';
require_once __DIR__ . '/../application/Entities/Quota.php';
require_once __DIR__ . '/../application/Entities/RememberMe.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';
require_once __DIR__ . '/../application/Repositories/Alias.php';
require_once __DIR__ . '/../application/Repositories/Archive.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';
require_once __DIR__ . '/../application/Repositories/Log.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    echo "  FAIL {$label}\n";
    $failures++;
};

echo "\n== DataTable list repositories: anchored/contains search SQL contract ==\n";

final class CapturedSearchQuery
{
    /** @param array<int|string,mixed> $params */
    public function __construct(public readonly string $sql, public readonly array $params)
    {
    }
}

final class CapturedSearchQueryException extends \RuntimeException
{
    public function __construct(public readonly CapturedSearchQuery $captured)
    {
        parent::__construct('search query captured');
    }
}

/**
 * A driver-level result carrying exactly one scalar row (`[0]` / `0`). Used
 * only to satisfy the unfiltered COUNT query every pagedFor*List method runs
 * before the filtered/searched one this test actually cares about.
 */
final class StubScalarResult implements DriverResult
{
    private bool $fetched = false;

    public function fetchNumeric(): array|false
    {
        if ($this->fetched) {
            return false;
        }
        $this->fetched = true;
        return [0];
    }

    public function fetchAssociative(): array|false
    {
        return false;
    }

    public function fetchOne(): mixed
    {
        if ($this->fetched) {
            return false;
        }
        $this->fetched = true;
        return 0;
    }

    /** @return list<list<mixed>> */
    public function fetchAllNumeric(): array
    {
        return [[0]];
    }

    /** @return list<array<string,mixed>> */
    public function fetchAllAssociative(): array
    {
        return [];
    }

    /** @return list<mixed> */
    public function fetchFirstColumn(): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function columnCount(): int
    {
        return 1;
    }

    public function free(): void
    {
    }

    public function getColumnName(int $index): string
    {
        // Only reached by DBAL's result-cache path (executeCacheQuery), which
        // enableResultCache() on the unfiltered COUNT routes through.
        return 'sclr_0';
    }
}

final class SearchCapturingStatement implements DriverStatement
{
    /** @var array<int|string,mixed> */
    private array $bound = [];

    public function __construct(private readonly string $sql)
    {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->bound[$param] = $value;
    }

    public function execute(): DriverResult
    {
        if ($this->bound === []) {
            return new StubScalarResult();
        }
        throw new CapturedSearchQueryException(new CapturedSearchQuery($this->sql, $this->bound));
    }
}

final class SearchCapturingConnection implements DriverConnection
{
    public function prepare(string $sql): DriverStatement
    {
        return new SearchCapturingStatement($sql);
    }

    public function query(string $sql): DriverResult
    {
        return new StubScalarResult();
    }

    public function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    public function exec(string $sql): int|string
    {
        throw new \LogicException('SearchCapturingConnection::exec() is not supported by this fixture');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function getServerVersion(): string
    {
        return '8.0.0';
    }

    /**
     * Never actually used: nothing in the search-SQL contract under test asks
     * for the native driver handle. The interface requires object|resource,
     * so this hands back an inert placeholder object rather than null.
     */
    public function getNativeConnection(): object
    {
        return new \stdClass();
    }
}

final class SearchCapturingDriver extends AbstractMySQLDriver
{
    public function connect(array $params): DriverConnection
    {
        return new SearchCapturingConnection();
    }
}

function searchSqlEntityManager(): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfig([], true);
    $configuration->enableNativeLazyObjects(true);
    $connection = new Connection(['serverVersion' => '8.0.0'], new SearchCapturingDriver());
    return new EntityManager($connection, $configuration);
}

function superAdmin(): \Entities\Admin
{
    $admin = new \Entities\Admin();
    $super = new ReflectionProperty($admin, 'super');
    $super->setAccessible(true);
    $super->setValue($admin, true);
    $id = new ReflectionProperty($admin, 'id');
    $id->setAccessible(true);
    $id->setValue($admin, 1);
    return $admin;
}

/**
 * Run one repository's paged-list method (which is expected to reach a
 * searched statement and throw) and return the captured SQL/parameter.
 */
function capturedSearch(callable $run): CapturedSearchQuery
{
    try {
        $run();
    } catch (CapturedSearchQueryException $e) {
        return $e->captured;
    }
    throw new \RuntimeException('no searched query was captured -- the search path was not reached');
}

// ---- Mailbox ---------------------------------------------------------- //
$em = searchSqlEntityManager();
$repo = new \Repositories\Mailbox($em, $em->getClassMetadata(\Entities\Mailbox::class));
$admin = superAdmin();

$default = capturedSearch(static fn () => $repo->pagedForMailboxList($admin, null, 'term', false, 'username', 'ASC', 0, 10));
$check('Mailbox: default search binds the anchored pattern', ($default->params[1] ?? null) === 'term%');
$check(
    'Mailbox: default search compiles a LIKE over username/name/domain',
    (bool) preg_match('/username LIKE .+ OR .+name LIKE .+ OR .+domain LIKE /i', $default->sql)
);

$contains = capturedSearch(static fn () => $repo->pagedForMailboxList($admin, null, 'term', true, 'username', 'ASC', 0, 10));
$check('Mailbox: starred search binds the contains pattern', ($contains->params[1] ?? null) === '%term%');

$escaped = capturedSearch(static fn () => $repo->pagedForMailboxList($admin, null, 'a%b_c', false, 'username', 'ASC', 0, 10));
$check('Mailbox: %/_ are escaped in the anchored pattern', ($escaped->params[1] ?? null) === 'a\\%b\\_c%');

// ---- Alias -------------------------------------------------------------- //
$em = searchSqlEntityManager();
$repo = new \Repositories\Alias($em, $em->getClassMetadata(\Entities\Alias::class));
$admin = superAdmin();

$default = capturedSearch(static fn () => $repo->pagedForAliasList($admin, null, false, 'term', false, 'address', 'ASC', 0, 10));
$check('Alias: default search binds the anchored pattern', ($default->params[1] ?? null) === 'term%');
$contains = capturedSearch(static fn () => $repo->pagedForAliasList($admin, null, false, 'term', true, 'address', 'ASC', 0, 10));
$check('Alias: starred search binds the contains pattern', ($contains->params[1] ?? null) === '%term%');

// ---- Archive -------------------------------------------------------------- //
$em = searchSqlEntityManager();
$repo = new \Repositories\Archive($em, $em->getClassMetadata(\Entities\Archive::class));
$admin = superAdmin();

$default = capturedSearch(static fn () => $repo->pagedForArchiveList($admin, null, 'term', false, 'username', 'ASC', 0, 10));
$check('Archive: default search binds the anchored pattern', ($default->params[1] ?? null) === 'term%');
$contains = capturedSearch(static fn () => $repo->pagedForArchiveList($admin, null, 'term', true, 'username', 'ASC', 0, 10));
$check('Archive: starred search binds the contains pattern', ($contains->params[1] ?? null) === '%term%');

// ---- Domain -------------------------------------------------------------- //
$em = searchSqlEntityManager();
$repo = new \Repositories\Domain($em, $em->getClassMetadata(\Entities\Domain::class));
$admin = superAdmin();

$default = capturedSearch(static fn () => $repo->pagedForDomainList($admin, 'term', false, 'domain', 'ASC', 0, 10));
$check('Domain: default search binds the anchored pattern', ($default->params[1] ?? null) === 'term%');
$contains = capturedSearch(static fn () => $repo->pagedForDomainList($admin, 'term', true, 'domain', 'ASC', 0, 10));
$check('Domain: starred search binds the contains pattern', ($contains->params[1] ?? null) === '%term%');

// ---- Log ------------------------------------------------------------------ //
$em = searchSqlEntityManager();
$repo = new \Repositories\Log($em, $em->getClassMetadata(\Entities\Log::class));

$default = capturedSearch(static fn () => $repo->pagedForLogList(null, null, 'term', false, 'timestamp', 'DESC', 0, 10));
$check('Log: default search binds the anchored pattern', ($default->params[1] ?? null) === 'term%');
$contains = capturedSearch(static fn () => $repo->pagedForLogList(null, null, 'term', true, 'timestamp', 'DESC', 0, 10));
$check('Log: starred search binds the contains pattern', ($contains->params[1] ?? null) === '%term%');

echo "\n";
if ($failures === 0) {
    // The runner's verdict regex admits only [A-Za-z0-9_+ -] in the subject,
    // so no namespace separator here.
    echo "OK: all DataTable repository search SQL assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
