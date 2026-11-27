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
 * inserted atomically with the time it was taken …), not just in memory.
 */
interface TokenStore
{
    /**
     * The stored connection, read from storage, not from a cache: another
     * process may have renewed it since. Null when there is none.
     *
     * @throws \InvalidArgumentException from {@see ConnectionState::fromArray()}
     *         when the stored data is damaged: the connection counts as none
     */
    public function load(): ?ConnectionState;

    public function save(ConnectionState $state): void;

    public function delete(): void;

    /**
     * Take the renewal lock without waiting.
     *
     * @param int $staleAfter seconds after which a lock is a leftover of a
     *                        process that died and may be taken over. Judge
     *                        it by the lock's age alone, even while its
     *                        holder still runs (an `flock()` held by a live
     *                        process does not qualify, nor a transient or
     *                        cache key that vanishes when it expires: see
     *                        {@see self::isLocked()}), and honour it
     *                        exactly: a takeover much later than this misses
     *                        Instafind's 60-second retry window, and the
     *                        connection is lost
     *
     * @return bool whether this process now holds it
     */
    public function acquireLock(int $staleAfter): bool;

    public function releaseLock(): void;

    /**
     * Whether some process holds the lock now, a stale one included until it
     * is taken over or released: a waiting process takes that as a sign that
     * the renewal is still running, and takes a stale lock over itself.
     */
    public function isLocked(): bool;
}
