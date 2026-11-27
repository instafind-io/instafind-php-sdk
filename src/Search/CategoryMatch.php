<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A category whose name matches the query.
 */
final class CategoryMatch
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function value(): string
    {
        return Data::string($this->raw, 'value');
    }

    public function label(): string
    {
        return Data::string($this->raw, 'label');
    }

    public function highlight(): string
    {
        return Data::string($this->raw, 'highlight');
    }

    /**
     * @return list<string>
     */
    public function path(): array
    {
        return Data::strings($this->raw, 'path');
    }

    public function subtitle(): ?string
    {
        return Data::stringOrNull($this->raw, 'subtitle');
    }

    public function count(): int
    {
        return Data::int($this->raw, 'count');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
