<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * How facet buckets are ordered: by product count or by value.
 */
enum FacetSort: string
{
    case Count = 'count';
    case Key = 'key';
}
