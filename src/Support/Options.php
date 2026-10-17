<?php

namespace AppLocalPlugins\GuideLink\Support;

class Options
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    public static function bool(array $payload, array $settings, string $key, bool $default): bool
    {
        $value = $payload[$key] ?? $settings[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    public static function number(array $payload, array $settings, string $key, int $default): int
    {
        $value = $payload[$key] ?? $settings[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    public static function float(array $payload, array $settings, string $key, float $default): float
    {
        $value = $payload[$key] ?? $settings[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    public static function string(array $payload, array $settings, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $settings[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    public static function list(array $payload, array $settings, string $key, array $default = [], string $separator = '/[\r\n,]+/'): array
    {
        $value = $payload[$key] ?? $settings[$key] ?? $default;

        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = preg_split($separator, $value) ?: [];
        } else {
            $items = $default;
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items,
        ), fn (string $item): bool => $item !== ''));
    }

    /** Regex lists use newlines; commas can be part of a quantifier or literal. */
    public static function patterns(array $payload, array $settings, string $key, array $default = []): array
    {
        return self::list($payload, $settings, $key, $default, '/[\r\n]+/');
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
