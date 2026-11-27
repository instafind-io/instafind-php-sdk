<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * One page of the products an index holds. Pages end at the 10,000th product
 * (`422 page_too_deep` beyond), so this cannot list a large catalogue: the
 * shop's own data is the source of truth.
 */
final class StoredProductsPage
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw `{data, meta}`
     */
    public function __construct(private readonly array $raw) {}

    /**
     * @return list<StoredProduct>
     */
    public function products(): array
    {
        return array_map(static fn(array $p): StoredProduct => new StoredProduct($p), Data::maps($this->raw, 'data'));
    }

    public function page(): int
    {
        return Data::int(Data::map($this->raw, 'meta'), 'current_page', 1);
    }

    public function perPage(): int
    {
        return Data::int(Data::map($this->raw, 'meta'), 'per_page');
    }

    /** Products the index holds (that match the search, if one was given). */
    public function total(): int
    {
        return Data::int(Data::map($this->raw, 'meta'), 'total');
    }

    public function totalPages(): int
    {
        return Data::int(Data::map($this->raw, 'meta'), 'total_pages');
    }

    public function hasMore(): bool
    {
        return $this->page() < $this->totalPages();
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
