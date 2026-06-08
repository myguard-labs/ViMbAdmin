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

$failures = 0;
function check(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== DataTable server-side protocol ==\n";

// --- DataTableQuery::fromArray ---------------------------------------------
$q = DataTableQuery::fromArray([
    'sEcho' => '3', 'iDisplayStart' => '20', 'iDisplayLength' => '25',
    'sSearch' => '  foo ', 'iSortCol_0' => '2', 'sSortDir_0' => 'desc',
]);
check('echo parsed',            $q->echo === 3);
check('start parsed',           $q->start === 20);
check('length parsed',          $q->length === 25);
check('search trimmed',         $q->search === 'foo');
check('sort column parsed',     $q->sortColumn === 2);
check('sort dir normalised',    $q->sortDir === 'DESC');

$d = DataTableQuery::fromArray([]);
check('defaults: echo 1',       $d->echo === 1);
check('defaults: start 0',      $d->start === 0);
check('defaults: length 10',    $d->length === 10);
check('defaults: dir ASC',      $d->sortDir === 'ASC');

check('negative start clamped', DataTableQuery::fromArray(['iDisplayStart' => '-5'])->start === 0);
check('length -1 (All) capped', DataTableQuery::fromArray(['iDisplayLength' => '-1'])->length === DataTableQuery::MAX_LENGTH);
check('over-cap length capped', DataTableQuery::fromArray(['iDisplayLength' => '99999'])->length === DataTableQuery::MAX_LENGTH);
check('zero length -> 10',      DataTableQuery::fromArray(['iDisplayLength' => '0'])->length === 10);
check('bad sort dir -> ASC',    DataTableQuery::fromArray(['sSortDir_0' => 'nonsense'])->sortDir === 'ASC');
check('echo cast to int',       DataTableQuery::fromArray(['sEcho' => '7; DROP'])->echo === 7);

// --- DataTableResult::envelope ---------------------------------------------
$rows = [['id' => 1, 'username' => 'a@b.c'], ['id' => 2, 'username' => 'd@e.f']];
$env  = DataTableResult::envelope($q, 100, 42, $rows);
check('envelope echoes sEcho',           $env['sEcho'] === 3);
check('envelope total',                  $env['iTotalRecords'] === 100);
check('envelope filtered',               $env['iTotalDisplayRecords'] === 42);
check('envelope carries page rows',      $env['aaData'] === $rows);

$json = DataTableResult::json($q, 100, 42, $rows);
$back = json_decode($json, true);
check('json round-trips',                $back['iTotalDisplayRecords'] === 42 && $back['aaData'][1]['username'] === 'd@e.f');

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
