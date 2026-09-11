<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\DataTable;

/**
 * Builds the JSON envelope the DataTables 2 server-side protocol expects in
 * response to a {@see DataTableQuery}:
 *
 *   - `draw`            — the draw counter, validated as an integer by the
 *                          query parser so a crafted value can never inject.
 *   - `recordsTotal`    — rows in the scope before filtering.
 *   - `recordsFiltered` — rows after the global filter (== total when the
 *                          search is empty).
 *   - `data`            — the current page's rows (array of column-keyed
 *                          objects; the client column defs map them).
 *
 * Pure (returns the array / its JSON), so it is unit-testable and the caller
 * decides the HTTP wrapper.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DataTableResult
{
    /**
     * @param list<array<string,mixed>> $rows the current page's rows
     * @return array<string,mixed>
     */
    public static function envelope(DataTableQuery $q, int $total, int $filtered, array $rows): array
    {
        return [
            'draw'            => $q->draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $rows,
        ];
    }

    /**
     * The envelope as a JSON string, ready for the response body.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function json(DataTableQuery $q, int $total, int $filtered, array $rows): string
    {
        return (string) json_encode(self::envelope($q, $total, $filtered, $rows));
    }
}
