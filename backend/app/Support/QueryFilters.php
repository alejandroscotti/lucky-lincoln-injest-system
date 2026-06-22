<?php

namespace App\Support;

final class QueryFilters
{
    public static function likePattern(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }

    /** @param array<string, mixed> $query */
    public static function parseFilterQuery(array $query, string $prefix = 'filter_'): array
    {
        $filters = [];
        foreach ($query as $key => $raw) {
            if (! str_starts_with((string) $key, $prefix)) {
                continue;
            }
            if (! is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ($value === '') {
                continue;
            }
            $filters[substr((string) $key, strlen($prefix))] = $value;
        }

        return $filters;
    }

    public static function appendLike(string $where, array $params, string $expression, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['where' => $where, 'params' => $params];
        }

        return [
            'where' => "{$where} AND LOWER({$expression}) LIKE LOWER(?)",
            'params' => [...$params, self::likePattern($value)],
        ];
    }

    public static function appendCastLike(string $where, array $params, string $expression, ?string $value): array
    {
        if ($value === null || $value === '') {
            return ['where' => $where, 'params' => $params];
        }

        return [
            'where' => "{$where} AND CAST({$expression} AS CHAR) LIKE ?",
            'params' => [...$params, self::likePattern($value)],
        ];
    }
}
