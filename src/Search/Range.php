<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * The smallest and largest value of a numeric field among the matching products.
 */
final class Range
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw the `min_<field>` and `max_<field>` aggregations, by name
     */
    public function __construct(private readonly string $field, private readonly array $raw) {}

    public function field(): string
    {
        return $this->field;
    }

    public function min(): ?float
    {
        return Data::floatOrNull(Data::map($this->raw, 'min_' . $this->field), 'value');
    }

    public function max(): ?float
    {
        return Data::floatOrNull(Data::map($this->raw, 'max_' . $this->field), 'value');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
