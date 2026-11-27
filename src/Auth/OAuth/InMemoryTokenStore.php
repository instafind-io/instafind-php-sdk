<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * A {@see TokenStore} that lives as long as the PHP process: for tests,
 * scripts and long-running workers with a single process. Its lock does not
 * reach other processes.
 */
final class InMemoryTokenStore implements TokenStore
{
    private bool $locked = false;

    public function __construct(private ?ConnectionState $state = null) {}

    public function load(): ?ConnectionState
    {
        return $this->state;
    }

    public function save(ConnectionState $state): void
    {
        $this->state = $state;
    }

    public function delete(): void
    {
        $this->state = null;
    }

    public function acquireLock(int $staleAfter): bool
    {
        if ($this->locked) {
            return false;
        }
        $this->locked = true;

        return true;
    }

    public function releaseLock(): void
    {
        $this->locked = false;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }
}
