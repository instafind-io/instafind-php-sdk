<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Support\Data;

/**
 * One field of an index: its id, type and how its values are read.
 */
final class FieldDefinition
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function fieldId(): string
    {
        return Data::string($this->raw, 'field_id');
    }

    /** The key in the source data, for a feed index. */
    public function originalKey(): ?string
    {
        return Data::stringOrNull($this->raw, 'original_key');
    }

    /** The type as Instafind names it; see {@see self::fieldType()}. */
    public function type(): string
    {
        return Data::string($this->raw, 'type');
    }

    public function typeLabel(): string
    {
        return Data::string($this->raw, 'type_label');
    }

    public function typeGroup(): string
    {
        return Data::string($this->raw, 'type_group');
    }

    /** The template token the field fills, null for none. */
    public function token(): ?string
    {
        return Data::stringOrNull($this->raw, 'token');
    }

    public function isCustom(): bool
    {
        return Data::bool($this->raw, 'is_custom');
    }

    public function splitDelimiter(): ?string
    {
        return Data::stringOrNull($this->raw, 'split_delimiter');
    }

    /** Whether a type change waits for the index to be rebuilt. */
    public function isRebuildPending(): bool
    {
        return Data::bool($this->raw, 'rebuild_pending');
    }

    /**
     * @return list<string>
     */
    public function sampleValues(): array
    {
        return Data::strings($this->raw, 'sample_values');
    }

    /** The type, null for a type this SDK does not know yet. */
    public function fieldType(): ?FieldType
    {
        return FieldType::tryFrom($this->type());
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
