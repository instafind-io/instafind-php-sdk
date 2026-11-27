<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * A website: the shop an API key belongs to.
 */
final class Website
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

    public function slug(): string
    {
        return Data::string($this->raw, 'slug');
    }

    public function organizationId(): string
    {
        return Data::string($this->raw, 'organization_id');
    }

    public function name(): string
    {
        return Data::string($this->raw, 'name');
    }

    public function domain(): string
    {
        return Data::string($this->raw, 'domain');
    }

    public function searchIndexCount(): int
    {
        return Data::int($this->raw, 'search_indices_count');
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'created_at');
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'updated_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
