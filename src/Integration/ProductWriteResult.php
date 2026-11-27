<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of one product of a write.
 */
final class ProductWriteResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** The product id; `(unknown)` when the id itself was unusable. */
    public function id(): string
    {
        return Data::string($this->raw, 'id');
    }

    /** `ok`, `filtered`, `pending` or `error`. */
    public function status(): string
    {
        return Data::string($this->raw, 'status');
    }

    /** Why it was rejected (`error`) or is not searchable yet (`pending`). */
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
