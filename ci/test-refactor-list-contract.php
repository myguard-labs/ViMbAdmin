<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';

use ViMbAdmin\Kernel\DataTable\DataTableQuery;

$failures = 0;
$counts = [];
$check = static function (string $group, string $label, bool $passed) use (&$failures, &$counts): void {
    $counts[$group] = ($counts[$group] ?? 0) + 1;
    if (!$passed) {
        $failures++;
    }
    echo ($passed ? '  ok   ' : '  FAIL ') . $label . "\n";
};
$error = static function (callable $operation, string $class, string $message): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception::class === $class && $exception->getMessage() === $message;
    }
    return false;
};
$invoke = static function (string $class, string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod($class, $method))->invoke(null, ...$arguments);
};
$sortField = static function (string $class, int $index, bool $layout) use ($invoke): string {
    $field = $invoke($class, 'listSortField', $index, $layout);
    return is_string($field) ? $field : '<non-string>';
};
$sortMap = static function (string $class, int $last, bool $layout) use ($sortField): array {
    $fields = [];
    for ($index = 0; $index <= $last; $index++) {
        $fields[] = $sortField($class, $index, $layout);
    }
    return $fields;
};

echo "== VC-C03/VC-C03b list adapter contract ==\n";

$request = DataTableQuery::requestArray([
    'draw' => '7',
    'start' => '20',
    'length' => '25',
    'search' => ['value' => ' *a%b_c\\d '],
    'order' => [['column' => '2', 'dir' => 'desc']],
    'columns' => [['data' => 'id']],
    0 => 'discarded transport residue',
]);
$query = DataTableQuery::fromArray($request, 3);
$check('positive', 'VC-C03 positive: shared HTTP adapter discards numeric keys', !array_key_exists(0, $request));
$check('positive', 'VC-C03 positive: shared HTTP adapter preserves nested protocol bytes', $request['search'] === ['value' => ' *a%b_c\\d ']);
$check('positive', 'VC-C03 positive: shared policy parses draw/start/length', $query->draw === 7 && $query->start === 20 && $query->length === 25);
$check('positive', 'VC-C03 positive: contains toggle reaches the stripped search term', $query->contains && $query->searchTerm === 'a%b_c\\d');
$check('positive', 'VC-C03 positive: LIKE policy escapes percent underscore and backslash', DataTableQuery::likePattern($query->searchTerm, $query->contains) === '%a\\%b\\_c\\\\d%');
$check('positive', 'VC-C03 positive: existing order policy remains first-column DESC', $query->sortColumn === 2 && $query->sortDir === 'DESC');

$defaults = DataTableQuery::fromArray(DataTableQuery::requestArray([]));
$maximumLength = (new ReflectionClassConstant(DataTableQuery::class, 'MAX_LENGTH'))->getValue();
$check('boundary', 'VC-C03 boundary: pagination maximum remains exactly 500', is_int($maximumLength) && $maximumLength === 500);
$check('boundary', 'VC-C03 boundary: missing pagination preserves defaults', $defaults->draw === 1 && $defaults->start === 0 && $defaults->length === 10);
$check('boundary', 'VC-C03 boundary: negative start clamps to zero', DataTableQuery::fromArray(DataTableQuery::requestArray(['start' => '-1']))->start === 0);
$check('boundary', 'VC-C03 boundary: All length clamps to 500', DataTableQuery::fromArray(DataTableQuery::requestArray(['length' => '-1']))->length === 500);
$check('boundary', 'VC-C03 boundary: over-cap length clamps to 500', DataTableQuery::fromArray(DataTableQuery::requestArray(['length' => '501']))->length === 500);
$check('boundary', 'VC-C03 boundary: zero length preserves fallback 10', DataTableQuery::fromArray(DataTableQuery::requestArray(['length' => '0']))->length === 10);
$check('boundary', 'VC-C03 boundary: exact maximum length remains accepted', DataTableQuery::fromArray(DataTableQuery::requestArray(['length' => '500']))->length === 500);

