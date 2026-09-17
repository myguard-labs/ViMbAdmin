<?php

/**
 * Unit test: Repositories\Admin::getNotAssignedForDomain() exclusion contract.
 *
 * VIM-D10 moved the "not assigned to this domain" filtering out of PHP and into
 * DQL. The risk that introduces is a silently wrong query: a super admin
 * offered for assignment, or an already-assigned admin offered a second time.
 * Asserting on the DQL's source text cannot catch either -- the substrings
 * survive a mutation that neuters the clause they belong to.
 *
 * So this test compiles the real DQL through Doctrine and asserts on the SQL
 * the ORM actually generates. No database is required: the connection is built
 * driverless (as tests/test-repository-mailbox-alias-identity.php does), and
 * only the query compiler runs. A mutation to either exclusion changes the
 * generated SQL and turns these assertions red.
 *
 * Exit 0 = all passed, 1 = a failure, 2 = bootstrap error.
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

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    echo "  FAIL {$label}\n";
    $failures++;
};

echo "\n== Repositories\\Admin::getNotAssignedForDomain exclusion contract ==\n";

/**
 * Capture the SQL the ORM generates for the repository's real DQL.
 *
 * The repository is exercised through its actual public method, so the DQL
 * under test is the one production runs -- not a copy pasted into the test.
 * runNotAssignedQuery() is the seam: it hands back the compiled query instead
 * of executing it, which is what lets this run without a database.
 */
final class NotAssignedSqlProbe extends \Repositories\Admin
{
    public ?string $sql = null;

    public ?string $dql = null;

    public function __construct(private \Doctrine\ORM\EntityManagerInterface $probeEm)
    {
    }

    /**
     * @param string $dql
     * @param \Entities\Domain $domain
     * @return mixed
     */
    protected function runNotAssignedQuery($dql, $domain)
    {
        $this->dql = $dql;
        $query = $this->probeEm->createQuery($dql);
        $query->setParameter(1, $domain);
        // getSQL() widens to list<string> for queries the ORM splits into
        // several statements. This one is a single SELECT, so join rather than
        // assume: a list would otherwise be silently stringified to "Array".
        $sql = $query->getSQL();
        $this->sql = is_array($sql) ? implode(' ; ', $sql) : $sql;

        // The caller only maps rows; an empty result keeps this probe
        // database-free while still exercising the real mapping path.
        return [];
    }
}

$configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([], true);
$configuration->enableNativeLazyObjects(true);
// serverVersion pins the platform so the compiler never reaches for a live
// server: SQL generation needs a platform, and without this DBAL would try to
// connect just to ask which MySQL it is talking to.
$connection = \Doctrine\DBAL\DriverManager::getConnection(
    ['driver' => 'pdo_mysql', 'serverVersion' => '8.0.0'],
    $configuration
);
$entityManager = new \Doctrine\ORM\EntityManager($connection, $configuration);

$domain = new \Entities\Domain();
$probe = new NotAssignedSqlProbe($entityManager);

$mapped = $probe->getNotAssignedForDomain($domain);
$sql = (string) $probe->sql;

// Metadata comes from the entity classes' own PHP attributes, so the driver
// needs the class LOADED (the autoloader above), not a filesystem scan path --
// which is why the empty-paths form is safe here and is the form the other
// repository tests use. Assert the discovery actually happened, so a future
// Doctrine change that silently resolved nothing could not leave the SQL
// assertions below vacuously passing against an empty string.
$adminMetadata = $entityManager->getClassMetadata(\Entities\Admin::class);
$check(
    'entity metadata is discovered from attributes without mapping paths',
    $adminMetadata->getTableName() === 'admin'
        && in_array('super', $adminMetadata->getFieldNames(), true)
        && in_array('active', $adminMetadata->getFieldNames(), true)
);

$check('the query compiles to SQL at all', $sql !== '');
$check('an empty result set maps to an empty list', $mapped === []);

// ---- the super-admin exclusion ---------------------------------------- //
// Doctrine renders `a.super = false` as a comparison against a boolean
// literal. Asserting on the compiled predicate means widening the DQL to
// `a.super = false OR a.super = true` (which leaks super admins into the
// assign dropdown) changes the SQL and fails here.
$superPredicates = preg_match_all('/\bsuper\s*=\s*[^\s)]+/i', $sql);
$check(
    'the compiled SQL constrains super exactly once',
    $superPredicates === 1
);
$check(
    'the super constraint selects non-super admins only',
    (bool) preg_match('/\bsuper\s*=\s*(0|false)\b/i', $sql)
);
$check(
    'the super constraint is not widened by a disjunction',
    !preg_match('/\bsuper\s*=\s*[^\s)]+\s+OR\b/i', $sql)
);

