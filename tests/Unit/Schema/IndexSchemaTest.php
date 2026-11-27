<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Schema;

use Instafind\Sdk\Exception\InvalidSchemaException;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexSchemaTest extends TestCase
{
    public function testAcceptsEnumsAndStrings(): void
    {
        $schema = IndexSchema::fromFields(['id' => FieldType::Identifier, 'title' => 'title', 'weight' => 'number']);

        self::assertSame([
            ['field_id' => 'id', 'type' => 'identifier'],
            ['field_id' => 'title', 'type' => 'title'],
            ['field_id' => 'weight', 'type' => 'number'],
        ], $schema->fields());
        self::assertSame('id', $schema->identifier());
        self::assertSame(FieldType::Number, $schema->type('weight'));
        self::assertSame(['id', 'title', 'weight'], $schema->fieldIds());
        self::assertSame(['colour'], $schema->undeclaredFieldsIn(['id' => 1, 'title' => 'x', 'colour' => 'red']));
    }

    /**
     * @return iterable<string, array{array<string, FieldType|string>}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [[]];
        yield 'no identifier' => [['title' => FieldType::Title]];
        yield 'two identifiers' => [['a' => FieldType::Identifier, 'b' => FieldType::Identifier]];
        yield 'two titles' => [['id' => FieldType::Identifier, 'a' => FieldType::Title, 'b' => FieldType::Title]];
        yield 'unknown type' => [['id' => FieldType::Identifier, 'x' => 'text']];
        yield 'upper case id' => [['id' => FieldType::Identifier, 'Price' => FieldType::Price]];
        yield 'leading digit' => [['id' => FieldType::Identifier, '1price' => FieldType::Price]];
        yield 'reserved' => [['id' => FieldType::Identifier, '_source' => FieldType::NoIndex]];
        yield 'too long' => [['id' => FieldType::Identifier, str_repeat('a', 65) => FieldType::Attribute]];
    }

    /**
     * @param array<string, FieldType|string> $fields
     */
    #[DataProvider('invalid')]
    public function testRefusesWhatTheApiWould(array $fields): void
    {
        $this->expectException(InvalidSchemaException::class);
        IndexSchema::fromFields($fields);
    }

    public function testLangcodes(): void
    {
        IndexSchema::assertSupportedLangcode('el');
        $this->expectException(InvalidSchemaException::class);
        IndexSchema::assertSupportedLangcode('de');
    }
}
