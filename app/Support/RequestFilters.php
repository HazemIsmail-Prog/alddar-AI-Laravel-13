<?php

namespace App\Support;

use Illuminate\Http\Request;

class RequestFilters
{
    /**
     * @return list<string>
     */
    public static function listParam(Request $request, string $key): array
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }

        return array_values(array_filter(array_map('strval', (array) $value), fn ($item) => $item !== ''));
    }

    /**
     * @return list<int>
     */
    public static function intList(Request $request, string $key): array
    {
        return array_values(array_filter(array_map('intval', self::listParam($request, $key))));
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function dateOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    public static function applyDateRange($query, string $column, mixed $from, mixed $to): void
    {
        $from = self::dateOrNull($from);
        $to = self::dateOrNull($to);
        if ($from) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }
}
