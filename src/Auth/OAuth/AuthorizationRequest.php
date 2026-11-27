<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * A started "Connect to Instafind": where to send the browser, and the two
 * secrets to keep (in the session) until it comes back.
 */
final class AuthorizationRequest
{
    public function __construct(
        private readonly string $url,
        private readonly string $state,
        private readonly string $verifier,
        private readonly string $redirectUri,
    ) {}

    /** Send the browser here. */
    public function url(): string
    {
        return $this->url;
    }

    /** Compare with the `state` the redirect brings back, in constant time. */
    public function state(): string
    {
        return $this->state;
    }

    /** The PKCE verifier {@see OAuthClient::exchangeCode()} needs. */
    public function verifier(): string
    {
        return $this->verifier;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** Whether the state the redirect brought back is this one. */
    public function matchesState(string $returned): bool
    {
        return hash_equals($this->state, $returned);
    }
}
