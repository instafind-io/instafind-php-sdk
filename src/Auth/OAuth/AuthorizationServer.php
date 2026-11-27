<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * Instafind's OAuth endpoints, as its metadata (RFC 8414) names them. Store
 * {@see self::toArray()} with the connection; the endpoints are never fixed paths.
 */
final class AuthorizationServer
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $authorizationEndpoint,
        private readonly string $tokenEndpoint,
        private readonly string $revocationEndpoint,
    ) {}

    /**
     * @param array<string, mixed> $data what {@see self::toArray()} returned
     */
    public static function fromArray(array $data): self
    {
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new \InvalidArgumentException("The stored authorization server has no $key.");
            }
        }

        /** @var array{issuer: string, authorization_endpoint: string, token_endpoint: string, revocation_endpoint: string} $data */
        return new self($data['issuer'], $data['authorization_endpoint'], $data['token_endpoint'], $data['revocation_endpoint']);
    }

    /**
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, revocation_endpoint: string}
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'revocation_endpoint' => $this->revocationEndpoint,
        ];
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function authorizationEndpoint(): string
    {
        return $this->authorizationEndpoint;
    }

    public function tokenEndpoint(): string
    {
        return $this->tokenEndpoint;
    }

    public function revocationEndpoint(): string
    {
        return $this->revocationEndpoint;
    }
}
