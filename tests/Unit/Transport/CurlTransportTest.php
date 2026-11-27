<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Tests\Fixtures\LocalServer;
use Instafind\Sdk\Transport\CurlTransport;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('curl')]
final class CurlTransportTest extends TestCase
{
    public function testSendsMethodHeadersAndBody(): void
    {
        $response = (new CurlTransport())->send(new Request('PUT', LocalServer::url() . '/x?a=1', [
            'Content-Type' => 'application/json',
            'User-Agent' => 'instafind-php-sdk/1.0.0',
            'Authorization' => 'Bearer ifk_x',
        ], '{"a":1}', 5.0));

        self::assertSame(200, $response->status());
        self::assertSame('yes', $response->header('X-Echo'));
        $echo = self::echo($response);
        self::assertSame('PUT', $echo['method']);
        self::assertSame('/x?a=1', $echo['uri']);
        self::assertSame('{"a":1}', $echo['body']);
        self::assertSame('Bearer ifk_x', $echo['headers']['authorization']);
        self::assertSame('instafind-php-sdk/1.0.0', $echo['headers']['user-agent']);
    }

    public function testReturnsErrorStatusesAsResponses(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', LocalServer::url() . '/status?code=503'));

        self::assertSame(503, $response->status());
        self::assertSame('7', $response->header('retry-after'));
    }

    public function testDoesNotFollowRedirects(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', LocalServer::url() . '/redirect'));

        self::assertSame(302, $response->status());
        self::assertSame('/elsewhere', $response->header('location'));
    }

    public function testATimeoutIsATransportException(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new Request('GET', LocalServer::url() . '/slow?ms=1500', [], null, 0.3));
    }

    public function testARefusedConnectionIsATransportException(): void
    {
        $this->expectException(TransportException::class);
        (new CurlTransport())->send(new Request('GET', 'http://127.0.0.1:1/', [], null, 2.0));
    }

    public function testSendManyKeepsKeysAndOrder(): void
    {
        $base = LocalServer::url();

        $out = (new CurlTransport(5.0, [], 2))->sendMany([
            'slow' => new Request('GET', $base . '/slow?ms=300&n=1'),
            7 => new Request('GET', $base . '/fast?n=2'),
            'down' => new Request('GET', 'http://127.0.0.1:1/', [], null, 2.0),
            'post' => new Request('POST', $base . '/p', ['Content-Type' => 'text/plain'], 'hello'),
        ]);

        self::assertSame(['slow', 7, 'down', 'post'], array_keys($out));
        self::assertInstanceOf(Response::class, $out['slow']);
        self::assertSame('/slow?ms=300&n=1', self::echo($out['slow'])['uri']);
        self::assertInstanceOf(Response::class, $out[7]);
        self::assertSame('/fast?n=2', self::echo($out[7])['uri']);
        self::assertInstanceOf(TransportException::class, $out['down']);
        self::assertInstanceOf(Response::class, $out['post']);
        self::assertSame('hello', self::echo($out['post'])['body']);
    }

    public function testSendManyRunsInParallel(): void
    {
        $base = LocalServer::url();
        $requests = [];
        for ($i = 0; $i < 4; $i++) {
            $requests[] = new Request('GET', $base . '/slow?ms=400&i=' . $i);
        }

        $start = microtime(true);
        $out = (new CurlTransport())->sendMany($requests);
        $elapsed = microtime(true) - $start;

        self::assertSame([0, 1, 2, 3], array_keys($out));
        foreach ($out as $i => $response) {
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('/slow?ms=400&i=' . $i, self::echo($response)['uri']);
        }
        // One after the other would take 1.6 s.
        self::assertLessThan(1.2, $elapsed);
    }

    public function testSendManyWithNothing(): void
    {
        self::assertSame([], (new CurlTransport())->sendMany([]));
    }

    /**
     * @return array{method: string, uri: string, headers: array<string, string>, body: string}
     */
    private static function echo(Response $response): array
    {
        /** @var array{method: string, uri: string, headers: array<string, string>, body: string} */
        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }
}
