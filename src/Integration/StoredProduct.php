<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * A product as the index holds it: what was sent, what was indexed, and its state.
 */
final class StoredProduct
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function productId(): string
    {
        return Data::string($this->raw, 'product_uid');
    }

    public function title(): ?string
    {
        return Data::stringOrNull($this->raw, 'title');
    }

    /** Stored, but dropped by a preprocessor: not searchable. */
    public function isFiltered(): bool
    {
        return Data::bool($this->raw, 'filtered');
    }

    /** Stored durably, not yet searchable; it heals by itself. */
    public function isPending(): bool
    {
        return Data::bool($this->raw, 'pending');
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'updated_at');
    }

    /**
     * Exactly what was sent, null when nothing was.
     *
     * @return array<string, mixed>|null
     */
    public function rawData(): ?array
    {
        return is_array($this->raw['raw_data'] ?? null) ? Data::map($this->raw, 'raw_data') : null;
    }

    /**
     * The product as indexed, null when a preprocessor dropped it.
     *
     * @return array<string, mixed>|null
     */
    public function processedData(): ?array
    {
        return is_array($this->raw['processed_data'] ?? null) ? Data::map($this->raw, 'processed_data') : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
