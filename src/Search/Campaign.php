<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A campaign page: its texts, banner and state.
 */
final class Campaign
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function name(): string
    {
        return Data::string($this->raw, 'name');
    }

    /** `active`, `upcoming`, `ended` or `unavailable`; new states may be added. */
    public function state(): string
    {
        return Data::string($this->raw, 'state');
    }

    public function isActive(): bool
    {
        return $this->state() === 'active';
    }

    public function title(): string
    {
        return Data::string($this->raw, 'title');
    }

    public function intro(): string
    {
        return Data::string($this->raw, 'intro');
    }

    public function banner(): ?CampaignBanner
    {
        $banner = Data::map($this->raw, 'banner');

        return $banner === [] ? null : new CampaignBanner($banner);
    }

    /**
     * Links shown as tiles: `{label, field, value}` for a filter, `{label, url}` for an address.
     *
     * @return list<array<string, mixed>>
     */
    public function tiles(): array
    {
        return Data::maps($this->raw, 'tiles');
    }

    /** `selection` (hand-picked products) or `catalogue`. */
    public function list(): string
    {
        return Data::string($this->raw, 'list');
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'starts_at');
    }

    /** What to tell the shopper when the campaign is not active, null when it is. */
    public function messageKey(): ?string
    {
        return Data::stringOrNull($this->raw, 'message_key');
    }

    public function endedMessage(): ?string
    {
        return Data::stringOrNull($this->raw, 'ended_message');
    }

    /** Where to send the shopper instead, null to show the page. */
    public function redirect(): ?string
    {
        return Data::stringOrNull($this->raw, 'redirect');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
