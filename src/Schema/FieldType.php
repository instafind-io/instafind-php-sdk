<?php

declare(strict_types=1);

namespace Instafind\Sdk\Schema;

/**
 * The field types Instafind builds an index mapping for.
 *
 * A type decides the analyser, whether the field can be a filter or a range,
 * and whether it comes back in the hits. It cannot change once the index
 * exists: a change answers `409 requires_new_index`.
 */
enum FieldType: string
{
    case Title = 'title';
    case Url = 'url';
    case Image = 'image';
    case Price = 'price';
    case SalePrice = 'sale_price';
    case Brand = 'brand';
    /** Takes a list; each value is analysed on its own. */
    case Category = 'category';
    /** Searchable, not returned in the hits by default. */
    case Description = 'description';
    /** The document key products are addressed by. Exactly one field has it. */
    case Identifier = 'identifier';
    case Sku = 'sku';
    case Attribute = 'attribute';
    case Availability = 'availability';
    case Boolean = 'boolean';
    case Size = 'size';
    case Barcode = 'barcode';
    case Color = 'color';
    case Mpn = 'mpn';
    case Number = 'number';
    /** Stored and returned, never matched against. */
    case NoIndex = 'no_index';
}
