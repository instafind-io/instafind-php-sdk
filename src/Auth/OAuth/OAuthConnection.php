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
 * it stored.
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

    /** A lock older than this was left by a process that died. */
    private const LOCK_STALE = 60;

    /** How long to wait for another process's renewal: longer than one can take. */
    private const LOCK_WAIT = 30;

    /** Token endpoint errors that end the connection. */
    private const FATAL_ERRORS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    /** The access token the last request was sent with. */
    private string $current = '';

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
     * Store a new connection after the code exchange, replacing any earlier one.
     */
    public function connect(AuthorizationServer $server, string $clientId, Tokens $tokens): void
    {
        // Behind the renewal lock, so a running renewal cannot overwrite it.
        $locked = $this->lock();
        try {
            $this->write(ConnectionState::fromTokens($server, $clientId, $tokens));
        } finally {
            if ($locked) {
                $this->store->releaseLock();
            }
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
     */
    public function disconnect(): bool
    {
        // Behind the renewal lock, so no renewal or new connection is stored meanwhile.
        $locked = $this->lock();
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
            if ($locked) {
                $this->store->releaseLock();
            }
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
        if (!$this->store->acquireLock(self::LOCK_STALE)) {
            // Another process is renewing: use what it stores.
            $this->waitForLock();

            return $this->takeNewer($before);
        }

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
            if ($now === null || $now->refreshToken() !== $spent) {
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
            $this->observer?->renewalFailed($e, $fatal);

            return false;
        } finally {
            $this->store->releaseLock();
        }
    }

    /**
     * Refresh, repeating at once when Instafind did not answer or answered
     * with a server error.
     */
    private function refresh(OAuthClient $oauth, string $refreshToken): Tokens
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $oauth->refresh($refreshToken);
            } catch (InstafindException $e) {
                $clientError = $e instanceof ApiException && $e->status() >= 400 && $e->status() < 500;
                if ($clientError || $attempt >= self::REFRESH_ATTEMPTS) {
                    throw $e;
                }
                $this->sleeper->sleep(self::REFRESH_PAUSE);
            }
        }
    }

    /**
     * Take the lock, waiting for a renewal that holds it. Whether it was
     * taken: after LOCK_WAIT the caller goes ahead without it.
     */
    private function lock(): bool
    {
        if ($this->store->acquireLock(self::LOCK_STALE)) {
            return true;
        }
        $this->waitForLock();

        return $this->store->acquireLock(self::LOCK_STALE);
    }

    /** Wait until another process releases the lock, or LOCK_WAIT has passed. */
    private function waitForLock(): void
    {
        $deadline = $this->clock->now() + self::LOCK_WAIT;
        while ($this->clock->now() < $deadline && $this->store->isLocked()) {
            $this->sleeper->sleep(0.25);
        }
    }

    private function oauth(ConnectionState $state): OAuthClient
    {
        return new OAuthClient($state->server(), $state->clientId(), $this->transport, self::REFRESH_TIMEOUT, $this->userAgent, $this->clock);
    }

    /** Whether the stored access token is a usable one other than $before. */
    private function takeNewer(string $before): bool
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
        return $this->store->load();
    }

    private function write(ConnectionState $state): void
    {
        $this->store->save($state);
    }
}
