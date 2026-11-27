<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * The index's Filters tab as the widget reads it: the filters per region,
 * and how filter values are shown (colours, images, labels).
 */
final class FacetConfig
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw the `data` of the answer
     */
    public function __construct(private readonly array $raw) {}

    /**
     * @return array<string, FacetRegion>
     */
    public function regions(): array
    {
        $out = [];
        foreach (Data::map($this->raw, 'regions') as $name => $region) {
            if (is_array($region)) {
                /** @var array<string, mixed> $region */
                $out[$name] = new FacetRegion($name, $region);
            }
        }

        return $out;
    }

    public function region(string $name): ?FacetRegion
    {
        return $this->regions()[$name] ?? null;
    }

    /**
     * Per field, how values are shown: `{value, type, display, aliases}`.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function rewrites(): array
    {
        $out = [];
        $rewrites = Data::map($this->raw, 'rewrites');
        foreach (array_keys($rewrites) as $field) {
            $out[$field] = Data::maps($rewrites, $field);
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
