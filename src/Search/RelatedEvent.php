<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * What a shopper did with related products.
 */
enum RelatedEvent: string
{
    case Click = 'click';
}
