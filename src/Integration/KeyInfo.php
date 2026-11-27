<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

use Instafind\Sdk\Support\Data;

/**
 * What the credential is: its website, scopes, index grant and expiry. The
 * quickest check of an installation's setup, and the way to learn its website.
 */
final class KeyInfo
{
    /**
     * @internal
     *
     * @param array<string, mixed> $raw the `data` of the answer
     */
    public function __construct(private readonly array $raw) {}

    public function id(): string
    {
        return Data::string($this->raw, 'id');
    }

    /** `key` for a site API key, `connection` for an OAuth connection. */
    public function kind(): string
    {
        return Data::string($this->raw, 'kind');
    }

    public function name(): string
    {
        return Data::string($this->raw, 'name');
    }

    /** The key's visible prefix (`ifk_…`), null for a connection. */
    public function prefix(): ?string
    {
        return Data::stringOrNull($this->raw, 'prefix');
    }

    /** The OAuth client of a connection, null for a key. */
    public function clientId(): ?string
    {
        return Data::stringOrNull(Data::map($this->raw, 'client'), 'client_id');
    }

    public function clientName(): ?string
    {
        return Data::stringOrNull(Data::map($this->raw, 'client'), 'name');
    }

    /** The site address a connection was made from, null when none. */
    public function connectedUrl(): ?string
    {
        return Data::stringOrNull($this->raw, 'connected_url');
    }

    public function websiteId(): string
    {
        return Data::string(Data::map($this->raw, 'website'), 'id');
    }

    public function websiteName(): string
    {
        return Data::string(Data::map($this->raw, 'website'), 'name');
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return Data::strings($this->raw, 'scopes');
    }

    /** Whether the credential has a scope, e.g. `products:write`. */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes(), true);
    }

    /** Whether the credential reaches every index of its website, now and later. */
    public function coversAllIndexes(): bool
    {
        return Data::bool($this->raw, 'all_indexes');
    }

    /**
     * The indexes it reaches when it does not cover all.
     *
     * @return list<string>
     */
    public function indexIds(): array
    {
        return Data::strings($this->raw, 'search_index_ids');
    }

    /** Whether the credential reaches this index. */
    public function coversIndex(string $indexId): bool
    {
        return $this->coversAllIndexes() || in_array($indexId, $this->indexIds(), true);
    }

    /** When it stops working, null for never. */
    public function expiresAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'expires_at');
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return Data::date($this->raw, 'created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
