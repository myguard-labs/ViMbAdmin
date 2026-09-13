<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\DataTable;

/**
 * Parsed DataTables server-side processing request parameters.
 *
 * The list pages render server-side paged: the table is configured with
 * `serverSide` + an AJAX source, and DataTables sends the draw counter
 * (`draw`), the window (`start` / `length`), the global filter
 * (`search[value]`) and the active sort column/direction
 * (`order[0][column]` / `order[0][dir]`)
 * on every interaction. A controller turns these into a scoped, paged Doctrine
 * query and answers with {@see DataTableResult::envelope()}.
 *
 * Pure value object (no superglobals, no framework) so it is unit-testable; the
 * controller passes `$_GET`. `length` is clamped to a sane maximum so a crafted
 * `length` cannot ask for an unbounded result set. Only the first ordering is
 * applied; the client disables multi-column ordering. Per-column metadata and
 * regex flags are ignored: searches always use the literal LIKE pattern below.
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
        public readonly int $draw,
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
     * @throws \TypeError when a request parameter has the wrong type or shape
     */
    public static function fromArray(array $p, int $minimumSearchLength = 0): self
    {
        if ($minimumSearchLength < 0) {
            throw new \LogicException('Minimum search length must be non-negative');
        }
        $draw   = self::integer($p['draw'] ?? null, 1, 'draw');
        $start  = max(0, self::integer($p['start'] ?? null, 0, 'start'));

        $length = self::integer($p['length'] ?? null, 10, 'length');
        // -1 ("All") and anything over the cap collapse to the cap; <=0 to 10.
        if ($length <= 0 || $length > self::MAX_LENGTH) {
            $length = $length === -1 ? self::MAX_LENGTH : ($length <= 0 ? 10 : self::MAX_LENGTH);
        }

        [$search, $contains, $searchTerm] = self::parseSearch($p, $minimumSearchLength);
        [$sortCol, $sortDir] = self::parseFirstOrder($p);

        return new self($draw, $start, $length, $search, $contains, $searchTerm, $sortCol, $sortDir);
    }

    /**
     * @param array<string,mixed> $p
     * @return array{string,bool,string}
     */
    private static function parseSearch(array $p, int $minimumSearchLength): array
    {
        $searchParams = self::arrayParameter($p['search'] ?? [], 'search');
        $searchValue = $searchParams['value'] ?? null;
        if ($searchValue !== null && !is_string($searchValue)) {
            throw new \TypeError('search[value] must be a string');
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
        return [$search, $contains, $searchTerm];
    }

    /**
     * @param array<string,mixed> $p
     * @return array{int,string}
     */
    private static function parseFirstOrder(array $p): array
    {
        $orders = self::arrayParameter($p['order'] ?? [], 'order');
        $order = self::arrayParameter($orders[0] ?? [], 'order[0]');
        $sortCol = max(0, self::integer($order['column'] ?? null, 0, 'order[0][column]'));
        $sortDirection = $order['dir'] ?? null;
        if ($sortDirection !== null && !is_string($sortDirection)) {
            throw new \TypeError('order[0][dir] must be a string');
        }
        $sortDir = strtoupper($sortDirection ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';
        return [$sortCol, $sortDir];
    }

    /** @return array<array-key,mixed> */
    private static function arrayParameter(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \TypeError($name . ' must be an array');
        }
        return $value;
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
