<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * Product suggestions ("you may also like") for one product, or for several
 * (a cart). Immutable: every method returns a copy.
 *
 *     $query = ProductSuggestionsQuery::forProducts(['123'])->limit(6)->fill(SuggestionFill::None);
 */
final class ProductSuggestionsQuery
{
    use BuildsFilters;

    public const MAX_PRODUCTS = 20;

    public const MAX_LIMIT = 12;

    private ?int $limit = null;

    private ?SuggestionFill $fill = null;

    /**
     * @param list<string> $productIds
     */
    private function __construct(private readonly array $productIds) {}

    /**
     * @param array<string|int> $productIds 1 to 20 product ids
     */
    public static function forProducts(array $productIds): self
    {
        $ids = array_values(array_map(static fn(string|int $id): string => (string) $id, $productIds));
        if ($ids === [] || count($ids) > self::MAX_PRODUCTS) {
            throw new \InvalidArgumentException('Ask for suggestions for 1 to ' . self::MAX_PRODUCTS . ' products.');
        }
        foreach ($ids as $id) {
            if ($id === '' || str_contains($id, ',') || strlen($id) > 255) {
                throw new \InvalidArgumentException("'$id' is not a product id suggestions can be asked for.");
            }
        }

        return new self($ids);
    }

    /**
     * @return list<string>
     */
    public function productIds(): array
    {
        return $this->productIds;
    }

    /** How many suggestions, 1–12; the API's default is 4. */
    public function limit(int $limit): self
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('The limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function fill(SuggestionFill $fill): self
    {
        $clone = clone $this;
        $clone->fill = $fill;

        return $clone;
    }

    /**
     * The query parameters, without the index id.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'product' => implode(',', $this->productIds),
            'limit' => $this->limit,
            'fill' => $this->fill?->value,
        ], static fn(mixed $v): bool => $v !== null) + $this->filterParams();
    }
}
