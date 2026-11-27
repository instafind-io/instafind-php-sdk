<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * What Instafind actually searched for when it changed the query: corrected
 * typos, dropped words.
 */
final class QueryUsed
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    /** The text searched for. */
    public function text(): string
    {
        return Data::string($this->raw, 'text');
    }

    /**
     * Words left out to find results.
     *
     * @return list<string>
     */
    public function droppedWords(): array
    {
        return Data::strings($this->raw, 'dropped_words');
    }

    /**
     * Corrected words: what was typed and what was searched instead.
     *
     * @return list<array{typed: string, shown: string}>
     */
    public function corrections(): array
    {
        $out = [];
        foreach (Data::maps($this->raw, 'corrections') as $correction) {
            $out[] = ['typed' => Data::string($correction, 'typed'), 'shown' => Data::string($correction, 'shown')];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