foreach (['draw', 'start', 'length'] as $leaf) {
    $check('malformed', "VC-C03 malformed: container {$leaf} fails at the HTTP boundary", $error(
        static fn () => DataTableQuery::requestArray([$leaf => ['7']]),
        LogicException::class,
        "DataTables parameter {$leaf} must be a string",
    ));
    $check('malformed', "VC-C03 malformed: non-string scalar {$leaf} retains transport error", $error(
        static fn () => DataTableQuery::requestArray([$leaf => 7]),
        LogicException::class,
        "DataTables parameter {$leaf} must be a string",
    ));
}
foreach ([
    [['search' => 'term'], 'search must be an array'],
    [['search' => ['value' => ['term']]], 'search[value] must be a string'],
    [['order' => 'desc'], 'order must be an array'],
    [['order' => [['column' => ['2']]]], 'order[0][column] must be an integer'],
] as [$malformed, $message]) {
    $check('malformed', 'VC-C03 malformed: shared parser rejects ' . $message, $error(
        static fn () => DataTableQuery::fromArray(DataTableQuery::requestArray($malformed)),
        TypeError::class,
        $message,
    ));
}
$check('error', 'VC-C03 error: short search retains the configured-minimum message', $error(
    static fn () => DataTableQuery::fromArray(DataTableQuery::requestArray(['search' => ['value' => '*ab']]), 3),
    LengthException::class,
    'Search must be empty or at least 3 characters',
));
$check('error', 'VC-C03 error: negative minimum remains an internal policy error', $error(
    static fn () => DataTableQuery::fromArray(DataTableQuery::requestArray([]), -1),
    LogicException::class,
    'Minimum search length must be non-negative',
));

$check('order-positive', 'VC-C03b positive: Alias fixed layout preserves its exact allowlist', [
    DataTableQuery::orderField(0, ['address', 'domain', 'active'], 'address'),
    DataTableQuery::orderField(1, ['address', 'domain', 'active'], 'address'),
    DataTableQuery::orderField(2, ['address', 'domain', 'active'], 'address'),
] === ['address', 'domain', 'active']);
$check('order-positive', 'VC-C03b positive: Archive fixed layout preserves its sparse allowlist', [
    DataTableQuery::orderField(0, ['username', 'status', 'domain', '', 'archived_at'], 'archived_at'),
    DataTableQuery::orderField(1, ['username', 'status', 'domain', '', 'archived_at'], 'archived_at'),
    DataTableQuery::orderField(2, ['username', 'status', 'domain', '', 'archived_at'], 'archived_at'),
    DataTableQuery::orderField(4, ['username', 'status', 'domain', '', 'archived_at'], 'archived_at'),
] === ['username', 'status', 'domain', 'archived_at']);
$check('order-positive', 'VC-C03b positive: mixed-case descending direction remains DESC', DataTableQuery::fromArray([
    'order' => [['column' => '2', 'dir' => 'dEsC']],
])->sortDir === 'DESC');

$check('order-boundary', 'VC-C03b boundary: an unsortable rendered column uses the controller fallback', DataTableQuery::orderField(
    3,
    ['username', 'status', 'domain', '', 'archived_at'],
    'archived_at',
) === 'archived_at');
$check('order-boundary', 'VC-C03b boundary: an out-of-range column uses the controller fallback', DataTableQuery::orderField(
    99,
    ['address', 'domain', 'active'],
    'address',
) === 'address');
$check('order-boundary', 'VC-C03b boundary: a negative request column still clamps to the first field', ($negativeOrder = DataTableQuery::fromArray([
    'order' => [['column' => '-1', 'dir' => 'asc']],
]))->sortColumn === 0 && DataTableQuery::orderField($negativeOrder->sortColumn, ['address'], 'fallback') === 'address');
$check('order-boundary', 'VC-C03b boundary: an unknown direction still falls back to ASC', DataTableQuery::fromArray([
    'order' => [['column' => '0', 'dir' => 'sideways']],
])->sortDir === 'ASC');
$check('order-boundary', 'VC-C03b boundary: only the first order entry remains active', ($firstOrder = DataTableQuery::fromArray([
    'order' => [
        ['column' => '1', 'dir' => 'desc'],
        ['column' => '2', 'dir' => 'asc'],
    ],
]))->sortColumn === 1 && $firstOrder->sortDir === 'DESC');

