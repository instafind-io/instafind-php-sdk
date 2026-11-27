<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A query parameter the search did not apply, and why.
 */
final class IgnoredFilter
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function param(): string
    {
        return Data::string($this->raw, 'param');
    }

    /** `unknown_field`, `not_a_facet`, `not_filterable_type`, `invalid_value`, `malformed_range` or `not_sortable`. */
    public function reason(): string
    {
        return Data::string($this->raw, 'reason');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