// ---- the already-assigned exclusion ------------------------------------ //
// The exclusion must be a NOT IN over a subquery that joins the domain to its
// admins. Neutering the subquery (for example by appending `AND 1 = 0`, so no
// admin is ever excluded and assigned admins reappear) alters the compiled
// SQL and fails here.
$check(
    'assigned admins are excluded with NOT IN',
    (bool) preg_match('/\bNOT\s+IN\s*\(\s*SELECT\b/i', $sql)
);
$check(
    'the exclusion subquery joins the domain to its admins',
    (bool) preg_match('/NOT\s+IN\s*\(\s*SELECT.*\bdomain_admins\b.*\)/is', $sql)
);
// The correlation must be an EQUALITY to the bound domain. `d <> ?1` would
// still put a parameter after WHERE while inverting the whole exclusion --
// every admin assigned to the selected domain reappears in the dropdown --
// so match the operator, not merely the presence of a placeholder.
$check(
    'the exclusion subquery is correlated to the bound domain by equality',
    (bool) preg_match('/NOT\s+IN\s*\(\s*SELECT.*\bWHERE\b\s*\S+\s*=\s*\?\s*\)/is', $sql)
);
$check(
    'the domain correlation is not negated',
    !preg_match('/NOT\s+IN\s*\(\s*SELECT.*\bWHERE\b[^)]*(<>|!=)/is', $sql)
);
$check(
    'the exclusion subquery is not short-circuited by a constant false',
    !preg_match('/NOT\s+IN\s*\(\s*SELECT.*\b(1\s*=\s*0|0\s*=\s*1)\b/is', $sql)
);

// ---- hydration stays scalar -------------------------------------------- //
// Entity hydration is what VIM-D10 removed; the compiled SELECT must name the
// three scalar columns and nothing else.
// Assert the SELECT list EXACTLY. Checking only that the three columns are
// present would let an extra column through -- adding a.password to the DQL
// would still pass while breaking the scalar-hydration contract and pulling a
// password hash into a dropdown query.
preg_match('/^SELECT\s+(.*?)\s+FROM\b/is', $sql, $selectMatch);
$selectedAliases = array_map(
    // Strip the table alias and Doctrine's positional result suffix, so the
    // assertion survives a change of alias but not a change of columns.
    static function (string $expression): string {
        $expression = trim($expression);
        $expression = (string) preg_replace('/\s+AS\s+\w+$/i', '', $expression);
        return (string) preg_replace('/^\w+\./', '', $expression);
    },
    explode(',', $selectMatch[1] ?? '')
);
sort($selectedAliases);
$check(
    'the SELECT list is exactly id, username and active -- no extra columns',
    $selectedAliases === ['active', 'id', 'username']
);
$check(
    'the admin table is not joined for eager entity hydration',
    substr_count(strtolower($sql), 'from admin') === 1
);

// ---- the mapping contract still holds over real rows ------------------- //
// Rows the query would return, mapped through the production path.
final class NotAssignedRowProbe extends \Repositories\Admin
{
    /** @param list<array{id:int,username:string,active:bool}> $rows */
    public function __construct(private array $rows)
    {
    }

    /**
     * @param string $dql
     * @param \Entities\Domain $domain
     * @return mixed
     */
    protected function runNotAssignedQuery($dql, $domain)
    {
        return $this->rows;
    }
}

$mappedRows = (new NotAssignedRowProbe([
    ['id' => 7, 'username' => 'active@example.com', 'active' => true],
    ['id' => 9, 'username' => 'dormant@example.com', 'active' => false],
]))->getNotAssignedForDomain(new \Entities\Domain());

$check(
    'query rows map to id => username with the inactive suffix',
    $mappedRows === [7 => 'active@example.com', 9 => 'dormant@example.com (inactive)']
);
$check(
    'ids are preserved as integer keys',
    array_keys($mappedRows) === [7, 9]
);

echo "\n";
if ($failures === 0) {
    // The runner's verdict regex admits only [A-Za-z0-9_+ -] in the subject,
    // so no namespace separator here.
    echo "OK: all Repositories Admin not-assigned assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
