<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

use Instafind\Sdk\Support\Data;

/**
 * A content snippet for one placement.
 */
final class Snippet
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw) {}

    public function slug(): string
    {
        return Data::string($this->raw, 'slug');
    }

    public function name(): string
    {
        return Data::string($this->raw, 'name');
    }

    /** HTML Instafind sanitised when it was saved. */
    public function content(): string
    {
        return Data::string($this->raw, 'content');
    }

    public function placement(): string
    {
        return Data::string($this->raw, 'placement');
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return Data::strings($this->raw, 'tags');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
