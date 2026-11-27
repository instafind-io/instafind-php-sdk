<?php

declare(strict_types=1);

namespace Instafind\Sdk\Transport;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * {@see Transport} over any PSR-18 client (Guzzle, Symfony HttpClient …).
 * Needs `psr/http-client` and `psr/http-factory`.
 *
 * PSR-18 has no timeouts and no parallel requests: configure the timeout on
 * the client, and {@see self::sendMany()} sends one request after the other.
 * Make sure the client does not follow redirects.
 *
 * Give the client a timeout of at most 15 seconds (Guzzle's default is none).
 * {@see \Instafind\Sdk\Auth\OAuth\OAuthConnection} relies on a token
 * refresh ending well inside Instafind's 60-second retry window: a refresh
 * that hangs longer lets another process spend the same refresh token, and
 * Instafind then revokes the connection.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(Request $request): Response
    {
        $psr = $this->requestFactory->createRequest($request->method(), $request->url());
        foreach ($request->headers() as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body() !== null) {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body()));
        }

        try {
            $response = $this->client->sendRequest($psr);
        } catch (NetworkExceptionInterface|RequestExceptionInterface $e) {
            throw new TransportException('Instafind could not be reached: ' . $e->getMessage(), $request->url(), $e);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('The HTTP client failed: ' . $e->getMessage(), $request->url(), $e);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $last = end($values);
            $headers[strtolower((string) $name)] = $last === false ? '' : (string) $last;
        }

        return new Response($response->getStatusCode(), $headers, (string) $response->getBody());
    }

    public function sendMany(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $request) {
            try {
                $out[$key] = $this->send($request);
            } catch (TransportException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }
}
