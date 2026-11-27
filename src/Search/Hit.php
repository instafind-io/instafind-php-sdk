<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * One product in a search answer: the fields the index returns for it.
 */
final class Hit
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /**
     * The product's fields, as the index returns them.
     *
     * @return array<string, mixed>
     */
    public function source(): array
    {
        return Data::map($this->raw, '_source');
    }

    /** One field of the product. */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->source()[$field] ?? $default;
    }

    /**
     * Fragments with the matched words in `<strong>`, per field, when asked for.
     *
     * @return array<string, list<string>>
     */
    public function highlight(): array
    {
        $out = [];
        foreach (Data::map($this->raw, 'highlight') as $field => $fragments) {
            $out[$field] = is_array($fragments) ? array_values(array_filter($fragments, 'is_string')) : [];
        }

        return $out;
    }

    /** Why a product suggestion was chosen: `manual`, `orders` or `category`; null outside suggestions. */
    public function suggestionSource(): ?string
    {
        return Data::stringOrNull($this->raw, 'suggestion_source');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
