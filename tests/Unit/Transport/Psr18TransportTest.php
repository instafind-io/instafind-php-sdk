<?php

declare(strict_types=1);

namespace Instafind\Sdk\Tests\Unit\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Transport\Psr18Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Psr18TransportTest extends TestCase
{
    public function testConvertsTheRequestAndTheResponse(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $last = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->last = $request;

                return new PsrResponse(409, ['Retry-After' => ['1', '5'], 'Content-Type' => 'application/json'], '{"error":"index_locked"}');
            }
        };
        $factory = new Psr17Factory();

        $response = (new Psr18Transport($client, $factory, $factory))->send(new Request('POST', 'https://platform.example/x?a=1', ['Content-Type' => 'application/json', 'User-Agent' => 'ua'], '{"a":1}'));

        self::assertSame(409, $response->status());
        self::assertSame('5', $response->header('retry-after'));
        self::assertSame('{"error":"index_locked"}', $response->body());
        self::assertNotNull($client->last);
        self::assertSame('POST', $client->last->getMethod());
        self::assertSame('https://platform.example/x?a=1', (string) $client->last->getUri());
        self::assertSame('ua', $client->last->getHeaderLine('User-Agent'));
        self::assertSame('{"a":1}', (string) $client->last->getBody());
    }

    public function testANetworkErrorIsATransportException(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('refused') extends \RuntimeException implements NetworkExceptionInterface {
                    public function getRequest(): RequestInterface
                    {
                        throw new \LogicException();
                    }
                };
            }
        };
        $factory = new Psr17Factory();
        $transport = new Psr18Transport($client, $factory, $factory);

        $out = $transport->sendMany(['a' => new Request('GET', 'https://platform.example/')]);
        self::assertInstanceOf(TransportException::class, $out['a']);

        $this->expectException(TransportException::class);
        $transport->send(new Request('GET', 'https://platform.example/'));
    }

    public function testSendManyKeepsKeys(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new PsrResponse(200, [], $request->getUri()->getPath());
            }
        };
        $factory = new Psr17Factory();

        $out = (new Psr18Transport($client, $factory, $factory))->sendMany(['x' => new Request('GET', 'https://p.example/x'), 'y' => new Request('GET', 'https://p.example/y')]);

        self::assertInstanceOf(Response::class, $out['x']);
        self::assertSame('/x', $out['x']->body());
        self::assertInstanceOf(Response::class, $out['y']);
        self::assertSame('/y', $out['y']->body());
    }
}