foreach ([
    [['order' => [['column' => '0', 'dir' => []]]], 'order[0][dir] must be a string'],
    [['order' => ['desc']], 'order[0] must be an array'],
] as [$malformedOrder, $message]) {
    $check('order-malformed', 'VC-C03b malformed: shared order parser rejects ' . $message, $error(
        static fn () => DataTableQuery::fromArray($malformedOrder),
        TypeError::class,
        $message,
    ));
}

$domain = 'ViMbAdmin\\Kernel\\Controller\\DomainController';
$log = 'ViMbAdmin\\Kernel\\Controller\\LogController';
$mailbox = 'ViMbAdmin\\Kernel\\Controller\\MailboxController';
$check('conditional-layout', 'VC-C03b conditional-layout: Domain size column rendered preserves every clicked index', $sortMap($domain, 9, true) === [
    'domain', 'mailboxes', 'aliases', 'domain', 'quota', 'active', 'transport', 'domain', 'created', 'domain',
]);
$check('conditional-layout', 'VC-C03b conditional-layout: Domain size column disabled shifts every later clicked index', $sortMap($domain, 8, false) === [
    'domain', 'mailboxes', 'aliases', 'quota', 'active', 'transport', 'domain', 'created', 'domain',
]);
$check('conditional-layout', 'VC-C03b named PR182 defect: disabled Domain size keeps Created at index 7', $sortField($domain, 7, false) === 'created');
$check('conditional-layout', 'VC-C03b conditional-layout: unscoped Log preserves the Domain column', $sortMap($log, 4, false) === [
    'action', 'timestamp', 'admin', 'domain', 'timestamp',
]);
$check('conditional-layout', 'VC-C03b conditional-layout: domain-scoped Log omits Domain before Occurred At', $sortMap($log, 3, true) === [
    'action', 'timestamp', 'admin', 'timestamp',
]);
$check('conditional-layout', 'VC-C03b named PR182 defect: scoped Log keeps Occurred At at index 3', $sortField($log, 3, true) === 'timestamp');
$check('conditional-layout', 'VC-C03b conditional-layout: Mailbox Domain column rendered preserves every clicked index', $sortMap($mailbox, 6, true) === [
    'username', 'name', 'username', 'username', 'domain', 'username', 'username',
]);
$check('conditional-layout', 'VC-C03b conditional-layout: Mailbox Domain column disabled exposes no domain sort', $sortMap($mailbox, 5, false) === [
    'username', 'name', 'username', 'username', 'username', 'username',
]);

$alias = 'ViMbAdmin\\Kernel\\Controller\\AliasController';
$check('retained', 'VC-C03 retained difference: Alias rejects an additional container parameter', $error(
    static fn () => $invoke($alias, 'requestArray', ['extra' => []]),
    LogicException::class,
    'DataTables parameter extra must be a string',
));
$check('retained', 'VC-C03 retained difference: shared pagination adapter leaves additional containers for parser ownership', DataTableQuery::requestArray(['extra' => []]) === ['extra' => []]);
$check('retained', 'VC-C03 retained difference: Log rejects a numeric request key', $error(
    static fn () => $invoke($log, 'stringMap', [0 => 'discard'], 'GET data'),
    TypeError::class,
    'GET data must use string keys',
));
$check('retained', 'VC-C03 retained difference: shared pagination adapter discards a numeric request key', DataTableQuery::requestArray([0 => 'discard']) === []);
$check('retained', 'VC-C03 retained difference: Log preserves additional nested values', $invoke($log, 'stringMap', ['extra' => []], 'GET data') === ['extra' => []]);

