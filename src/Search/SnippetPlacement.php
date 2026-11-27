<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * Where a content snippet is shown.
 */
enum SnippetPlacement: string
{
    case Presearch = 'presearch';
    case NoResults = 'no-results';
}
