<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * What a shopper did with a campaign page.
 */
enum CampaignEvent: string
{
    case View = 'view';
    case Click = 'click';
}
