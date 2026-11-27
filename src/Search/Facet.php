<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * The values of one field among the matching products.
 */
final class Facet
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw the `<field>_facet` aggregation
     */
    public function __construct(private readonly string $field, private readonly array $raw) {}

    public function field(): string
    {
        return $this->field;
    }

    /**
     * @return list<FacetBucket>
     */
    public function buckets(): array
    {
        $out = [];
        foreach (Data::maps($this->raw, 'buckets') as $bucket) {
            $out[] = new FacetBucket(Data::string($bucket, 'key'), Data::int($bucket, 'doc_count'), Data::stringOrNull($bucket, 'subtitle'));
        }

        return $out;
    }

    /** Products with a value outside the returned buckets. */
    public function otherCount(): int
    {
        return Data::int($this->raw, 'sum_other_doc_count');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
