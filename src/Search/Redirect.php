<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A redirect rule matched the query: send the shopper to this page instead of the results.
 */
final class Redirect
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function id(): string
    {
        return Data::string($this->raw, 'id');
    }

    public function url(): string
    {
        return Data::string($this->raw, 'url');
    }

    public function title(): string
    {
        return Data::string($this->raw, 'title');
    }

    public function description(): string
    {
        return Data::string($this->raw, 'description');
    }

    /** Whether the query matched the rule exactly. */
    public function exact(): bool
    {
        return Data::bool($this->raw, 'exact');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
