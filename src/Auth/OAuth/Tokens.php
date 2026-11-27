<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * What the token endpoint issued: an access token (`ifa_…`), its refresh
 * token (works once) and what the connection may do.
 */
final class Tokens
{
    /**
     * @param int          $expiresAt Unix time the access token stops working
     * @param list<string> $scopes
     * @param array<string, mixed> $raw the token response as it arrived
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly string $refreshToken,
        private readonly int $expiresAt,
        private readonly array $scopes = [],
        private readonly string $websiteId = '',
        private readonly array $raw = [],
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /** The website the connection belongs to, '' when the server did not say. */
    public function websiteId(): string
    {
        return $this->websiteId;
    }

    /**
     * The token response as it arrived (it contains the tokens: never log it).
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
