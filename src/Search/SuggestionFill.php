<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * What fills product suggestions when the picks and orders give too few: products of the same category, or nothing.
 */
enum SuggestionFill: string
{
    case Category = 'category';
    case None = 'none';
}
