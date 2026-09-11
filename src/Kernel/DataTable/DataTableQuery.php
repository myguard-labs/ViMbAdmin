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

    /**
     * @param string $search     The raw trimmed request value, sigil included. Kept for
     *                           callers that need to echo back what the user typed;
     *                           repositories must NOT bind this.
     * @param bool   $contains   The user opted into matching anywhere (leading `*`).
     * @param string $searchTerm The sigil-stripped term repositories bind, via
     *                           {@see likePattern()}. This is the search value.
     */
    private function __construct(
        public readonly int $echo,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly bool $contains,
        public readonly string $searchTerm,
        public readonly int $sortColumn,
        public readonly string $sortDir,
    ) {
    }

    /**
     * Build a `LIKE` pattern for a stripped search term, escaping `%`/`_`/`\`.
     *
     * `$contains` true anchors nowhere (`%term%`, the leading `*` toggle);
     * false anchors the match to the start of the field (`term%`), which is
     * index-friendly, unlike a leading wildcard.
     */
    public static function likePattern(string $searchTerm, bool $contains): string
    {
        $escaped = addcslashes($searchTerm, '%_\\');
        return $contains ? '%' . $escaped . '%' : $escaped . '%';
    }

    /**
     * Build from a DataTables request array (typically `$_GET`).
     *
     * @param array<string,mixed> $p
     * @param int $minimumSearchLength Nonempty searches shorter than this are
     *        rejected; zero explicitly disables the minimum.
     * @throws \LengthException when a nonempty search is below the minimum
     * @throws \LogicException when the configured minimum search length is negative
     * @throws \TypeError when a request parameter has the wrong scalar type
     */
    public static function fromArray(array $p, int $minimumSearchLength = 0): self
    {
        if ($minimumSearchLength < 0) {
            throw new \LogicException('Minimum search length must be non-negative');
        }
        $echo   = self::integer($p['sEcho'] ?? null, 1, 'sEcho');
        $start  = max(0, self::integer($p['iDisplayStart'] ?? null, 0, 'iDisplayStart'));

        $length = self::integer($p['iDisplayLength'] ?? null, 10, 'iDisplayLength');
        // -1 ("All") and anything over the cap collapse to the cap; <=0 to 10.
        if ($length <= 0 || $length > self::MAX_LENGTH) {
            $length = $length === -1 ? self::MAX_LENGTH : ($length <= 0 ? 10 : self::MAX_LENGTH);
        }

        $searchValue = $p['sSearch'] ?? null;
        if ($searchValue !== null && !is_string($searchValue)) {
            throw new \TypeError('sSearch must be a string');
        }
        $search = trim($searchValue ?? '');

        // A leading `*` toggles "contains anywhere" (matches the existing
        // convention in Alias::filteredAliasListQuery); the sigil itself is
        // not part of the term and must not count toward the minimum length.
        $contains   = str_starts_with($search, '*');
        $searchTerm = $contains ? ltrim(substr($search, 1)) : $search;
        // A search that is only `*` (searchTerm '') is treated as no search.
        if ($searchTerm === '') {
            $contains = false;
        }

        if ($searchTerm !== '' && mb_strlen($searchTerm, 'UTF-8') < $minimumSearchLength) {
            throw new \LengthException("Search must be empty or at least {$minimumSearchLength} characters");
        }
        $sortCol = max(0, self::integer($p['iSortCol_0'] ?? null, 0, 'iSortCol_0'));
        $sortDirection = $p['sSortDir_0'] ?? null;
        if ($sortDirection !== null && !is_string($sortDirection)) {
            throw new \TypeError('sSortDir_0 must be a string');
        }
        $sortDir = strtoupper($sortDirection ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';

        return new self($echo, $start, $length, $search, $contains, $searchTerm, $sortCol, $sortDir);
    }

    private static function integer(mixed $value, int $default, string $name): int
    {
        if ($value === null) return $default;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false) return $parsed;
        }
        throw new \TypeError($name . ' must be an integer');
    }
}
