<?php

declare(strict_types=1);

// The browser fixture mirrors these source paths under its temporary root.
require __DIR__ . '/../../src/Kernel/DataTable/DataTableQuery.php';
require __DIR__ . '/../../src/Kernel/DataTable/DataTableResult.php';

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;

$scope = $_GET['scope'] ?? '';
if (!is_string($scope) || !in_array($scope, ['domain', 'mailbox', 'alias', 'archive', 'log'], true)) {
    http_response_code(400);
    exit('Invalid fixture scope');
}

try {
    $parameters = [];
    foreach ($_GET as $key => $value) {
        if (is_string($key)) {
            $parameters[$key] = $value;
        }
    }
    $query = DataTableQuery::fromArray($parameters, 3);
} catch (\TypeError | \LengthException) {
    http_response_code(400);
    exit('Invalid fixture request');
}
$rows = array_map(static fn(string $name): array => ['name' => $scope . '-' . $name], ['Alpha', 'Beta', 'Delta', 'Gamma']);
$total = count($rows);
if ($query->searchTerm !== '') {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => $query->contains
        ? str_contains($row['name'], $query->searchTerm)
        : str_starts_with($row['name'], $query->searchTerm)));
}
if ($query->sortDir === 'DESC') {
    $rows = array_reverse($rows);
}
header('Content-Type: application/json');
echo DataTableResult::json($query, $total, count($rows), array_slice($rows, $query->start, $query->length));
