<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * One value of a facet and how many products have it.
 */
final class FacetBucket
{
    /**
     * @internal
     */
    public function __construct(
        private readonly string $value,
        private readonly int $count,
        private readonly ?string $subtitle = null,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** A second line for the value (a parent category …), null when none. */
    public function subtitle(): ?string
    {
        return $this->subtitle;
    }
}
