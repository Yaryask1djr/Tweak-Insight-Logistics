<?php
declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';

final class ListingQuery
{
    public static function page(array $query): array
    {
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $limit = filter_var($query['limit'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) throw new TransactionBusinessException('Page must be 1–10,000 and page size 1–100. Use a date filter or cursor for deeper history.', 422);
        return [$page, $limit, ($page - 1) * $limit];
    }
    public static function search(array $query): string
    {
        $value = $query['search'] ?? '';
        if (!is_string($value) || mb_strlen($value) > 100) throw new TransactionBusinessException('Search must be at most 100 characters.', 422);
        return trim($value);
    }
    public static function order(array $query, array $columns, string $default): string
    {
        $sort = $query['sort'] ?? $default;
        if (!is_string($sort) || !isset($columns[$sort])) throw new TransactionBusinessException('Unsupported sort order.', 422);
        return $columns[$sort];
    }
    public static function pagination(int $total, int $page, int $limit): array
    {
        $pages = max(1, (int)ceil($total / $limit));
        return ['current_page' => $page, 'per_page' => $limit, 'total_records' => $total, 'total_pages' => $pages, 'has_next_page' => $page < $pages, 'has_prev_page' => $page > 1];
    }
}
