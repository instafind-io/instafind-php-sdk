<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * The smallest and largest value of a numeric field among the matching products.
 */
final class Range
{
    /**
     * @internal
     */
    public function __construct(
        private readonly string $field,
        private readonly ?float $min,
        private readonly ?float $max,
    ) {}

    public function field(): string
    {
        return $this->field;
    }

    public function min(): ?float
    {
        return $this->min;
    }

    public function max(): ?float
    {
        return $this->max;
    }
}
