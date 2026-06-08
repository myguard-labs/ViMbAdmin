<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\DataTable;

/**
 * Builds the JSON envelope the DataTables server-side protocol expects in
 * response to a {@see DataTableQuery}:
 *
 *   - `sEcho`                — the draw counter, echoed back verbatim (cast to
 *                              int here so a crafted value can never inject).
 *   - `iTotalRecords`        — rows in the scope before filtering.
 *   - `iTotalDisplayRecords` — rows after the global filter (== total when the
 *                              search is empty).
 *   - `aaData`               — the current page's rows (array of column-keyed
 *                              objects; the client column defs map them).
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
            'sEcho'                => $q->echo,
            'iTotalRecords'        => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData'               => $rows,
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
