<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A campaign page's banner image.
 */
final class CampaignBanner
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function desktopUrl(): string
    {
        return Data::string($this->raw, 'desktop_url');
    }

    public function mobileUrl(): ?string
    {
        return Data::stringOrNull($this->raw, 'mobile_url');
    }

    public function link(): string
    {
        return Data::string($this->raw, 'link');
    }

    public function alt(): string
    {
        return Data::string($this->raw, 'alt');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
