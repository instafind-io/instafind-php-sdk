<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * Counts of what is set up on an index.
 */
final class IndexSummary
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function synonyms(): int
    {
        return Data::int($this->raw, 'synonyms');
    }

    public function hiddenSearches(): int
    {
        return Data::int($this->raw, 'hidden_searches');
    }

    public function activeMerchandisingRules(): int
    {
        return Data::int(Data::map($this->raw, 'merchandising_rules'), 'active');
    }

    public function merchandisingRules(): int
    {
        return Data::int(Data::map($this->raw, 'merchandising_rules'), 'total');
    }

    public function liveCampaigns(): int
    {
        return Data::int(Data::map($this->raw, 'campaigns'), 'live');
    }

    public function campaigns(): int
    {
        return Data::int(Data::map($this->raw, 'campaigns'), 'total');
    }

    public function activeContentSnippets(): int
    {
        return Data::int(Data::map($this->raw, 'content_snippets'), 'active');
    }

    public function contentSnippets(): int
    {
        return Data::int(Data::map($this->raw, 'content_snippets'), 'total');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
