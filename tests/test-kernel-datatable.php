<?php
/**
 * Unit test: ViMbAdmin\Kernel\DataTable\{DataTableQuery,DataTableResult}.
 *
 * Pure parsing + envelope logic for the DataTables server-side protocol — no
 * framework, no DB. Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/DataTable/DataTableQuery.php';
require __DIR__ . '/../src/Kernel/DataTable/DataTableResult.php';

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;

final class DataTableTestState
{
    public static int $failures = 0;
}

function dataTableCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { DataTableTestState::$failures++; }
}

/**
 * @return array{
 *   draw:int,
 *   recordsTotal:int,
 *   recordsFiltered:int,
 *   data:list<array<string,mixed>>
 * }
 */
function dataTableEnvelope(mixed $value): array
{
    if (!is_array($value)
        || !is_int($value['draw'] ?? null)
        || !is_int($value['recordsTotal'] ?? null)
        || !is_int($value['recordsFiltered'] ?? null)) {
        throw new \RuntimeException('malformed DataTable envelope');
    }

    return [
        'draw' => $value['draw'],
        'recordsTotal' => $value['recordsTotal'],
        'recordsFiltered' => $value['recordsFiltered'],
        'data' => dataTableRows($value['data'] ?? null),
    ];
}

/** @return list<array<string,mixed>> */
function dataTableRows(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new \RuntimeException('malformed DataTable rows');
    }

    $rows = [];
    foreach ($value as $row) {
        if (!is_array($row)) {
            throw new \RuntimeException('malformed DataTable row');
        }

        $typedRow = [];
        foreach ($row as $key => $cell) {
            if (!is_string($key)) {
                throw new \RuntimeException('malformed DataTable row key');
            }
            $typedRow[$key] = $cell;
        }
        $rows[] = $typedRow;
    }

    return $rows;
}

echo "== DataTable server-side protocol ==\n";

// --- DataTableQuery::fromArray ---------------------------------------------
$q = DataTableQuery::fromArray([
    'draw' => '3', 'start' => '20', 'length' => '25',
    'search' => ['value' => '  foo '], 'order' => [['column' => '2', 'dir' => 'desc']],
]);
dataTableCheck('draw parsed',            $q->draw === 3);
dataTableCheck('start parsed',           $q->start === 20);
dataTableCheck('length parsed',          $q->length === 25);
dataTableCheck('search trimmed',         $q->search === 'foo');
dataTableCheck('search at configured minimum retained', DataTableQuery::fromArray(['search' => ['value' => 'foo']], 3)->search === 'foo');
dataTableCheck('zero minimum explicitly permits short searches', DataTableQuery::fromArray(['search' => ['value' => 'x']], 0)->search === 'x');
dataTableCheck('empty search remains valid with a minimum', DataTableQuery::fromArray(['search' => ['value' => '']], 3)->search === '');
dataTableCheck('whitespace search remains empty with a minimum', DataTableQuery::fromArray(['search' => ['value' => '  ']], 3)->search === '');
dataTableCheck('sort column parsed',     $q->sortColumn === 2);
dataTableCheck('sort dir normalised',    $q->sortDir === 'DESC');

// --- leading `*` contains toggle --------------------------------------------
$plain = DataTableQuery::fromArray(['search' => ['value' => 'abc']]);
dataTableCheck('plain search: contains false',  $plain->contains === false);
dataTableCheck('plain search: term unchanged',  $plain->searchTerm === 'abc');

$star = DataTableQuery::fromArray(['search' => ['value' => '*abc']]);
dataTableCheck('starred search: contains true',  $star->contains === true);
dataTableCheck('starred search: sigil stripped', $star->searchTerm === 'abc');

$starOnly = DataTableQuery::fromArray(['search' => ['value' => '*']]);
dataTableCheck('lone star: contains false (empty search)', $starOnly->contains === false);
dataTableCheck('lone star: term empty',                    $starOnly->searchTerm === '');

// Minimum-length gate applies to the stripped term, not to the raw search
// (which still carries the `*` sigil): `*ab` has a 2-char term and must be
// rejected against a 3-char minimum even though the raw string is 3 chars.
$starMinimumRejected = false;
try {
    DataTableQuery::fromArray(['search' => ['value' => '*ab']], 3);
} catch (\LengthException $e) {
    $starMinimumRejected = $e->getMessage() === 'Search must be empty or at least 3 characters';
}
dataTableCheck('starred search: minimum applies to stripped term', $starMinimumRejected);
dataTableCheck('starred search at minimum retained',
    DataTableQuery::fromArray(['search' => ['value' => '*abc']], 3)->searchTerm === 'abc');

// A lone `*` is an empty search and must sail through any minimum, exactly
// like an empty search value would.
dataTableCheck('lone star bypasses minimum like an empty search',
    DataTableQuery::fromArray(['search' => ['value' => '*']], 3)->searchTerm === '');

// --- DataTableQuery::likePattern ---------------------------------------------
dataTableCheck('likePattern anchored (default)',  DataTableQuery::likePattern('term', false) === 'term%');
dataTableCheck('likePattern contains (starred)',   DataTableQuery::likePattern('term', true) === '%term%');
dataTableCheck('likePattern escapes %/_/\\ (anchored)',
    DataTableQuery::likePattern('a%b_c\\d', false) === 'a\\%b\\_c\\\\d%');
dataTableCheck('likePattern escapes %/_/\\ (contains)',
    DataTableQuery::likePattern('a%b_c\\d', true) === '%a\\%b\\_c\\\\d%');

