<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A popular search keyword.
 */
final class Keyword
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function keyword(): string
    {
        return Data::string($this->raw, 'keyword');
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
