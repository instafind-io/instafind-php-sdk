<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

use Instafind\Sdk\Auth\Credential;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Support\Clock;
use Instafind\Sdk\Support\Sleeper;
use Instafind\Sdk\Support\SystemClock;
use Instafind\Sdk\Support\SystemSleeper;
use Instafind\Sdk\Transport\Transport;

/**
 * The host's OAuth connection to Instafind as a {@see Credential}: a one-hour
 * access token (`ifa_…`), renewed with a refresh token that works once.
 *
 * Renewal runs behind the {@see TokenStore}'s lock, because two processes
 * spending the same refresh token look like a stolen token to Instafind.
 * A process that finds the lock taken waits for the other one and uses what
 * it stored; it renews itself only when the lock goes stale (the other
 * process died), early enough to repeat that one's refresh inside
 * Instafind's 60-second retry window.
 *
 * When Instafind refuses the refresh token for good (`invalid_grant` …: the
 * connection was revoked), the stored connection is marked lost and the
 * token endpoint is no longer called until someone connects again.
 */
final class OAuthConnection implements Credential
{
    /** Renew this many seconds before the access token runs out. */
    private const EARLY = 60;

    /**
     * Refresh attempts in one renewal, and the timeout of each. A refresh
     * whose answer was lost must be repeated within Instafind's 60-second
     * retry window, after which the spent token counts as stolen. So it is
     * repeated at once: 3 × 8 s plus the pauses stays inside the window.
     */
    private const REFRESH_ATTEMPTS = 3;
    private const REFRESH_TIMEOUT = 8.0;
    private const REFRESH_PAUSE = 2.0;

    /**
     * Seconds after taking the lock by which the refresh attempts must have
     * ended (3 × 8 s plus the pauses, and 2 s for whole-second clocks), before
     * LOCK_STALE - 1, after which the lock is no longer released. Another
     * attempt starts only if it would end in time, assuming it lasts as long as the slowest one so far:
     * with a transport that ignores the timeout (a PSR-18 client, a filtered
     * WordPress request) a renewal could otherwise outlast LOCK_STALE.
     */
    private const REFRESH_BUDGET = 30;

    /**
     * A lock older than this was left by a process that died. Longer than a
     * renewal can take (30 s of refresh attempts plus the store), and short
     * enough that the process taking over repeats the dead one's refresh
     * inside Instafind's 60-second retry window, which counts from the
     * token's first use. At 60 s the takeover came too late, and the
     * connection was revoked as stolen.
     */
    private const LOCK_STALE = 35;

    /** Token endpoint errors that end the connection. */
    private const FATAL_ERRORS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    /** The access token the last request was sent with. */
    private string $current = '';

    /** When this process took the renewal lock. */
    private int $lockedAt = 0;

    private readonly Clock $clock;

    private readonly Sleeper $sleeper;

    public function __construct(
        private readonly TokenStore $store,
        private readonly Transport $transport,
        private readonly ?UserAgent $userAgent = null,
        private readonly ?ConnectionObserver $observer = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }

    /**
     * Store a new connection after the code exchange, replacing any earlier
     * one. A working earlier connection is revoked in Instafind, best effort:
     * nobody holds its tokens afterwards, so it could not be ended from here.
     *
     * @throws InstafindException when the renewal lock stays taken; nothing was stored
     */
    public function connect(AuthorizationServer $server, string $clientId, Tokens $tokens): void
    {
        // Behind the renewal lock, so a running renewal cannot overwrite it.
        $this->lock();
        try {
            $earlier = $this->read();
            $this->write(ConnectionState::fromTokens($server, $clientId, $tokens));
            if ($earlier !== null && !$earlier->isLost() && $earlier->refreshToken() !== $tokens->refreshToken()) {
                try {
                    $this->oauth($earlier)->revoke($earlier->refreshToken());
                } catch (InstafindException) {
                    // Best effort, as in disconnect().
                }
            }
        } finally {
            $this->unlock();
        }
    }

    /** The stored connection, null when there is none. */
    public function state(): ?ConnectionState
    {
        return $this->read();
    }

    public function isConnected(): bool
    {
        $state = $this->read();

        return $state !== null && !$state->isLost();
    }

    /** Why Instafind ended the connection (an RFC 6749 error code), '' while it works. */
    public function lostReason(): string
    {
        return $this->read()?->lostReason() ?? '';
    }

    /**
     * Revoke the connection in Instafind, then forget it. Best effort: the
     * tokens are forgotten even when Instafind cannot be reached; the
     * connection then stays open there until someone ends it in Instafind.
     *
     * @return bool whether Instafind confirmed the revocation
     *
     * @throws InstafindException when the renewal lock stays taken; nothing was changed
     */
    public function disconnect(): bool
    {
        // Behind the renewal lock, so no renewal or new connection is stored meanwhile.
        $this->lock();
        try {
            $state = $this->read();
            $revoked = false;
            if ($state !== null && !$state->isLost()) {
                try {
                    $this->oauth($state)->revoke($state->refreshToken());
                    $revoked = true;
                } catch (InstafindException) {
                    // Forget it anyway: see above.
                }
            }
            $this->store->delete();
            $this->current = '';

            return $revoked;
        } finally {
            $this->unlock();
        }
    }

    public function bearer(): string
    {
        $state = $this->read();
        if ($state === null) {
            return '';
        }
        $this->current = $state->accessToken();
        if (!$state->isLost() && $state->expiresAt() - self::EARLY <= $this->clock->now()) {
            $this->renew();
        }

        return $this->current;
    }

