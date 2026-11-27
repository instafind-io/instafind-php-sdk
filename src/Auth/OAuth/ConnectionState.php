<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * A stored OAuth connection: the server, the client and the current tokens.
 * A {@see TokenStore} keeps {@see self::toArray()} and gives it back through
 * {@see self::fromArray()}.
 */
final class ConnectionState
{
    /**
     * @internal use {@see self::fromTokens()} or {@see self::fromArray()}
     *
     * @param list<string> $scopes
     * @param string       $lost why Instafind ended the connection (an RFC 6749 error code), '' while it works
     */
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $accessToken,
        #[\SensitiveParameter]
        private readonly string $refreshToken,
        private readonly int $expiresAt,
        private readonly array $scopes = [],
        private readonly string $websiteId = '',
        private readonly string $lost = '',
    ) {}

    /** A new connection from the tokens of a code exchange. */
    public static function fromTokens(AuthorizationServer $server, string $clientId, Tokens $tokens): self
    {
        return new self($server, $clientId, $tokens->accessToken(), $tokens->refreshToken(), $tokens->expiresAtTimestamp(), $tokens->scopes(), $tokens->websiteId());
    }

    /**
     * @param array<string, mixed> $data what {@see self::toArray()} returned
     *
     * @throws \InvalidArgumentException when the data is not a stored connection
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        $clientId = $data['client_id'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $server = $data['server'] ?? null;
        if (!is_string($clientId) || !is_string($accessToken) || !is_string($refreshToken) || !is_array($server)) {
            throw new \InvalidArgumentException('This is not a stored Instafind connection.');
        }
        $expiresAt = $data['expires_at'] ?? null;
        $scopes = $data['scopes'] ?? null;
        $websiteId = $data['website_id'] ?? null;
        $lost = $data['lost'] ?? null;

        /** @var array<string, mixed> $server */
        return new self(
            AuthorizationServer::fromArray($server),
            $clientId,
            $accessToken,
            $refreshToken,
            is_numeric($expiresAt) ? (int) $expiresAt : 0,
            is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            is_string($websiteId) ? $websiteId : '',
            is_string($lost) ? $lost : '',
        );
    }

    /**
     * @return array{client_id: string, server: array{issuer: string, authorization_endpoint: string, token_endpoint: string, revocation_endpoint: string}, access_token: string, refresh_token: string, expires_at: int, scopes: list<string>, website_id: string, lost: string}
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'server' => $this->server->toArray(),
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scopes' => $this->scopes,
            'website_id' => $this->websiteId,
            'lost' => $this->lost,
        ];
    }

    /** The same connection with renewed tokens. */
    public function withTokens(Tokens $tokens): self
    {
        return new self(
            $this->server,
            $this->clientId,
            $tokens->accessToken(),
            $tokens->refreshToken(),
            $tokens->expiresAtTimestamp(),
            $tokens->scopes() !== [] ? $tokens->scopes() : $this->scopes,
            $tokens->websiteId() !== '' ? $tokens->websiteId() : $this->websiteId,
            '',
        );
    }

    /** The same connection, marked as ended by Instafind. */
    public function withLost(string $reason): self
    {
        return new self($this->server, $this->clientId, $this->accessToken, $this->refreshToken, $this->expiresAt, $this->scopes, $this->websiteId, $reason);
    }

    public function server(): AuthorizationServer
    {
        return $this->server;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    /** When the access token stops working. */
    public function expiresAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->expiresAt);
    }

    /** {@see self::expiresAt()} as a Unix timestamp. */
    public function expiresAtTimestamp(): int
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

    public function websiteId(): string
    {
        return $this->websiteId;
    }

    /** Why Instafind ended the connection, '' while it works. */
    public function lostReason(): string
    {
        return $this->lost;
    }

    public function isLost(): bool
    {
        return $this->lost !== '';
    }
}
