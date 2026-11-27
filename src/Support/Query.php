<?php

declare(strict_types=1);

namespace Instafind\Sdk\Support;

/**
 * Query strings the way the API reads them.
 *
 * @internal
 */
final class Query
{
    /**
     * A query string with `key[]=value` for list values, the shape Laravel
     * reads as an array, and `key[sub]=value` for maps. Null values are left
     * out; booleans become 1 and 0.
     *
     * @param array<array-key, mixed> $params
     */
    public static function build(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            self::append($parts, rawurlencode((string) $key), $value);
        }

        return implode('&', $parts);
    }

    /**
     * @param list<string> $parts
     */
    private static function append(array &$parts, string $name, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $subKey => $item) {
                self::append($parts, $name . ($isList ? '[]' : '[' . rawurlencode((string) $subKey) . ']'), $item);
            }

            return;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('Query parameter ' . rawurldecode($name) . ' must be a scalar or an array of scalars.');
        }
        $parts[] = $name . '=' . rawurlencode((string) $value);
    }

    private function __construct() {}
}
