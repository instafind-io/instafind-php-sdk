<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of a product write. A 200 can carry rejected products: check
 * {@see self::failed()} or {@see self::errors()}, never just the status.
 */
final class BulkWriteResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** Stored and searchable. */
    public function successful(): int
    {
        return Data::int($this->raw, 'successful');
    }

    /** Stored, but dropped by a preprocessor: not searchable. */
    public function filtered(): int
    {
        return Data::int($this->raw, 'filtered');
    }

    /** Stored durably, not yet searchable; it heals by itself. Do not send again. */
    public function pending(): int
    {
        return Data::int($this->raw, 'pending');
    }

    /** Rejected; nothing of them was stored. */
    public function failed(): int
    {
        return Data::int($this->raw, 'failed');
    }

    /**
     * Per product, in request order.
     *
     * @return list<ProductWriteResult>
     */
    public function results(): array
    {
        return array_map(static fn(array $r): ProductWriteResult => new ProductWriteResult($r), Data::maps($this->raw, 'results'));
    }

    /**
     * The rejected products.
     *
     * @return list<ProductWriteResult>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->results(), static fn(ProductWriteResult $r): bool => $r->isError()));
    }

    public function isClean(): bool
    {
        return $this->failed() === 0;
    }

    /** `none`, `async` (wait_for) or `immediate`. */
    public function refreshPolicy(): string
    {
        return Data::string(Data::map($this->raw, 'meta'), 'refresh_policy');
    }

    public function durationMs(): int
    {
        return Data::int(Data::map($this->raw, 'meta'), 'duration_ms');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
