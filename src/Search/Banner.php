<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A merchandising banner to show with the results.
 */
final class Banner
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

    /** `band` or `card`. */
    public function placement(): string
    {
        return Data::string($this->raw, 'placement');
    }

    /** `top`, `bottom` or `inline-N`. */
    public function position(): string
    {
        return Data::string($this->raw, 'position');
    }

    public function slot(): ?string
    {
        return Data::stringOrNull($this->raw, 'slot');
    }

    /** HTML Instafind sanitised when it was saved. */
    public function html(): string
    {
        return Data::string($this->raw, 'html');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
