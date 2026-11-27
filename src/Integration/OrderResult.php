<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of one order sent.
 */
final class OrderResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function reference(): ?string
    {
        return Data::stringOrNull($this->raw, 'reference');
    }

    /** `ok`, `stale` (an older update than the one stored) or `error`. */
    public function status(): string
    {
        return Data::string($this->raw, 'status');
    }

    public function reason(): ?string
    {
        return Data::stringOrNull($this->raw, 'reason');
    }

    public function isError(): bool
    {
        return $this->status() === 'error';
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
