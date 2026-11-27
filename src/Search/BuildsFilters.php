<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * Filters and extra parameters, shared by the query builders.
 *
 * @internal
 */
trait BuildsFilters
{
    /** @var array<string, list<string>> */
    private array $filters = [];

    /** @var array<string, string> one `min-max` per field */
    private array $ranges = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /**
     * Keep products whose field has one of these values. Replaces an earlier
     * filter or range on the same field.
     *
     * @param array<string|int|float> $values
     */
    public function filter(string $field, array $values): static
    {
        self::assertField($field);
        $clone = clone $this;
        $clone->filters[$field] = array_values(array_map(self::format(...), $values));
        unset($clone->ranges[$field]);

        return $clone;
    }

    /**
     * Keep products whose numeric field lies between min and max (inclusive);
     * null leaves that side open. Bounds are 0 or more. The API takes one
     * range per field and no values beside it, so this replaces an earlier
     * range or filter on the same field.
     */
    public function range(string $field, int|float|null $min, int|float|null $max): static
    {
        self::assertField($field);
        if ($min === null && $max === null) {
            throw new \InvalidArgumentException("A range on '$field' needs a minimum, a maximum or both.");
        }
        if (($min !== null && $min < 0) || ($max !== null && $max < 0)) {
            // The API reads `min-max`, so a minus sign would be the separator.
            throw new \InvalidArgumentException("A range on '$field' cannot have negative bounds.");
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException("A range on '$field' cannot have its minimum above its maximum.");
        }
        $clone = clone $this;
        unset($clone->filters[$field]);
        $clone->ranges[$field] = ($min === null ? '' : self::format($min)) . '-' . ($max === null ? '' : self::format($max));

        return $clone;
    }

    /**
     * Any other query parameter the API accepts, sent as it is. For
     * parameters this SDK does not name yet; a named method wins when both
     * set the same one.
     */
    public function param(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$name] = $value;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterParams(): array
    {
        $fields = $this->filters;
        foreach ($this->ranges as $field => $range) {
            $fields[$field] = [$range];
        }

        return $fields + $this->extra;
    }

    private static function assertField(string $field): void
    {
        if ($field === '' || in_array($field, SearchQuery::RESERVED, true)) {
            throw new \InvalidArgumentException("'$field' is not a field that can be filtered on.");
        }
    }

    private static function format(string|int|float $value): string
    {
        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }

        return (string) $value;
    }
}
