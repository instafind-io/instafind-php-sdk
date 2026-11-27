<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A campaign page's answer: the campaign, and its products as a search answer.
 */
final class CampaignResult
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** The campaign's products, facets and banners. */
    public function result(): SearchResult
    {
        return new SearchResult($this->raw);
    }

    /** The campaign, null when the index is disabled and no campaign was read. */
    public function campaign(): ?Campaign
    {
        $campaign = Data::map($this->raw, 'campaign');

        return $campaign === [] ? null : new Campaign($campaign);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
