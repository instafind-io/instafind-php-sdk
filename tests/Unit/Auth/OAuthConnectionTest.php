<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Auth;

use Instafind\Sdk\Auth\OAuth\ConnectionObserver;
use Instafind\Sdk\Auth\OAuth\ConnectionState;
use Instafind\Sdk\Auth\OAuth\InMemoryTokenStore;
use Instafind\Sdk\Auth\OAuth\OAuthClient;
use Instafind\Sdk\Auth\OAuth\OAuthConnection;
use Instafind\Sdk\Auth\OAuth\Tokens;
use Instafind\Sdk\Auth\OAuth\TokenStore;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Support\Sleeper;
use Instafind\Sdk\Testing\MockTransport;
use Instafind\Sdk\Tests\Fixtures\FakeClock;
use Instafind\Sdk\Tests\Fixtures\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class OAuthConnectionTest extends TestCase
{
    private FakeClock $clock;

    private RecordingSleeper $sleeper;

    private MockTransport $transport;

    private InMemoryTokenStore $store;

    /** @var list<array{string, bool}> */
    private array $failures = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->transport = new MockTransport();
        $this->store = new InMemoryTokenStore();
    }

    private function connection(): OAuthConnection
    {
        return new OAuthConnection($this->store, $this->transport, null, $this->observer(), $this->clock, $this->sleeper);
    }

    private function observer(): ConnectionObserver
    {
        $failures = &$this->failures;
        $observer = new class ($failures) implements ConnectionObserver {
            /** @var \Closure(string, bool): void */
            private \Closure $record;

            /** @param list<array{string, bool}> $failures */
            public function __construct(array &$failures)
            {
                $this->record = static function (string $message, bool $lost) use (&$failures): void {
                    $failures[] = [$message, $lost];
                };
            }

            public function renewalFailed(InstafindException $failure, bool $connectionLost): void
            {
                ($this->record)($failure->getMessage(), $connectionLost);
            }
        };

        return $observer;
    }

    private function connect(int $expiresIn = 3600): OAuthConnection
    {
        $connection = $this->connection();
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + $expiresIn, ['products:write'], 'w-1'));

        return $connection;
    }

    private function queueTokens(string $suffix): void
    {
        $this->transport->queueJson(200, ['access_token' => 'ifa_' . $suffix, 'refresh_token' => 'ifr_' . $suffix, 'expires_in' => 3600]);
    }

    public function testNoConnectionNoBearer(): void
    {
        $connection = $this->connection();

        self::assertSame('', $connection->bearer());
        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->renew());
    }

    public function testAConnectionMadeByAnotherProcessIsSeen(): void
    {
        $worker = $this->connection();
        self::assertSame('', $worker->bearer());

        // The shop connects in another request while this worker keeps running.
        $this->connect();
        self::assertSame('ifa_1', $worker->bearer());

        // And reconnects to another website: the worker follows at once.
        $this->transport->queueJson(200, []);
        $this->connection()->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_other', 'ifr_other', $this->clock->now + 3600));
        self::assertSame('ifa_other', $worker->bearer());
    }

    public function testAFreshTokenIsUsedAsItIs(): void
    {
        $connection = $this->connect();

        self::assertSame('ifa_1', $connection->bearer());
        self::assertTrue($connection->isConnected());
        self::assertSame([], $this->transport->requests());
    }

    public function testATokenAboutToExpireIsRenewedFirst(): void
    {
        $connection = $this->connect(30);
        $this->queueTokens('2');

        self::assertSame('ifa_2', $connection->bearer());
        $state = $this->store->load();
        self::assertNotNull($state);
        self::assertSame('ifr_2', $state->refreshToken());
        self::assertSame(['products:write'], $state->scopes());
        self::assertSame('w-1', $state->websiteId());
        self::assertFalse($this->store->isLocked());
        parse_str((string) $this->transport->lastRequest()->body(), $form);
        self::assertSame('ifr_1', $form['refresh_token']);
    }

    public function testRenewRepeatsARefreshWithoutAnswer(): void
    {
        $connection = $this->connect();
        $connection->bearer();
        $this->transport->queueFailure('timeout');
        $this->transport->queueJson(502, []);
        $this->queueTokens('2');

        self::assertTrue($connection->renew());
        self::assertSame('ifa_2', $connection->bearer());
        self::assertCount(3, $this->transport->requests());
        self::assertSame([2.0, 2.0], $this->sleeper->sleeps);
    }

    public function testARefusedRefreshTokenEndsTheConnection(): void
    {
        $connection = $this->connect();
        $connection->bearer();
        $this->transport->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'Revoked.']);

        self::assertFalse($connection->renew());
        self::assertSame('invalid_grant', $connection->lostReason());
        self::assertFalse($connection->isConnected());
        self::assertSame([['Revoked.', true]], $this->failures);
        self::assertCount(1, $this->transport->requests());

        // A lost connection no longer calls the token endpoint.
        $this->clock->advance(7200);
        self::assertSame('ifa_1', $connection->bearer());
        self::assertFalse($connection->renew());
        self::assertCount(1, $this->transport->requests());
    }

    public function testATemporaryFailureKeepsTheConnection(): void
    {
        $connection = $this->connect();
        $connection->bearer();
        $this->transport->queueFailure();
        $this->transport->queueFailure();
        $this->transport->queueFailure();

        self::assertFalse($connection->renew());
        self::assertTrue($connection->isConnected());
        self::assertSame(false, $this->failures[0][1]);
    }

    public function testWhileAnotherProcessRenewsItsTokenIsTaken(): void
    {
        $connection = $this->connect();
        $connection->bearer();
        $this->store->acquireLock(60);
        // The other process stores its tokens, then the lock goes; simulate by storing now.
        $state = $this->store->load();
        self::assertNotNull($state);
        $this->store->save($state->withTokens(new Tokens('ifa_other', 'ifr_other', $this->clock->now + 3600)));

        // The lock never goes in this test: the wait ends at the deadline and takes the stored token.
        self::assertTrue($connection->renew());
        self::assertSame('ifa_other', $connection->bearer());
        self::assertSame([], $this->transport->requests());
    }

    /**
     * A store whose lock goes stale like a real one's: after $staleAfter
     * seconds on the clock, another process may take it over.
     */
    private function staleLockStore(): TokenStore
    {
        return new class ($this->clock) implements TokenStore {
            private ?ConnectionState $state = null;

            public ?int $lockedAt = null;

            public function __construct(private FakeClock $clock) {}

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
                if ($this->lockedAt !== null && $this->clock->now() - $this->lockedAt < $staleAfter) {
                    return false;
                }
                $this->lockedAt = $this->clock->now();

                return true;
            }

            public function releaseLock(): void
            {
                $this->lockedAt = null;
            }

            public function isLocked(): bool
            {
                return $this->lockedAt !== null;
            }
        };
    }

    public function testALeftoverLockIsTakenOverInsideInstafindsRetryWindow(): void
    {
        $store = $this->staleLockStore();
        $sentAt = null;
        $transport = new MockTransport(function () use (&$sentAt): Response {
            $sentAt = $this->clock->now();

            return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => 'ifa_2', 'refresh_token' => 'ifr_2', 'expires_in' => 3600]));
        });
        $connection = new OAuthConnection($store, $transport, null, null, $this->clock, $this->sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();

        // A process took the lock and sent ifr_1, which Instafind accepted, then died.
        $died = $this->clock->now();
        self::assertTrue($store->acquireLock(1));

        // A waiting request takes the lock over and repeats the refresh inside
        // Instafind's 60 s retry window, counted from the dead process's use.
        self::assertTrue($connection->renew());
        self::assertNotNull($sentAt);
        self::assertLessThan($died + 60, $sentAt);
        self::assertSame('ifr_2', $store->load()?->refreshToken());
        self::assertFalse($store->isLocked());
    }

    public function testAWaiterDoesNotRepeatAFailedRenewalOfALiveProcess(): void
    {
        $store = $this->staleLockStore();
        $clock = $this->clock;
        // The other process gives up on a refresh after a few seconds and lets go.
        $sleeper = new class ($store, $clock) implements Sleeper {
            private int $sleeps = 0;

            public function __construct(private TokenStore $store, private FakeClock $clock) {}

            public function sleep(float $seconds): void
            {
                $this->clock->advance(1);
                if (++$this->sleeps === 5) {
                    $this->store->releaseLock();
                }
            }
        };
        $connection = new OAuthConnection($store, $this->transport, null, null, $this->clock, $sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();
        self::assertTrue($store->acquireLock(1));

        // Before, every waiter then ran a renewal of its own, one after the other.
        self::assertFalse($connection->renew());
        self::assertSame([], $this->transport->requests());
    }

    public function testASlowTransportIsNotRetriedPastTheBudget(): void
    {
        // A PSR-18 client with a 15 s timeout: a second attempt could end after
        // the lock goes stale, and another process would spend the same token.
        $transport = new MockTransport(function (): TransportException {
            $this->clock->advance(15);

            return new TransportException('timeout');
        });
        $connection = new OAuthConnection($this->store, $transport, null, null, $this->clock, $this->sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();

        self::assertFalse($connection->renew());
        self::assertCount(1, $transport->requests());
    }

    public function testAFastTransportGetsAllAttempts(): void
    {
        $transport = new MockTransport(function (): TransportException {
            $this->clock->advance(8);

            return new TransportException('timeout');
        });
        $connection = new OAuthConnection($this->store, $transport, null, null, $this->clock, $this->sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();

        self::assertFalse($connection->renew());
        self::assertCount(3, $transport->requests());
    }

    public function testALockHeldUntilItWentStaleIsNotReleased(): void
    {
        $store = $this->staleLockStore();
        $transport = new MockTransport(function (): Response {
            // A client without a timeout hangs; meanwhile another process may
            // have taken the lock over as stale, and it is now that one's.
            $this->clock->advance(40);

            return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => 'ifa_2', 'refresh_token' => 'ifr_2', 'expires_in' => 3600]));
        });
        $connection = new OAuthConnection($store, $transport, null, null, $this->clock, $this->sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();
        self::assertFalse($store->isLocked());

        self::assertTrue($connection->renew());
        self::assertTrue($store->isLocked());
    }

    public function testReconnectingRevokesTheEarlierConnection(): void
    {
        $this->connect();
        $this->transport->queueJson(200, []);

        $this->connection()->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_other', 'ifr_other', $this->clock->now + 3600));

        self::assertSame('ifr_other', $this->store->load()?->refreshToken());
        self::assertCount(1, $this->transport->requests());
        parse_str((string) $this->transport->lastRequest()->body(), $form);
        self::assertSame('ifr_1', $form['token']);
    }

    public function testReconnectingWorksWhenTheRevocationFails(): void
    {
        $this->connect();
        $this->transport->queueFailure();

        $this->connection()->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_other', 'ifr_other', $this->clock->now + 3600));

        self::assertSame('ifr_other', $this->store->load()?->refreshToken());
        self::assertFalse($this->store->isLocked());
    }

    public function testTokensLostAfterARenewalAreReported(): void
    {
        $this->connect(30);
        // The store forgets the connection while the refresh is under way.
        $bodies = [];
        $transport = new MockTransport(function (Request $request) use (&$bodies): Response {
            $this->store->delete();
            parse_str((string) $request->body(), $form);
            $bodies[] = $form;

            return new Response(200, ['content-type' => 'application/json'], (string) json_encode(['access_token' => 'ifa_2', 'refresh_token' => 'ifr_2', 'expires_in' => 3600]));
        });
        $connection = new OAuthConnection($this->store, $transport, null, $this->observer(), $this->clock, $this->sleeper);
        $connection->bearer();

        self::assertCount(1, $this->failures);
        self::assertStringContainsString('not saved', $this->failures[0][0]);
        // Nobody holds the new tokens: the connection is over, and ended in Instafind.
        self::assertTrue($this->failures[0][1]);
        self::assertCount(2, $bodies);
        self::assertSame('ifr_2', $bodies[1]['token']);
        self::assertFalse($this->store->isLocked());
    }

    public function testDisconnectRevokesAndForgets(): void
    {
        $connection = $this->connect();
        $this->transport->queueJson(200, []);

        self::assertTrue($connection->disconnect());
        self::assertNull($this->store->load());
        self::assertSame('', $connection->bearer());
        parse_str((string) $this->transport->lastRequest()->body(), $form);
        self::assertSame('ifr_1', $form['token']);
    }

    public function testDisconnectForgetsEvenWhenInstafindIsAway(): void
    {
        $connection = $this->connect();
        $this->transport->queueFailure();

        self::assertFalse($connection->disconnect());
        self::assertNull($this->store->load());
    }

    public function testDisconnectNeverGoesAheadWithoutTheLock(): void
    {
        $this->connect();
        // Another process holds the lock past the wait, e.g. after taking over a stale one.
        $this->store->acquireLock(60);

        try {
            $this->connection()->disconnect();
            self::fail('No exception');
        } catch (InstafindException $e) {
            self::assertStringContainsString('busy', $e->getMessage());
        }
        self::assertSame('ifr_1', $this->store->load()?->refreshToken());
        self::assertSame([], $this->transport->requests());
        self::assertTrue($this->store->isLocked());
    }

    public function testConnectNeverGoesAheadWithoutTheLock(): void
    {
        $this->store->acquireLock(60);

        try {
            $this->connect();
            self::fail('No exception');
        } catch (InstafindException) {
        }
        self::assertNull($this->store->load());
        self::assertNotSame([], $this->sleeper->sleeps);
        self::assertTrue($this->store->isLocked());
    }

    public function testDisconnectWaitsForARenewalAndRevokesWhatItStored(): void
    {
        $this->connect();
        // A renewal in another process holds the lock and stores new tokens before it lets go.
        $this->store->acquireLock(60);
        $store = $this->store;
        $sleeper = new class ($store) implements Sleeper {
            public int $sleeps = 0;

            public function __construct(private InMemoryTokenStore $store) {}

            public function sleep(float $seconds): void
            {
                if (++$this->sleeps === 3) {
                    $state = $this->store->load();
                    \assert($state !== null);
                    $this->store->save($state->withTokens(new Tokens('ifa_2', 'ifr_2', time() + 3600)));
                    $this->store->releaseLock();
                }
            }
        };
        $lockedDuringRevoke = null;
        $this->transport = new MockTransport(static function () use ($store, &$lockedDuringRevoke): Response {
            $lockedDuringRevoke = $store->isLocked();

            return new Response(200, [], '{}');
        });
        $connection = new OAuthConnection($this->store, $this->transport, null, null, $this->clock, $sleeper);

        self::assertTrue($connection->disconnect());
        self::assertTrue($lockedDuringRevoke);
        parse_str((string) $this->transport->lastRequest()->body(), $form);
        self::assertSame('ifr_2', $form['token']);
        self::assertNull($this->store->load());
        self::assertFalse($this->store->isLocked());
    }

    public function testConnectTakesTheLockWhenItIsTakenAgainAtOnce(): void
    {
        // The lock is released and taken again by the next renewal between two looks.
        $store = new class ($this->store) implements TokenStore {
            public int $tries = 0;

            public ?bool $lockedOnSave = null;

            public function __construct(private InMemoryTokenStore $inner) {}

            public function load(): ?ConnectionState
            {
                return $this->inner->load();
            }

            public function save(ConnectionState $state): void
            {
                $this->lockedOnSave = $this->inner->isLocked();
                $this->inner->save($state);
            }

            public function delete(): void
            {
                $this->inner->delete();
            }

            public function acquireLock(int $staleAfter): bool
            {
                return ++$this->tries > 3 && $this->inner->acquireLock($staleAfter);
            }

            public function releaseLock(): void
            {
                $this->inner->releaseLock();
            }

            public function isLocked(): bool
            {
                return false;
            }
        };
        $connection = new OAuthConnection($store, $this->transport, null, null, $this->clock, $this->sleeper);

        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));

        self::assertTrue($store->lockedOnSave);
        self::assertFalse($this->store->isLocked());
    }

    public function testAnObserverMayDisconnectALostConnection(): void
    {
        $connection = null;
        $observer = new class ($connection) implements ConnectionObserver {
            public ?bool $disconnected = null;

            public function __construct(private ?OAuthConnection &$connection) {}

            public function renewalFailed(InstafindException $failure, bool $connectionLost): void
            {
                if ($connectionLost && $this->connection !== null) {
                    $this->connection->disconnect();
                    $this->disconnected = true;
                }
            }
        };
        $connection = new OAuthConnection($this->store, $this->transport, null, $observer, $this->clock, $this->sleeper);
        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));
        $connection->bearer();
        $this->transport->queueJson(400, ['error' => 'invalid_grant']);

        self::assertFalse($connection->renew());
        self::assertTrue($observer->disconnected);
        self::assertNull($this->store->load());
        self::assertSame([], $this->sleeper->sleeps);
        self::assertFalse($this->store->isLocked());
    }

    public function testConnectHoldsTheRenewalLock(): void
    {
        $store = new class ($this->store) implements TokenStore {
            public ?bool $lockedOnSave = null;

            public function __construct(private InMemoryTokenStore $inner) {}

            public function load(): ?ConnectionState
            {
                return $this->inner->load();
            }

            public function save(ConnectionState $state): void
            {
                $this->lockedOnSave = $this->inner->isLocked();
                $this->inner->save($state);
            }

            public function delete(): void
            {
                $this->inner->delete();
            }

            public function acquireLock(int $staleAfter): bool
            {
                return $this->inner->acquireLock($staleAfter);
            }

            public function releaseLock(): void
            {
                $this->inner->releaseLock();
            }

            public function isLocked(): bool
            {
                return $this->inner->isLocked();
            }
        };
        $connection = new OAuthConnection($store, $this->transport, null, null, $this->clock, $this->sleeper);

        $connection->connect(OAuthClientTest::server(), 'client-1', new Tokens('ifa_1', 'ifr_1', $this->clock->now + 3600));

        self::assertTrue($store->lockedOnSave);
        self::assertFalse($this->store->isLocked());
    }

    public function testDisconnectHoldsTheRenewalLock(): void
    {
        $this->connect();
        $store = $this->store;
        $lockedDuringRevoke = null;
        $this->transport = new MockTransport(static function () use ($store, &$lockedDuringRevoke): Response {
            $lockedDuringRevoke = $store->isLocked();

            return new Response(200, [], '{}');
        });

        self::assertTrue($this->connection()->disconnect());
        self::assertTrue($lockedDuringRevoke);
        self::assertFalse($this->store->isLocked());
        self::assertNull($this->store->load());
    }

    public function testDamagedStoredDataCountsAsNoConnection(): void
    {
        $store = new class implements TokenStore {
            public bool $deleted = false;

            public function load(): ?ConnectionState
            {
                return $this->deleted ? null : ConnectionState::fromArray(['client_id' => 'client-1']);
            }

            public function save(ConnectionState $state): void {}

            public function delete(): void
            {
                $this->deleted = true;
            }

            public function acquireLock(int $staleAfter): bool
            {
                return true;
            }

            public function releaseLock(): void {}

            public function isLocked(): bool
            {
                return false;
            }
        };
        $connection = new OAuthConnection($store, $this->transport, null, null, $this->clock, $this->sleeper);

        self::assertSame('', $connection->bearer());
        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->disconnect());
        self::assertTrue($store->deleted);
        self::assertSame([], $this->transport->requests());
    }

    public function testTokensAreLeftOutOfStackTraces(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('SensitiveParameter needs PHP 8.2.');
        }
        // Made while the request is sent, so its trace holds the calls that led there.
        $transport = new MockTransport(static fn(): TransportException => new TransportException('timed out'));
        try {
            (new OAuthClient(OAuthClientTest::server(), 'client-1', $transport))->refresh('ifr_secret');
            self::fail('No exception');
        } catch (TransportException $e) {
            // Strings and arrays, as error trackers send them; objects stay out of it.
            $args = [];
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    if (!is_object($arg)) {
                        $args[] = $arg;
                    }
                }
            }
            self::assertNotSame([], $args);
            self::assertStringNotContainsString('ifr_secret', print_r($args, true));
        }
    }

    public function testStateRoundTrips(): void
    {
        $this->connect();
        $state = $this->store->load();
        self::assertNotNull($state);

        self::assertEquals($state, ConnectionState::fromArray($state->toArray()));
    }
}