$d = DataTableQuery::fromArray([]);
dataTableCheck('defaults: draw 1',       $d->draw === 1);
dataTableCheck('defaults: start 0',      $d->start === 0);
dataTableCheck('defaults: length 10',    $d->length === 10);
dataTableCheck('defaults: dir ASC',      $d->sortDir === 'ASC');

dataTableCheck('negative start clamped', DataTableQuery::fromArray(['start' => '-5'])->start === 0);
dataTableCheck('length -1 (All) capped', DataTableQuery::fromArray(['length' => '-1'])->length === DataTableQuery::MAX_LENGTH);
dataTableCheck('over-cap length capped', DataTableQuery::fromArray(['length' => '99999'])->length === DataTableQuery::MAX_LENGTH);
dataTableCheck('zero length -> 10',      DataTableQuery::fromArray(['length' => '0'])->length === 10);
dataTableCheck('bad sort dir -> ASC',    DataTableQuery::fromArray(['order' => [['dir' => 'nonsense']]])->sortDir === 'ASC');
dataTableCheck('integer request values remain supported',
    DataTableQuery::fromArray(['draw' => 9, 'start' => 15, 'length' => 5])->draw === 9);
dataTableCheck('only the first ordering is applied',
    DataTableQuery::fromArray(['order' => [['column' => '2', 'dir' => 'desc'], ['column' => '4', 'dir' => 'asc']]])->sortColumn === 2);
dataTableCheck('unused column metadata and regex flags do not change literal search',
    DataTableQuery::fromArray(['search' => ['value' => 'a.*', 'regex' => 'true'], 'columns' => [['data' => 'id']]])->searchTerm === 'a.*');

// Wrong container shapes must be rejected before offsets are read, and leaf
// values retain the scalar checks of the original protocol.
$malformedRequests = [
    [['draw' => ['1']], 'draw must be an integer'],
    [['start' => '1.5'], 'start must be an integer'],
    [['length' => str_repeat('9', 40)], 'length must be an integer'],
    [['search' => 'abc'], 'search must be an array'],
    [['search' => ['value' => ['abc']]], 'search[value] must be a string'],
    [['search' => ['value' => 123]], 'search[value] must be a string'],
    [['order' => 'asc'], 'order must be an array'],
    [['order' => ['column']], 'order[0] must be an array'],
    [['order' => [['column' => ['2']]]], 'order[0][column] must be an integer'],
    [['order' => [['dir' => ['desc']]]], 'order[0][dir] must be a string'],
];
foreach ($malformedRequests as [$request, $message]) {
    $rejected = false;
    try {
        DataTableQuery::fromArray($request);
    } catch (\TypeError $e) {
        $rejected = $e->getMessage() === $message;
    }
    dataTableCheck('malformed request: ' . $message, $rejected);
}
$malformedDrawRejected = false;
$malformedDraw = getenv('VIMBADMIN_TEST_MALFORMED_DRAW');
if ($malformedDraw === false) {
    $malformedDraw = '7; DROP';
}
try {
    DataTableQuery::fromArray(['draw' => $malformedDraw]);
} catch (\TypeError) {
    $malformedDrawRejected = true;
}
dataTableCheck('malformed draw fails closed', $malformedDrawRejected);

$shortSearchRejected = static function (string $search): bool {
    try {
        DataTableQuery::fromArray(['search' => ['value' => $search]], 3);
    } catch (\LengthException $e) {
        return $e->getMessage() === 'Search must be empty or at least 3 characters';
    }
    return false;
};
dataTableCheck('short search below configured minimum rejected', $shortSearchRejected('xy'));
dataTableCheck('trimmed short search rejected', $shortSearchRejected('  xy  '));
dataTableCheck('multibyte minimum counts characters', $shortSearchRejected('éé'));
$negativeMinimumRejected = false;
try {
    DataTableQuery::fromArray(['search' => ['value' => 'valid']], -1);
} catch (\LogicException $e) {
    $negativeMinimumRejected = $e->getMessage() === 'Minimum search length must be non-negative';
}
dataTableCheck('negative minimum is rejected', $negativeMinimumRejected);

// --- DataTableResult::envelope ---------------------------------------------
$rows = [['id' => 1, 'username' => 'a@b.c'], ['id' => 2, 'username' => 'd@e.f']];
$env  = dataTableEnvelope(DataTableResult::envelope($q, 100, 42, $rows));
dataTableCheck('envelope has exactly the modern protocol keys',
    array_keys(DataTableResult::envelope($q, 100, 42, $rows)) === ['draw', 'recordsTotal', 'recordsFiltered', 'data']);
dataTableCheck('envelope echoes draw',           $env['draw'] === 3);
dataTableCheck('envelope total',                  $env['recordsTotal'] === 100);
dataTableCheck('envelope filtered',               $env['recordsFiltered'] === 42);
dataTableCheck('envelope carries page rows',      $env['data'] === $rows);

$json = DataTableResult::json($q, 100, 42, $rows);
$back = dataTableEnvelope(json_decode($json, true));
dataTableCheck('json round-trips',       $back['recordsFiltered'] === 42 && $back['data'][1]['username'] === 'd@e.f');

$storedDestination = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
$aliasRows = [[
    'id' => 41,
    'address' => 'alias@example.com',
    'goto' => $storedDestination,
    'active' => true,
    'domain' => 'example.com',
]];
$aliasBack = dataTableEnvelope(json_decode(DataTableResult::json($q, 1, 1, $aliasRows), true));
dataTableCheck(
    'alias list JSON returns the exact markup-like destination',
    ($aliasBack['data'][0]['goto'] ?? null) === $storedDestination,
);

echo DataTableTestState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . DataTableTestState::$failures . " FAILED\n";
exit(DataTableTestState::$failures === 0 ? 0 : 1);
