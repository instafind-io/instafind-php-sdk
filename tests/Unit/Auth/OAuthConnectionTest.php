<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Auth;

use Instafind\Sdk\Auth\OAuth\ConnectionObserver;
use Instafind\Sdk\Auth\OAuth\ConnectionState;
use Instafind\Sdk\Auth\OAuth\InMemoryTokenStore;
use Instafind\Sdk\Auth\OAuth\OAuthConnection;
use Instafind\Sdk\Auth\OAuth\Tokens;
use Instafind\Sdk\Auth\OAuth\TokenStore;
use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Response;
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

        return new OAuthConnection($this->store, $this->transport, null, $observer, $this->clock, $this->sleeper);
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

    public function testDisconnectForgetsTokensRenewedMeanwhile(): void
    {
        $this->connect();
        // A renewal in another process holds the lock past the wait.
        $this->store->acquireLock(60);
        $store = $this->store;
        $this->transport = new MockTransport(static function () use ($store): TransportException {
            $store->save(ConnectionState::fromTokens(OAuthClientTest::server(), 'client-1', new Tokens('ifa_2', 'ifr_2', time() + 3600)));

            return new TransportException('timed out');
        });

        self::assertFalse($this->connection()->disconnect());
        self::assertNull($this->store->load());
    }

    public function testConnectWaitsForARunningRenewal(): void
    {
        // A renewal in another process holds the lock past the wait.
        $this->store->acquireLock(60);

        $this->connect();

        self::assertSame('ifr_1', $this->store->load()?->refreshToken());
        self::assertNotSame([], $this->sleeper->sleeps);
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

    public function testStateRoundTrips(): void
    {
        $this->connect();
        $state = $this->store->load();
        self::assertNotNull($state);

        self::assertEquals($state, ConnectionState::fromArray($state->toArray()));
    }
}
