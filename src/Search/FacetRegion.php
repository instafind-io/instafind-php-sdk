<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * The filters of one region of the page (`sidebar`, `modal`, `results`).
 */
final class FacetRegion
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly string $name, private readonly array $raw) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return Data::bool($this->raw, 'enabled');
    }

    /**
     * The filters' fields, in order.
     *
     * @return list<string>
     */
    public function facetOrder(): array
    {
        return Data::strings($this->raw, 'facet_order');
    }

    /**
     * Per field: `{label, display, config}`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function facets(): array
    {
        $out = [];
        foreach (Data::map($this->raw, 'facets') as $field => $facet) {
            if (is_array($facet)) {
                /** @var array<string, mixed> $facet */
                $out[$field] = $facet;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
