<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of the default filters the integration proposed.
 */
final class DefaultFiltersResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** Whether the index's Filters tab now holds them. */
    public function isApplied(): bool
    {
        return Data::bool($this->raw, 'applied');
    }

    /** Why not: `already_set`, `no_products`, `not_ready` or `nothing_to_show`; null when applied. */
    public function reason(): ?string
    {
        return Data::stringOrNull($this->raw, 'reason');
    }

    /**
     * The fields now in the sidebar.
     *
     * @return list<string>
     */
    public function sidebar(): array
    {
        return Data::strings($this->raw, 'sidebar');
    }

    /**
     * The fields now in the filter modal.
     *
     * @return list<string>
     */
    public function modal(): array
    {
        return Data::strings($this->raw, 'modal');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
