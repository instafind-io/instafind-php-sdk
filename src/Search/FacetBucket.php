<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * One value of a facet and how many products have it.
 */
final class FacetBucket
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw one bucket of the `<field>_facet` aggregation
     */
    public function __construct(private readonly array $raw) {}

    public function value(): string
    {
        return Data::string($this->raw, 'key');
    }

    public function count(): int
    {
        return Data::int($this->raw, 'doc_count');
    }

    /** The name to show for the value (a category's name for its id …), null when it has none. */
    public function label(): ?string
    {
        return Data::stringOrNull($this->raw, 'label');
    }

    /** A second line for the value (a parent category …), null when none. */
    public function subtitle(): ?string
    {
        return Data::stringOrNull($this->raw, 'subtitle');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
