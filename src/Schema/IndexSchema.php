<?php

declare(strict_types=1);

namespace Instafind\Sdk\Schema;

use Instafind\Sdk\Exception\InvalidSchemaException;

/**
 * The fields an index is created with, checked against the API's rules
 * before anything is sent: field types and the langcode cannot change once
 * the index exists, so a mistake the API finds costs a new index and a full
 * reload.
 *
 *     $schema = IndexSchema::fromFields([
 *         'id' => FieldType::Identifier,
 *         'title' => FieldType::Title,
 *         'price' => 'price',
 *     ]);
 */
final class IndexSchema
{
    /** The API's rule for a field id. */
    public const FIELD_ID_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    public const MAX_FIELD_ID_LENGTH = 64;

    public const MAX_FIELDS = 200;

    /** Field ids the search engine keeps for itself. */
    public const RESERVED_FIELD_IDS = ['_id', '_index', '_type', '_routing', '_doc', '_source', '_meta', '_score', '_primary_term', '_seq_no', '_version'];

    /** Langcodes Instafind has an analyser for. */
    public const SUPPORTED_LANGCODES = ['el', 'en'];

    /**
     * @param array<string, FieldType> $fields
     */
    private function __construct(private readonly array $fields) {}

    /**
     * @param array<string, FieldType|string> $fields field id => type
     *
     * @throws InvalidSchemaException when a field id or type breaks a rule of the API
     */
    public static function fromFields(array $fields): self
    {
        if ($fields === []) {
            throw new InvalidSchemaException('An index schema must declare at least one field.');
        }
        if (count($fields) > self::MAX_FIELDS) {
            throw new InvalidSchemaException('An index schema may declare at most ' . self::MAX_FIELDS . ' fields.');
        }

        $typed = [];
        foreach ($fields as $fieldId => $type) {
            $fieldId = (string) $fieldId;
            self::assertFieldId($fieldId);
            if (is_string($type)) {
                $parsed = FieldType::tryFrom($type);
                if ($parsed === null) {
                    $valid = implode(', ', array_map(static fn(FieldType $t): string => $t->value, FieldType::cases()));
                    throw new InvalidSchemaException("Field '$fieldId' has the unknown type '$type'. Valid types: $valid.");
                }
                $type = $parsed;
            }
            $typed[$fieldId] = $type;
        }

        $identifiers = count(array_filter($typed, static fn(FieldType $t): bool => $t === FieldType::Identifier));
        if ($identifiers !== 1) {
            throw new InvalidSchemaException("An index schema must declare exactly one 'identifier' field, found $identifiers.");
        }
        $titles = count(array_filter($typed, static fn(FieldType $t): bool => $t === FieldType::Title));
        if ($titles > 1) {
            throw new InvalidSchemaException("An index schema may declare at most one 'title' field, found $titles.");
        }

        return new self($typed);
    }

    /**
     * @throws InvalidSchemaException
     */
    public static function assertFieldId(string $fieldId): void
    {
        if (in_array($fieldId, self::RESERVED_FIELD_IDS, true)) {
            throw new InvalidSchemaException("'$fieldId' is reserved by the search engine.");
        }
        if (preg_match(self::FIELD_ID_PATTERN, $fieldId) !== 1) {
            throw new InvalidSchemaException("Field id '$fieldId' must start with a lower-case letter and hold only a-z, 0-9, _ and -.");
        }
        if (strlen($fieldId) > self::MAX_FIELD_ID_LENGTH) {
            throw new InvalidSchemaException("Field id '$fieldId' is longer than " . self::MAX_FIELD_ID_LENGTH . ' characters.');
        }
    }

    /**
     * @throws InvalidSchemaException on a langcode Instafind has no analyser for
     */
    public static function assertSupportedLangcode(string $langcode): void
    {
        if (!in_array($langcode, self::SUPPORTED_LANGCODES, true)) {
            throw new InvalidSchemaException('Instafind supports only ' . implode(' and ', self::SUPPORTED_LANGCODES) . " as a langcode, got '$langcode'.");
        }
    }

    /**
     * @return array<string, FieldType> field id => type
     */
    public function types(): array
    {
        return $this->fields;
    }

    public function type(string $fieldId): ?FieldType
    {
        return $this->fields[$fieldId] ?? null;
    }

    /**
     * The fields as the create-index request sends them.
     *
     * @return list<array{field_id: string, type: string}>
     */
    public function fields(): array
    {
        $out = [];
        foreach ($this->fields as $fieldId => $type) {
            $out[] = ['field_id' => $fieldId, 'type' => $type->value];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function fieldIds(): array
    {
        return array_keys($this->fields);
    }

    /** The field whose value is the document key. */
    public function identifier(): string
    {
        return (string) array_search(FieldType::Identifier, $this->fields, true);
    }

    /**
     * Fields of a document the schema does not declare. Instafind rejects
     * such a document (`unknown_field: …`) inside an HTTP 200, so checking
     * first is cheaper than reading it out of the bulk answer.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    public function undeclaredFieldsIn(array $document): array
    {
        return array_values(array_diff(array_map('strval', array_keys($document)), $this->fieldIds()));
    }
}
