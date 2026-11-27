<?php

declare(strict_types=1);

namespace Instafind\Sdk\Support;

/**
 * Typed reads from decoded JSON, forgiving about what the API leaves out.
 *
 * @internal
 */
final class Data
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string|int $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function stringOrNull(array $data, string|int $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string|int $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function intOrNull(array $data, string|int $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function floatOrNull(array $data, string|int $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string|int $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : (is_int($value) ? $value !== 0 : $default);
    }

    /**
     * An object (JSON map) value, [] when absent or not one.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function map(array $data, string|int $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The JSON maps in a list value, [] when absent.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function maps(array $data, string|int $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * The strings in a list value.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string|int $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn(bool|float|int|string $v): string => (string) $v, array_filter($value, 'is_scalar')));
    }

    /**
     * An ISO 8601 date-time, null when absent or unreadable.
     *
     * @param array<array-key, mixed> $data
     */
    public static function date(array $data, string|int $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function __construct() {}
}
