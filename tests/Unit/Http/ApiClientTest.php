<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Http;

use Instafind\Sdk\Auth\Credential;
use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\Exception\ApiException;
use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\ApiClient;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Http\RetryPolicy;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Sdk;
use Instafind\Sdk\Testing\MockTransport;
use Instafind\Sdk\Tests\Fixtures\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    private MockTransport $transport;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->sleeper = new RecordingSleeper();
    }

    private function client(?Credential $credential = null, ?RetryPolicy $retry = null): ApiClient
    {
        return new ApiClient('https://platform.example/', $this->transport, new UserAgent('test-shop', '1.2.3', 'https://shop.example/'), $retry ?? new RetryPolicy(), 10.0, $credential, $this->sleeper);
    }

    public function testSendsHeadersBodyAndBearer(): void
    {
        $this->transport->queueJson(200, ['data' => ['ok' => true]]);
        $client = $this->client(new StaticKey('ifk_secret'));

        $out = $client->send($client->request('POST', '/api/integration/v1/x', ['a' => ['b', 'c']], ['name' => 'Ä/x', 'n' => 1.0]));

        self::assertSame(['data' => ['ok' => true]], $out);
        $request = $this->transport->lastRequest();
        self::assertSame('https://platform.example/api/integration/v1/x?a[]=b&a[]=c', $request->url());
        self::assertSame('Bearer ifk_secret', $request->header('authorization'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('instafind-php-sdk/' . Sdk::VERSION . ' (test-shop/1.2.3; https://shop.example/)', $request->header('User-Agent'));
        self::assertSame('{"name":"Ä/x","n":1.0}', $request->body());
        self::assertSame(10.0, $request->timeout());
    }

    public function testNoCredentialNoAuthorization(): void
    {
        $this->transport->queueJson(200, []);
        $client = $this->client();
        $client->send($client->request('GET', '/api/public/v1/search'));

        self::assertNull($this->transport->lastRequest()->header('Authorization'));
    }

    public function testRepeatsTemporaryAnswers(): void
    {
        $this->transport->queueJson(503, ['message' => 'busy'], ['retry-after' => '2']);
        $this->transport->queueJson(200, ['ok' => 1]);
        $client = $this->client();

        self::assertSame(['ok' => 1], $client->send($client->request('GET', '/x')));
        self::assertSame([2.0], $this->sleeper->sleeps);
        self::assertCount(2, $this->transport->requests());
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $this->transport->queueJson(503, []);
        $this->transport->queueJson(503, []);
        $client = $this->client(null, new RetryPolicy(2));

        try {
            $client->send($client->request('GET', '/x'));
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame(503, $e->status());
        }
        self::assertCount(2, $this->transport->requests());
    }

    public function testDoesNotRepeatAPostThatGotNoAnswer(): void
    {
        $this->transport->queueFailure();
        $client = $this->client();

        $this->expectException(TransportException::class);
        $client->send($client->request('POST', '/x', [], ['a' => 1]));
    }

    public function testRepeatsAnIdempotentPostThatGotNoAnswer(): void
    {
        $this->transport->queueFailure();
        $this->transport->queueJson(200, ['ok' => 1]);
        $client = $this->client();

        self::assertSame(['ok' => 1], $client->send($client->request('POST', '/x', [], ['a' => 1], null, true)));
    }

    public function testRenewsAnExpiredAccessTokenOnce(): void
    {
        $credential = new class implements Credential {
            public int $renewals = 0;

            public function bearer(): string
            {
                return 'ifa_' . $this->renewals;
            }

            public function renew(): bool
            {
                $this->renewals++;

                return true;
            }
        };
        $this->transport->queueJson(401, ['error' => 'access_token_expired']);
        $this->transport->queueJson(401, ['error' => 'access_token_expired']);
        $client = $this->client($credential);

        try {
            $client->send($client->request('GET', '/x'));
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame('access_token_expired', $e->errorCode());
        }
        self::assertSame(1, $credential->renewals);
        self::assertSame('Bearer ifa_0', $this->transport->requests()[0]->header('Authorization'));
        self::assertSame('Bearer ifa_1', $this->transport->requests()[1]->header('Authorization'));
    }

    public function testAnEmptyBodyIsAnEmptyArray(): void
    {
        $this->transport->queue(new Response(204));
        $client = $this->client();

        self::assertSame([], $client->send($client->request('DELETE', '/x')));
    }

    public function testABodyThatIsNotJsonIsUnexpected(): void
    {
        $this->transport->queue(new Response(200, [], '<html>'));
        $client = $this->client();

        try {
            $client->send($client->request('GET', '/x'));
            self::fail('No exception');
        } catch (ApiException $e) {
            self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode());
        }
    }

    public function testARedirectIsUnexpected(): void
    {
        $this->transport->queue(new Response(301, ['location' => 'https://elsewhere/']));
        $client = $this->client();

        $this->expectException(ApiException::class);
        $client->send($client->request('GET', '/x'));
    }

    public function testSendManyKeepsKeysAndFailuresApart(): void
    {
        $this->transport->queueJson(200, ['n' => 1]);
        $this->transport->queueFailure('timeout');
        $this->transport->queueJson(404, ['error' => 'index_not_found']);
        $client = $this->client();

        $out = $client->sendMany([
            'a' => $client->request('GET', '/a'),
            'b' => $client->request('GET', '/b'),
            'c' => $client->request('GET', '/c'),
        ]);

        self::assertSame(['n' => 1], $out['a']);
        self::assertInstanceOf(TransportException::class, $out['b']);
        self::assertInstanceOf(ApiException::class, $out['c']);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testRefusesAnAddressWithoutScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiClient('platform.example', $this->transport, new UserAgent(), new RetryPolicy(), 5.0);
    }
}
