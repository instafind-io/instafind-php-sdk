<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * PKCE (RFC 7636) with S256.
 */
final class Pkce
{
    /** A new code verifier: 43 characters of base64url. */
    public static function newVerifier(): string
    {
        return self::base64url(random_bytes(32));
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /** A random `state` value: 43 characters of base64url. */
    public static function newState(): string
    {
        return self::base64url(random_bytes(32));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function __construct() {}
}
