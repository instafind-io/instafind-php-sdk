<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What became of a batch of orders. A 200 can still carry rejected orders.
 */
final class OrdersResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function accepted(): int
    {
        return Data::int($this->raw, 'accepted');
    }

    /** Orders older than the version Instafind holds: ignored, not an error. */
    public function stale(): int
    {
        return Data::int($this->raw, 'stale');
    }

    public function failed(): int
    {
        return Data::int($this->raw, 'failed');
    }

    /**
     * Per order, in request order.
     *
     * @return list<OrderResult>
     */
    public function results(): array
    {
        return array_map(static fn(array $r): OrderResult => new OrderResult($r), Data::maps($this->raw, 'results'));
    }

    /**
     * @return list<OrderResult>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->results(), static fn(OrderResult $r): bool => $r->isError()));
    }

    public function isClean(): bool
    {
        return $this->failed() === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