$root = dirname(__DIR__);
foreach (['Archive', 'Domain', 'Mailbox'] as $owner) {
    $source = file_get_contents("{$root}/src/Kernel/Controller/{$owner}Controller.php");
    $check('source-row', "VC-C03 source-row {$owner}: private request adapter removed", is_string($source)
        && !str_contains($source, 'private static function requestArray('));
    $check('source-row', "VC-C03 source-row {$owner}: request binding delegates to DataTableQuery", is_string($source)
        && str_contains($source, '$request = DataTableQuery::requestArray($_GET);'));
    $check('source-row', "VC-C03 source-row {$owner}: repository receives parsed pagination fields", is_string($source)
        && str_contains($source, '$q->sortDir, $q->start, $q->length)'));
    $check('source-row', "VC-C03 source-row {$owner}: result envelope remains owned by DataTableResult", is_string($source)
        && str_contains($source, 'DataTableResult::json($q,'));
}
$aliasSource = file_get_contents("{$root}/src/Kernel/Controller/AliasController.php");
$archiveSource = file_get_contents("{$root}/src/Kernel/Controller/ArchiveController.php");
$domainSource = file_get_contents("{$root}/src/Kernel/Controller/DomainController.php");
$logSource = file_get_contents("{$root}/src/Kernel/Controller/LogController.php");
$mailboxSource = file_get_contents("{$root}/src/Kernel/Controller/MailboxController.php");
$check('source-row', 'VC-C03 source-row Alias: transport-specific adapter retained', is_string($aliasSource)
    && str_contains($aliasSource, 'private static function requestArray(')
    && str_contains($aliasSource, '$request = self::requestArray($_GET);'));
$check('source-row', 'VC-C03 source-row Log: transport-specific adapter retained', is_string($logSource)
    && str_contains($logSource, 'private static function stringMap(')
    && str_contains($logSource, "self::stringMap(\$_GET, 'GET data')"));
$check('source-row', 'VC-C03b source-row: all five live order paths use the shared selector', is_string($aliasSource)
    && is_string($archiveSource)
    && is_string($domainSource)
    && is_string($logSource)
    && is_string($mailboxSource)
    && str_contains($aliasSource, 'DataTableQuery::orderField(')
    && str_contains($archiveSource, 'DataTableQuery::orderField(')
    && str_contains($domainSource, 'DataTableQuery::orderField(')
    && str_contains($logSource, 'DataTableQuery::orderField(')
    && str_contains($mailboxSource, 'DataTableQuery::orderField('));
$check('source-row', 'VC-C03b exact disabled-guard Domain: size layout remains controller-owned', is_string($domainSource)
    && str_contains($domainSource, "\$sizeColumn ? '' : null"));
$check('source-row', 'VC-C03b exact disabled-guard Log: request scope remains controller-owned', is_string($logSource)
    && str_contains($logSource, "\$domainScoped ? null : 'domain'"));
$check('source-row', 'VC-C03b exact disabled-guard Mailbox: domain layout remains controller-owned', is_string($mailboxSource)
    && str_contains($mailboxSource, "\$domainColumn ? 'domain' : null"));

$expectedCounts = [
    'positive' => 6,
    'boundary' => 7,
    'malformed' => 10,
    'error' => 2,
    'order-positive' => 3,
    'order-boundary' => 5,
    'order-malformed' => 2,
    'conditional-layout' => 8,
    'retained' => 5,
    'source-row' => 18,
];
foreach ($expectedCounts as $group => $expected) {
    if (($counts[$group] ?? 0) !== $expected) {
        $failures++;
        echo "  FAIL VC-C03 guard: {$group} expected {$expected}, observed " . ($counts[$group] ?? 0) . "\n";
    }
}
if (array_keys($counts) !== array_keys($expectedCounts)) {
    $failures++;
    echo "  FAIL VC-C03 guard: execution group discovery changed\n";
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
