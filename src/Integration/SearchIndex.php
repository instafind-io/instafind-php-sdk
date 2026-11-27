<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * A search index: one language of one website's catalogue.
 */
final class SearchIndex
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw the `data` of the answer
     */
    public function __construct(private readonly array $raw) {}

    public function id(): string
    {
        return Data::string($this->raw, 'id');
    }

    public function name(): string
    {
        return Data::string($this->raw, 'name');
    }

    /** The name a person set in Instafind, null when none. */
    public function nameOverride(): ?string
    {
        return Data::stringOrNull($this->raw, 'name_override');
    }

    /** `api` (products written through this API) or `feed`. Fixed at creation. */
    public function source(): string
    {
        return Data::string($this->raw, 'source');
    }

    /** `creating`, `active` or `deleting`. */
    public function lifecycleState(): string
    {
        return Data::string($this->raw, 'lifecycle_state');
    }

    public function isActive(): bool
    {
        return $this->lifecycleState() === 'active';
    }

    /** Whether search is turned on for it. */
    public function isEnabled(): bool
    {
        return Data::int($this->raw, 'status') === 1;
    }

    /**
     * A rebuild in progress (`status`, `reason`, `started_at`, `built`,
     * `expected`), null when none.
     *
     * @return array<string, mixed>|null
     */
    public function rebuild(): ?array
    {
        $rebuild = Data::map($this->raw, 'rebuild');

        return $rebuild === [] ? null : $rebuild;
    }

    public function langcode(): string
    {
        return Data::string($this->raw, 'langcode');
    }

    /**
     * @return list<FieldDefinition>
     */
    public function fieldDefinitions(): array
    {
        return array_map(static fn(array $f): FieldDefinition => new FieldDefinition($f), Data::maps($this->raw, 'field_definitions'));
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
