<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * Where results are shown: it decides the promote rules that apply and whether merchandising impressions are recorded.
 */
enum SearchContext: string
{
    case Results = 'results';
    case Dropdown = 'dropdown';
}
