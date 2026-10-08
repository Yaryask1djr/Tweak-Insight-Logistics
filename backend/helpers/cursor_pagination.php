<?php

require_once __DIR__ . '/response.php';

/** Empty cursor starts cursor mode; absent cursor preserves offset mode. */
final class CursorPagination
{
    public static function fromQuery(array $query): ?int
    {
        if (!array_key_exists('cursor', $query)) return null;
        if ($query['cursor'] === '') return 0;
        $cursor = filter_var($query['cursor'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($cursor === false) Response::error('cursor must be empty or a non-negative integer.', 422);
        return $cursor;
    }

    /** The query must fetch limit + 1 rows to distinguish full and final pages. */
    public static function page(array $rows, int $limit, string $idColumn = 'id'): array
    {
        $hasMore = count($rows) > $limit;
        $items = array_slice($rows, 0, $limit);
        return ['items' => $items, 'next_cursor' => $hasMore ? (int)end($items)[$idColumn] : null, 'has_more' => $hasMore];
    }
}
