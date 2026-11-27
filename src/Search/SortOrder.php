<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * The direction of a sort.
 */
enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
