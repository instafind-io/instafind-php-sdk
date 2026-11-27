<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

/**
 * Where the host keeps its OAuth connection, implemented by the host (the
 * WooCommerce plugin keeps it in a WordPress option).
 *
 * The lock matters: a refresh token works once, and two processes spending
 * the same one look like a stolen token to Instafind, which then revokes the
 * connection. The lock must therefore hold across processes (a database row
 * inserted atomically, `flock()` on a shared file …), not just in memory.
 */
interface TokenStore
{
    /**
     * The stored connection, read from storage, not from a cache: another
     * process may have renewed it since. Null when there is none.
     */
    public function load(): ?ConnectionState;

    public function save(ConnectionState $state): void;

    public function delete(): void;

    /**
     * Take the renewal lock without waiting.
     *
     * @param int $staleAfter seconds after which a lock is a leftover of a
     *                        process that died and may be taken over
     *
     * @return bool whether this process now holds it
     */
    public function acquireLock(int $staleAfter): bool;

    public function releaseLock(): void;

    /** Whether some process holds the lock now. */
    public function isLocked(): bool;
}
