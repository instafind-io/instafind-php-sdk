<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * One search, built step by step. Immutable: every method returns a copy.
 *
 *     $query = SearchQuery::text('running shoes')
 *         ->limit(24)->page(2)
 *         ->filter('brand', ['Asics', 'Nike'])
 *         ->range('price', 50, 120)
 *         ->sort('price', SortOrder::Asc);
 */
final class SearchQuery
{
    use BuildsFilters;

    /** Parameter names the API reserves: never field filters. */
    public const RESERVED = [
        'id', 'q', 'context', 'limit', 'page', 'offset', 'sort', 'order', 'facet_size', 'facets', 'facet_sort',
        'strict', 'browse', 'merchandising', 'highlight', 'ignore_detected', 'category_matches', 'sid', 'name',
        'product', 'fill', 'preview', 'picks_ignore',
    ];

    /** @var array<string, mixed> */
    private array $params = [];

    private function __construct(private readonly string $text) {}

    /** A search for this text; '' for all products (with filters, a category page). */
    public static function text(string $text = ''): self
    {
        return new self($text);
    }

    public function query(): string
    {
        return $this->text;
    }

    /** Hits per page, 0–100; 0 returns only the facets. */
    public function limit(int $limit): self
    {
        if ($limit < 0 || $limit > 100) {
            throw new \InvalidArgumentException('The limit must be between 0 and 100.');
        }

        return $this->with('limit', $limit);
    }

    /** The page, from 1. Pages end at the 10,000th product. */
    public function page(int $page): self
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Pages start at 1.');
        }

        return $this->with('page', $page);
    }

    /** Sort by a field (or `<field>.keyword`) instead of relevance. */
    public function sort(string $field, SortOrder $order = SortOrder::Asc): self
    {
        return $this->with('sort', $field)->with('order', $order->value);
    }

    public function context(SearchContext $context): self
    {
        return $this->with('context', $context->value);
    }

    /**
     * Facets to compute, by field; by default the index's own.
     *
     * @param list<string> $fields
     */
    public function facets(array $fields): self
    {
        return $this->with('facets', implode(',', $fields));
    }

    /**
     * Buckets per facet, 1–1000; for one field only when `$field` is given.
     */
    public function facetSize(int $size, ?string $field = null): self
    {
        if ($size < 1 || $size > 1000) {
            throw new \InvalidArgumentException('The facet size must be between 1 and 1000.');
        }
        if ($field === null) {
            return $this->with('facet_size', $size);
        }
        $sizes = is_array($this->params['facet_size'] ?? null) ? $this->params['facet_size'] : [];
        $sizes[$field] = $size;

        return $this->with('facet_size', $sizes);
    }

    public function facetSort(FacetSort $sort): self
    {
        return $this->with('facet_sort', $sort->value);
    }

    /** Mark the matched words in the hits' `highlight`. */
    public function highlight(bool $highlight = true): self
    {
        return $this->with('highlight', $highlight ? 1 : null);
    }

    /** Match only what was typed: no typo tolerance, no dropped words. */
    public function strict(bool $strict = true): self
    {
        return $this->with('strict', $strict ? 1 : null);
    }

    /** Browse the catalogue (a category page): no text, no zero-result logging. */
    public function browse(bool $browse = true): self
    {
        return $this->with('browse', $browse ? 1 : null);
    }

    /** Also return up to `$count` matching categories (1–10). */
    public function categoryMatches(int $count): self
    {
        if ($count < 1 || $count > 10) {
            throw new \InvalidArgumentException('Category matches must be between 1 and 10.');
        }

        return $this->with('category_matches', $count);
    }

    /** The shopper's session id, which ties searches to orders in analytics. */
    public function sessionId(string $sessionId): self
    {
        return $this->with('sid', $sessionId === '' ? null : $sessionId);
    }

    /**
     * Turn off value detection for these `field:value` pairs, e.g. after the
     * shopper removed a detected filter.
     *
     * @param list<string> $pairs
     */
    public function ignoreDetected(array $pairs): self
    {
        return $this->with('ignore_detected', $pairs === [] ? null : $pairs);
    }

    /** The limit set, null for the API's default. */
    public function getLimit(): ?int
    {
        $limit = $this->params['limit'] ?? null;

        return is_int($limit) ? $limit : null;
    }

    /** The page set, null for the first. */
    public function getPage(): ?int
    {
        $page = $this->params['page'] ?? null;

        return is_int($page) ? $page : null;
    }

    /**
     * The query parameters, without the index id.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $named = ($this->text === '' ? [] : ['q' => $this->text]) + $this->params;

        return array_filter($named + $this->filterParams(), static fn(mixed $v): bool => $v !== null);
    }

    private function with(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->params[$name] = $value;

        return $clone;
    }
}
