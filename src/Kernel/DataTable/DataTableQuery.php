<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\DataTable;

/**
 * Parsed DataTables (legacy "server-side processing" protocol, as shipped by
 * the bundled DataTables 1.11.5 via `fnServerData`) request parameters.
 *
 * The list pages render server-side paged: the table is configured with
 * `bServerProcessing` + an AJAX source, and DataTables sends the draw counter
 * (`sEcho`), the window (`iDisplayStart` / `iDisplayLength`), the global filter
 * (`sSearch`) and the active sort column/direction (`iSortCol_0` / `sSortDir_0`)
 * on every interaction. A controller turns these into a scoped, paged Doctrine
 * query and answers with {@see DataTableResult::envelope()}.
 *
 * Pure value object (no superglobals, no framework) so it is unit-testable; the
 * controller passes `$_GET`. `length` is clamped to a sane maximum so a crafted
 * `iDisplayLength` cannot ask for an unbounded result set.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DataTableQuery
{
    public const MAX_LENGTH = 500;

    private function __construct(
        public readonly int $echo,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly int $sortColumn,
        public readonly string $sortDir,
    ) {
    }

    /**
     * Build from a DataTables request array (typically `$_GET`).
     *
     * @param array<string,mixed> $p
     */
    public static function fromArray(array $p): self
    {
        $echo   = (int) ($p['sEcho'] ?? 1);
        $start  = max(0, (int) ($p['iDisplayStart'] ?? 0));

        $length = (int) ($p['iDisplayLength'] ?? 10);
        // -1 ("All") and anything over the cap collapse to the cap; <=0 to 10.
        if ($length <= 0 || $length > self::MAX_LENGTH) {
            $length = $length === -1 ? self::MAX_LENGTH : ($length <= 0 ? 10 : self::MAX_LENGTH);
        }

        $search = trim((string) ($p['sSearch'] ?? ''));
        $sortCol = max(0, (int) ($p['iSortCol_0'] ?? 0));
        $sortDir = strtoupper((string) ($p['sSortDir_0'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        return new self($echo, $start, $length, $search, $sortCol, $sortDir);
    }
}
