<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * One search answer: the hits of the page, the facets, and what Instafind
 * did with the query (redirects, banners, corrections, ignored filters).
 */
final class SearchResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** Matching products in all pages. */
    public function total(): int
    {
        return Data::int(Data::map($this->hitsEnvelope(), 'total'), 'value');
    }

    /** Whether {@see self::total()} is exact rather than a lower bound. */
    public function totalIsExact(): bool
    {
        return Data::string(Data::map($this->hitsEnvelope(), 'total'), 'relation', 'eq') === 'eq';
    }

    /**
     * The products of this page, in order.
     *
     * @return list<Hit>
     */
    public function hits(): array
    {
        return array_map(static fn(array $hit): Hit => new Hit($hit), Data::maps($this->hitsEnvelope(), 'hits'));
    }

    /** Whether the page has no products. */
    public function isEmpty(): bool
    {
        return Data::maps($this->hitsEnvelope(), 'hits') === [];
    }

    /**
     * Per field, its values among the matching products.
     *
     * @return array<string, Facet>
     */
    public function facets(): array
    {
        $out = [];
        foreach ($this->aggregations() as $name => $aggregation) {
            if (is_array($aggregation) && str_ends_with($name, '_facet') && $name !== '_facet') {
                /** @var array<string, mixed> $aggregation */
                $field = substr($name, 0, -strlen('_facet'));
                $out[$field] = new Facet($field, $aggregation);
            }
        }

        return $out;
    }

    public function facet(string $field): ?Facet
    {
        return $this->facets()[$field] ?? null;
    }

    /**
     * Per numeric field, its smallest and largest value among the matching products.
     *
     * @return array<string, Range>
     */
    public function ranges(): array
    {
        $aggregations = $this->aggregations();
        $fields = [];
        foreach (array_keys($aggregations) as $name) {
            if (preg_match('/^(min|max)_(.+)$/', $name, $m) === 1) {
                $fields[$m[2]] = true;
            }
        }
        $out = [];
        foreach (array_keys($fields) as $field) {
            $field = (string) $field;
            $out[$field] = new Range(
                $field,
                Data::floatOrNull(Data::map($aggregations, 'min_' . $field), 'value'),
                Data::floatOrNull(Data::map($aggregations, 'max_' . $field), 'value'),
            );
        }

        return $out;
    }

    /**
     * @return list<Banner>
     */
    public function banners(): array
    {
        return array_map(static fn(array $b): Banner => new Banner($b), Data::maps($this->raw, 'banners'));
    }

    /** A redirect rule the query matched, null when none. */
    public function redirect(): ?Redirect
    {
        $redirect = Data::map($this->raw, 'redirect');

        return $redirect === [] ? null : new Redirect($redirect);
    }

    /** The query Instafind searched for when it changed it, null when it did not. */
    public function queryUsed(): ?QueryUsed
    {
        $used = Data::map($this->raw, 'query_used');

        return $used === [] ? null : new QueryUsed($used);
    }

    /**
     * Categories matching the query, when asked for.
     *
     * @return list<CategoryMatch>
     */
    public function categoryMatches(): array
    {
        return array_map(static fn(array $c): CategoryMatch => new CategoryMatch($c), Data::maps($this->raw, 'category_matches'));
    }

    /**
     * Values found in the query and used as a boost or a filter:
     * `{field, value, facet_values, words, action}`.
     *
     * @return list<array<string, mixed>>
     */
    public function detected(): array
    {
        return Data::maps($this->raw, 'detected');
    }

    /**
     * Products a merchandising rule placed: `{id, position, rule_id, label, sponsored, sponsor}`.
     *
     * @return list<array<string, mixed>>
     */
    public function promoted(): array
    {
        return Data::maps(Data::map($this->raw, 'merchandising'), 'promoted');
    }

    /**
     * Related products, when the index shows them.
     *
     * @return list<Hit>
     */
    public function related(): array
    {
        return array_map(static fn(array $hit): Hit => new Hit($hit), Data::maps($this->raw, 'related'));
    }

    /**
     * The fields whose filters were applied.
     *
     * @return list<string>
     */
    public function appliedFilters(): array
    {
        return Data::strings(Data::map($this->raw, 'applied'), 'filters');
    }

    /**
     * Parameters the search left out, and why.
     *
     * @return list<IgnoredFilter>
     */
    public function ignoredFilters(): array
    {
        return array_map(static fn(array $i): IgnoredFilter => new IgnoredFilter($i), Data::maps(Data::map($this->raw, 'applied'), 'ignored'));
    }

    /**
     * Why no search ran (`index_disabled`, `empty_query`, `suggestions_off` …),
     * null when one did.
     */
    public function reason(): ?string
    {
        return Data::stringOrNull($this->raw, 'reason');
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
    private function hitsEnvelope(): array
    {
        return Data::map(Data::map($this->raw, 'results'), 'hits');
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregations(): array
    {
        return Data::map(Data::map($this->raw, 'results'), 'aggregations');
    }
}
