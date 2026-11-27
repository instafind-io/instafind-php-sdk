<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth;

/**
 * A site API key (`ifk_…`): the same token until a person replaces it.
 */
final class StaticKey implements Credential
{
    public function __construct(private readonly string $key) {}

    public function bearer(): string
    {
        return $this->key;
    }

    public function renew(): bool
    {
        return false;
    }
}