    public function renew(): bool
    {
        $before = $this->current;
        if (!$this->store->acquireLock(self::LOCK_STALE) && !$this->takeOverStaleLock()) {
            // Another process renewed, or tried and failed: use what it stored.
            return $this->takeNewer($before);
        }
        $this->lockedAt = $this->clock->now();

        $spent = '';
        try {
            // Renewed by another process while this one waited for the lock.
            if ($this->takeNewer($before)) {
                return true;
            }
            $state = $this->read();
            if ($state === null || $state->isLost()) {
                return false;
            }
            $spent = $state->refreshToken();
            $tokens = $this->refresh($this->oauth($state), $spent);

            // Store the new tokens only if the connection is still the one
            // refreshed: a disconnect or a new connection may have replaced it.
            $now = $this->read();
            if ($now === null) {
                // Disconnected meanwhile, or the store failed to read: the host
                // has to know, because the new tokens are lost.
                throw new InstafindException('The stored Instafind connection was gone after the renewal; its new tokens were not saved.');
            }
            if ($now->refreshToken() !== $spent) {
                return $this->takeNewer($before);
            }
            $this->write($now->withTokens($tokens));
            $this->current = $tokens->accessToken();

            return true;
        } catch (InstafindException $e) {
            $fatal = $e instanceof ApiException && in_array($e->errorCode(), self::FATAL_ERRORS, true);
            if ($fatal) {
                $now = $this->read();
                // Only the connection whose token was refused is over.
                if ($now !== null && $now->refreshToken() === $spent) {
                    $this->write($now->withLost($e->errorCode()));
                } else {
                    $fatal = false;
                }
            }
            $failure = $e;
        } finally {
            $this->unlock();
        }

        // After the lock is released: an observer may connect or disconnect.
        $this->observer?->renewalFailed($failure, $fatal);

        return false;
    }

    /**
     * Refresh, repeating at once when Instafind did not answer or answered
     * with a server error.
     */
    private function refresh(OAuthClient $oauth, #[\SensitiveParameter] string $refreshToken): Tokens
    {
        $slowest = (int) ceil(self::REFRESH_TIMEOUT);
        for ($attempt = 1; ; $attempt++) {
            $started = $this->clock->now();
            try {
                return $oauth->refresh($refreshToken);
            } catch (InstafindException $e) {
                $now = $this->clock->now();
                $slowest = max($slowest, $now - $started);
                $clientError = $e instanceof ApiException && $e->status() >= 400 && $e->status() < 500;
                $inTime = $now - $this->lockedAt + (int) ceil(self::REFRESH_PAUSE) + $slowest <= self::REFRESH_BUDGET;
                if ($clientError || $attempt >= self::REFRESH_ATTEMPTS || !$inTime) {
                    throw $e;
                }
                $this->sleeper->sleep(self::REFRESH_PAUSE);
            }
        }
    }

    /**
     * Take the lock, waiting for a renewal that holds it, at most until a
     * lock left by a dead process would count as stale. Never goes ahead
     * without it: another process may have taken over a stale lock and be
     * renewing right now.
     *
     * @throws InstafindException when the lock stays taken
     */
    private function lock(): void
    {
        $deadline = $this->clock->now() + self::LOCK_STALE + 1;
        while (!$this->store->acquireLock(self::LOCK_STALE)) {
            if ($this->clock->now() >= $deadline) {
                throw new InstafindException('The Instafind connection is busy being renewed. Try again in a minute.');
            }
            $this->sleeper->sleep(0.25);
        }
        $this->lockedAt = $this->clock->now();
    }

    /**
     * Wait while another process holds the lock. False as soon as it lets
     * go; true when this process took the lock over because it went stale
     * (that process died while renewing, and its refresh must be repeated).
     */
    private function takeOverStaleLock(): bool
    {
        $deadline = $this->clock->now() + self::LOCK_STALE + 1;
        while ($this->clock->now() < $deadline) {
            $this->sleeper->sleep(0.25);
            if (!$this->store->isLocked()) {
                return false;
            }
            if ($this->store->acquireLock(self::LOCK_STALE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Release the lock, unless this process held it so long that another may
     * have taken it over as stale: releasing would then free that one's lock.
     */
    private function unlock(): void
    {
        if ($this->clock->now() - $this->lockedAt < self::LOCK_STALE - 1) {
            $this->store->releaseLock();
        }
    }

    private function oauth(ConnectionState $state): OAuthClient
    {
        return new OAuthClient($state->server(), $state->clientId(), $this->transport, self::REFRESH_TIMEOUT, $this->userAgent, $this->clock);
    }

    /** Whether the stored access token is a usable one other than $before. */
    private function takeNewer(#[\SensitiveParameter] string $before): bool
    {
        $state = $this->read();
        if ($state === null || $state->isLost() || $state->accessToken() === $before || $state->expiresAt() - self::EARLY <= $this->clock->now()) {
            return false;
        }
        $this->current = $state->accessToken();

        return true;
    }

    /**
     * Always from the store: another process may have connected, renewed,
     * reconnected or disconnected since this object last looked.
     */
    private function read(): ?ConnectionState
    {
        try {
            return $this->store->load();
        } catch (\InvalidArgumentException) {
            // Damaged stored data ({@see ConnectionState::fromArray()}): no
            // connection, so calls go on and connecting again overwrites it.
            return null;
        }
    }

    private function write(ConnectionState $state): void
    {
        $this->store->save($state);
    }
}
