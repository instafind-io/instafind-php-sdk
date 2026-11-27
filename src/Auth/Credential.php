<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth;

/**
 * What the integration API sees as the caller's bearer token: a site API key
 * ({@see StaticKey}) or the access token of an OAuth connection
 * ({@see OAuth\OAuthConnection}).
 */
interface Credential
{
    /** The bearer token for the next request, '' for none. */
    public function bearer(): string;

    /**
     * Get a fresh token after Instafind answered `access_token_expired`.
     *
     * @return bool whether {@see self::bearer()} now returns a new token worth one more attempt
     */
    public function renew(): bool;
}
