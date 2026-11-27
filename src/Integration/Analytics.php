<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * Search analytics of an index for a window of days. The window is typed;
 * the series and lists are the API's own arrays (see the API documentation),
 * read through {@see self::section()} or {@see self::raw()}.
 */
final class Analytics
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function indexId(): string
    {
        return Data::string($this->raw, 'search_index_id');
    }

    public function days(): int
    {
        return Data::int(Data::map($this->raw, 'window'), 'days');
    }

    public function startAt(): ?\DateTimeImmutable
    {
        return Data::date(Data::map($this->raw, 'window'), 'start_at');
    }

    public function endAt(): ?\DateTimeImmutable
    {
        return Data::date(Data::map($this->raw, 'window'), 'end_at');
    }

    /**
     * Totals of the window with the previous one.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return Data::map($this->raw, 'summary');
    }

    /**
     * One section of the answer: `search_volume_series`, `latency_series`,
     * `interface_breakdown`, `top_popular_queries`, `top_zero_result_queries`,
     * `trending_queries`, `merchandising`, `semantic_search`.
     *
     * @return array<array-key, mixed>
     */
    public function section(string $name): array
    {
        $value = $this->raw[$name] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
