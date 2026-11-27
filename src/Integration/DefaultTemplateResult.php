<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of the templates the integration proposed, per view: `applied`, `already_set` or `not_sent`.
 */
final class DefaultTemplateResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function results(): string
    {
        return Data::string($this->raw, 'results');
    }

    public function liveSearch(): string
    {
        return Data::string($this->raw, 'live_search');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
