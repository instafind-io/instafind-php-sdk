<?php

declare(strict_types=1);

namespace Instafind\Sdk\Testing;

use Instafind\Sdk\Exception\TransportException;
use Instafind\Sdk\Http\Request;
use Instafind\Sdk\Http\Response;
use Instafind\Sdk\Transport\Transport;

/**
 * A {@see Transport} for tests: answers from a queue or a callback and
 * remembers every request.
 *
 *     $transport = new MockTransport();
 *     $transport->queueJson(200, ['data' => [...]]);
 *     // … call the SDK …
 *     $transport->lastRequest()->url();
 */
final class MockTransport implements Transport
{
    /** @var list<Response|TransportException> */
    private array $queue = [];

    /** @var list<Request> */
    private array $requests = [];

    /** @var (callable(Request): (Response|TransportException))|null */
    private $responder;

    /**
     * @param (callable(Request): (Response|TransportException))|null $responder answers every
     *        request when the queue is empty
     */
    public function __construct(?callable $responder = null)
    {
        $this->responder = $responder;
    }

    public function queue(Response|TransportException ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string>   $headers
     */
    public function queueJson(int $status, array $body, array $headers = []): self
    {
        return $this->queue(new Response($status, $headers + ['content-type' => 'application/json'], (string) json_encode($body)));
    }

    /** Make the next request fail without an answer. */
    public function queueFailure(string $message = 'Connection refused'): self
    {
        return $this->queue(new TransportException($message));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $response = $this->next($request);
        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    }

    public function sendMany(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $request) {
            $this->requests[] = $request;
            $out[$key] = $this->next($request);
        }

        return $out;
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): Request
    {
        if ($this->requests === []) {
            throw new \LogicException('No request was sent.');
        }

        return $this->requests[count($this->requests) - 1];
    }

    /** Responses queued but not used yet. */
    public function pending(): int
    {
        return count($this->queue);
    }

    private function next(Request $request): Response|TransportException
    {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        if ($this->responder !== null) {
            return ($this->responder)($request);
        }

        throw new \LogicException('MockTransport has no response for ' . $request->method() . ' ' . $request->url() . '.');
    }
}
