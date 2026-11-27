<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * How each field of an index takes part in search: searchable, a filter, a
 * range, returned in the hits, its boosts.
 */
final class SearchBehaviors
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw `{data, meta}`
     */
    public function __construct(private readonly array $raw) {}

    /**
     * Per field id, its behaviour as the API names it (`searchable`, `fuzzy`,
     * `facet`, `range`, `include_in_results`, `exact_match_boost`,
     * `partial_match_boost`, `sort_label`, `value_detection`, `sortable`,
     * `latin_spelling`). The shape {@see \Instafind\Sdk\IntegrationApi::putSearchBehaviors()} takes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (Data::map($this->raw, 'data') as $field => $behavior) {
            if (is_array($behavior)) {
                /** @var array<string, mixed> $behavior */
                $out[$field] = $behavior;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forField(string $fieldId): ?array
    {
        return $this->all()[$fieldId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function searchableFields(): array
    {
        return Data::strings($this->meta(), 'searchable_fields');
    }

    /**
     * @return list<string>
     */
    public function facetableFields(): array
    {
        return Data::strings($this->meta(), 'facetable_fields');
    }

    /**
     * @return list<string>
     */
    public function rangeableFields(): array
    {
        return Data::strings($this->meta(), 'rangeable_fields');
    }

    /**
     * @return list<string>
     */
    public function sortableFields(): array
    {
        return Data::strings($this->meta(), 'sortable_fields');
    }

    /**
     * Fields whose change waits for a rebuild of the index.
     *
     * @return list<string>
     */
    public function rebuildPendingFields(): array
    {
        return Data::strings($this->meta(), 'rebuild_pending_fields');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return Data::map($this->raw, 'meta');
    }
}
